<?php

declare(strict_types=1);

namespace Tests\WordPress;

require_once __DIR__ . '/ManagedReleaseRuntimeWordPressFunctions.php';
require_once __DIR__ . '/RuntimeReleaseStore.php';
require_once __DIR__ . '/RuntimeUpdaterFacade.php';
require_once __DIR__ . '/RuntimeReleaseProvider.php';
require_once __DIR__ . '/../Support/WPError.php';
require_once __DIR__ . '/../Support/WordPressUpgraderSkins.php';
require_once __DIR__ . '/../Portability/WpPusherCoexistenceWordPressFunctions.php';

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use RAN\AddOn\ReleaseTracking\NativeReleaseTrackingFacade;
use RAN\AddOn\ReleaseTracking\ReleaseTrackingEligibility;
use RAN\AddOn\ReleaseTracking\ReleaseTrackingPreflight;
use RAN\Booster\GitHub\GitHubReleaseNativeTarget;
use RAN\Deployment\DeploymentPolicy;
use RAN\ManagedRepository;
use RAN\Package;
use RAN\PackageSource;
use RAN\RepositoryProvider\ProviderCode;
use RAN\RepositoryProvider\ProviderMetadata;
use RAN\RepositoryProvider\ProviderRegistry;
use RAN\RepositoryProvider\RepositoryProvider;
use RAN\RepositoryProvider\RepositoryReference;
use RAN\RepositoryProvider\RepositoryReleaseCandidate;
use RAN\RepositoryProvider\RepositoryReleaseCandidateList;
use RAN\RepositoryProvider\RepositoryReleaseCandidateListing;
use RAN\RepositoryProvider\RepositoryReleaseInspection;
use RAN\RepositoryProvider\RepositoryReleaseInspectionRejected;
use RAN\RepositoryProvider\RepositoryReleaseInspector;
use RAN\RepositoryProvider\RepositoryReleaseMetadata;
use RAN\RepositoryProvider\RepositoryReleaseNativeTarget;
use RAN\RepositoryProvider\RepositoryReleaseNativeTargets;
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
			$store,
			new RuntimeUpdaterLock(),
			$this->releaseMetadataRegistry( 'vendor-fixture', 'https://vendor.example/' )
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
		$metadataOnly = new NativeReleaseTrackingFacade(
			$plugins,
			$themes,
			$store,
			$registrar,
			new RuntimeUpdaterLock(),
			$this->metadataOnlyRegistry( 'vendor-fixture', 'https://vendor.example/' ),
			metadataEligible: static fn (): bool => true
		);
		self::assertSame(
			ReleaseTrackingEligibility::UNSUPPORTED_PROVIDER,
			$metadataOnly->status( 'plugin', 'example/example.php' )->eligibility()->code()
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

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function testRegistrarRegistersProviderOwnedPluginAndThemeTargetsWithMappedPolicies(): void {
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
		$targets   = array();
		$factory   = static function ( mixed ...$options ) use ( &$targets ): object {
			$targets[] = $options;

			return new RuntimeUpdaterFacade();
		};
		$registrar = new ManagedReleaseTargetRegistrar(
			$plugins,
			$themes,
			$store,
			new RuntimeUpdaterLock(),
			$this->releaseMetadataRegistry( targetFactory: $factory )
		);

		$registrar->register();

		self::assertCount( 3, $targets );
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
		self::assertInstanceOf( RuntimeUpdaterFacade::class, $registrar->target( 'plugin', 'installed-example/example.php' ) );
		self::assertInstanceOf( RuntimeUpdaterFacade::class, $registrar->target( 'theme', 'example-theme' ) );
		$preDownload = array_values(
			array_filter(
				$GLOBALS['ran_booster_runtime_actions'],
				static fn ( array $registration ): bool => 'upgrader_pre_download' === ( $registration['hook'] ?? null )
					&& is_array( $registration['callback'] ?? null )
					&& 'authorizeNativeDownload' === ( $registration['callback'][1] ?? null )
			)
		);
		self::assertCount( 1, $preDownload );
		self::assertSame( PHP_INT_MIN, $preDownload[0]['priority'] );
		self::assertSame( 4, $preDownload[0]['acceptedArgs'] );
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
			$store,
			new RuntimeUpdaterLock(),
			$this->releaseMetadataRegistry(
				targetFactory: static fn ( mixed ...$options ): object => new RuntimeUpdaterFacade( $options )
			)
		);

		$registrar->register();

		self::assertNotNull( $registrar->target( 'theme', 'example-theme' ) );
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
			$store,
			new RuntimeUpdaterLock(),
			$this->releaseMetadataRegistry(
				targetFactory: static fn ( mixed ...$options ): object => new RuntimeUpdaterFacade( $options )
			)
		);

		$registrar->register();

		self::assertNotNull( $registrar->target( 'plugin', 'valid/valid.php' ) );
		self::assertNull( $registrar->target( 'plugin', 'invalid/invalid.php' ) );
		self::assertSame( 'target_registration_failed', $registrar->failureCode( 'plugin', 'invalid/invalid.php' ) );
	}

	public function testRegistrarRejectsAProviderTargetThatReturnsFalse(): void {
		$package = $this->package( 'plugin', self::NATIVE_PLUGIN, 'example', DeploymentPolicy::MANUAL );
		$plugins = $this->createStub( PluginRepository::class );
		$plugins->method( 'allDeploymentPlugins' )->willReturn( array( self::NATIVE_PLUGIN => $package ) );
		$themes = $this->createStub( ThemeRepository::class );
		$themes->method( 'allDeploymentThemes' )->willReturn( array() );
		$target = new RuntimeUpdaterFacade();
		$target->failRegistration();
		$registrar = new ManagedReleaseTargetRegistrar(
			$plugins,
			$themes,
			new RuntimeReleaseStore(
				array( "plugin\0" . self::NATIVE_PLUGIN => new ManagedReleaseConfiguration( 'example', 'example.php' ) )
			),
			new RuntimeUpdaterLock(),
			$this->releaseMetadataRegistry(
				targetFactory: static fn ( mixed ...$options ): RuntimeUpdaterFacade => $target
			)
		);
		$registrar->register();

		self::assertNull( $registrar->target( 'plugin', self::NATIVE_PLUGIN ) );
		self::assertSame( 'target_registration_failed', $registrar->failureCode( 'plugin', self::NATIVE_PLUGIN ) );
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
			new RuntimeReleaseStore(
				array(
					"plugin\0" . self::NATIVE_PLUGIN => new ManagedReleaseConfiguration( 'example', 'example.php' ),
					"theme\0example-theme"           => new ManagedReleaseConfiguration( 'example-theme', 'style.css' ),
				)
			),
			new RuntimeUpdaterLock(),
			$this->releaseMetadataRegistry(
				targetFactory: static function (): never {
					throw new \RuntimeException( 'target rejected' );
				}
			)
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

	public function testRegisteredTargetFailsClosedWhenItsManagementRowDisappears(): void {
		$release       = $this->package( 'plugin', self::NATIVE_PLUGIN, 'example', DeploymentPolicy::MANUAL );
		[ $registrar ] = $this->nativePluginRegistrar(
			$release,
			static function (): Package {
				throw new PluginNotFound();
			}
		);

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
			new RuntimeReleaseStore(),
			new RuntimeUpdaterLock(),
			$this->releaseMetadataRegistry()
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
			new RuntimeReleaseStore(),
			new RuntimeUpdaterLock(),
			$this->releaseMetadataRegistry()
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

	public function testPersistedManagedOfferRequiresAnActiveExactNativeTarget(): void {
		$package                  = $this->package( 'plugin', self::NATIVE_PLUGIN, 'example', DeploymentPolicy::MANUAL );
		[ $registrar, , $target ] = $this->nativePluginRegistrar( $package );
		$active                   = (object) array(
			'response'  => array( self::NATIVE_PLUGIN => (object) array() ),
			'no_update' => array( self::NATIVE_PLUGIN => (object) array() ),
		);
		self::assertArrayHasKey( self::NATIVE_PLUGIN, $registrar->suppressUnauthorizedPluginOffers( $active )->response );

		$target->replaceDiagnostics( array( 'state' => 'inactive' ) );
		$inactive = (object) array(
			'response'  => array( self::NATIVE_PLUGIN => (object) array() ),
			'no_update' => array( self::NATIVE_PLUGIN => (object) array() ),
		);
		$filtered = $registrar->suppressUnauthorizedPluginOffers( $inactive );
		self::assertArrayNotHasKey( self::NATIVE_PLUGIN, $filtered->response );
		self::assertArrayHasKey( self::NATIVE_PLUGIN, $filtered->no_update );

		$target->failDiagnostics();
		$throwing = (object) array( 'response' => array( self::NATIVE_PLUGIN => (object) array() ) );
		self::assertArrayNotHasKey( self::NATIVE_PLUGIN, $registrar->suppressUnauthorizedPluginOffers( $throwing )->response );
	}

	public function testPersistedPluginAndThemeOffersAreSuppressedWithoutNativeCapability(): void {
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
			new RuntimeReleaseStore(
				array(
					"plugin\0" . self::NATIVE_PLUGIN => new ManagedReleaseConfiguration( 'example', 'example.php' ),
					"theme\0example-theme"           => new ManagedReleaseConfiguration( 'example-theme', 'style.css' ),
				)
			),
			new RuntimeUpdaterLock(),
			$this->metadataOnlyRegistry()
		);
		$registrar->register();
		$pluginOffers = (object) array( 'response' => array( self::NATIVE_PLUGIN => (object) array() ) );
		$themeOffers  = (object) array( 'response' => array( 'example-theme' => (object) array() ) );
		self::assertArrayNotHasKey( self::NATIVE_PLUGIN, $registrar->suppressUnauthorizedPluginOffers( $pluginOffers )->response );
		self::assertArrayNotHasKey( 'example-theme', $registrar->suppressUnauthorizedThemeOffers( $themeOffers )->response );
	}

	public function testPersistedManagedOffersFailClosedAfterSourceChangeOrRepositoryFailure(): void {
		$branch  = $this->package(
			'plugin',
			self::NATIVE_PLUGIN,
			'example',
			DeploymentPolicy::MANUAL,
			source: PackageSource::BRANCH
		);
		$plugins = $this->createStub( PluginRepository::class );
		$plugins->method( 'allDeploymentPlugins' )->willReturn( array( self::NATIVE_PLUGIN => $branch ) );
		$themes = $this->createStub( ThemeRepository::class );
		$themes->method( 'allDeploymentThemes' )->willReturn( array() );
		$registrar = new ManagedReleaseTargetRegistrar(
			$plugins,
			$themes,
			new RuntimeReleaseStore(),
			new RuntimeUpdaterLock(),
			$this->releaseMetadataRegistry()
		);
		$offers    = (object) array(
			'response'  => array(
				self::NATIVE_PLUGIN   => (object) array(),
				'unmanaged/other.php' => (object) array(),
			),
			'no_update' => array( self::NATIVE_PLUGIN => (object) array() ),
		);

		$filtered = $registrar->suppressUnauthorizedPluginOffers( $offers );
		self::assertArrayNotHasKey( self::NATIVE_PLUGIN, $filtered->response );
		self::assertArrayHasKey( 'unmanaged/other.php', $filtered->response );
		self::assertArrayHasKey( self::NATIVE_PLUGIN, $filtered->no_update );

		$unavailable = $this->createStub( PluginRepository::class );
		$unavailable->method( 'allDeploymentPlugins' )->willThrowException( new \RuntimeException( 'read failed' ) );
		$registrar = new ManagedReleaseTargetRegistrar(
			$unavailable,
			$themes,
			new RuntimeReleaseStore(),
			new RuntimeUpdaterLock(),
			$this->releaseMetadataRegistry()
		);
		$offers    = (object) array(
			'response'  => array(
				self::NATIVE_PLUGIN   => (object) array(),
				'unmanaged/other.php' => (object) array(),
			),
			'no_update' => array( self::NATIVE_PLUGIN => (object) array() ),
		);

		$filtered = $registrar->suppressUnauthorizedPluginOffers( $offers );
		self::assertArrayHasKey( self::NATIVE_PLUGIN, $filtered->response );
		self::assertArrayHasKey( 'unmanaged/other.php', $filtered->response );
		self::assertArrayHasKey( self::NATIVE_PLUGIN, $filtered->no_update );
	}

	public function testRepositoryFailureSuppressesOnlyTargetsRegisteredByCoreThisRequest(): void {
		$package = $this->package( 'plugin', self::NATIVE_PLUGIN, 'example', DeploymentPolicy::MANUAL );
		$reads   = 0;
		$plugins = $this->createStub( PluginRepository::class );
		$plugins->method( 'allDeploymentPlugins' )->willReturnCallback(
			static function () use ( &$reads, $package ): array {
				if ( 0 < $reads++ ) {
					throw new \RuntimeException( 'read failed' );
				}

				return array( self::NATIVE_PLUGIN => $package );
			}
		);
		$plugins->method( 'boosterPluginFromFile' )->willReturn( $package );
		$themes = $this->createStub( ThemeRepository::class );
		$themes->method( 'allDeploymentThemes' )->willReturn( array() );
		$registrar = new ManagedReleaseTargetRegistrar(
			$plugins,
			$themes,
			new RuntimeReleaseStore(
				array( "plugin\0" . self::NATIVE_PLUGIN => new ManagedReleaseConfiguration( 'example', 'example.php' ) )
			),
			new RuntimeUpdaterLock(),
			$this->releaseMetadataRegistry()
		);
		$registrar->register();
		$offers = (object) array(
			'response' => array(
				self::NATIVE_PLUGIN   => (object) array(),
				'unmanaged/other.php' => (object) array(),
			),
		);

		$filtered = $registrar->suppressUnauthorizedPluginOffers( $offers );

		self::assertArrayNotHasKey( self::NATIVE_PLUGIN, $filtered->response );
		self::assertArrayHasKey( 'unmanaged/other.php', $filtered->response );
	}

	public function testRuntimeReleaseProviderDefaultTargetAcceptsTheNamedContractArguments(): void {
		$target = ( new RuntimeReleaseProvider() )->createNativeTarget(
			'plugin',
			new RepositoryReference( 'owner/example', '123456789', false, null ),
			'/tmp/example.php',
			'example',
			'example/example.php',
			'stable',
			'manual'
		);

		self::assertInstanceOf( RepositoryReleaseNativeTarget::class, $target );
		self::assertTrue( $target->register() );
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
			$store,
			new RuntimeUpdaterLock(),
			$this->releaseMetadataRegistry()
		);

		$expectedAction = 'ran-booster-release-tracking-enable-plugin-example/example.php-1';

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
		$listings      = array();
		$inspections   = array();
		$invalidations = array();
		$allowed       = true;
		$providers     = $this->releaseMetadataRegistry(
			list: static function ( string $type, RepositoryReference $repository, string $channel ) use ( &$listings ): RepositoryReleaseCandidateList {
				$listings[] = compact( 'type', 'repository', 'channel' );
				$ids        = 'plugin' === $type ? array( '101', '102' ) : array( '201' );

				return new RepositoryReleaseCandidateList(
					array_map(
						static fn ( string $id ): RepositoryReleaseCandidate => new RepositoryReleaseCandidate(
							$id,
							'v2.0.0',
							'2.0.0',
							false,
							'2026-08-17T12:00:00Z',
							array( 'example.zip' )
						),
						$ids
					)
				);
			},
			inspect: static function ( string $type, RepositoryReference $repository, string $releaseId, string $tag, string $channel ) use ( &$inspections ): RepositoryReleaseInspection {
				$inspections[] = compact( 'type', 'repository', 'releaseId', 'tag', 'channel' );
				if ( '101' === $releaseId ) {
					throw RepositoryReleaseInspectionRejected::incompatible();
				}

				return new RepositoryReleaseInspection(
					$releaseId,
					$tag,
					'2.0.0',
					str_repeat( 'a', 40 ),
					'plugin' === $type ? 'example' : 'example-theme',
					'plugin' === $type ? 'example.php' : 'style.css',
					'v1:' . str_repeat( 'b', 64 )
				);
			}
		);
		$facade        = new NativeReleaseTrackingFacade(
			$plugins,
			$themes,
			$store,
			new ManagedReleaseTargetRegistrar(
				$plugins,
				$themes,
				$store,
				$lock,
				$this->releaseMetadataRegistry()
			),
			$lock,
			$providers,
			static function () use ( &$allowed ): bool {
				return $allowed;
			},
			static fn ( string $nonce, string $action ): bool => hash_equals( $action, $nonce ),
			metadataEligible: static fn (): bool => true,
			invalidateNative: static function ( string $type ) use ( &$invalidations ): void {
				$invalidations[] = $type;
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

		self::assertSame( array( 'plugin', 'theme' ), array_column( $listings, 'type' ) );
		self::assertSame( array( 'prerelease', 'stable' ), array_column( $listings, 'channel' ) );
		self::assertSame( array( '101', '102', '201' ), array_column( $inspections, 'releaseId' ) );
		self::assertSame( array( 'prerelease', 'prerelease', 'stable' ), array_column( $inspections, 'channel' ) );
		self::assertSame( array(), $store->transitions );
		self::assertSame( array(), $invalidations );
		self::assertSame( 0, $lock->acquires );
	}

	public function testProviderPreflightFailsClosedAcrossIdentityChannelAndOperationalBoundaries(): void {
		$package = $this->package(
			'plugin',
			'example/example.php',
			'example',
			DeploymentPolicy::MANUAL,
			source: PackageSource::BRANCH
		);
		$plugins = $this->createStub( PluginRepository::class );
		$plugins->method( 'boosterPluginFromFile' )->willReturn( $package );
		$themes          = $this->createStub( ThemeRepository::class );
		$store           = new RuntimeReleaseStore();
		$lock            = new RuntimeUpdaterLock();
		$mode            = 'release_id';
		$inspectionCalls = 0;
		$providers       = $this->releaseMetadataRegistry(
			list: static function () use ( &$mode ): RepositoryReleaseCandidateList {
				if ( 'operational' === $mode ) {
					throw new \RuntimeException( 'token=must-not-escape' );
				}
				if ( 'none' === $mode ) {
					return new RepositoryReleaseCandidateList( array() );
				}
				if ( 'incompatible_budget' === $mode ) {
					return new RepositoryReleaseCandidateList(
						array(
							new RepositoryReleaseCandidate( '101', 'v3.0.0', '3.0.0', false, '2026-08-17T12:00:00Z', array( 'example.zip' ) ),
							new RepositoryReleaseCandidate( '102', 'v2.5.0', '2.5.0', false, '2026-08-16T12:00:00Z', array( 'example.zip' ) ),
							new RepositoryReleaseCandidate( '103', 'v2.0.0', '2.0.0', false, '2026-08-15T12:00:00Z', array( 'example.zip' ) ),
						)
					);
				}

				$prerelease = 'channel' === $mode;
				$version    = 'hyphenated_stable' === $mode ? '2026-08' : ( $prerelease ? '2.0.0-beta' : '2.0.0' );
				return new RepositoryReleaseCandidateList(
					array(
						new RepositoryReleaseCandidate(
							'101',
							$prerelease ? 'v2.0.0-beta' : ( 'hyphenated_stable' === $mode ? 'v2026-08' : 'v2.0.0' ),
							$version,
							$prerelease,
							'2026-08-17T12:00:00Z',
							array( 'example.zip' )
						),
					)
				);
			},
			inspect: static function ( string $type, RepositoryReference $repository, string $releaseId, string $tag, string $channel ) use ( &$mode, &$inspectionCalls ): RepositoryReleaseInspection {
				++$inspectionCalls;
				unset( $type, $repository, $channel );
				if ( 'incompatible_budget' === $mode ) {
					throw RepositoryReleaseInspectionRejected::incompatible();
				}

				return new RepositoryReleaseInspection(
					'release_id' === $mode ? '999' : $releaseId,
					'tag' === $mode ? 'v2.0.1' : $tag,
					'version' === $mode ? '2.0.1' : ( 'hyphenated_stable' === $mode ? '2026-08' : '2.0.0' ),
					str_repeat( 'a', 40 ),
					'package_root' === $mode ? 'other' : 'example',
					'main_file' === $mode ? 'other.php' : 'example.php',
					'v1:' . str_repeat( 'b', 64 )
				);
			}
		);
		$facade          = new NativeReleaseTrackingFacade(
			$plugins,
			$themes,
			$store,
			new ManagedReleaseTargetRegistrar(
				$plugins,
				$themes,
				$store,
				$lock,
				$providers
			),
			$lock,
			$providers,
			static fn (): bool => true,
			static fn (): bool => true,
			metadataEligible: static fn (): bool => true
		);
		$nonce           = $facade->nonceAction( 'preflight', 'plugin', 'example/example.php', 1, 'stable' );

		foreach ( array( 'release_id', 'tag', 'version', 'package_root', 'main_file' ) as $mode ) {
			$result = $facade->preflight( 'plugin', 'example/example.php', 1, 'stable', $nonce );
			self::assertSame( ReleaseTrackingPreflight::INVALID_RELEASE_ASSETS, $result?->code(), $mode );
			self::assertSame( 'release_identity_mismatch', $result?->reasonCode(), $mode );
		}

		$mode   = 'channel';
		$result = $facade->preflight( 'plugin', 'example/example.php', 1, 'stable', $nonce );
		self::assertSame( ReleaseTrackingPreflight::INVALID_RELEASE_ASSETS, $result?->code() );
		self::assertSame( 'invalid_release', $result?->reasonCode() );

		$mode   = 'none';
		$result = $facade->preflight( 'plugin', 'example/example.php', 1, 'stable', $nonce );
		self::assertSame( ReleaseTrackingPreflight::RELEASE_UNAVAILABLE, $result?->code() );
		self::assertSame( 'no_releases', $result?->reasonCode() );

		$mode   = 'operational';
		$result = $facade->preflight( 'plugin', 'example/example.php', 1, 'stable', $nonce );
		self::assertSame( ReleaseTrackingPreflight::PREFLIGHT_UNAVAILABLE, $result?->code() );
		self::assertSame( 'provider_unavailable', $result?->reasonCode() );
		self::assertStringNotContainsString( 'token', $result?->reasonCode() ?? '' );

		$mode   = 'hyphenated_stable';
		$result = $facade->preflight( 'plugin', 'example/example.php', 1, 'stable', $nonce );
		self::assertSame( ReleaseTrackingPreflight::READY, $result?->code() );
		self::assertSame( '2026-08', $result?->latestVersion() );

		$callsBeforeBudget = $inspectionCalls;
		$mode              = 'incompatible_budget';
		$result            = $facade->preflight( 'plugin', 'example/example.php', 1, 'stable', $nonce );
		self::assertSame( ReleaseTrackingPreflight::RELEASE_UNAVAILABLE, $result?->code() );
		self::assertSame( 'release_incompatible', $result?->reasonCode() );
		self::assertSame( 2, $inspectionCalls - $callsBeforeBudget );
		self::assertSame( array(), $store->transitions );
		self::assertSame( 0, $lock->acquires );
	}

	public function testProviderPreflightRequiresTheCompleteReadFacetSetBeforeRemoteWork(): void {
		$package = $this->package(
			'plugin',
			'example/example.php',
			'example',
			DeploymentPolicy::MANUAL,
			provider: 'partial',
			source: PackageSource::BRANCH
		);
		$plugins = $this->createStub( PluginRepository::class );
		$plugins->method( 'boosterPluginFromFile' )->willReturn( $package );
		$themes    = $this->createStub( ThemeRepository::class );
		$provider  = new class() implements RepositoryProvider, RepositoryReleaseMetadata, RepositoryReleaseCandidateListing, RepositoryReleaseNativeTargets {
			use \Tests\RepositoryProvider\Support\SuppliesProviderDiagnostics;

			public int $listCalls = 0;

			public function getMetadata(): ProviderMetadata {
				return new ProviderMetadata( ProviderCode::parse( 'partial' ), 'Partial release fixture', 'https://partial.example/', 'Owner' );
			}

			public function expectedUpdateUri( RepositoryReference $repository ): string {
				return 'https://partial.example/' . $repository->locator;
			}

			public function releaseDetailsUrl( RepositoryReference $repository, string $tag ): string {
				return $this->expectedUpdateUri( $repository ) . '/releases/' . rawurlencode( $tag );
			}

			public function listReleaseCandidates( string $packageType, RepositoryReference $repository, string $channel ): RepositoryReleaseCandidateList {
				unset( $packageType, $repository, $channel );
				++$this->listCalls;

				return new RepositoryReleaseCandidateList( array() );
			}

			public function hasRegisteredNativeTarget( string $packageType, string $installedIdentifier ): bool {
				unset( $packageType, $installedIdentifier );

				return false;
			}

			public function createNativeTarget(
				string $packageType,
				RepositoryReference $repository,
				string $metadataFile,
				string $packageRoot,
				string $installedIdentifier,
				string $channel,
				string $deploymentPolicy
			): RepositoryReleaseNativeTarget {
				unset( $packageType, $repository, $metadataFile, $packageRoot, $installedIdentifier, $channel, $deploymentPolicy );

				throw new \RuntimeException( 'Native target construction must remain inert during branch preflight.' );
			}
		};
		$store     = new RuntimeReleaseStore();
		$lock      = new RuntimeUpdaterLock();
		$providers = new ProviderRegistry( array( $provider ) );
		$providers->seal();
		$facade = new NativeReleaseTrackingFacade(
			$plugins,
			$themes,
			$store,
			new ManagedReleaseTargetRegistrar(
				$plugins,
				$themes,
				$store,
				$lock,
				$providers
			),
			$lock,
			$providers,
			static fn (): bool => true,
			static fn (): bool => true,
			metadataEligible: static fn (): bool => true
		);
		$nonce  = $facade->nonceAction( 'preflight', 'plugin', 'example/example.php', 1, 'stable' );

		$result = $facade->preflight( 'plugin', 'example/example.php', 1, 'stable', $nonce );

		self::assertSame( ReleaseTrackingPreflight::PREFLIGHT_UNAVAILABLE, $result?->code() );
		self::assertSame( 'provider_unavailable', $result?->reasonCode() );
		self::assertSame( 0, $provider->listCalls );
		self::assertSame( array(), $store->transitions );
		self::assertSame( 0, $lock->acquires );
	}

	public function testCompleteExternalProviderCanPreflightAfterNativeTargetOwnershipLands(): void {
		$package = $this->package(
			'plugin',
			'example/example.php',
			'example',
			DeploymentPolicy::MANUAL,
			provider: 'bb',
			source: PackageSource::BRANCH
		);
		$plugins = $this->createStub( PluginRepository::class );
		$plugins->method( 'boosterPluginFromFile' )->willReturn( $package );
		$themes    = $this->createStub( ThemeRepository::class );
		$listCalls = 0;
		$store     = new RuntimeReleaseStore();
		$lock      = new RuntimeUpdaterLock();
		$facade    = new NativeReleaseTrackingFacade(
			$plugins,
			$themes,
			$store,
			new ManagedReleaseTargetRegistrar(
				$plugins,
				$themes,
				$store,
				$lock,
				$this->releaseMetadataRegistry()
			),
			$lock,
			$this->releaseMetadataRegistry(
				'bb',
				'https://bitbucket.example/',
				static function () use ( &$listCalls ): RepositoryReleaseCandidateList {
					++$listCalls;

					return new RepositoryReleaseCandidateList( array() );
				}
			),
			static fn (): bool => true,
			static fn (): bool => true,
			metadataEligible: static fn (): bool => true
		);
		$nonce     = $facade->nonceAction( 'preflight', 'plugin', 'example/example.php', 1, 'stable' );

		$result = $facade->preflight( 'plugin', 'example/example.php', 1, 'stable', $nonce );

		self::assertSame( ReleaseTrackingPreflight::RELEASE_UNAVAILABLE, $result?->code() );
		self::assertSame( 'no_releases', $result?->reasonCode() );
		self::assertSame( 1, $listCalls );
		self::assertSame( array(), $store->transitions );
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
			$store,
			new RuntimeUpdaterLock(),
			$this->releaseMetadataRegistry( collision: true )
		);
		$expectedAction = 'ran-booster-release-tracking-enable-plugin-example/example.php-1';
		$facade         = new NativeReleaseTrackingFacade(
			$plugins,
			$themes,
			$store,
			$registrar,
			new RuntimeUpdaterLock(),
			$this->releaseMetadataRegistry( collision: true ),
			static fn (): bool => true,
			static fn ( string $nonce, string $action ): bool => 'valid' === $nonce && $expectedAction === $action,
			metadataEligible: static fn (): bool => true
		);

		$status = $facade->status( 'plugin', 'example/example.php' );
		$result = $facade->enable( 'plugin', 'example/example.php', 1, 'stable', 'valid' );

		self::assertSame( ReleaseTrackingEligibility::TARGET_ALREADY_USES_RAN_UPDATER, $status->eligibility()->code() );
		self::assertFalse( $status->eligible() );
		self::assertSame( 'target_already_uses_ran_updater', $result->code() );
		self::assertFalse( $result->successful() );
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
			$store,
			new RuntimeUpdaterLock(),
			$this->releaseMetadataRegistry()
		);
		$facade    = new NativeReleaseTrackingFacade(
			$plugins,
			$this->createStub( ThemeRepository::class ),
			$store,
			$registrar,
			new RuntimeUpdaterLock(),
			$this->releaseMetadataRegistry(
				inspect: static function (): RepositoryReleaseInspection {
					throw RepositoryReleaseInspectionRejected::invalidRelease();
				}
			),
			static fn (): bool => true,
			static fn (): bool => true,
			metadataEligible: static fn (): bool => true
		);

		$result = $facade->enable( 'plugin', 'example/example.php', 1, 'stable', 'valid' );

		self::assertFalse( $result->successful() );
		self::assertSame( ReleaseTrackingPreflight::INVALID_RELEASE_ASSETS, $result->code() );
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
			$store,
			new RuntimeUpdaterLock(),
			$this->releaseMetadataRegistry(
				targetFactory: static fn ( mixed ...$options ): object => new RuntimeUpdaterFacade(
					diagnostics: array(
						'state'                => 'ready',
						'code'                 => 'release_available',
						'offered_version'      => '2.0.0',
						'last_check'           => 1_700_000_000,
						'next_check'           => 1_700_003_600,
						'installed_version'    => '1.0.0',
						'version_relationship' => 'newer',
					)
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
		self::assertSame( 1, $registrar->target( 'theme', 'example-theme' )?->refreshes() );
		$registrar->target( 'theme', 'example-theme' )?->rejectRefresh();
		self::assertSame( 'refresh_failed', $facade->refresh( 'theme', 'example-theme', 1, 'nonce' )->code() );
		self::assertSame( array( 'theme' ), $refreshes );
		$registrar->target( 'theme', 'example-theme' )?->failRefresh();
		self::assertSame( 'refresh_failed', $facade->refresh( 'theme', 'example-theme', 1, 'nonce' )->code() );
		self::assertSame( array( 'theme' ), $refreshes );
		self::assertFalse( $facade->refresh( 'theme', 'example-theme', 2, 'nonce' )->successful() );
		self::assertSame( array( 'theme' ), $refreshes );
		self::assertSame( 0, $lock->acquires );
		self::assertSame( array(), $lock->releases );
	}

	public function testFacadeProjectsNeutralTargetOffersAndValidationFailures(): void {
		$package = $this->package( 'theme', 'example-theme', 'example-theme', DeploymentPolicy::MANUAL );
		$plugins = $this->createStub( PluginRepository::class );
		$plugins->method( 'allDeploymentPlugins' )->willReturn( array() );
		$themes = $this->createStub( ThemeRepository::class );
		$themes->method( 'allDeploymentThemes' )->willReturn( array( 'example-theme' => $package ) );
		$themes->method( 'boosterThemeFromStylesheet' )->willReturn( $package );
		$store   = new RuntimeReleaseStore(
			array(
				"theme\0example-theme" => new ManagedReleaseConfiguration( 'example-theme', 'style.css' ),
			)
		);
		$updater = new class() {
			/** @var array<string, int|string|null> */
			public array $currentStatus = array(
				'candidate_tag'             => 'v2.0.0',
				'candidate_validation_code' => 'archive_identity_verified',
				'candidate_version'         => '2.0.0',
				'candidate_header_version'  => '2.0.0',
				'failure_code'              => null,
				'installed_version'         => '1.0.0',
				'last_check'                => 1_700_000_000,
				'offered_version'           => '2.0.0',
				'relationship'              => 'newer',
			);

			/** @return array<string, int|string|null> */
			public function status(): array {
				return $this->currentStatus;
			}
		};
		$target  = new GitHubReleaseNativeTarget(
			'theme',
			'/wordpress/wp-content/themes/example-theme/style.css',
			'owner/example-theme',
			'123456789',
			'example-theme',
			'example-theme',
			null,
			'stable',
			'manual'
		);
		( new \ReflectionProperty( GitHubReleaseNativeTarget::class, 'updater' ) )->setValue( $target, $updater );
		$registrar = new ManagedReleaseTargetRegistrar(
			$plugins,
			$themes,
			$store,
			new RuntimeUpdaterLock(),
			$this->releaseMetadataRegistry( targetFactory: static fn ( mixed ...$options ): object => $target )
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
			static fn (): bool => true
		);

		$offer = $facade->status( 'theme', 'example-theme' );

		self::assertSame( '2.0.0', $offer->latestVersion() );
		self::assertTrue( $offer->updateAvailable() );
		self::assertSame( '', $offer->failureCode() );

		$updater->currentStatus = array(
			'candidate_tag'             => 'v2.0.0',
			'candidate_validation_code' => 'archive_header_missing',
			'candidate_version'         => '2.0.0',
			'candidate_header_version'  => null,
			'failure_code'              => null,
			'installed_version'         => '1.0.0',
			'last_check'                => 1_700_000_000,
			'offered_version'           => null,
			'relationship'              => 'newer',
		);

		$failure = $facade->status( 'theme', 'example-theme' );

		self::assertSame( '2.0.0', $failure->latestVersion() );
		self::assertFalse( $failure->updateAvailable() );
		self::assertSame( ReleaseTrackingPreflight::RELEASE_HEADER_MISSING, $failure->failureCode() );
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
			$store,
			new RuntimeUpdaterLock(),
			$this->releaseMetadataRegistry()
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
			$store,
			new RuntimeUpdaterLock(),
			$this->releaseMetadataRegistry()
		);
		$preflightCalls = 0;
		$lock           = new RuntimeUpdaterLock();
		$providers      = $this->releaseMetadataRegistry(
			list: static function () use ( &$preflightCalls ): RepositoryReleaseCandidateList {
				++$preflightCalls;

				return new RepositoryReleaseCandidateList( array() );
			}
		);
		$facade         = new NativeReleaseTrackingFacade(
			$plugins,
			$themes,
			$store,
			$registrar,
			$lock,
			$providers,
			metadataEligible: static fn (): bool => true
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
			$store,
			new RuntimeUpdaterLock(),
			$this->releaseMetadataRegistry(
				targetFactory: static fn ( mixed ...$options ): object => new RuntimeUpdaterFacade(
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
			metadataEligible: static fn (): bool => true
		);

		$status = $facade->status( 'plugin', 'example/example.php' );

		self::assertSame( 'release_version_mismatch', $status->failureCode() );
		self::assertFalse( $status->updateAvailable() );
		self::assertNotNull( $status->preflight() );
		self::assertSame( 'v2.1.0', $status->preflight()?->releaseTag() );
		self::assertSame( '2.0.0', $status->preflight()?->packageHeaderVersion() );
	}

	public function testReturningToBranchRemainsTruthfulWhenProviderAndNativeCacheCleanupFail(): void {
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
			$store,
			new RuntimeUpdaterLock(),
			$this->releaseMetadataRegistry(
				targetFactory: static fn ( mixed ...$options ): object => new RuntimeUpdaterFacade( $options )
			)
		);
		$registrar->register();
		$registrar->target( 'plugin', 'example/example.php' )?->failRefresh();
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
				throw new \RuntimeException( 'native cache cleanup failed' );
			}
		);

		$result = $facade->returnToBranch( 'plugin', 'example/example.php', 1, 'nonce' );

		self::assertTrue( $result->successful() );
		self::assertSame( PackageSource::BRANCH, $store->transitions[0]['new_source'] );
		self::assertSame( array( 'plugin' ), $invalidated );
		self::assertSame( 0, $registrar->target( 'plugin', 'example/example.php' )?->refreshes() );
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
			$store,
			new RuntimeUpdaterLock(),
			$this->releaseMetadataRegistry(
				targetFactory: static fn ( mixed ...$options ): object => new RuntimeUpdaterFacade( $options )
			)
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
		self::assertSame( 0, $registrar->target( 'plugin', 'example/example.php' )?->refreshes() );
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
			$store,
			$lock,
			$this->releaseMetadataRegistry()
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
				$store,
				new RuntimeUpdaterLock(),
				$this->releaseMetadataRegistry()
			),
			new RuntimeUpdaterLock(),
			$this->releaseMetadataRegistry()
		);
	}

	/**
	 * @param callable(string, RepositoryReference, string): RepositoryReleaseCandidateList|null $list
	 * @param callable(string, RepositoryReference, string, string, string): RepositoryReleaseInspection|null $inspect
	 */
	private function releaseMetadataRegistry(
		string $code = 'gh',
		string $baseUrl = 'https://github.com/',
		?callable $list = null,
		?callable $inspect = null,
		?callable $targetFactory = null,
		bool $collision = false
	): ProviderRegistry {
		$registry = new ProviderRegistry(
			array( new RuntimeReleaseProvider( $code, $baseUrl, $list, $inspect, $targetFactory, $collision ) )
		);
		$registry->seal();

		return $registry;
	}

	private function metadataOnlyRegistry( string $code = 'gh', string $baseUrl = 'https://github.com/' ): ProviderRegistry {
		$provider = new class( $code, $baseUrl ) implements RepositoryProvider, RepositoryReleaseMetadata {
			use \Tests\RepositoryProvider\Support\SuppliesProviderDiagnostics;

			public function __construct( private string $code, private string $baseUrl ) {
			}

			public function getMetadata(): ProviderMetadata {
				return new ProviderMetadata( ProviderCode::parse( $this->code ), 'Metadata-only fixture', $this->baseUrl, 'Owner' );
			}

			public function expectedUpdateUri( RepositoryReference $repository ): string {
				return $this->baseUrl . $repository->locator;
			}

			public function releaseDetailsUrl( RepositoryReference $repository, string $tag ): string {
				return '' === $tag ? '' : $this->expectedUpdateUri( $repository ) . '/releases/tag/' . rawurlencode( $tag );
			}
		};
		$registry = new ProviderRegistry( array( $provider ) );
		$registry->seal();

		return $registry;
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
			new RuntimeReleaseStore(
				array(
					"plugin\0" . self::NATIVE_PLUGIN => new ManagedReleaseConfiguration( 'example', 'example.php' ),
				)
			),
			$lock,
			$this->releaseMetadataRegistry(
				targetFactory: static function ( mixed ...$options ) use ( $facade ): object {
					unset( $options );

					return $facade;
				}
			)
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
