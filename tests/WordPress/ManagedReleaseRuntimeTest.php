<?php

declare(strict_types=1);

namespace Tests\WordPress;

require_once __DIR__ . '/ManagedReleaseRuntimeWordPressFunctions.php';
require_once __DIR__ . '/RuntimeReleaseStore.php';
require_once __DIR__ . '/RuntimeUpdaterFacade.php';
require_once __DIR__ . '/../Support/WPError.php';
require_once __DIR__ . '/../Support/WordPressUpgraderSkins.php';
require_once __DIR__ . '/../Portability/WpPusherCoexistenceWordPressFunctions.php';

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use RAN\AddOn\ReleaseTracking\NativeReleaseTrackingFacade;
use RAN\AddOn\ReleaseTracking\ReleaseTrackingEligibility;
use RAN\Deployment\DeploymentPolicy;
use RAN\ManagedRepository;
use RAN\Package;
use RAN\PackageSource;
use RAN\RepositoryProvider\ProviderCode;
use RAN\RepositoryProvider\ProviderMetadata;
use RAN\RepositoryProvider\ProviderRegistry;
use RAN\RepositoryProvider\RepositoryProvider;
use RAN\RepositoryProvider\RepositoryReference;
use RAN\RepositoryProvider\RepositoryReleaseMetadata;
use RAN\Secrets\SecretsFile;
use RAN\Storage\PluginNotFound;
use RAN\Storage\PluginRepository;
use RAN\Storage\ThemeNotFound;
use RAN\Storage\ThemeRepository;
use RAN\WordPress\ManagedReleaseConfiguration;
use RAN\WordPress\ManagedReleaseStore;
use RAN\WordPress\ManagedReleaseTargetRegistrar;
use RAN\WordPress\WordPressUpdaterLock;

final class ManagedReleaseRuntimeTest extends TestCase {

	private const NATIVE_PLUGIN = 'example/example.php';
	private const NATIVE_EXTRA  = array(
		'plugin' => self::NATIVE_PLUGIN,
		'action' => 'update',
		'type'   => 'plugin',
	);

	protected function setUp(): void {
		$GLOBALS['ran_booster_runtime_actions'] = array();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['ran_booster_runtime_action'], $GLOBALS['ran_booster_runtime_actions'], $GLOBALS['ran_booster_wp_pusher_active_plugins'] );
	}

	public function testConfigurationUsesCanonicalStableJson(): void {
		$configuration = new ManagedReleaseConfiguration( 'example', 'example.php' );
		$json          = '{"channel":"stable","package_root":"example","metadata_file":"example.php"}';

		self::assertSame( $json, $configuration->toJson() );
		self::assertSame( $configuration->toArray(), ManagedReleaseConfiguration::fromJson( $json )->toArray() );
		self::assertSame( 'stable', $configuration->channel() );

		$this->expectException( InvalidArgumentException::class );
		ManagedReleaseConfiguration::fromJson( str_replace( '{"channel"', '{ "channel"', $json ) );
	}

	public function testConfigurationPersistsCanonicalPrereleaseChannel(): void {
		$configuration = new ManagedReleaseConfiguration(
			'example',
			'example.php',
			'prerelease'
		);
		$json          = '{"channel":"prerelease","package_root":"example","metadata_file":"example.php"}';

		self::assertSame( $json, $configuration->toJson() );
		self::assertSame( 'prerelease', ManagedReleaseConfiguration::fromJson( $json )->channel() );
	}

	public function testConfigurationRejectsRemovedArtifactAuthority(): void {
		foreach (
			array(
				'{"channel":"stable","package_root":"example","metadata_file":"example.php","asset_prefix":"example"}',
				'{"channel":"stable","package_root":"example","metadata_file":"example.php","manifest_public_keys":{"current":"unused"}}',
			) as $json
		) {
			try {
				ManagedReleaseConfiguration::fromJson( $json );
				self::fail( 'Removed artifact authority must not remain readable.' );
			} catch ( InvalidArgumentException ) {
				self::assertTrue( true );
			}
		}
	}

	public function testEligibilityUsesTheSelectedProvidersReleaseMetadataFacet(): void {
		$package = $this->package(
			'plugin',
			'example/example.php',
			'example',
			DeploymentPolicy::MANUAL,
			provider: 'vendor-fixture',
			source: PackageSource::BRANCH
		);
		$plugins = $this->createStub( PluginRepository::class );
		$plugins->method( 'boosterPluginFromFile' )->willReturn( $package );
		$themes    = $this->createStub( ThemeRepository::class );
		$store     = new RuntimeReleaseStore();
		$registrar = new ManagedReleaseTargetRegistrar(
			$plugins,
			$themes,
			$this->createStub( SecretsFile::class ),
			$store,
			new RuntimeUpdaterLock()
		);
		$facade    = new NativeReleaseTrackingFacade(
			$plugins,
			$themes,
			$store,
			$registrar,
			new RuntimeUpdaterLock(),
			$this->releaseMetadataRegistry( 'vendor-fixture', 'https://vendor.example/' ),
			metadataEligible: static fn (): bool => true
		);

		$status = $facade->status( 'plugin', 'example/example.php' );

		self::assertTrue( $status->eligible() );
		self::assertSame( 'https://vendor.example/owner/example', $status->eligibility()->expectedUpdateUri() );

		$unsupported = new NativeReleaseTrackingFacade(
			$plugins,
			$themes,
			$store,
			$registrar,
			new RuntimeUpdaterLock(),
			new ProviderRegistry(),
			metadataEligible: static fn (): bool => true
		);
		self::assertSame(
			ReleaseTrackingEligibility::UNSUPPORTED_PROVIDER,
			$unsupported->status( 'plugin', 'example/example.php' )->eligibility()->code()
		);
	}

	public function testConfigurationRejectsTraversalAndInvalidChannel(): void {
		foreach (
			array(
				static fn () => new ManagedReleaseConfiguration( '../example', 'example.php' ),
				static fn () => new ManagedReleaseConfiguration( 'example', '../example.php' ),
				static fn () => new ManagedReleaseConfiguration( 'example', 'example.php', 'preview' ),
			) as $invalid
		) {
			try {
				$invalid();
				self::fail( 'Invalid release configuration must fail closed.' );
			} catch ( InvalidArgumentException ) {
				self::assertTrue( true );
			}
		}
	}

	public function testRegistrarRegistersPluginAndThemeWithLazyCredentialsAndMappedPolicies(): void {
		$plugin   = $this->package(
			'plugin',
			'installed-example/example.php',
			'installed-example',
			DeploymentPolicy::MANUAL,
			true,
			'profile_1'
		);
		$disabled = $this->package(
			'plugin',
			'disabled/disabled.php',
			'disabled',
			DeploymentPolicy::DISABLED
		);
		$theme    = $this->package(
			'theme',
			'example-theme',
			'example-theme',
			DeploymentPolicy::AUTOMATIC
		);
		$store    = new RuntimeReleaseStore(
			array(
				"plugin\0installed-example/example.php" => new ManagedReleaseConfiguration( 'canonical-example', 'example.php' ),
				"plugin\0disabled/disabled.php"         => new ManagedReleaseConfiguration( 'disabled', 'disabled.php' ),
				"theme\0example-theme"                  => new ManagedReleaseConfiguration(
					'example-theme',
					'style.css',
					'prerelease'
				),
			)
		);
		$plugins  = $this->createMock( PluginRepository::class );
		$plugins->expects( self::once() )
			->method( 'allDeploymentPlugins' )
			->with( PackageSource::RELEASE_ASSET )
			->willReturn(
				array(
					'installed-example/example.php' => $plugin,
					'disabled/disabled.php'         => $disabled,
				)
			);
		$themes = $this->createMock( ThemeRepository::class );
		$themes->expects( self::once() )
			->method( 'allDeploymentThemes' )
			->with( PackageSource::RELEASE_ASSET )
			->willReturn( array( 'example-theme' => $theme ) );
		$credentialReads = 0;
		$secrets         = $this->createStub( SecretsFile::class );
		$secrets->method( 'credentialMaterial' )->willReturnCallback(
			static function () use ( &$credentialReads ): array {
				++$credentialReads;

				return array( 'secret' => 'github_pat_test_' . $credentialReads );
			}
		);
		$targets       = array();
		$factory       = static function ( mixed ...$options ) use ( &$targets ): object {
			$targets[] = $options;

			return new RuntimeUpdaterFacade();
		};
			$registrar = new ManagedReleaseTargetRegistrar( $plugins, $themes, $secrets, $store, new RuntimeUpdaterLock(), $factory );

			$registrar->register();

			self::assertCount( 3, $targets );
			self::assertSame( 0, $credentialReads, 'Registration must not read secret material.' );
			self::assertSame( 'plugin', $targets[0]['targetType'] );
			self::assertSame(
				rtrim( WP_PLUGIN_DIR, '/\\' ) . '/installed-example/example.php',
				$targets[0]['pluginFile']
			);
			self::assertSame( 'canonical-example', $targets[0]['pluginSlug'] );
			self::assertSame( 'manual', $targets[0]['autoUpdatePolicy'] );
			self::assertSame( 'stable', $targets[0]['channel'] );
			self::assertSame( 'disabled', $targets[1]['autoUpdatePolicy'] );
			self::assertSame( 'theme', $targets[2]['targetType'] );
			self::assertSame( 'example-theme', $targets[2]['stylesheet'] );
			self::assertSame( 'automatic', $targets[2]['autoUpdatePolicy'] );
			self::assertSame( 'prerelease', $targets[2]['channel'] );
			self::assertArrayNotHasKey( 'nativeUpdateObserver', $targets[0] );
			self::assertArrayNotHasKey( 'nativeUpdateObserver', $targets[2] );
			self::assertIsCallable( $targets[0]['accessToken'] );
			self::assertSame( 'github_pat_test_1', $targets[0]['accessToken']() );
			self::assertSame( 'github_pat_test_2', $targets[0]['accessToken']() );
			self::assertSame( 2, $credentialReads, 'Every HTTP request may observe newly resolved credential material.' );
			self::assertInstanceOf( RuntimeUpdaterFacade::class, $registrar->facade( 'plugin', 'installed-example/example.php' ) );
			self::assertInstanceOf( RuntimeUpdaterFacade::class, $registrar->facade( 'theme', 'example-theme' ) );
	}

	public function testRegistrarContinuesWithThemesWhenPluginRepositoryReadFails(): void {
		$theme   = $this->package( 'theme', 'example-theme', 'example-theme', DeploymentPolicy::MANUAL );
		$plugins = $this->createStub( PluginRepository::class );
		$plugins->method( 'allDeploymentPlugins' )->willThrowException( new \RuntimeException( 'read failed' ) );
		$themes = $this->createStub( ThemeRepository::class );
		$themes->method( 'allDeploymentThemes' )->willReturn( array( 'example-theme' => $theme ) );
		$store     = new RuntimeReleaseStore(
			array(
				"theme\0example-theme" => new ManagedReleaseConfiguration( 'example-theme', 'style.css' ),
			)
		);
		$registrar = new ManagedReleaseTargetRegistrar(
			$plugins,
			$themes,
			$this->createStub( SecretsFile::class ),
			$store,
			new RuntimeUpdaterLock(),
			static fn ( mixed ...$options ): object => new RuntimeUpdaterFacade( $options )
		);

		$registrar->register();

		self::assertNotNull( $registrar->facade( 'theme', 'example-theme' ) );
		self::assertSame( 'repository_read_failed', $registrar->failureCode( 'plugin', 'unavailable/unavailable.php' ) );
	}

	public function testRegistrarIsolatesAnInvalidTarget(): void {
		$valid   = $this->package( 'plugin', 'valid/valid.php', 'valid', DeploymentPolicy::MANUAL );
		$invalid = $this->package( 'plugin', 'invalid/invalid.php', 'invalid', DeploymentPolicy::MANUAL, provider: 'bb' );
		$plugins = $this->createStub( PluginRepository::class );
		$plugins->method( 'allDeploymentPlugins' )->willReturn(
			array(
				'valid/valid.php'     => $valid,
				'invalid/invalid.php' => $invalid,
			)
		);
		$themes = $this->createStub( ThemeRepository::class );
		$themes->method( 'allDeploymentThemes' )->willReturn( array() );
		$store     = new RuntimeReleaseStore(
			array(
				"plugin\0valid/valid.php"     => new ManagedReleaseConfiguration( 'valid', 'valid.php' ),
				"plugin\0invalid/invalid.php" => new ManagedReleaseConfiguration( 'invalid', 'invalid.php' ),
			)
		);
		$registrar = new ManagedReleaseTargetRegistrar(
			$plugins,
			$themes,
			$this->createStub( SecretsFile::class ),
			$store,
			new RuntimeUpdaterLock(),
			static fn ( mixed ...$options ): object => new RuntimeUpdaterFacade( $options )
		);

		$registrar->register();

		self::assertNotNull( $registrar->facade( 'plugin', 'valid/valid.php' ) );
		self::assertNull( $registrar->facade( 'plugin', 'invalid/invalid.php' ) );
		self::assertSame( 'target_registration_failed', $registrar->failureCode( 'plugin', 'invalid/invalid.php' ) );
	}

	public function testManualNativeUpdateHoldsExistingLockThroughCompletion(): void {
		[ $registrar, $lock ] = $this->nativePluginRegistrar(
			$this->package( 'plugin', self::NATIVE_PLUGIN, 'example', DeploymentPolicy::MANUAL )
		);
		$upgrader             = new \stdClass();

		self::assertFalse( $registrar->authorizeNativeDownload( false, 'package.zip', $upgrader, self::NATIVE_EXTRA ) );
		self::assertFalse( $registrar->fenceNativeMutation( false, self::NATIVE_EXTRA ) );
		self::assertSame( 1, $lock->acquires );

		$registrar->completeNativeMutation( $upgrader, self::NATIVE_EXTRA );

		self::assertSame( array( 'runtime-lock' ), $lock->releases );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function testFailedManualNativeUpdateHoldsLockUntilAfterShutdownRestoration(): void {
		[ $registrar, $lock ] = $this->nativePluginRegistrar(
			$this->package( 'plugin', self::NATIVE_PLUGIN, 'example', DeploymentPolicy::MANUAL )
		);
		$extra                = array_merge(
			self::NATIVE_EXTRA,
			array(
				'temp_backup' => array( 'slug' => 'example' ),
			)
		);
		$upgrader             = (object) array(
			'result' => array(),
			'skin'   => (object) array(
				'result' => new \WP_Error( 'install_failed', 'failed' ),
			),
		);

		self::assertFalse( $registrar->authorizeNativeDownload( false, 'package.zip', $upgrader, $extra ) );
		self::assertFalse( $registrar->fenceNativeMutation( false, $extra ) );
		$registrar->completeNativeMutation( $upgrader, $extra );

		self::assertSame( array(), $lock->releases );
		$shutdown = array_values(
			array_filter(
				$GLOBALS['ran_booster_runtime_actions'],
				static fn ( array $action ): bool => 'shutdown' === ( $action['hook'] ?? null )
			)
		);
		self::assertCount( 1, $shutdown );
		$shutdown = $shutdown[0];
		self::assertSame( PHP_INT_MAX, $shutdown['priority'] );
		$shutdown['callback']();
		self::assertSame( array( 'runtime-lock' ), $lock->releases );
	}

	public function testNativeUpdateRejectsAuthorityChangeAndReleasesManualLock(): void {
		$first                = $this->package( 'plugin', 'example/example.php', 'example', DeploymentPolicy::MANUAL );
		$changed              = $this->package( 'plugin', 'example/example.php', 'example', DeploymentPolicy::MANUAL, sourceRevision: 2 );
		$reads                = 0;
		[ $registrar, $lock ] = $this->nativePluginRegistrar(
			$first,
			static function () use ( &$reads, $first, $changed ): Package {
				return 0 === $reads++ ? $first : $changed;
			}
		);
		$upgrader             = new \stdClass();

		self::assertFalse( $registrar->authorizeNativeDownload( false, 'package.zip', $upgrader, self::NATIVE_EXTRA ) );
		$result = $registrar->fenceNativeMutation( false, self::NATIVE_EXTRA );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'ran_booster_native_update_authority_changed', $result->get_error_code() );
		self::assertSame( array( 'runtime-lock' ), $lock->releases );
	}

	public function testNativeUpdateRejectsAuthorityChangedAfterRegistration(): void {
		$registered           = $this->package( 'plugin', 'example/example.php', 'example', DeploymentPolicy::MANUAL );
		$changed              = $this->package( 'plugin', 'example/example.php', 'example', DeploymentPolicy::MANUAL, sourceRevision: 2 );
		[ $registrar, $lock ] = $this->nativePluginRegistrar( $registered, $changed );

		$result = $registrar->authorizeNativeDownload(
			false,
			'package.zip',
			new \stdClass(),
			self::NATIVE_EXTRA
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'ran_booster_native_update_authority_changed', $result->get_error_code() );
		self::assertSame( 0, $lock->acquires );
	}

	public function testStaleManagedOffersFailClosedWhenTargetRegistrationFails(): void {
		$plugin  = $this->package( 'plugin', self::NATIVE_PLUGIN, 'example', DeploymentPolicy::MANUAL );
		$theme   = $this->package( 'theme', 'example-theme', 'example-theme', DeploymentPolicy::MANUAL );
		$plugins = $this->createStub( PluginRepository::class );
		$plugins->method( 'allDeploymentPlugins' )->willReturn( array( self::NATIVE_PLUGIN => $plugin ) );
		$plugins->method( 'boosterPluginFromFile' )->willReturn( $plugin );
		$themes = $this->createStub( ThemeRepository::class );
		$themes->method( 'allDeploymentThemes' )->willReturn( array( 'example-theme' => $theme ) );
		$themes->method( 'boosterThemeFromStylesheet' )->willReturn( $theme );
		$registrar = new ManagedReleaseTargetRegistrar(
			$plugins,
			$themes,
			$this->createStub( SecretsFile::class ),
			new RuntimeReleaseStore(
				array(
					"plugin\0" . self::NATIVE_PLUGIN => new ManagedReleaseConfiguration( 'example', 'example.php' ),
					"theme\0example-theme"           => new ManagedReleaseConfiguration( 'example-theme', 'style.css' ),
				)
			),
			new RuntimeUpdaterLock(),
			static function (): never {
				throw new \RuntimeException( 'target rejected' );
			}
		);
		$registrar->register();
		$incoming = new \WP_Error( 'download_failed', 'Download already failed.' );
		self::assertSame(
			$incoming,
			$registrar->authorizeNativeDownload( $incoming, 'package.zip', new \stdClass(), self::NATIVE_EXTRA )
		);

		foreach (
			array(
				self::NATIVE_EXTRA,
				array(
					'theme'  => 'example-theme',
					'action' => 'update',
					'type'   => 'theme',
				),
			) as $extra
		) {
			$this->assertNativeAuthorityError(
				$registrar->authorizeNativeDownload( false, 'package.zip', new \stdClass(), $extra )
			);
			$this->assertNativeAuthorityError( $registrar->fenceNativeMutation( false, $extra ) );
		}
	}

	public function testStaleOfferFailsClosedAfterPackageReturnsToBranch(): void {
		$release       = $this->package( 'plugin', self::NATIVE_PLUGIN, 'example', DeploymentPolicy::MANUAL );
		$branch        = $this->package(
			'plugin',
			self::NATIVE_PLUGIN,
			'example',
			DeploymentPolicy::MANUAL,
			source: PackageSource::BRANCH
		);
		[ $registrar ] = $this->nativePluginRegistrar( $release, $branch );

		$this->assertNativeAuthorityError(
			$registrar->authorizeNativeDownload( false, 'package.zip', new \stdClass(), self::NATIVE_EXTRA )
		);
		$this->assertNativeAuthorityError( $registrar->fenceNativeMutation( false, self::NATIVE_EXTRA ) );
	}

	public function testUnmanagedPluginAndThemeOffersRemainWordPressOwned(): void {
		$plugins = $this->createStub( PluginRepository::class );
		$plugins->method( 'allDeploymentPlugins' )->willReturn( array() );
		$plugins->method( 'boosterPluginFromFile' )->willThrowException( new PluginNotFound() );
		$themes = $this->createStub( ThemeRepository::class );
		$themes->method( 'allDeploymentThemes' )->willReturn( array() );
		$themes->method( 'boosterThemeFromStylesheet' )->willThrowException( new ThemeNotFound() );
		$registrar = new ManagedReleaseTargetRegistrar(
			$plugins,
			$themes,
			$this->createStub( SecretsFile::class ),
			new RuntimeReleaseStore(),
			new RuntimeUpdaterLock()
		);
		$registrar->register();

		foreach (
			array(
				self::NATIVE_EXTRA,
				array(
					'theme'  => 'example-theme',
					'action' => 'update',
					'type'   => 'theme',
				),
			) as $extra
		) {
			self::assertFalse( $registrar->authorizeNativeDownload( false, 'package.zip', new \stdClass(), $extra ) );
			self::assertFalse( $registrar->fenceNativeMutation( false, $extra ) );
		}
	}

	public function testManagedOfferFailsClosedWhenRepositoryReadIsUncertain(): void {
		$plugins = $this->createStub( PluginRepository::class );
		$plugins->method( 'allDeploymentPlugins' )->willReturn( array() );
		$plugins->method( 'boosterPluginFromFile' )->willThrowException( new \RuntimeException( 'read failed' ) );
		$registrar = new ManagedReleaseTargetRegistrar(
			$plugins,
			$this->createStub( ThemeRepository::class ),
			$this->createStub( SecretsFile::class ),
			new RuntimeReleaseStore(),
			new RuntimeUpdaterLock()
		);
		$registrar->register();

		$this->assertNativeAuthorityError(
			$registrar->authorizeNativeDownload( false, 'package.zip', new \stdClass(), self::NATIVE_EXTRA )
		);
		$this->assertNativeAuthorityError( $registrar->fenceNativeMutation( false, self::NATIVE_EXTRA ) );
	}

	public function testInactiveOrThrowingTargetDiagnosticsFenceNativeUpdate(): void {
		$package                  = $this->package( 'plugin', self::NATIVE_PLUGIN, 'example', DeploymentPolicy::MANUAL );
		[ $registrar, , $facade ] = $this->nativePluginRegistrar( $package );
		$facade->replaceDiagnostics( array( 'state' => 'inactive' ) );

		$this->assertNativeAuthorityError(
			$registrar->authorizeNativeDownload( false, 'package.zip', new \stdClass(), self::NATIVE_EXTRA )
		);

		[ $registrar, , $facade ] = $this->nativePluginRegistrar( $package );
		self::assertFalse(
			$registrar->authorizeNativeDownload( false, 'package.zip', new \stdClass(), self::NATIVE_EXTRA )
		);
		$facade->replaceDiagnostics( array( 'state' => 'inactive' ) );
		$this->assertNativeAuthorityError( $registrar->fenceNativeMutation( false, self::NATIVE_EXTRA ) );

		[ $registrar, , $facade ] = $this->nativePluginRegistrar( $package );
		$facade->failDiagnostics();
		$this->assertNativeAuthorityError(
			$registrar->authorizeNativeDownload( false, 'package.zip', new \stdClass(), self::NATIVE_EXTRA )
		);

		[ $registrar, , $facade ] = $this->nativePluginRegistrar( $package );
		self::assertFalse(
			$registrar->authorizeNativeDownload( false, 'package.zip', new \stdClass(), self::NATIVE_EXTRA )
		);
		$facade->failDiagnostics();
		$this->assertNativeAuthorityError( $registrar->fenceNativeMutation( false, self::NATIVE_EXTRA ) );
	}

	public function testSelectedTargetWithUnavailableReleaseStateStillOwnsItsOffer(): void {
		$package                  = $this->package( 'plugin', self::NATIVE_PLUGIN, 'example', DeploymentPolicy::MANUAL );
		[ $registrar, , $facade ] = $this->nativePluginRegistrar( $package );
		$facade->replaceDiagnostics( array( 'state' => 'unavailable' ) );

		self::assertFalse(
			$registrar->authorizeNativeDownload( false, 'package.zip', new \stdClass(), self::NATIVE_EXTRA )
		);
	}

	public function testNativeUpdateRechecksWpPusherConflictBeforeDownloadAndMutation(): void {
		$package              = $this->package( 'plugin', self::NATIVE_PLUGIN, 'example', DeploymentPolicy::MANUAL );
		[ $registrar, $lock ] = $this->nativePluginRegistrar( $package );
		$GLOBALS['ran_booster_wp_pusher_active_plugins'] = array( 'wppusher/wppusher.php' );

		$blocked = $registrar->authorizeNativeDownload( false, 'package.zip', new \stdClass(), self::NATIVE_EXTRA );
		self::assertInstanceOf( \WP_Error::class, $blocked );
		self::assertSame( 'ran_booster_native_update_authority_changed', $blocked->get_error_code() );
		self::assertSame( 0, $lock->acquires );

		unset( $GLOBALS['ran_booster_wp_pusher_active_plugins'] );
		[ $registrar, $lock ] = $this->nativePluginRegistrar( $package );
		self::assertFalse( $registrar->authorizeNativeDownload( false, 'package.zip', new \stdClass(), self::NATIVE_EXTRA ) );
		$GLOBALS['ran_booster_wp_pusher_active_plugins'] = array( 'wppusher/wppusher.php' );

		$blocked = $registrar->fenceNativeMutation( false, self::NATIVE_EXTRA );
		self::assertInstanceOf( \WP_Error::class, $blocked );
		self::assertSame( 'ran_booster_native_update_authority_changed', $blocked->get_error_code() );
		self::assertSame( 0, $lock->acquires );
		self::assertSame( array(), $lock->releases );
	}

	public function testAutomaticNativeUpdateRequiresSupportedActionAndFreshOuterLock(): void {
		$package              = $this->package( 'plugin', 'example/example.php', 'example', DeploymentPolicy::AUTOMATIC );
		[ $registrar, $lock ] = $this->nativePluginRegistrar( $package );
		$upgrader             = (object) array( 'skin' => new \Automatic_Upgrader_Skin() );

		$unsupported = $registrar->authorizeNativeDownload( false, 'package.zip', $upgrader, self::NATIVE_EXTRA );
		self::assertInstanceOf( \WP_Error::class, $unsupported );
		self::assertSame( 'ran_booster_native_update_unsupported_context', $unsupported->get_error_code() );

		$GLOBALS['ran_booster_runtime_action'] = 'wp_maybe_auto_update';
		self::assertFalse( $registrar->authorizeNativeDownload( false, 'package.zip', $upgrader, self::NATIVE_EXTRA ) );
		self::assertFalse( $registrar->fenceNativeMutation( false, self::NATIVE_EXTRA ) );
		self::assertSame( 2, $lock->tokenReads );
		self::assertSame( 0, $lock->acquires );
	}

	public function testAjaxUpdaterSkinIsNotClassifiedAsAnAutomaticUpdate(): void {
		[ $registrar, $lock ] = $this->nativePluginRegistrar(
			$this->package( 'plugin', self::NATIVE_PLUGIN, 'example', DeploymentPolicy::MANUAL )
		);
		$upgrader             = (object) array( 'skin' => new \WP_Ajax_Upgrader_Skin() );

		self::assertFalse( $registrar->authorizeNativeDownload( false, 'package.zip', $upgrader, self::NATIVE_EXTRA ) );
		self::assertFalse( $registrar->fenceNativeMutation( false, self::NATIVE_EXTRA ) );
		self::assertSame( 1, $lock->acquires );
	}

	public function testManagedNativeMultiTargetAndMalformedBulkUpdatesFailClosed(): void {
		$package       = $this->package( 'plugin', 'example/example.php', 'example', DeploymentPolicy::MANUAL );
		[ $registrar ] = $this->nativePluginRegistrar( $package );

		foreach ( array( array( 2, 1 ), array( '1', 1 ), array( 1, null ) ) as $state ) {
			$result = $registrar->authorizeNativeDownload(
				false,
				'package.zip',
				(object) array(
					'bulk'           => true,
					'update_count'   => $state[0],
					'update_current' => $state[1],
				),
				array( 'plugin' => self::NATIVE_PLUGIN )
			);

			self::assertInstanceOf( \WP_Error::class, $result );
			self::assertSame( 'ran_booster_native_update_unsupported_context', $result->get_error_code() );
		}
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function testOneTargetWordPressBulkUpdateUsesManualFenceAndDefersFailedRestoreRelease(): void {
		$package              = $this->package( 'plugin', 'example/example.php', 'example', DeploymentPolicy::MANUAL );
		[ $registrar, $lock ] = $this->nativePluginRegistrar( $package );
		$upgrader             = (object) array(
			'bulk'           => true,
			'update_count'   => 1,
			'update_current' => 1,
			'result'         => array(),
			'skin'           => (object) array(
				'result' => new \WP_Error( 'install_failed', 'failed' ),
			),
		);
		$itemExtra            = array(
			'plugin'      => self::NATIVE_PLUGIN,
			'temp_backup' => array( 'slug' => 'example' ),
		);

		self::assertFalse( $registrar->authorizeNativeDownload( false, 'package.zip', $upgrader, $itemExtra ) );
		self::assertFalse( $registrar->fenceNativeMutation( false, $itemExtra ) );
		self::assertSame( 1, $lock->acquires );

		$registrar->completeNativeMutation(
			$upgrader,
			array(
				'action'  => 'update',
				'type'    => 'plugin',
				'bulk'    => true,
				'plugins' => array( self::NATIVE_PLUGIN ),
			)
		);

		self::assertSame( array(), $lock->releases );
		$shutdown = array_values(
			array_filter(
				$GLOBALS['ran_booster_runtime_actions'],
				static fn ( array $action ): bool => 'shutdown' === ( $action['hook'] ?? null )
			)
		);
		self::assertCount( 1, $shutdown );
		self::assertSame( PHP_INT_MAX, $shutdown[0]['priority'] );
		$shutdown[0]['callback']();
		self::assertSame( array( 'runtime-lock' ), $lock->releases );
	}

	public function testFacadeEnablesWithExactNonceRevisionAndPreservesPolicyThroughStore(): void {
		$package = $this->package(
			'plugin',
			'example/example.php',
			'example',
			DeploymentPolicy::MANUAL,
			source: PackageSource::BRANCH
		);
		$plugins = $this->createStub( PluginRepository::class );
		$plugins->method( 'boosterPluginFromFile' )->willReturn( $package );
		$themes    = $this->createStub( ThemeRepository::class );
		$store     = new RuntimeReleaseStore();
		$registrar = new ManagedReleaseTargetRegistrar(
			$plugins,
			$themes,
			$this->createStub( SecretsFile::class ),
			$store,
			new RuntimeUpdaterLock(),
			static fn (): object => new RuntimeUpdaterFacade()
		);

		$expectedAction = 'ran-booster-release-tracking-enable-plugin-example/example.php-1';

		$preflightRoot    = null;
		$preflightHeader  = null;
		$preflightChannel = null;

		$lock   = new RuntimeUpdaterLock();
		$facade = new NativeReleaseTrackingFacade(
			$plugins,
			$themes,
			$store,
			$registrar,
			$lock,
			$this->releaseMetadataRegistry(),
			static fn ( string $type ): bool => 'plugin' === $type,
			static fn ( string $nonce, string $action ): bool => 'valid' === $nonce && $expectedAction === $action,
			metadataEligible: static fn (): bool => true,
			invalidateNative: static function (): void {
			},
			releasePreflight: static function (
				string $type,
				Package $preflightPackage,
				string $packageRoot,
				string $headerFile,
				bool $force,
				string $channel
			) use (
				&$preflightRoot,
				&$preflightHeader,
				&$preflightChannel
			): \RAN\AddOn\ReleaseTracking\ReleaseTrackingPreflight {
				$preflightRoot    = $packageRoot;
				$preflightHeader  = $headerFile;
				$preflightChannel = $channel;
				unset( $type, $preflightPackage, $force );

				return new \RAN\AddOn\ReleaseTracking\ReleaseTrackingPreflight(
					\RAN\AddOn\ReleaseTracking\ReleaseTrackingPreflight::READY,
					$packageRoot,
					'2.0.0'
				);
			}
		);

		self::assertSame(
			$expectedAction,
			$facade->nonceAction( 'enable', 'plugin', 'example/example.php', 1 )
		);
		$result = $facade->enable( 'plugin', 'example/example.php', 1, 'prerelease', 'valid' );

		self::assertTrue( $result->successful() );
		self::assertSame( 'release_enabled', $result->code() );
		self::assertCount( 1, $store->transitions );
		self::assertSame( PackageSource::BRANCH, $store->transitions[0]['expected_source'] );
		self::assertSame( PackageSource::RELEASE_ASSET, $store->transitions[0]['new_source'] );
		self::assertSame( 'example', $store->transitions[0]['configuration']->packageRoot() );
		self::assertSame( 'example.php', $store->transitions[0]['configuration']->metadataFile() );
		self::assertSame( 'prerelease', $store->transitions[0]['configuration']->channel() );
		self::assertSame( 'example', $preflightRoot );
		self::assertSame( 'example.php', $preflightHeader );
		self::assertSame( 'prerelease', $preflightChannel );
		self::assertSame( 1, $lock->acquires );
		self::assertSame( array( 'runtime-lock' ), $lock->releases );

		$denied = $facade->enable( 'plugin', 'example/example.php', 1, 'prerelease', 'wrong' );
		self::assertFalse( $denied->successful() );
		self::assertSame( 'forbidden', $denied->code() );
		self::assertCount( 1, $store->transitions );

		$lock->acquireFails = true;
		$contended          = $facade->enable( 'plugin', 'example/example.php', 1, 'prerelease', 'valid' );
		self::assertFalse( $contended->successful() );
		self::assertSame( 'release_unavailable', $contended->code() );
		self::assertCount( 1, $store->transitions );
		self::assertSame( 2, $lock->acquires );
		self::assertSame( array( 'runtime-lock' ), $lock->releases );
	}

	public function testReadOnlyPreflightBindsTargetRevisionAndChannelWithoutMutation(): void {
		$plugin  = $this->package(
			'plugin',
			'example/example.php',
			'example',
			DeploymentPolicy::MANUAL,
			source: PackageSource::BRANCH
		);
		$theme   = $this->package(
			'theme',
			'example-theme',
			'example-theme',
			DeploymentPolicy::MANUAL,
			source: PackageSource::BRANCH
		);
		$plugins = $this->createStub( PluginRepository::class );
		$plugins->method( 'boosterPluginFromFile' )->willReturn( $plugin );
		$themes = $this->createStub( ThemeRepository::class );
		$themes->method( 'boosterThemeFromStylesheet' )->willReturn( $theme );
		$store         = new RuntimeReleaseStore();
		$lock          = new RuntimeUpdaterLock();
		$preflights    = array();
		$invalidations = array();
		$allowed       = true;
		$facade        = new NativeReleaseTrackingFacade(
			$plugins,
			$themes,
			$store,
			new ManagedReleaseTargetRegistrar(
				$plugins,
				$themes,
				$this->createStub( SecretsFile::class ),
				$store,
				$lock
			),
			$lock,
			$this->releaseMetadataRegistry(),
			static function () use ( &$allowed ): bool {
				return $allowed;
			},
			static fn ( string $nonce, string $action ): bool => hash_equals( $action, $nonce ),
			metadataEligible: static fn (): bool => true,
			invalidateNative: static function ( string $type ) use ( &$invalidations ): void {
				$invalidations[] = $type;
			},
			releasePreflight: static function (
				string $type,
				Package $package,
				string $packageRoot,
				string $headerFile,
				bool $force,
				string $channel
			) use ( &$preflights ): \RAN\AddOn\ReleaseTracking\ReleaseTrackingPreflight {
				unset( $package );
				$preflights[] = compact( 'type', 'packageRoot', 'headerFile', 'force', 'channel' );

				return new \RAN\AddOn\ReleaseTracking\ReleaseTrackingPreflight(
					\RAN\AddOn\ReleaseTracking\ReleaseTrackingPreflight::READY,
					$packageRoot,
					'2.0.0'
				);
			}
		);

		$pluginNonce = $facade->nonceAction( 'preflight', 'plugin', 'example/example.php', 1, 'prerelease' );
		$themeNonce  = $facade->nonceAction( 'preflight', 'theme', 'example-theme', 1, 'stable' );
		self::assertSame( 'ran-booster-release-tracking-preflight-plugin-example/example.php-1-prerelease', $pluginNonce );
		self::assertTrue( $facade->preflight( 'plugin', 'example/example.php', 1, 'prerelease', $pluginNonce )?->ready() );
		self::assertTrue( $facade->preflight( 'theme', 'example-theme', 1, 'stable', $themeNonce )?->ready() );

		$staleNonce = $facade->nonceAction( 'preflight', 'plugin', 'example/example.php', 2, 'stable' );
		self::assertNull( $facade->preflight( 'plugin', 'example/example.php', 2, 'stable', $staleNonce ) );
		self::assertNull( $facade->preflight( 'plugin', 'example/example.php', 1, 'stable', $pluginNonce ) );
		self::assertNull( $facade->preflight( 'plugin', 'example/example.php', 1, 'preview', 'nonce' ) );
		$allowed = false;
		self::assertNull( $facade->preflight( 'plugin', 'example/example.php', 1, 'prerelease', $pluginNonce ) );

		self::assertSame(
			array(
				array(
					'type'        => 'plugin',
					'packageRoot' => 'example',
					'headerFile'  => 'example.php',
					'force'       => true,
					'channel'     => 'prerelease',
				),
				array(
					'type'        => 'theme',
					'packageRoot' => 'example-theme',
					'headerFile'  => 'style.css',
					'force'       => true,
					'channel'     => 'stable',
				),
			),
			$preflights
		);
		self::assertSame( array(), $store->transitions );
		self::assertSame( array(), $invalidations );
		self::assertSame( 0, $lock->acquires );
	}

	public function testFacadeRefusesBranchPackageAlreadyRegisteredByTheRANUpdater(): void {
		$package = $this->package(
			'plugin',
			'example/example.php',
			'example',
			DeploymentPolicy::MANUAL,
			source: PackageSource::BRANCH
		);
		$plugins = $this->createStub( PluginRepository::class );
		$plugins->method( 'boosterPluginFromFile' )->willReturn( $package );
		$themes         = $this->createStub( ThemeRepository::class );
		$store          = new RuntimeReleaseStore();
		$registrar      = new ManagedReleaseTargetRegistrar(
			$plugins,
			$themes,
			$this->createStub( SecretsFile::class ),
			$store,
			new RuntimeUpdaterLock(),
			static fn (): object => new RuntimeUpdaterFacade()
		);
		$expectedAction = 'ran-booster-release-tracking-enable-plugin-example/example.php-1';
		$signals        = array();
		$facade         = new NativeReleaseTrackingFacade(
			$plugins,
			$themes,
			$store,
			$registrar,
			new RuntimeUpdaterLock(),
			$this->releaseMetadataRegistry(),
			static fn (): bool => true,
			static fn ( string $nonce, string $action ): bool => 'valid' === $nonce && $expectedAction === $action,
			metadataEligible: static fn (): bool => true,
			releasePreflight: static function (): \RAN\AddOn\ReleaseTracking\ReleaseTrackingPreflight {
				self::fail( 'Self-managed targets must not run release preflight.' );
			},
			hasRegisteredTarget: static function ( string $type, string $identity ) use ( &$signals ): bool {
				$signals[] = array( $type, $identity );

				return true;
			}
		);

		$status = $facade->status( 'plugin', 'example/example.php' );
		$result = $facade->enable( 'plugin', 'example/example.php', 1, 'stable', 'valid' );

		self::assertSame( ReleaseTrackingEligibility::TARGET_ALREADY_USES_RAN_UPDATER, $status->eligibility()->code() );
		self::assertFalse( $status->eligible() );
		self::assertSame( 'target_already_uses_ran_updater', $result->code() );
		self::assertFalse( $result->successful() );
		self::assertSame( array( array( 'plugin', 'example/example.php' ), array( 'plugin', 'example/example.php' ) ), $signals );
		self::assertCount( 0, $store->transitions );
	}

	public function testFailedFreshReleaseValidationLeavesSourceStateUnchanged(): void {
		$package = $this->package(
			'plugin',
			'example/example.php',
			'example',
			DeploymentPolicy::AUTOMATIC,
			source: PackageSource::BRANCH
		);
		$plugins = $this->createStub( PluginRepository::class );
		$plugins->method( 'boosterPluginFromFile' )->willReturn( $package );
		$store     = new RuntimeReleaseStore();
		$registrar = new ManagedReleaseTargetRegistrar(
			$plugins,
			$this->createStub( ThemeRepository::class ),
			$this->createStub( SecretsFile::class ),
			$store,
			new RuntimeUpdaterLock(),
			static fn (): object => new RuntimeUpdaterFacade()
		);
		$facade    = new NativeReleaseTrackingFacade(
			$plugins,
			$this->createStub( ThemeRepository::class ),
			$store,
			$registrar,
			new RuntimeUpdaterLock(),
			$this->releaseMetadataRegistry(),
			static fn (): bool => true,
			static fn (): bool => true,
			metadataEligible: static fn (): bool => true,
			releasePreflight: static fn (): \RAN\AddOn\ReleaseTracking\ReleaseTrackingPreflight => new \RAN\AddOn\ReleaseTracking\ReleaseTrackingPreflight(
				\RAN\AddOn\ReleaseTracking\ReleaseTrackingPreflight::INVALID_RELEASE_ASSETS,
				'example',
				reasonCode: 'github_updater_ambiguous_release_asset'
			)
		);

		$result = $facade->enable( 'plugin', 'example/example.php', 1, 'stable', 'valid' );

		self::assertFalse( $result->successful() );
		self::assertSame( 'github_updater_ambiguous_release_asset', $result->code() );
		self::assertCount( 0, $store->transitions );
		self::assertSame( PackageSource::BRANCH, $package->getSource() );
		self::assertSame( DeploymentPolicy::AUTOMATIC, $package->getDeploymentPolicy() );
	}

	public function testFacadeProjectsNativeDiagnosticsAndRefreshesOnlyExactReleaseRevision(): void {
		$package = $this->package(
			'theme',
			'example-theme',
			'example-theme',
			DeploymentPolicy::AUTOMATIC
		);
		$plugins = $this->createStub( PluginRepository::class );
		$plugins->method( 'allDeploymentPlugins' )->willReturn( array() );
		$themes = $this->createStub( ThemeRepository::class );
		$themes->method( 'allDeploymentThemes' )->willReturn( array( 'example-theme' => $package ) );
		$themes->method( 'boosterThemeFromStylesheet' )->willReturn( $package );
		$store     = new RuntimeReleaseStore(
			array(
				"theme\0example-theme" => new ManagedReleaseConfiguration( 'example-theme', 'style.css' ),
			)
		);
		$registrar = new ManagedReleaseTargetRegistrar(
			$plugins,
			$themes,
			$this->createStub( SecretsFile::class ),
			$store,
			new RuntimeUpdaterLock(),
			static fn ( mixed ...$options ): object => new RuntimeUpdaterFacade(
				diagnostics: array(
					'state'                => 'ready',
					'code'                 => 'release_available',
					'offered_version'      => '2.0.0',
					'last_check'           => 1_700_000_000,
					'next_check'           => 1_700_003_600,
					'installed_version'    => '1.0.0',
					'version_relationship' => 'newer',
				)
			),
		);
		$registrar->register();
		$refreshes = array();
		$lock      = new RuntimeUpdaterLock();
		$facade    = new NativeReleaseTrackingFacade(
			$plugins,
			$themes,
			$store,
			$registrar,
			$lock,
			$this->releaseMetadataRegistry(),
			static fn (): bool => true,
			static fn (): bool => true,
			static function ( string $type ) use ( &$refreshes ): void {
				$refreshes[] = $type;
			},
			null,
			static fn (): bool => true
		);

		$status = $facade->status( 'theme', 'example-theme' );

		self::assertSame( '123456789', $status->providerRepositoryId() );
		self::assertSame( '2.0.0', $status->latestVersion() );
		self::assertSame( 'stable', $status->channel() );
		self::assertTrue( $status->updateAvailable() );
		self::assertTrue( $facade->refresh( 'theme', 'example-theme', 1, 'nonce' )->successful() );
		self::assertSame( array( 'theme' ), $refreshes );
		self::assertSame( 1, $registrar->facade( 'theme', 'example-theme' )?->refreshes() );
		self::assertFalse( $facade->refresh( 'theme', 'example-theme', 2, 'nonce' )->successful() );
		self::assertSame( array( 'theme' ), $refreshes );
		self::assertSame( 0, $lock->acquires );
		self::assertSame( array(), $lock->releases );
	}

	public function testRefreshReportsRemovedReleaseConfigurationWithoutRunningTheUpdater(): void {
		$package = $this->package( 'theme', 'example-theme', 'example-theme', DeploymentPolicy::MANUAL );
		$plugins = $this->createStub( PluginRepository::class );
		$themes  = $this->createStub( ThemeRepository::class );
		$themes->method( 'boosterThemeFromStylesheet' )->willReturn( $package );
		$store     = new class() extends ManagedReleaseStore {
			public function configuration( string $type, string $identifier ): ?ManagedReleaseConfiguration {
				throw new InvalidArgumentException( 'Retired release configuration.' );
			}
		};
		$registrar = new ManagedReleaseTargetRegistrar(
			$plugins,
			$themes,
			$this->createStub( SecretsFile::class ),
			$store,
			new RuntimeUpdaterLock(),
			static fn (): object => new RuntimeUpdaterFacade()
		);
		$facade    = new NativeReleaseTrackingFacade(
			$plugins,
			$themes,
			$store,
			$registrar,
			new RuntimeUpdaterLock(),
			$this->releaseMetadataRegistry(),
			static fn (): bool => true,
			static fn (): bool => true
		);

		$result = $facade->refresh( 'theme', 'example-theme', 1, 'nonce' );

		self::assertFalse( $result->successful() );
		self::assertSame( 'release_configuration_invalid', $result->code() );
	}

	public function testBranchStatusesProjectSafeLocalStateWithoutPassivePreflight(): void {
		$package = $this->package(
			'plugin',
			'branch/branch.php',
			'branch',
			DeploymentPolicy::MANUAL,
			source: PackageSource::BRANCH
		);
		$plugins = $this->createStub( PluginRepository::class );
		$plugins->method( 'boosterPluginFromFile' )->willReturn( $package );
		$themes         = $this->createStub( ThemeRepository::class );
		$store          = new RuntimeReleaseStore();
		$registrar      = new ManagedReleaseTargetRegistrar(
			$plugins,
			$themes,
			$this->createStub( SecretsFile::class ),
			$store,
			new RuntimeUpdaterLock(),
			static fn (): object => new RuntimeUpdaterFacade()
		);
		$preflightCalls = 0;
		$lock           = new RuntimeUpdaterLock();
		$facade         = new NativeReleaseTrackingFacade(
			$plugins,
			$themes,
			$store,
			$registrar,
			$lock,
			$this->releaseMetadataRegistry(),
			metadataEligible: static fn (): bool => true,
			releasePreflight: static function () use ( &$preflightCalls ): \RAN\AddOn\ReleaseTracking\ReleaseTrackingPreflight {
				++$preflightCalls;

				return new \RAN\AddOn\ReleaseTracking\ReleaseTrackingPreflight(
					\RAN\AddOn\ReleaseTracking\ReleaseTrackingPreflight::READY,
					'example',
					'2.0.0'
				);
			}
		);

		$statuses = $facade->statuses(
			'plugin',
			array( 'branch/branch.php' )
		);
		$status   = $facade->status( 'plugin', 'branch/branch.php' );

		self::assertSame( 0, $preflightCalls );
		self::assertSame( 'branch', $statuses['branch/branch.php']->source() );
		self::assertSame( 'stable', $statuses['branch/branch.php']->channel() );
		self::assertTrue( $statuses['branch/branch.php']->eligible() );
		self::assertNull( $statuses['branch/branch.php']->preflight() );
		self::assertSame( 'branch', $statuses['branch/branch.php']->packageRoot() );
		self::assertSame( '1.0.0', $statuses['branch/branch.php']->installedVersion() );
		self::assertSame( '', $statuses['branch/branch.php']->latestVersion() );
		self::assertFalse( $statuses['branch/branch.php']->updateAvailable() );
		self::assertNull( $status->preflight() );
		self::assertSame( '', $status->latestVersion() );
		self::assertSame( 0, $lock->acquires );
		self::assertSame( array(), $lock->releases );
	}

	public function testReleaseActionNoncesAreBoundToTheSourceRevision(): void {
		$facade = $this->nonceFacade();

		self::assertSame(
			'ran-booster-release-tracking-refresh-plugin-example/example.php-1',
			$facade->nonceAction( 'refresh', 'plugin', 'example/example.php', 1 )
		);
		self::assertNotSame(
			$facade->nonceAction( 'refresh', 'plugin', 'example/example.php', 1 ),
			$facade->nonceAction( 'refresh', 'plugin', 'example/example.php', 2 )
		);
		self::assertSame(
			'ran-booster-release-tracking-change_channel-plugin-example/example.php-1',
			$facade->nonceAction( 'change_channel', 'plugin', 'example/example.php', 1 )
		);
	}

	public function testReleaseManagedStatusProjectsCandidateMismatchWithoutOfferingAnUpdate(): void {
		$package = $this->package(
			'plugin',
			'example/example.php',
			'example',
			DeploymentPolicy::MANUAL
		);
		$plugins = $this->createStub( PluginRepository::class );
		$plugins->method( 'allDeploymentPlugins' )->willReturn( array( 'example/example.php' => $package ) );
		$plugins->method( 'boosterPluginFromFile' )->willReturn( $package );
		$themes = $this->createStub( ThemeRepository::class );
		$themes->method( 'allDeploymentThemes' )->willReturn( array() );
		$store     = new RuntimeReleaseStore(
			array(
				"plugin\0example/example.php" => new ManagedReleaseConfiguration( 'example', 'example.php' ),
			)
		);
		$registrar = new ManagedReleaseTargetRegistrar(
			$plugins,
			$themes,
			$this->createStub( SecretsFile::class ),
			$store,
			new RuntimeUpdaterLock(),
			static fn ( mixed ...$options ): object => new RuntimeUpdaterFacade(
				diagnostics: array(
					'state'                => 'ready',
					'code'                 => 'release_available',
					'offered_version'      => null,
					'candidate_validation' => array(
						'code'                   => 'release_version_mismatch',
						'release_tag'            => 'v2.1.0',
						'release_version'        => '2.1.0',
						'package_header_version' => '2.0.0',
					),
				)
			)
		);
		$registrar->register();
		$facade = new NativeReleaseTrackingFacade(
			$plugins,
			$themes,
			$store,
			$registrar,
			new RuntimeUpdaterLock(),
			$this->releaseMetadataRegistry(),
			static fn (): bool => true,
			static fn (): bool => true,
			null,
			null,
			static fn (): bool => true
		);

		$status = $facade->status( 'plugin', 'example/example.php' );

		self::assertSame( 'release_version_mismatch', $status->failureCode() );
		self::assertFalse( $status->updateAvailable() );
		self::assertNotNull( $status->preflight() );
		self::assertSame( 'v2.1.0', $status->preflight()?->releaseTag() );
		self::assertSame( '2.0.0', $status->preflight()?->packageHeaderVersion() );
	}

	public function testReturningToBranchClearsPackageAndNativeUpdateCaches(): void {
		$package = $this->package(
			'plugin',
			'example/example.php',
			'example',
			DeploymentPolicy::MANUAL
		);
		$plugins = $this->createStub( PluginRepository::class );
		$plugins->method( 'allDeploymentPlugins' )->willReturn( array( 'example/example.php' => $package ) );
		$plugins->method( 'boosterPluginFromFile' )->willReturn( $package );
		$themes = $this->createStub( ThemeRepository::class );
		$themes->method( 'allDeploymentThemes' )->willReturn( array() );
		$store     = new RuntimeReleaseStore(
			array(
				"plugin\0example/example.php" => new ManagedReleaseConfiguration( 'example', 'example.php' ),
			)
		);
		$registrar = new ManagedReleaseTargetRegistrar(
			$plugins,
			$themes,
			$this->createStub( SecretsFile::class ),
			$store,
			new RuntimeUpdaterLock(),
			static fn ( mixed ...$options ): object => new RuntimeUpdaterFacade( $options )
		);
		$registrar->register();
		$invalidated = array();
		$lock        = new RuntimeUpdaterLock();
		$facade      = new NativeReleaseTrackingFacade(
			$plugins,
			$themes,
			$store,
			$registrar,
			$lock,
			$this->releaseMetadataRegistry(),
			static fn (): bool => true,
			static fn (): bool => true,
			metadataEligible: static fn (): bool => true,
			invalidateNative: static function ( string $type ) use ( &$invalidated ): void {
				$invalidated[] = $type;
			}
		);

		$result = $facade->returnToBranch( 'plugin', 'example/example.php', 1, 'nonce' );

		self::assertTrue( $result->successful() );
		self::assertSame( PackageSource::BRANCH, $store->transitions[0]['new_source'] );
		self::assertSame( array( 'plugin' ), $invalidated );
		self::assertSame( 1, $registrar->facade( 'plugin', 'example/example.php' )?->refreshes() );
		self::assertSame( 1, $lock->acquires );
		self::assertSame( array( 'runtime-lock' ), $lock->releases );
	}

	public function testChangingReleaseChannelUsesSameSourceCasAndOnlyInvalidatesNativeState(): void {
		$package = $this->package(
			'plugin',
			'example/example.php',
			'example',
			DeploymentPolicy::AUTOMATIC
		);
		$plugins = $this->createStub( PluginRepository::class );
		$plugins->method( 'allDeploymentPlugins' )->willReturn( array( 'example/example.php' => $package ) );
		$plugins->method( 'boosterPluginFromFile' )->willReturn( $package );
		$themes = $this->createStub( ThemeRepository::class );
		$themes->method( 'allDeploymentThemes' )->willReturn( array() );
		$store     = new RuntimeReleaseStore(
			array(
				"plugin\0example/example.php" => new ManagedReleaseConfiguration(
					'example',
					'example.php',
					'prerelease'
				),
			)
		);
		$registrar = new ManagedReleaseTargetRegistrar(
			$plugins,
			$themes,
			$this->createStub( SecretsFile::class ),
			$store,
			new RuntimeUpdaterLock(),
			static fn ( mixed ...$options ): object => new RuntimeUpdaterFacade( $options )
		);
		$registrar->register();
		$invalidated   = array();
		$expectedNonce = 'ran-booster-release-tracking-change_channel-plugin-example/example.php-1';
		$lock          = new RuntimeUpdaterLock();
		$facade        = new NativeReleaseTrackingFacade(
			$plugins,
			$themes,
			$store,
			$registrar,
			$lock,
			$this->releaseMetadataRegistry(),
			static fn (): bool => true,
			static fn ( string $nonce, string $action ): bool =>
				'valid' === $nonce && $expectedNonce === $action,
			invalidateNative: static function ( string $type ) use ( &$invalidated ): void {
				$invalidated[] = $type;
			},
			metadataEligible: static fn (): bool => true
		);

		self::assertSame( 'prerelease', $facade->status( 'plugin', 'example/example.php' )->channel() );
		$result = $facade->changeChannel(
			'plugin',
			'example/example.php',
			1,
			'stable',
			'valid'
		);

		self::assertTrue( $result->successful() );
		self::assertSame( 'release_channel_changed', $result->code() );
		$expectedUserId = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		self::assertSame(
			array(
				array(
					'type'              => 'plugin',
					'identifier'        => 'example/example.php',
					'expected_revision' => 1,
					'channel'           => 'stable',
					'user_id'           => $expectedUserId,
				),
			),
			$store->channelChanges
		);
		self::assertSame( array( 'plugin' ), $invalidated );
		self::assertSame( 0, $registrar->facade( 'plugin', 'example/example.php' )?->refreshes() );
		self::assertSame( array(), $store->transitions );
		self::assertSame( 1, $lock->acquires );
		self::assertSame( array( 'runtime-lock' ), $lock->releases );

		$lock->releaseSucceeds = false;
		$releaseFailed         = $facade->changeChannel(
			'plugin',
			'example/example.php',
			1,
			'stable',
			'valid'
		);
		self::assertFalse( $releaseFailed->successful() );
		self::assertSame( 'release_unavailable', $releaseFailed->code() );
		self::assertCount( 2, $store->channelChanges );
		self::assertSame( 2, $lock->acquires );
		self::assertSame( array( 'runtime-lock', 'runtime-lock' ), $lock->releases );

		$invalid = $facade->changeChannel(
			'plugin',
			'example/example.php',
			1,
			'preview',
			'valid'
		);
		self::assertFalse( $invalid->successful() );
		self::assertSame( 'forbidden', $invalid->code() );
		self::assertCount( 2, $store->channelChanges );
	}

	public function testChangingToCurrentReleaseChannelIsANoopButStaleRevisionStillFails(): void {
		$package = $this->package(
			'plugin',
			'example/example.php',
			'example',
			DeploymentPolicy::MANUAL
		);
		$plugins = $this->createStub( PluginRepository::class );
		$plugins->method( 'allDeploymentPlugins' )->willReturn( array( 'example/example.php' => $package ) );
		$plugins->method( 'boosterPluginFromFile' )->willReturn( $package );
		$themes = $this->createStub( ThemeRepository::class );
		$themes->method( 'allDeploymentThemes' )->willReturn( array() );
		$store       = new RuntimeReleaseStore(
			array(
				"plugin\0example/example.php" => new ManagedReleaseConfiguration( 'example', 'example.php' ),
			)
		);
		$lock        = new RuntimeUpdaterLock();
		$invalidated = array();
		$registrar   = new ManagedReleaseTargetRegistrar(
			$plugins,
			$themes,
			$this->createStub( SecretsFile::class ),
			$store,
			$lock
		);
		$facade      = new NativeReleaseTrackingFacade(
			$plugins,
			$themes,
			$store,
			$registrar,
			$lock,
			$this->releaseMetadataRegistry(),
			static fn (): bool => true,
			static fn (): bool => true,
			metadataEligible: static fn (): bool => true,
			invalidateNative: static function ( string $type ) use ( &$invalidated ): void {
				$invalidated[] = $type;
			}
		);

		$current = $facade->changeChannel( 'plugin', 'example/example.php', 1, 'stable', 'valid' );

		self::assertTrue( $current->successful() );
		self::assertSame( 'release_channel_current', $current->code() );
		self::assertSame( 'Stable is already the active release track. No settings were changed.', $current->message() );
		self::assertSame( array(), $store->channelChanges );
		self::assertSame( array(), $invalidated );
		self::assertSame( 0, $lock->acquires );

		$stale = $facade->changeChannel( 'plugin', 'example/example.php', 2, 'prerelease', 'valid' );

		self::assertFalse( $stale->successful() );
		self::assertSame( 'source_changed', $stale->code() );
		self::assertStringContainsString( 'Refresh this browser page', $stale->message() );
		self::assertSame( array(), $store->channelChanges );
		self::assertSame( array(), $invalidated );
		self::assertSame( 0, $lock->acquires );
	}

	private function nonceFacade(): NativeReleaseTrackingFacade {
		$plugins = $this->createStub( PluginRepository::class );
		$themes  = $this->createStub( ThemeRepository::class );
		$store   = new RuntimeReleaseStore();

		return new NativeReleaseTrackingFacade(
			$plugins,
			$themes,
			$store,
			new ManagedReleaseTargetRegistrar(
				$plugins,
				$themes,
				$this->createStub( SecretsFile::class ),
				$store,
				new RuntimeUpdaterLock()
			),
			new RuntimeUpdaterLock(),
			$this->releaseMetadataRegistry()
		);
	}

	private function releaseMetadataRegistry( string $code = 'gh', string $baseUrl = 'https://github.com/' ): ProviderRegistry {
		$provider = new class( $code, $baseUrl ) implements RepositoryProvider, RepositoryReleaseMetadata {
			use \Tests\RepositoryProvider\Support\SuppliesProviderDiagnostics;

			public function __construct( private string $code, private string $baseUrl ) {
			}

			public function getMetadata(): ProviderMetadata {
				return new ProviderMetadata( ProviderCode::parse( $this->code ), 'Release metadata fixture', $this->baseUrl, 'Owner' );
			}

			public function expectedUpdateUri( RepositoryReference $repository ): string {
				return $this->baseUrl . $repository->locator;
			}

			public function releaseDetailsUrl( RepositoryReference $repository, string $tag ): string {
				return '' === $tag ? '' : $this->expectedUpdateUri( $repository ) . '/releases/tag/' . rawurlencode( $tag );
			}
		};

		return new ProviderRegistry( array( $provider ) );
	}

	/**
	 * @param Package|callable(): Package|null $live
	 * @return array{ManagedReleaseTargetRegistrar, RuntimeUpdaterLock, RuntimeUpdaterFacade}
	 */
	private function nativePluginRegistrar(
		Package $registered,
		Package|callable|null $live = null
	): array {
		$plugins = $this->createStub( PluginRepository::class );
		$plugins->method( 'allDeploymentPlugins' )->willReturn( array( self::NATIVE_PLUGIN => $registered ) );
		$live = $live ?? $registered;
		if ( is_callable( $live ) ) {
			$plugins->method( 'boosterPluginFromFile' )->willReturnCallback( $live );
		} else {
			$plugins->method( 'boosterPluginFromFile' )->willReturn( $live );
		}
		$themes = $this->createStub( ThemeRepository::class );
		$themes->method( 'allDeploymentThemes' )->willReturn( array() );
		$lock      = new RuntimeUpdaterLock();
		$facade    = new RuntimeUpdaterFacade();
		$registrar = new ManagedReleaseTargetRegistrar(
			$plugins,
			$themes,
			$this->createStub( SecretsFile::class ),
			new RuntimeReleaseStore(
				array(
					"plugin\0" . self::NATIVE_PLUGIN => new ManagedReleaseConfiguration( 'example', 'example.php' ),
				)
			),
			$lock,
			static function ( mixed ...$options ) use ( $facade ): object {
				unset( $options );

				return $facade;
			}
		);
		$registrar->register();

		return array( $registrar, $lock, $facade );
	}

	private function assertNativeAuthorityError( mixed $result ): void {
		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'ran_booster_native_update_authority_changed', $result->get_error_code() );
	}

	private function package(
		string $type,
		string $identifier,
		string $slug,
		DeploymentPolicy $policy,
		bool $private = false,
		string $credentialId = '',
		string $provider = 'gh',
		PackageSource $source = PackageSource::RELEASE_ASSET,
		int $sourceRevision = 1
	): Package {
		$package = $this->createStub( Package::class );
		$package->method( 'getIdentifier' )->willReturn( $identifier );
		$package->method( 'getSlug' )->willReturn( $slug );
		$package->method( 'getDeploymentPolicy' )->willReturn( $policy );
		$package->method( 'getSource' )->willReturn( $source );
		$package->method( 'getSourceRevision' )->willReturn( $sourceRevision );
		$package->method( 'getProviderCode' )->willReturn( $provider );
		$package->method( 'getRepository' )->willReturn(
			new ManagedRepository( $provider, 'owner/example', '123456789', 'main', $private, $credentialId )
		);
		$package->method( 'getProviderRepositoryId' )->willReturn( '123456789' );
		$package->method( 'getCredentialId' )->willReturn( $credentialId );
		$package->method( 'getPrivate' )->willReturn( $private );
		$package->method( 'getVersion' )->willReturn( '1.0.0' );
		unset( $type );

		return $package;
	}
}

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- Focused lock spy belongs with the registrar contract.
final class RuntimeUpdaterLock extends WordPressUpdaterLock {

	public int $acquires         = 0;
	public int $tokenReads       = 0;
	public bool $acquireFails    = false;
	public bool $releaseSucceeds = true;

	/** @var list<string> */
	public array $releases = array();

	public ?string $token = 'outer-lock';

	public function currentToken(): ?string {
		++$this->tokenReads;

		return $this->token;
	}

	public function acquire(): string {
		++$this->acquires;
		if ( $this->acquireFails ) {
			throw new \RuntimeException( 'busy' );
		}

		return 'runtime-lock';
	}

	public function release( string $token ): bool {
		$this->releases[] = $token;

		return $this->releaseSucceeds;
	}
}
