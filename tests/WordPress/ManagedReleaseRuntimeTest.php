<?php

declare(strict_types=1);

namespace Tests\WordPress;

require_once __DIR__ . '/ManagedReleaseRuntimeWordPressFunctions.php';
require_once __DIR__ . '/RuntimeReleaseStore.php';
require_once __DIR__ . '/RuntimeUpdaterFacade.php';
require_once __DIR__ . '/RuntimeReleaseProvider.php';
require_once __DIR__ . '/../Support/WPError.php';
require_once __DIR__ . '/../Support/WordPressUpgraderSkins.php';
require_once __DIR__ . '/../Support/InMemoryPublicRepositoryLookupProfileStore.php';
require_once __DIR__ . '/../Portability/WpPusherCoexistenceWordPressFunctions.php';

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use RAN\AddOn\ReleaseTracking\NativeReleaseTrackingFacade;
use RAN\AddOn\ReleaseTracking\ReleaseTrackingEligibility;
use RAN\AddOn\ReleaseTracking\ReleaseTrackingPreflight;
use RAN\BoosterGitHubProvider\V1\GitHubReleaseNativeTarget;
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
use RAN\RepositoryProvider\RepositoryReleaseReadUnavailable;
use RAN\Storage\PluginNotFound;
use RAN\Storage\PluginRepository;
use RAN\Storage\Database;
use RAN\Storage\RepositorySourceGuard;
use RAN\Storage\ThemeNotFound;
use RAN\Storage\ThemeRepository;
use RAN\WordPress\ManagedReleaseConfiguration;
use RAN\WordPress\ManagedReleaseRepositorySourceUnavailable;
use RAN\WordPress\ManagedReleaseStore;
use RAN\WordPress\ManagedReleaseSubdirectoryNotSupported;
use RAN\WordPress\ManagedReleaseTargetRegistrar;
use RAN\WordPress\WordPressUpdaterLock;
use Tests\Support\InMemoryPublicRepositoryLookupProfileStore;

final class ManagedReleaseRuntimeTest extends TestCase {

	/** @var array<string, object> Exact persistent rows represented by this test's packages. */
	private array $repository_rows = array();

	private const NATIVE_PLUGIN = 'example/example.php';
	private const NATIVE_EXTRA  = array(
		'plugin' => self::NATIVE_PLUGIN,
		'action' => 'update',
		'type'   => 'plugin',
	);

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this exact lifecycle override name.
	protected function setUp(): void {
		$GLOBALS['ran_booster_runtime_actions'] = array();
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this exact lifecycle override name.
	protected function tearDown(): void {
		unset( $GLOBALS['ran_booster_runtime_action'], $GLOBALS['ran_booster_runtime_actions'], $GLOBALS['ran_booster_wp_pusher_active_plugins'] );
	}

	public function test_configuration_uses_canonical_stable_json(): void {
		$configuration = new ManagedReleaseConfiguration( 'example', 'example.php' );
		$json          = '{"channel":"stable","package_root":"example","metadata_file":"example.php"}';

		self::assertSame( $json, $configuration->to_json() );
		self::assertSame( $configuration->to_array(), ManagedReleaseConfiguration::from_json( $json )->to_array() );
		self::assertSame( 'stable', $configuration->channel() );

		$this->expectException( InvalidArgumentException::class );
		ManagedReleaseConfiguration::from_json( str_replace( '{"channel"', '{ "channel"', $json ) );
	}

	public function test_configuration_persists_canonical_prerelease_channel(): void {
		$configuration = new ManagedReleaseConfiguration(
			'example',
			'example.php',
			'prerelease'
		);
		$json          = '{"channel":"prerelease","package_root":"example","metadata_file":"example.php"}';

		self::assertSame( $json, $configuration->to_json() );
		self::assertSame( 'prerelease', ManagedReleaseConfiguration::from_json( $json )->channel() );
	}

	public function test_configuration_rejects_removed_artifact_authority(): void {
		foreach (
			array(
				'{"channel":"stable","package_root":"example","metadata_file":"example.php","asset_prefix":"example"}',
				'{"channel":"stable","package_root":"example","metadata_file":"example.php","manifest_public_keys":{"current":"unused"}}',
			) as $json
		) {
			try {
				ManagedReleaseConfiguration::from_json( $json );
				self::fail( 'Removed artifact authority must not remain readable.' );
			} catch ( InvalidArgumentException ) {
				self::assertTrue( true );
			}
		}
	}

	public function test_eligibility_uses_the_selected_providers_release_metadata_facet(): void {
		$package = $this->package(
			'plugin',
			'example/example.php',
			'example',
			DeploymentPolicy::MANUAL,
			provider: 'vendor-fixture',
			source: PackageSource::BRANCH
		);
		$plugins = $this->createStub( PluginRepository::class );
		$plugins->method( 'booster_plugin_from_file' )->willReturn( $package );
		$themes    = $this->createStub( ThemeRepository::class );
		$store     = new RuntimeReleaseStore();
		$registrar = $this->registrar(
			$plugins,
			$themes,
			$store,
			new RuntimeUpdaterLock(),
			$this->release_metadata_registry( 'vendor-fixture', 'https://vendor.example/' )
		);
		$facade    = $this->facade(
			$plugins,
			$themes,
			$store,
			$registrar,
			new RuntimeUpdaterLock(),
			$this->release_metadata_registry( 'vendor-fixture', 'https://vendor.example/' ),
			metadata_eligible: static fn (): bool => true
		);

		$status = $facade->status( 'plugin', 'example/example.php' );

		self::assertTrue( $status->eligible() );
		self::assertSame( 'https://vendor.example/owner/example', $status->eligibility()->expected_update_uri() );

		$unsupported = $this->facade(
			$plugins,
			$themes,
			$store,
			$registrar,
			new RuntimeUpdaterLock(),
			new ProviderRegistry(),
			metadata_eligible: static fn (): bool => true
		);
		self::assertSame(
			ReleaseTrackingEligibility::UNSUPPORTED_PROVIDER,
			$unsupported->status( 'plugin', 'example/example.php' )->eligibility()->code()
		);
		$metadata_only = $this->facade(
			$plugins,
			$themes,
			$store,
			$registrar,
			new RuntimeUpdaterLock(),
			$this->metadata_only_registry( 'vendor-fixture', 'https://vendor.example/' ),
			metadata_eligible: static fn (): bool => true
		);
		self::assertSame(
			ReleaseTrackingEligibility::UNSUPPORTED_PROVIDER,
			$metadata_only->status( 'plugin', 'example/example.php' )->eligibility()->code()
		);
	}

	public function test_configuration_rejects_traversal_and_invalid_channel(): void {
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
	public function test_registrar_registers_provider_owned_plugin_and_theme_targets_with_mapped_policies(): void {
		$plugin   = $this->package(
			'plugin',
			'installed-example/example.php',
			'installed-example',
			DeploymentPolicy::MANUAL,
			true,
			'profile_1',
			'gh',
			PackageSource::RELEASE_ASSET,
			1,
			null,
			'plugin-repository'
		);
		$disabled = $this->package(
			'plugin',
			'disabled/disabled.php',
			'disabled',
			DeploymentPolicy::DISABLED,
			false,
			'',
			'gh',
			PackageSource::RELEASE_ASSET,
			1,
			null,
			'disabled-repository'
		);
		$theme    = $this->package(
			'theme',
			'example-theme',
			'example-theme',
			DeploymentPolicy::AUTOMATIC,
			false,
			'',
			'gh',
			PackageSource::RELEASE_ASSET,
			1,
			null,
			'theme-repository'
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
			->method( 'all_deployment_plugins' )
			->with( PackageSource::RELEASE_ASSET )
			->willReturn(
				array(
					'installed-example/example.php' => $plugin,
					'disabled/disabled.php'         => $disabled,
				)
			);
		$themes = $this->createMock( ThemeRepository::class );
		$themes->expects( self::once() )
			->method( 'all_deployment_themes' )
			->with( PackageSource::RELEASE_ASSET )
			->willReturn( array( 'example-theme' => $theme ) );
		$targets   = array();
		$factory   = static function ( mixed ...$options ) use ( &$targets ): object {
			$targets[] = $options;

			return new RuntimeUpdaterFacade();
		};
		$registrar = $this->registrar(
			$plugins,
			$themes,
			$store,
			new RuntimeUpdaterLock(),
			$this->release_metadata_registry( target_factory: $factory )
		);

		$registrar->register();

		self::assertCount( 3, $targets );
		self::assertSame( 'plugin', $targets[0]['targetType'] );
			self::assertSame(
				rtrim( WP_PLUGIN_DIR, '/\\' ) . '/installed-example/example.php',
				$targets[0]['plugin_file']
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
		$pre_download = array_values(
			array_filter(
				$GLOBALS['ran_booster_runtime_actions'],
				static fn ( array $registration ): bool => 'upgrader_pre_download' === ( $registration['hook'] ?? null )
					&& is_array( $registration['callback'] ?? null )
					&& 'authorize_native_download' === ( $registration['callback'][1] ?? null )
			)
		);
		self::assertCount( 1, $pre_download );
		self::assertSame( PHP_INT_MIN, $pre_download[0]['priority'] );
		self::assertSame( 4, $pre_download[0]['acceptedArgs'] );
	}

	public function test_registrar_quarantines_every_legacy_release_target_for_one_repository(): void {
		$plugin  = $this->package( 'plugin', 'example/example.php', 'example', DeploymentPolicy::DISABLED );
		$theme   = $this->package( 'theme', 'example-theme', 'example-theme', DeploymentPolicy::DISABLED );
		$plugins = $this->createStub( PluginRepository::class );
		$plugins->method( 'all_deployment_plugins' )->willReturn( array( 'example/example.php' => $plugin ) );
		$themes = $this->createStub( ThemeRepository::class );
		$themes->method( 'all_deployment_themes' )->willReturn( array( 'example-theme' => $theme ) );
		$store     = new RuntimeReleaseStore(
			array(
				"plugin\0example/example.php" => new ManagedReleaseConfiguration( 'example', 'example.php' ),
				"theme\0example-theme"        => new ManagedReleaseConfiguration( 'example-theme', 'style.css', 'prerelease' ),
			)
		);
		$targets   = array();
		$registrar = $this->registrar(
			$plugins,
			$themes,
			$store,
			new RuntimeUpdaterLock(),
			$this->release_metadata_registry(
				target_factory: static function ( mixed ...$options ) use ( &$targets ): object {
					$targets[] = $options;

					return new RuntimeUpdaterFacade();
				}
			)
		);

		$registrar->register();

		self::assertSame( array(), $targets );
		self::assertNull( $registrar->target( 'plugin', 'example/example.php' ) );
		self::assertNull( $registrar->target( 'theme', 'example-theme' ) );
		self::assertSame( 'repository_release_owner_exists', $registrar->failure_code( 'plugin', 'example/example.php' ) );
		self::assertSame( 'repository_release_owner_exists', $registrar->failure_code( 'theme', 'example-theme' ) );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_registrar_keeps_fences_and_quarantines_conflicts_when_plugin_repository_read_fails(): void {
		$theme = $this->package( 'theme', 'example-theme', 'example-theme', DeploymentPolicy::MANUAL );
		$this->package(
			'plugin',
			'branch/branch.php',
			'branch',
			DeploymentPolicy::MANUAL,
			source: PackageSource::BRANCH
		);
		$plugins = $this->createStub( PluginRepository::class );
		$plugins->method( 'all_deployment_plugins' )->willThrowException( new \RuntimeException( 'read failed' ) );
		$themes = $this->createStub( ThemeRepository::class );
		$themes->method( 'all_deployment_themes' )->willReturn( array( 'example-theme' => $theme ) );
		$store     = new RuntimeReleaseStore(
			array(
				"theme\0example-theme" => new ManagedReleaseConfiguration( 'example-theme', 'style.css' ),
			)
		);
		$registrar = $this->registrar(
			$plugins,
			$themes,
			$store,
			new RuntimeUpdaterLock(),
			$this->release_metadata_registry(
				target_factory: static fn ( mixed ...$options ): object => new RuntimeUpdaterFacade( $options )
			)
		);

		$registrar->register();

		self::assertNull( $registrar->target( 'theme', 'example-theme' ) );
		self::assertSame( 'repository_read_failed', $registrar->failure_code( 'plugin', 'unavailable/unavailable.php' ) );
		self::assertSame( 'repository_release_owner_exists', $registrar->failure_code( 'theme', 'example-theme' ) );
		self::assertCount( 5, $GLOBALS['ran_booster_runtime_actions'] );
		self::assertSame(
			array(
				'upgrader_pre_download',
				'upgrader_pre_install',
				'site_transient_update_plugins',
				'site_transient_update_themes',
				'upgrader_process_complete',
			),
			array_column( $GLOBALS['ran_booster_runtime_actions'], 'hook' )
		);
		$offers   = (object) array(
			'response' => array(
				'example-theme'         => (object) array(),
				'unmanaged-other-theme' => (object) array(),
			),
		);
		$filtered = $registrar->suppress_unauthorized_theme_offers( $offers );
		self::assertArrayNotHasKey( 'example-theme', $filtered->response );
		self::assertArrayHasKey( 'unmanaged-other-theme', $filtered->response );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_registrar_quarantines_only_the_package_whose_repository_source_read_fails(): void {
		$available   = $this->package( 'plugin', 'available/available.php', 'available', DeploymentPolicy::MANUAL );
		$unavailable = $this->package(
			'plugin',
			'unavailable/unavailable.php',
			'unavailable',
			DeploymentPolicy::MANUAL,
			provider_repository_id: 'unavailable'
		);
		$plugins     = $this->createStub( PluginRepository::class );
		$plugins->method( 'all_deployment_plugins' )->willReturn(
			array(
				'available/available.php'     => $available,
				'unavailable/unavailable.php' => $unavailable,
			)
		);
		$themes = $this->createStub( ThemeRepository::class );
		$themes->method( 'all_deployment_themes' )->willReturn( array() );
		$database  = new class() {
			public string $last_error = '';

			// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundInExtendedClassBeforeLastUsed -- The fixture implementation of prepare retains the production method contract; these inputs do not affect this controlled result.
			public function prepare( string $query, mixed ...$arguments ): array {
				return $arguments;
			}

			public function get_results( array $arguments ): array {
				if ( 'unavailable' === $arguments[2] ) {
					throw new \RuntimeException( 'repository source unavailable' );
				}

				return array();
			}
		};
		$registrar = new ManagedReleaseTargetRegistrar(
			$plugins,
			$themes,
			new RuntimeReleaseStore(
				array(
					"plugin\0available/available.php"     => new ManagedReleaseConfiguration( 'available', 'available.php' ),
					"plugin\0unavailable/unavailable.php" => new ManagedReleaseConfiguration( 'unavailable', 'unavailable.php' ),
				)
			),
			new RuntimeUpdaterLock(),
			$this->release_metadata_registry(
				target_factory: static fn ( mixed ...$options ): object => new RuntimeUpdaterFacade( $options )
			),
			new RepositorySourceGuard( $database, $this->createStub( Database::class ) )
		);

		$registrar->register();

		self::assertInstanceOf( RepositoryReleaseNativeTarget::class, $registrar->target( 'plugin', 'available/available.php' ) );
		self::assertNull( $registrar->target( 'plugin', 'unavailable/unavailable.php' ) );
		self::assertSame( 'repository_source_unavailable', $registrar->failure_code( 'plugin', 'unavailable/unavailable.php' ) );
	}

	public function test_registrar_isolates_an_invalid_target(): void {
		$valid   = $this->package( 'plugin', 'valid/valid.php', 'valid', DeploymentPolicy::MANUAL );
		$invalid = $this->package( 'plugin', 'invalid/invalid.php', 'invalid', DeploymentPolicy::MANUAL, provider: 'bb' );
		$plugins = $this->createStub( PluginRepository::class );
		$plugins->method( 'all_deployment_plugins' )->willReturn(
			array(
				'valid/valid.php'     => $valid,
				'invalid/invalid.php' => $invalid,
			)
		);
		$themes = $this->createStub( ThemeRepository::class );
		$themes->method( 'all_deployment_themes' )->willReturn( array() );
		$store     = new RuntimeReleaseStore(
			array(
				"plugin\0valid/valid.php"     => new ManagedReleaseConfiguration( 'valid', 'valid.php' ),
				"plugin\0invalid/invalid.php" => new ManagedReleaseConfiguration( 'invalid', 'invalid.php' ),
			)
		);
		$registrar = $this->registrar(
			$plugins,
			$themes,
			$store,
			new RuntimeUpdaterLock(),
			$this->release_metadata_registry(
				target_factory: static fn ( mixed ...$options ): object => new RuntimeUpdaterFacade( $options )
			)
		);

		$registrar->register();

		self::assertNotNull( $registrar->target( 'plugin', 'valid/valid.php' ) );
		self::assertNull( $registrar->target( 'plugin', 'invalid/invalid.php' ) );
		self::assertSame( 'target_registration_failed', $registrar->failure_code( 'plugin', 'invalid/invalid.php' ) );
	}

	public function test_registrar_quarantines_nested_legacy_plugin_and_theme_and_suppresses_their_offers(): void {
		$plugin  = $this->package(
			'plugin',
			self::NATIVE_PLUGIN,
			'example',
			DeploymentPolicy::MANUAL,
			subdirectory: 'packages/example'
		);
		$theme   = $this->package(
			'theme',
			'example-theme',
			'example-theme',
			DeploymentPolicy::MANUAL,
			subdirectory: 'themes/example',
			repository_id: 'nested-theme-repository'
		);
		$plugins = $this->createStub( PluginRepository::class );
		$plugins->method( 'all_deployment_plugins' )->willReturn( array( self::NATIVE_PLUGIN => $plugin ) );
		$plugins->method( 'booster_plugin_from_file' )->willReturn( $plugin );
		$themes = $this->createStub( ThemeRepository::class );
		$themes->method( 'all_deployment_themes' )->willReturn( array( 'example-theme' => $theme ) );
		$themes->method( 'booster_theme_from_stylesheet' )->willReturn( $theme );
		$registrar = $this->registrar(
			$plugins,
			$themes,
			new RuntimeReleaseStore(
				array(
					"plugin\0" . self::NATIVE_PLUGIN => new ManagedReleaseConfiguration( 'example', 'example.php' ),
					"theme\0example-theme"           => new ManagedReleaseConfiguration( 'example-theme', 'style.css' ),
				)
			),
			new RuntimeUpdaterLock(),
			$this->release_metadata_registry()
		);

		$registrar->register();
		$plugin_offers = (object) array( 'response' => array( self::NATIVE_PLUGIN => (object) array() ) );
		$theme_offers  = (object) array( 'response' => array( 'example-theme' => (object) array() ) );

		self::assertNull( $registrar->target( 'plugin', self::NATIVE_PLUGIN ) );
		self::assertNull( $registrar->target( 'theme', 'example-theme' ) );
		self::assertSame( 'subdirectory_not_supported', $registrar->failure_code( 'plugin', self::NATIVE_PLUGIN ) );
		self::assertSame( 'subdirectory_not_supported', $registrar->failure_code( 'theme', 'example-theme' ) );
		self::assertArrayNotHasKey( self::NATIVE_PLUGIN, $registrar->suppress_unauthorized_plugin_offers( $plugin_offers )->response );
		self::assertArrayNotHasKey( 'example-theme', $registrar->suppress_unauthorized_theme_offers( $theme_offers )->response );
	}

	public function test_registrar_rejects_aprovider_target_that_returns_false(): void {
		$package = $this->package( 'plugin', self::NATIVE_PLUGIN, 'example', DeploymentPolicy::MANUAL );
		$plugins = $this->createStub( PluginRepository::class );
		$plugins->method( 'all_deployment_plugins' )->willReturn( array( self::NATIVE_PLUGIN => $package ) );
		$themes = $this->createStub( ThemeRepository::class );
		$themes->method( 'all_deployment_themes' )->willReturn( array() );
		$target = new RuntimeUpdaterFacade();
		$target->fail_registration();
		$registrar = $this->registrar(
			$plugins,
			$themes,
			new RuntimeReleaseStore(
				array( "plugin\0" . self::NATIVE_PLUGIN => new ManagedReleaseConfiguration( 'example', 'example.php' ) )
			),
			new RuntimeUpdaterLock(),
			$this->release_metadata_registry(
				// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- The release-runtime fixture callback retains the registered factory or provider callable signature while returning a controlled result.
				target_factory: static fn ( mixed ...$options ): RuntimeUpdaterFacade => $target
			)
		);
		$registrar->register();

		self::assertNull( $registrar->target( 'plugin', self::NATIVE_PLUGIN ) );
		self::assertSame( 'target_registration_failed', $registrar->failure_code( 'plugin', self::NATIVE_PLUGIN ) );
	}

	public function test_manual_native_update_holds_existing_lock_through_completion(): void {
		[ $registrar, $lock ] = $this->native_plugin_registrar(
			$this->package( 'plugin', self::NATIVE_PLUGIN, 'example', DeploymentPolicy::MANUAL )
		);
		$upgrader             = new \stdClass();

		self::assertFalse( $registrar->authorize_native_download( false, 'package.zip', $upgrader, self::NATIVE_EXTRA ) );
		self::assertFalse( $registrar->fence_native_mutation( false, self::NATIVE_EXTRA ) );
		self::assertSame( 1, $lock->acquires );

		$registrar->complete_native_mutation( $upgrader, self::NATIVE_EXTRA );

		self::assertSame( array( 'runtime-lock' ), $lock->releases );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_failed_manual_native_update_holds_lock_until_after_shutdown_restoration(): void {
		[ $registrar, $lock ] = $this->native_plugin_registrar(
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

		self::assertFalse( $registrar->authorize_native_download( false, 'package.zip', $upgrader, $extra ) );
		self::assertFalse( $registrar->fence_native_mutation( false, $extra ) );
		$registrar->complete_native_mutation( $upgrader, $extra );

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

	public function test_native_update_rejects_authority_change_and_releases_manual_lock(): void {
		$first                = $this->package( 'plugin', 'example/example.php', 'example', DeploymentPolicy::MANUAL );
		$changed              = $this->package( 'plugin', 'example/example.php', 'example', DeploymentPolicy::MANUAL, source_revision: 2 );
		$reads                = 0;
		[ $registrar, $lock ] = $this->native_plugin_registrar(
			$first,
			static function () use ( &$reads, $first, $changed ): Package {
				return 0 === $reads++ ? $first : $changed;
			}
		);
		$upgrader             = new \stdClass();

		self::assertFalse( $registrar->authorize_native_download( false, 'package.zip', $upgrader, self::NATIVE_EXTRA ) );
		$result = $registrar->fence_native_mutation( false, self::NATIVE_EXTRA );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'ran_booster_native_update_authority_changed', $result->get_error_code() );
		self::assertSame( array( 'runtime-lock' ), $lock->releases );
	}

	public function test_native_update_rejects_authority_changed_after_registration(): void {
		$registered           = $this->package( 'plugin', 'example/example.php', 'example', DeploymentPolicy::MANUAL );
		$changed              = $this->package( 'plugin', 'example/example.php', 'example', DeploymentPolicy::MANUAL, source_revision: 2 );
		[ $registrar, $lock ] = $this->native_plugin_registrar( $registered, $changed );

		$result = $registrar->authorize_native_download(
			false,
			'package.zip',
			new \stdClass(),
			self::NATIVE_EXTRA
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'ran_booster_native_update_authority_changed', $result->get_error_code() );
		self::assertSame( 0, $lock->acquires );
	}

	public function test_stale_managed_offers_fail_closed_when_target_registration_fails(): void {
		$plugin  = $this->package( 'plugin', self::NATIVE_PLUGIN, 'example', DeploymentPolicy::MANUAL );
		$theme   = $this->package( 'theme', 'example-theme', 'example-theme', DeploymentPolicy::MANUAL );
		$plugins = $this->createStub( PluginRepository::class );
		$plugins->method( 'all_deployment_plugins' )->willReturn( array( self::NATIVE_PLUGIN => $plugin ) );
		$plugins->method( 'booster_plugin_from_file' )->willReturn( $plugin );
		$themes = $this->createStub( ThemeRepository::class );
		$themes->method( 'all_deployment_themes' )->willReturn( array( 'example-theme' => $theme ) );
		$themes->method( 'booster_theme_from_stylesheet' )->willReturn( $theme );
		$registrar = $this->registrar(
			$plugins,
			$themes,
			new RuntimeReleaseStore(
				array(
					"plugin\0" . self::NATIVE_PLUGIN => new ManagedReleaseConfiguration( 'example', 'example.php' ),
					"theme\0example-theme"           => new ManagedReleaseConfiguration( 'example-theme', 'style.css' ),
				)
			),
			new RuntimeUpdaterLock(),
			$this->release_metadata_registry(
				target_factory: static function (): never {
					throw new \RuntimeException( 'target rejected' );
				}
			)
		);
		$registrar->register();
		$incoming = new \WP_Error( 'download_failed', 'Download already failed.' );
		self::assertSame(
			$incoming,
			$registrar->authorize_native_download( $incoming, 'package.zip', new \stdClass(), self::NATIVE_EXTRA )
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
			$this->assert_native_authority_error(
				$registrar->authorize_native_download( false, 'package.zip', new \stdClass(), $extra )
			);
			$this->assert_native_authority_error( $registrar->fence_native_mutation( false, $extra ) );
		}
	}

	public function test_stale_offer_fails_closed_after_package_returns_to_branch(): void {
		$release       = $this->package( 'plugin', self::NATIVE_PLUGIN, 'example', DeploymentPolicy::MANUAL );
		$branch        = $this->package(
			'plugin',
			self::NATIVE_PLUGIN,
			'example',
			DeploymentPolicy::MANUAL,
			source: PackageSource::BRANCH
		);
		[ $registrar ] = $this->native_plugin_registrar( $release, $branch );

		$this->assert_native_authority_error(
			$registrar->authorize_native_download( false, 'package.zip', new \stdClass(), self::NATIVE_EXTRA )
		);
		$this->assert_native_authority_error( $registrar->fence_native_mutation( false, self::NATIVE_EXTRA ) );
	}

	public function test_registered_release_target_fails_closed_when_abranch_companion_is_recorded(): void {
		$release       = $this->package( 'plugin', self::NATIVE_PLUGIN, 'example', DeploymentPolicy::MANUAL );
		[ $registrar ] = $this->native_plugin_registrar( $release );
		$this->package(
			'theme',
			'branch-theme',
			'branch-theme',
			DeploymentPolicy::MANUAL,
			source: PackageSource::BRANCH
		);

		$this->assert_native_authority_error(
			$registrar->authorize_native_download( false, 'package.zip', new \stdClass(), self::NATIVE_EXTRA )
		);
		$this->assert_native_authority_error( $registrar->fence_native_mutation( false, self::NATIVE_EXTRA ) );
	}

	public function test_branch_managed_target_never_enters_native_release_mutation_fence(): void {
		$branch               = $this->package(
			'plugin',
			self::NATIVE_PLUGIN,
			'example',
			DeploymentPolicy::MANUAL,
			source: PackageSource::BRANCH
		);
		[ $registrar, $lock ] = $this->native_plugin_registrar( $branch );
		$incoming             = '/tmp/branch-artifact.zip';

		self::assertSame(
			$incoming,
			$registrar->authorize_native_download( $incoming, $incoming, new \stdClass(), self::NATIVE_EXTRA )
		);
		self::assertSame( $incoming, $registrar->fence_native_mutation( $incoming, self::NATIVE_EXTRA ) );
		self::assertSame( 0, $lock->acquires );
		self::assertSame( array(), $lock->releases );
	}

	public function test_registered_target_fails_closed_when_its_management_row_disappears(): void {
		$release       = $this->package( 'plugin', self::NATIVE_PLUGIN, 'example', DeploymentPolicy::MANUAL );
		[ $registrar ] = $this->native_plugin_registrar(
			$release,
			static function (): Package {
				throw new PluginNotFound();
			}
		);

		$this->assert_native_authority_error(
			$registrar->authorize_native_download( false, 'package.zip', new \stdClass(), self::NATIVE_EXTRA )
		);
		$this->assert_native_authority_error( $registrar->fence_native_mutation( false, self::NATIVE_EXTRA ) );
	}

	public function test_unmanaged_plugin_and_theme_offers_remain_word_press_owned(): void {
		$plugins = $this->createStub( PluginRepository::class );
		$plugins->method( 'all_deployment_plugins' )->willReturn( array() );
		$plugins->method( 'booster_plugin_from_file' )->willThrowException( new PluginNotFound() );
		$themes = $this->createStub( ThemeRepository::class );
		$themes->method( 'all_deployment_themes' )->willReturn( array() );
		$themes->method( 'booster_theme_from_stylesheet' )->willThrowException( new ThemeNotFound() );
		$registrar = $this->registrar(
			$plugins,
			$themes,
			new RuntimeReleaseStore(),
			new RuntimeUpdaterLock(),
			$this->release_metadata_registry()
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
			self::assertFalse( $registrar->authorize_native_download( false, 'package.zip', new \stdClass(), $extra ) );
			self::assertFalse( $registrar->fence_native_mutation( false, $extra ) );
		}
	}

	public function test_managed_offer_fails_closed_when_repository_read_is_uncertain(): void {
		$plugins = $this->createStub( PluginRepository::class );
		$plugins->method( 'all_deployment_plugins' )->willReturn( array() );
		$plugins->method( 'booster_plugin_from_file' )->willThrowException( new \RuntimeException( 'read failed' ) );
		$registrar = $this->registrar(
			$plugins,
			$this->createStub( ThemeRepository::class ),
			new RuntimeReleaseStore(),
			new RuntimeUpdaterLock(),
			$this->release_metadata_registry()
		);
		$registrar->register();

		$this->assert_native_authority_error(
			$registrar->authorize_native_download( false, 'package.zip', new \stdClass(), self::NATIVE_EXTRA )
		);
		$this->assert_native_authority_error( $registrar->fence_native_mutation( false, self::NATIVE_EXTRA ) );
	}

	public function test_inactive_or_throwing_target_diagnostics_fence_native_update(): void {
		$package                  = $this->package( 'plugin', self::NATIVE_PLUGIN, 'example', DeploymentPolicy::MANUAL );
		[ $registrar, , $facade ] = $this->native_plugin_registrar( $package );
		$facade->replace_diagnostics( array( 'state' => 'inactive' ) );

		$this->assert_native_authority_error(
			$registrar->authorize_native_download( false, 'package.zip', new \stdClass(), self::NATIVE_EXTRA )
		);

		[ $registrar, , $facade ] = $this->native_plugin_registrar( $package );
		self::assertFalse(
			$registrar->authorize_native_download( false, 'package.zip', new \stdClass(), self::NATIVE_EXTRA )
		);
		$facade->replace_diagnostics( array( 'state' => 'inactive' ) );
		$this->assert_native_authority_error( $registrar->fence_native_mutation( false, self::NATIVE_EXTRA ) );

		[ $registrar, , $facade ] = $this->native_plugin_registrar( $package );
		$facade->fail_diagnostics();
		$this->assert_native_authority_error(
			$registrar->authorize_native_download( false, 'package.zip', new \stdClass(), self::NATIVE_EXTRA )
		);

		[ $registrar, , $facade ] = $this->native_plugin_registrar( $package );
		self::assertFalse(
			$registrar->authorize_native_download( false, 'package.zip', new \stdClass(), self::NATIVE_EXTRA )
		);
		$facade->fail_diagnostics();
		$this->assert_native_authority_error( $registrar->fence_native_mutation( false, self::NATIVE_EXTRA ) );
	}

	public function test_selected_target_with_unavailable_release_state_still_owns_its_offer(): void {
		$package                  = $this->package( 'plugin', self::NATIVE_PLUGIN, 'example', DeploymentPolicy::MANUAL );
		[ $registrar, , $facade ] = $this->native_plugin_registrar( $package );
		$facade->replace_diagnostics( array( 'state' => 'unavailable' ) );

		self::assertFalse(
			$registrar->authorize_native_download( false, 'package.zip', new \stdClass(), self::NATIVE_EXTRA )
		);
	}

	public function test_persisted_managed_offer_requires_an_active_exact_native_target(): void {
		$package                  = $this->package( 'plugin', self::NATIVE_PLUGIN, 'example', DeploymentPolicy::MANUAL );
		[ $registrar, , $target ] = $this->native_plugin_registrar( $package );
		$active                   = (object) array(
			'response'  => array( self::NATIVE_PLUGIN => (object) array() ),
			'no_update' => array( self::NATIVE_PLUGIN => (object) array() ),
		);
		self::assertArrayHasKey( self::NATIVE_PLUGIN, $registrar->suppress_unauthorized_plugin_offers( $active )->response );

		$target->replace_diagnostics( array( 'state' => 'inactive' ) );
		$inactive = (object) array(
			'response'  => array( self::NATIVE_PLUGIN => (object) array() ),
			'no_update' => array( self::NATIVE_PLUGIN => (object) array() ),
		);
		$filtered = $registrar->suppress_unauthorized_plugin_offers( $inactive );
		self::assertArrayNotHasKey( self::NATIVE_PLUGIN, $filtered->response );
		self::assertArrayHasKey( self::NATIVE_PLUGIN, $filtered->no_update );

		$target->fail_diagnostics();
		$throwing = (object) array( 'response' => array( self::NATIVE_PLUGIN => (object) array() ) );
		self::assertArrayNotHasKey( self::NATIVE_PLUGIN, $registrar->suppress_unauthorized_plugin_offers( $throwing )->response );
	}

	public function test_persisted_plugin_and_theme_offers_are_suppressed_without_native_capability(): void {
		$plugin  = $this->package( 'plugin', self::NATIVE_PLUGIN, 'example', DeploymentPolicy::MANUAL );
		$theme   = $this->package( 'theme', 'example-theme', 'example-theme', DeploymentPolicy::MANUAL );
		$plugins = $this->createStub( PluginRepository::class );
		$plugins->method( 'all_deployment_plugins' )->willReturn( array( self::NATIVE_PLUGIN => $plugin ) );
		$plugins->method( 'booster_plugin_from_file' )->willReturn( $plugin );
		$themes = $this->createStub( ThemeRepository::class );
		$themes->method( 'all_deployment_themes' )->willReturn( array( 'example-theme' => $theme ) );
		$themes->method( 'booster_theme_from_stylesheet' )->willReturn( $theme );
		$registrar = $this->registrar(
			$plugins,
			$themes,
			new RuntimeReleaseStore(
				array(
					"plugin\0" . self::NATIVE_PLUGIN => new ManagedReleaseConfiguration( 'example', 'example.php' ),
					"theme\0example-theme"           => new ManagedReleaseConfiguration( 'example-theme', 'style.css' ),
				)
			),
			new RuntimeUpdaterLock(),
			$this->metadata_only_registry()
		);
		$registrar->register();
		$plugin_offers = (object) array( 'response' => array( self::NATIVE_PLUGIN => (object) array() ) );
		$theme_offers  = (object) array( 'response' => array( 'example-theme' => (object) array() ) );
		self::assertArrayNotHasKey( self::NATIVE_PLUGIN, $registrar->suppress_unauthorized_plugin_offers( $plugin_offers )->response );
		self::assertArrayNotHasKey( 'example-theme', $registrar->suppress_unauthorized_theme_offers( $theme_offers )->response );
	}

	public function test_persisted_managed_offers_fail_closed_after_source_change_or_repository_failure(): void {
		$branch  = $this->package(
			'plugin',
			self::NATIVE_PLUGIN,
			'example',
			DeploymentPolicy::MANUAL,
			source: PackageSource::BRANCH
		);
		$plugins = $this->createStub( PluginRepository::class );
		$plugins->method( 'all_deployment_plugins' )->willReturn( array( self::NATIVE_PLUGIN => $branch ) );
		$themes = $this->createStub( ThemeRepository::class );
		$themes->method( 'all_deployment_themes' )->willReturn( array() );
		$registrar = $this->registrar(
			$plugins,
			$themes,
			new RuntimeReleaseStore(),
			new RuntimeUpdaterLock(),
			$this->release_metadata_registry()
		);
		$offers    = (object) array(
			'response'  => array(
				self::NATIVE_PLUGIN   => (object) array(),
				'unmanaged/other.php' => (object) array(),
			),
			'no_update' => array( self::NATIVE_PLUGIN => (object) array() ),
		);

		$filtered = $registrar->suppress_unauthorized_plugin_offers( $offers );
		self::assertArrayNotHasKey( self::NATIVE_PLUGIN, $filtered->response );
		self::assertArrayHasKey( 'unmanaged/other.php', $filtered->response );
		self::assertArrayHasKey( self::NATIVE_PLUGIN, $filtered->no_update );

		$unavailable = $this->createStub( PluginRepository::class );
		$unavailable->method( 'all_deployment_plugins' )->willThrowException( new \RuntimeException( 'read failed' ) );
		$registrar = $this->registrar(
			$unavailable,
			$themes,
			new RuntimeReleaseStore(),
			new RuntimeUpdaterLock(),
			$this->release_metadata_registry()
		);
		$offers    = (object) array(
			'response'  => array(
				self::NATIVE_PLUGIN   => (object) array(),
				'unmanaged/other.php' => (object) array(),
			),
			'no_update' => array( self::NATIVE_PLUGIN => (object) array() ),
		);

		$filtered = $registrar->suppress_unauthorized_plugin_offers( $offers );
		self::assertArrayHasKey( self::NATIVE_PLUGIN, $filtered->response );
		self::assertArrayHasKey( 'unmanaged/other.php', $filtered->response );
		self::assertArrayHasKey( self::NATIVE_PLUGIN, $filtered->no_update );
	}

	public function test_repository_failure_suppresses_only_targets_registered_by_core_this_request(): void {
		$package = $this->package( 'plugin', self::NATIVE_PLUGIN, 'example', DeploymentPolicy::MANUAL );
		$reads   = 0;
		$plugins = $this->createStub( PluginRepository::class );
		$plugins->method( 'all_deployment_plugins' )->willReturnCallback(
			static function () use ( &$reads, $package ): array {
				if ( 0 < $reads++ ) {
					throw new \RuntimeException( 'read failed' );
				}

				return array( self::NATIVE_PLUGIN => $package );
			}
		);
		$plugins->method( 'booster_plugin_from_file' )->willReturn( $package );
		$themes = $this->createStub( ThemeRepository::class );
		$themes->method( 'all_deployment_themes' )->willReturn( array() );
		$registrar = $this->registrar(
			$plugins,
			$themes,
			new RuntimeReleaseStore(
				array( "plugin\0" . self::NATIVE_PLUGIN => new ManagedReleaseConfiguration( 'example', 'example.php' ) )
			),
			new RuntimeUpdaterLock(),
			$this->release_metadata_registry()
		);
		$registrar->register();
		$offers = (object) array(
			'response' => array(
				self::NATIVE_PLUGIN   => (object) array(),
				'unmanaged/other.php' => (object) array(),
			),
		);

		$filtered = $registrar->suppress_unauthorized_plugin_offers( $offers );

		self::assertArrayNotHasKey( self::NATIVE_PLUGIN, $filtered->response );
		self::assertArrayHasKey( 'unmanaged/other.php', $filtered->response );
	}

	public function test_repository_failure_still_suppresses_aquarantined_legacy_release_offer(): void {
		$nested  = $this->package(
			'plugin',
			self::NATIVE_PLUGIN,
			'example',
			DeploymentPolicy::MANUAL,
			subdirectory: 'packages/example'
		);
		$reads   = 0;
		$plugins = $this->createStub( PluginRepository::class );
		$plugins->method( 'all_deployment_plugins' )->willReturnCallback(
			static function () use ( &$reads, $nested ): array {
				if ( 0 < $reads++ ) {
					throw new \RuntimeException( 'read failed' );
				}

				return array( self::NATIVE_PLUGIN => $nested );
			}
		);
		$themes = $this->createStub( ThemeRepository::class );
		$themes->method( 'all_deployment_themes' )->willReturn( array() );
		$registrar = $this->registrar(
			$plugins,
			$themes,
			new RuntimeReleaseStore(
				array( "plugin\0" . self::NATIVE_PLUGIN => new ManagedReleaseConfiguration( 'example', 'example.php' ) )
			),
			new RuntimeUpdaterLock(),
			$this->release_metadata_registry()
		);
		$registrar->register();
		$offers = (object) array(
			'response' => array(
				self::NATIVE_PLUGIN   => (object) array(),
				'unmanaged/other.php' => (object) array(),
			),
		);

		$filtered = $registrar->suppress_unauthorized_plugin_offers( $offers );

		self::assertSame( 'subdirectory_not_supported', $registrar->failure_code( 'plugin', self::NATIVE_PLUGIN ) );
		self::assertArrayNotHasKey( self::NATIVE_PLUGIN, $filtered->response );
		self::assertArrayHasKey( 'unmanaged/other.php', $filtered->response );
	}

	public function test_previously_registered_target_becoming_nested_loses_its_offer_and_native_mutation_authority(): void {
		$registered    = $this->package( 'plugin', self::NATIVE_PLUGIN, 'example', DeploymentPolicy::MANUAL );
		$nested        = $this->package(
			'plugin',
			self::NATIVE_PLUGIN,
			'example',
			DeploymentPolicy::MANUAL,
			subdirectory: 'packages/example'
		);
		$live          = $registered;
		[ $registrar ] = $this->native_plugin_registrar(
			$registered,
			static function () use ( &$live ): Package {
				return $live;
			}
		);
		$live          = $nested;
		$offers        = (object) array( 'response' => array( self::NATIVE_PLUGIN => (object) array() ) );

		self::assertArrayNotHasKey( self::NATIVE_PLUGIN, $registrar->suppress_unauthorized_plugin_offers( $offers )->response );
		$this->assert_native_authority_error(
			$registrar->authorize_native_download( false, 'package.zip', new \stdClass(), self::NATIVE_EXTRA )
		);
	}

	public function test_runtime_release_provider_default_target_accepts_the_named_contract_arguments(): void {
		$target = ( new RuntimeReleaseProvider() )->create_native_target(
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

	public function test_native_update_rechecks_wp_pusher_conflict_before_download_and_mutation(): void {
		$package              = $this->package( 'plugin', self::NATIVE_PLUGIN, 'example', DeploymentPolicy::MANUAL );
		[ $registrar, $lock ] = $this->native_plugin_registrar( $package );
		$GLOBALS['ran_booster_wp_pusher_active_plugins'] = array( 'wppusher/wppusher.php' );

		$blocked = $registrar->authorize_native_download( false, 'package.zip', new \stdClass(), self::NATIVE_EXTRA );
		self::assertInstanceOf( \WP_Error::class, $blocked );
		self::assertSame( 'ran_booster_native_update_authority_changed', $blocked->get_error_code() );
		self::assertSame( 0, $lock->acquires );

		unset( $GLOBALS['ran_booster_wp_pusher_active_plugins'] );
		[ $registrar, $lock ] = $this->native_plugin_registrar( $package );
		self::assertFalse( $registrar->authorize_native_download( false, 'package.zip', new \stdClass(), self::NATIVE_EXTRA ) );
		$GLOBALS['ran_booster_wp_pusher_active_plugins'] = array( 'wppusher/wppusher.php' );

		$blocked = $registrar->fence_native_mutation( false, self::NATIVE_EXTRA );
		self::assertInstanceOf( \WP_Error::class, $blocked );
		self::assertSame( 'ran_booster_native_update_authority_changed', $blocked->get_error_code() );
		self::assertSame( 0, $lock->acquires );
		self::assertSame( array(), $lock->releases );
	}

	public function test_automatic_native_update_requires_supported_action_and_fresh_outer_lock(): void {
		$package              = $this->package( 'plugin', 'example/example.php', 'example', DeploymentPolicy::AUTOMATIC );
		[ $registrar, $lock ] = $this->native_plugin_registrar( $package );
		$upgrader             = (object) array( 'skin' => new \Automatic_Upgrader_Skin() );

		$unsupported = $registrar->authorize_native_download( false, 'package.zip', $upgrader, self::NATIVE_EXTRA );
		self::assertInstanceOf( \WP_Error::class, $unsupported );
		self::assertSame( 'ran_booster_native_update_unsupported_context', $unsupported->get_error_code() );

		$GLOBALS['ran_booster_runtime_action'] = 'wp_maybe_auto_update';
		self::assertFalse( $registrar->authorize_native_download( false, 'package.zip', $upgrader, self::NATIVE_EXTRA ) );
		self::assertFalse( $registrar->fence_native_mutation( false, self::NATIVE_EXTRA ) );
		self::assertSame( 2, $lock->token_reads );
		self::assertSame( 0, $lock->acquires );
	}

	public function test_ajax_updater_skin_is_not_classified_as_an_automatic_update(): void {
		[ $registrar, $lock ] = $this->native_plugin_registrar(
			$this->package( 'plugin', self::NATIVE_PLUGIN, 'example', DeploymentPolicy::MANUAL )
		);
		$upgrader             = (object) array( 'skin' => new \WP_Ajax_Upgrader_Skin() );

		self::assertFalse( $registrar->authorize_native_download( false, 'package.zip', $upgrader, self::NATIVE_EXTRA ) );
		self::assertFalse( $registrar->fence_native_mutation( false, self::NATIVE_EXTRA ) );
		self::assertSame( 1, $lock->acquires );
	}

	public function test_managed_native_multi_target_and_malformed_bulk_updates_fail_closed(): void {
		$package       = $this->package( 'plugin', 'example/example.php', 'example', DeploymentPolicy::MANUAL );
		[ $registrar ] = $this->native_plugin_registrar( $package );

		foreach ( array( array( 2, 1 ), array( '1', 1 ), array( 1, null ) ) as $state ) {
			$result = $registrar->authorize_native_download(
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

	public function test_self_bulk_update_is_rejected_before_managed_lookup_and_manual_single_is_unaffected(): void {
		$plugins = $this->createMock( PluginRepository::class );
		$plugins->expects( self::never() )->method( 'booster_plugin_from_file' );
		$registrar = $this->registrar(
			$plugins,
			$this->createStub( ThemeRepository::class ),
			new RuntimeReleaseStore(),
			$lock  = new RuntimeUpdaterLock(),
			$this->release_metadata_registry(),
			bulk_forbidden_plugin_identifier: 'ran-booster.php'
		);

		$result = $registrar->authorize_native_download(
			false,
			'package.zip',
			(object) array(
				'bulk'           => true,
				'update_count'   => 1,
				'update_current' => 1,
			),
			array(
				'plugin' => 'ran-booster.php',
				'action' => 'update',
				'type'   => 'plugin',
			)
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'ran_booster_native_update_unsupported_context', $result->get_error_code() );
		self::assertSame( 0, $lock->token_reads );
		self::assertSame( 0, $lock->acquires );

		$package = $this->package( 'plugin', 'ran-booster.php', 'ran-booster', DeploymentPolicy::MANUAL );
		$plugins = $this->createMock( PluginRepository::class );
		$plugins->expects( self::once() )->method( 'booster_plugin_from_file' )->with( 'ran-booster.php' )->willReturn( $package );
		$registrar = $this->registrar(
			$plugins,
			$this->createStub( ThemeRepository::class ),
			new RuntimeReleaseStore(),
			new RuntimeUpdaterLock(),
			$this->release_metadata_registry(),
			bulk_forbidden_plugin_identifier: 'ran-booster.php'
		);

		$result = $registrar->authorize_native_download(
			false,
			'package.zip',
			new \stdClass(),
			array(
				'plugin' => 'ran-booster.php',
				'action' => 'update',
				'type'   => 'plugin',
			)
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'ran_booster_native_update_authority_changed', $result->get_error_code() );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_one_target_word_press_bulk_update_uses_manual_fence_and_defers_failed_restore_release(): void {
		$package              = $this->package( 'plugin', 'example/example.php', 'example', DeploymentPolicy::MANUAL );
		[ $registrar, $lock ] = $this->native_plugin_registrar( $package );
		$upgrader             = (object) array(
			'bulk'           => true,
			'update_count'   => 1,
			'update_current' => 1,
			'result'         => array(),
			'skin'           => (object) array(
				'result' => new \WP_Error( 'install_failed', 'failed' ),
			),
		);
		$item_extra           = array(
			'plugin'      => self::NATIVE_PLUGIN,
			'temp_backup' => array( 'slug' => 'example' ),
		);

		self::assertFalse( $registrar->authorize_native_download( false, 'package.zip', $upgrader, $item_extra ) );
		self::assertFalse( $registrar->fence_native_mutation( false, $item_extra ) );
		self::assertSame( 1, $lock->acquires );

		$registrar->complete_native_mutation(
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

	public function test_facade_enables_with_exact_nonce_revision_and_preserves_policy_through_store(): void {
		$package = $this->package(
			'plugin',
			'example/example.php',
			'example',
			DeploymentPolicy::MANUAL,
			source: PackageSource::BRANCH
		);
		$plugins = $this->createStub( PluginRepository::class );
		$plugins->method( 'booster_plugin_from_file' )->willReturn( $package );
		$themes    = $this->createStub( ThemeRepository::class );
		$store     = new RuntimeReleaseStore();
		$registrar = $this->registrar(
			$plugins,
			$themes,
			$store,
			new RuntimeUpdaterLock(),
			$this->release_metadata_registry()
		);

		$expected_action = 'ran-booster-release-tracking-enable-plugin-example/example.php-1';

		$lock   = new RuntimeUpdaterLock();
		$facade = $this->facade(
			$plugins,
			$themes,
			$store,
			$registrar,
			$lock,
			$this->release_metadata_registry(),
			static fn ( string $type ): bool => 'plugin' === $type,
			static fn ( string $nonce, string $action ): bool => 'valid' === $nonce && $expected_action === $action,
			metadata_eligible: static fn (): bool => true,
			invalidate_native: static function (): void {
			}
		);

		self::assertSame(
			$expected_action,
			$facade->nonce_action( 'enable', 'plugin', 'example/example.php', 1 )
		);
		$result = $facade->enable( 'plugin', 'example/example.php', 1, 'prerelease', 'valid' );

		self::assertTrue( $result->successful() );
		self::assertSame( 'release_enabled', $result->code() );
		self::assertCount( 1, $store->transitions );
		self::assertSame( PackageSource::BRANCH, $store->transitions[0]['expected_source'] );
		self::assertSame( PackageSource::RELEASE_ASSET, $store->transitions[0]['new_source'] );
		self::assertSame( 'example', $store->transitions[0]['configuration']->package_root() );
		self::assertSame( 'example.php', $store->transitions[0]['configuration']->metadata_file() );
		self::assertSame( 'prerelease', $store->transitions[0]['configuration']->channel() );
		self::assertSame( 1, $lock->acquires );
		self::assertSame( array( 'runtime-lock' ), $lock->releases );

		$store->transition_failure = new ManagedReleaseSubdirectoryNotSupported();
		$rejected                  = $facade->enable( 'plugin', 'example/example.php', 1, 'stable', 'valid' );
		self::assertFalse( $rejected->successful() );
		self::assertSame( 'subdirectory_not_supported', $rejected->code() );
		self::assertCount( 1, $store->transitions );
		$store->transition_failure = null;

		$denied = $facade->enable( 'plugin', 'example/example.php', 1, 'prerelease', 'wrong' );
		self::assertFalse( $denied->successful() );
		self::assertSame( 'forbidden', $denied->code() );
		self::assertCount( 1, $store->transitions );

		$lock->acquire_fails = true;
		$contended           = $facade->enable( 'plugin', 'example/example.php', 1, 'prerelease', 'valid' );
		self::assertFalse( $contended->successful() );
		self::assertSame( 'release_unavailable', $contended->code() );
		self::assertCount( 1, $store->transitions );
		self::assertSame( 3, $lock->acquires );
		self::assertSame( array( 'runtime-lock', 'runtime-lock' ), $lock->releases );
	}

	public function test_read_only_preflight_binds_target_revision_and_channel_without_mutation(): void {
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
			source: PackageSource::BRANCH,
			repository_id: 'theme-preflight-repository'
		);
		$plugins = $this->createStub( PluginRepository::class );
		$plugins->method( 'booster_plugin_from_file' )->willReturnCallback(
			static function () use ( &$plugin ) {
				return $plugin;
			}
		);
		$themes = $this->createStub( ThemeRepository::class );
		$themes->method( 'booster_theme_from_stylesheet' )->willReturn( $theme );
		$store         = new RuntimeReleaseStore();
		$lock          = new RuntimeUpdaterLock();
		$listings      = array();
		$inspections   = array();
		$invalidations = array();
		$allowed       = true;
		$providers     = $this->release_metadata_registry(
			// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- The release-runtime fixture callback retains the registered factory or provider callable signature while returning a controlled result.
			list_releases: static function ( string $type, RepositoryReference $repository, string $channel ) use ( &$listings ): RepositoryReleaseCandidateList {
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
			inspect: static function ( string $type, RepositoryReference $repository, string $release_id, string $tag, string $channel ) use ( &$inspections ): RepositoryReleaseInspection {
				$inspections[] = array(
					'type'       => $type,
					'repository' => $repository,
					'releaseId'  => $release_id,
					'tag'        => $tag,
					'channel'    => $channel,
				);
				if ( '101' === $release_id ) {
					throw RepositoryReleaseInspectionRejected::incompatible();
				}

				return new RepositoryReleaseInspection(
					$release_id,
					$tag,
					'2.0.0',
					str_repeat( 'a', 40 ),
					'plugin' === $type ? 'example' : 'example-theme',
					'plugin' === $type ? 'example.php' : 'style.css',
					'v1:' . str_repeat( 'b', 64 )
				);
			}
		);
		$facade = $this->facade(
			$plugins,
			$themes,
			$store,
			$this->registrar(
				$plugins,
				$themes,
				$store,
				$lock,
				$this->release_metadata_registry()
			),
			$lock,
			$providers,
			static function () use ( &$allowed ): bool {
				return $allowed;
			},
			static fn ( string $nonce, string $action ): bool => hash_equals( $action, $nonce ),
			metadata_eligible: static fn (): bool => true,
			invalidate_native: static function ( string $type ) use ( &$invalidations ): void {
				$invalidations[] = $type;
			}
		);

		$plugin_nonce = $facade->nonce_action( 'preflight', 'plugin', 'example/example.php', 1, 'prerelease' );
		$theme_nonce  = $facade->nonce_action( 'preflight', 'theme', 'example-theme', 1, 'stable' );
		self::assertSame( 'ran-booster-release-tracking-preflight-plugin-example/example.php-1-prerelease', $plugin_nonce );
		self::assertTrue( $facade->preflight( 'plugin', 'example/example.php', 1, 'prerelease', $plugin_nonce )?->ready() );
		self::assertTrue( $facade->preflight( 'theme', 'example-theme', 1, 'stable', $theme_nonce )?->ready() );

		$stale_nonce = $facade->nonce_action( 'preflight', 'plugin', 'example/example.php', 2, 'stable' );
		self::assertNull( $facade->preflight( 'plugin', 'example/example.php', 2, 'stable', $stale_nonce ) );
		self::assertNull( $facade->preflight( 'plugin', 'example/example.php', 1, 'stable', $plugin_nonce ) );
		self::assertNull( $facade->preflight( 'plugin', 'example/example.php', 1, 'preview', 'nonce' ) );
		$allowed = false;
		self::assertNull( $facade->preflight( 'plugin', 'example/example.php', 1, 'prerelease', $plugin_nonce ) );
		$allowed = true;

		$plugin           = $this->package(
			'plugin',
			'example/example.php',
			'example',
			DeploymentPolicy::MANUAL,
			source: PackageSource::RELEASE_ASSET
		);
		$assessment_nonce = $facade->nonce_action( 'assessment_preflight', 'plugin', 'example/example.php', 1, 'stable' );
		self::assertNull( $facade->preflight( 'plugin', 'example/example.php', 1, 'stable', $plugin_nonce ) );
		self::assertTrue( $facade->assessment_preflight( 'plugin', 'example/example.php', 1, 'stable', $assessment_nonce )?->ready() );
		self::assertNull( $facade->assessment_preflight( 'plugin', 'example/example.php', 2, 'stable', $facade->nonce_action( 'assessment_preflight', 'plugin', 'example/example.php', 2, 'stable' ) ) );
		self::assertNull( $facade->assessment_preflight( 'plugin', 'example/example.php', 1, 'stable', $plugin_nonce ) );

		self::assertSame( array( 'plugin', 'theme', 'plugin' ), array_column( $listings, 'type' ) );
		self::assertSame( array( 'prerelease', 'stable', 'stable' ), array_column( $listings, 'channel' ) );
		self::assertSame( array( '101', '102', '201', '101', '102' ), array_column( $inspections, 'releaseId' ) );
		self::assertSame( array( 'prerelease', 'prerelease', 'stable', 'stable', 'stable' ), array_column( $inspections, 'channel' ) );
		self::assertSame( array(), $store->transitions );
		self::assertSame( array(), $invalidations );
		self::assertSame( 0, $lock->acquires );
	}

	public function test_enable_refuses_arepository_already_owned_by_another_branch_package(): void {
		$root    = $this->package(
			'plugin',
			'example/example.php',
			'example',
			DeploymentPolicy::MANUAL,
			source: PackageSource::BRANCH
		);
		$nested  = $this->package(
			'plugin',
			'other/other.php',
			'other',
			DeploymentPolicy::MANUAL,
			source: PackageSource::BRANCH,
			subdirectory: 'packages/other'
		);
		$plugins = $this->createStub( PluginRepository::class );
		$plugins->method( 'booster_plugin_from_file' )->willReturn( $root );
		$plugins->method( 'all_deployment_plugins' )->willReturn(
			array(
				'example/example.php' => $root,
				'other/other.php'     => $nested,
			)
		);
		$themes = $this->createStub( ThemeRepository::class );
		$themes->method( 'all_deployment_themes' )->willReturn( array() );
		$store          = new RuntimeReleaseStore();
		$lock           = new RuntimeUpdaterLock();
		$provider_calls = 0;
		$providers      = $this->release_metadata_registry(
			list_releases: static function () use ( &$provider_calls ): never {
				++$provider_calls;
				throw new \RuntimeException( 'Provider preflight must not run for a repository conflict.' );
			}
		);
		$facade         = $this->facade(
			$plugins,
			$themes,
			$store,
			$this->registrar( $plugins, $themes, $store, $lock, $providers ),
			$lock,
			$providers,
			static fn (): bool => true,
			static fn (): bool => true,
			metadata_eligible: static fn (): bool => true
		);

		$result = $facade->enable( 'plugin', 'example/example.php', 1, 'stable', 'valid' );
		$status = $facade->status( 'plugin', 'example/example.php' );

		self::assertFalse( $result->successful() );
		self::assertSame( 'release_repository_conflict', $result->code() );
		self::assertSame( 'release_repository_conflict', $status->failure_code() );
		self::assertSame( 0, $provider_calls );
		self::assertSame( array(), $store->transitions );
		self::assertSame( 0, $lock->acquires );
		self::assertSame( PackageSource::BRANCH, $root->get_source() );
		self::assertSame( 1, $root->get_source_revision() );
	}

	public function test_enable_treats_missing_repository_identity_and_guard_read_failure_as_unavailable(): void {
		foreach ( array( 'missing identity', 'unavailable guard' ) as $case ) {
			$package = $this->package(
				'plugin',
				'example/example.php',
				'example',
				DeploymentPolicy::MANUAL,
				source: PackageSource::BRANCH,
				provider_repository_id: 'missing identity' === $case ? '' : null
			);
			if ( 'unavailable guard' === $case ) {
				$this->repository_rows["plugin\0example/example.php"]->source = 'unknown';
			}
			$plugins = $this->createStub( PluginRepository::class );
			$plugins->method( 'booster_plugin_from_file' )->willReturn( $package );
			$themes = $this->createStub( ThemeRepository::class );
			$store  = new RuntimeReleaseStore();
			$lock   = new RuntimeUpdaterLock();
			$facade = $this->facade(
				$plugins,
				$themes,
				$store,
				$this->registrar( $plugins, $themes, $store, $lock, $this->release_metadata_registry() ),
				$lock,
				$this->release_metadata_registry(),
				static fn (): bool => true,
				static fn (): bool => true,
				metadata_eligible: static fn (): bool => true
			);

			$result = $facade->enable( 'plugin', 'example/example.php', 1, 'stable', 'valid' );
			self::assertSame( 'release_unavailable', $result->code(), $case );
			if ( 'unavailable guard' === $case ) {
				self::assertSame( 'release_unavailable', $facade->status( 'plugin', 'example/example.php' )->failure_code(), $case );
			}
			self::assertSame( array(), $store->transitions, $case );
			self::assertSame( 0, $lock->acquires, $case );
		}
	}

	public function test_enable_reports_aconflict_that_appears_under_the_updater_lock(): void {
		$root    = $this->package(
			'plugin',
			'example/example.php',
			'example',
			DeploymentPolicy::MANUAL,
			source: PackageSource::BRANCH
		);
		$reads   = 0;
		$plugins = $this->createStub( PluginRepository::class );
		$plugins->method( 'booster_plugin_from_file' )->willReturnCallback(
			function () use ( $root, &$reads ): Package {
				if ( 1 === ++$reads ) {
					return $root;
				}
				$this->repository_rows["plugin\0other/other.php"] = (object) array(
					'type'                   => '1',
					'package'                => 'other/other.php',
					'source'                 => PackageSource::BRANCH->value,
					'provider'               => 'gh',
					'provider_repository_id' => '123456789',
				);

				return $root;
			}
		);
		$themes    = $this->createStub( ThemeRepository::class );
		$store     = new RuntimeReleaseStore();
		$lock      = new RuntimeUpdaterLock();
		$providers = $this->release_metadata_registry();
		$facade    = $this->facade(
			$plugins,
			$themes,
			$store,
			$this->registrar( $plugins, $themes, $store, $lock, $providers ),
			$lock,
			$providers,
			static fn (): bool => true,
			static fn (): bool => true,
			metadata_eligible: static fn (): bool => true
		);

		$result = $facade->enable( 'plugin', 'example/example.php', 1, 'stable', 'valid' );

		self::assertSame( 'release_repository_conflict', $result->code() );
		self::assertSame( array(), $store->transitions );
		self::assertSame( 1, $lock->acquires );
		self::assertSame( PackageSource::BRANCH, $root->get_source() );
		self::assertSame( 1, $root->get_source_revision() );
	}

	public function test_managed_release_reads_use_the_configured_public_lookup_profile_without_replacing_package_credentials(): void {
		$packages = array(
			'public'         => $this->package( 'plugin', 'example/example.php', 'example', DeploymentPolicy::MANUAL ),
			'explicit'       => $this->package( 'plugin', 'example/example.php', 'example', DeploymentPolicy::MANUAL, credential_id: 'package-profile' ),
			'private'        => $this->package( 'plugin', 'example/example.php', 'example', DeploymentPolicy::MANUAL, is_private: true, credential_id: 'private-profile' ),
			'private_lookup' => $this->package( 'plugin', 'example/example.php', 'example', DeploymentPolicy::MANUAL, is_private: true ),
		);
		$current  = 'public';
		$plugins  = $this->createStub( PluginRepository::class );
		$plugins->method( 'booster_plugin_from_file' )->willReturnCallback(
			static function () use ( &$packages, &$current ): Package {
				return $packages[ $current ];
			}
		);
		$themes             = $this->createStub( ThemeRepository::class );
		$store              = new RuntimeReleaseStore(
			array( "plugin\0example/example.php" => new ManagedReleaseConfiguration( 'example', 'example.php' ) )
		);
		$lock               = new RuntimeUpdaterLock();
		$profiles           = new InMemoryPublicRepositoryLookupProfileStore();
		$profiles->profiles = array( 'gh' => 'public-profile' );
		$references         = array();
		$failure_mode       = 'typed';
		$providers          = $this->release_metadata_registry(
			// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed,Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- The release-runtime fixture callback retains the registered factory or provider callable signature while returning a controlled result.
			list_releases: static function ( string $type, RepositoryReference $repository, string $channel ) use ( &$references, &$failure_mode ): RepositoryReleaseCandidateList {
				$references[] = $repository;
				if ( in_array( $repository->credential_id, array( 'package-profile', 'private-profile' ), true ) ) {
					if ( 'typed' === $failure_mode ) {
						throw new RepositoryReleaseReadUnavailable( 'Fixture access failure.' );
					}
					if ( 'domain' === $failure_mode ) {
						throw new \RuntimeException( 'Fixture domain failure.' );
					}
				}

				return new RepositoryReleaseCandidateList( array() );
			},
			// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed,Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- The release-runtime fixture callback retains the registered factory or provider callable signature while returning a controlled result.
			inspect: static function ( string $type, RepositoryReference $repository, string $release_id, string $tag, string $channel ) use ( &$references, &$failure_mode ): RepositoryReleaseInspection {
				$references[] = $repository;
				if ( in_array( $repository->credential_id, array( 'package-profile', 'private-profile' ), true ) ) {
					if ( 'typed' === $failure_mode ) {
						throw new RepositoryReleaseReadUnavailable( 'Fixture access failure.' );
					}
					if ( 'domain' === $failure_mode ) {
						throw new \RuntimeException( 'Fixture domain failure.' );
					}
				}

				return new RepositoryReleaseInspection( $release_id, $tag, '2.0.0', str_repeat( 'a', 40 ), 'example', 'example.php', 'v1:' . str_repeat( 'b', 64 ) );
			}
		);
		$facade = $this->facade(
			$plugins,
			$themes,
			$store,
			$this->registrar( $plugins, $themes, $store, $lock, $providers ),
			$lock,
			$providers,
			static fn (): bool => true,
			static fn ( string $nonce, string $action ): bool => hash_equals( $action, $nonce ),
			metadata_eligible: static fn (): bool => true,
			public_lookup_profile: static fn ( string $provider ): ?string => $profiles->get( $provider )
		);

		foreach ( array( 'public', 'explicit' ) as $current ) {
			$nonce = $facade->nonce_action( 'list_candidates', 'plugin', 'example/example.php', 1, 'stable' );
			self::assertInstanceOf( RepositoryReleaseCandidateList::class, $facade->list_candidates( 'plugin', 'example/example.php', 1, 'stable', $nonce ) );
			$nonce = $facade->nonce_action( 'inspect_candidate', 'plugin', 'example/example.php', 1, 'stable' );
			self::assertTrue( $facade->inspect_candidate( 'plugin', 'example/example.php', 1, '101', 'v2.0.0', 'stable', $nonce )?->ready() );
		}

		self::assertSame( array( 'public-profile', 'public-profile', 'package-profile', 'public-profile', 'package-profile', 'public-profile' ), array_map( static fn ( RepositoryReference $reference ): ?string => $reference->credential_id, $references ) );
		self::assertSame( array( false, false, false, false, false, false ), array_map( static fn ( RepositoryReference $reference ): bool => $reference->private, $references ) );

		$current    = 'private';
		$references = array();
		$nonce      = $facade->nonce_action( 'list_candidates', 'plugin', 'example/example.php', 1, 'stable' );
		self::assertNull( $facade->list_candidates( 'plugin', 'example/example.php', 1, 'stable', $nonce ) );
		$nonce = $facade->nonce_action( 'inspect_candidate', 'plugin', 'example/example.php', 1, 'stable' );
		self::assertNull( $facade->inspect_candidate( 'plugin', 'example/example.php', 1, '101', 'v2.0.0', 'stable', $nonce ) );
		self::assertSame( array( 'private-profile', 'private-profile' ), array_map( static fn ( RepositoryReference $reference ): ?string => $reference->credential_id, $references ) );
		self::assertSame( array( true, true ), array_map( static fn ( RepositoryReference $reference ): bool => $reference->private, $references ) );

		$failure_mode = 'none';
		$current      = 'explicit';
		$references   = array();
		$nonce        = $facade->nonce_action( 'list_candidates', 'plugin', 'example/example.php', 1, 'stable' );
		self::assertInstanceOf( RepositoryReleaseCandidateList::class, $facade->list_candidates( 'plugin', 'example/example.php', 1, 'stable', $nonce ) );
		$nonce = $facade->nonce_action( 'inspect_candidate', 'plugin', 'example/example.php', 1, 'stable' );
		self::assertTrue( $facade->inspect_candidate( 'plugin', 'example/example.php', 1, '101', 'v2.0.0', 'stable', $nonce )?->ready() );
		self::assertSame( array( 'package-profile', 'package-profile' ), array_map( static fn ( RepositoryReference $reference ): ?string => $reference->credential_id, $references ) );

		$failure_mode = 'domain';
		$references   = array();
		$nonce        = $facade->nonce_action( 'list_candidates', 'plugin', 'example/example.php', 1, 'stable' );
		self::assertNull( $facade->list_candidates( 'plugin', 'example/example.php', 1, 'stable', $nonce ) );
		self::assertSame( array( 'package-profile' ), array_map( static fn ( RepositoryReference $reference ): ?string => $reference->credential_id, $references ) );

		$failure_mode = 'none';
		$current      = 'private_lookup';
		$references   = array();
		$nonce        = $facade->nonce_action( 'list_candidates', 'plugin', 'example/example.php', 1, 'stable' );
		self::assertNull( $facade->list_candidates( 'plugin', 'example/example.php', 1, 'stable', $nonce ) );
		$nonce = $facade->nonce_action( 'inspect_candidate', 'plugin', 'example/example.php', 1, 'stable' );
		self::assertNull( $facade->inspect_candidate( 'plugin', 'example/example.php', 1, '101', 'v2.0.0', 'stable', $nonce ) );
		self::assertSame( array(), $references );
		self::assertNull( $packages['private_lookup']->get_repository()->reference->credential_id );

		$packages['branch_public'] = $this->package(
			'plugin',
			'example/example.php',
			'example',
			DeploymentPolicy::MANUAL,
			source: PackageSource::BRANCH
		);
		$current                   = 'branch_public';
		$profiles->profiles        = array( 'gh' => 'public-profile' );
		$references                = array();
		$nonce                     = $facade->nonce_action( 'preflight', 'plugin', 'example/example.php', 1, 'stable' );
		$preflight                 = $facade->preflight( 'plugin', 'example/example.php', 1, 'stable', $nonce );
		self::assertSame( ReleaseTrackingPreflight::RELEASE_UNAVAILABLE, $preflight?->code() );
		self::assertSame( array( 'public-profile' ), array_map( static fn ( RepositoryReference $reference ): ?string => $reference->credential_id, $references ) );
		self::assertNull( $packages['branch_public']->get_repository()->reference->credential_id );

		$packages['branch_explicit'] = $this->package(
			'plugin',
			'example/example.php',
			'example',
			DeploymentPolicy::MANUAL,
			credential_id: 'package-profile',
			source: PackageSource::BRANCH
		);
		$current                     = 'branch_explicit';
		$references                  = array();
		$failure_mode                = 'typed';
		$nonce                       = $facade->nonce_action( 'preflight', 'plugin', 'example/example.php', 1, 'stable' );
		$preflight                   = $facade->preflight( 'plugin', 'example/example.php', 1, 'stable', $nonce );
		self::assertSame( ReleaseTrackingPreflight::RELEASE_UNAVAILABLE, $preflight?->code() );
		self::assertSame( array( 'package-profile', 'public-profile' ), array_map( static fn ( RepositoryReference $reference ): ?string => $reference->credential_id, $references ) );
		self::assertSame( 'package-profile', $packages['branch_explicit']->get_repository()->reference->credential_id );
	}

	public function test_private_enable_preflight_uses_only_the_saved_package_credential(): void {
		$package = $this->package(
			'plugin',
			'example/example.php',
			'example',
			DeploymentPolicy::MANUAL,
			is_private: true,
			credential_id: 'package-profile',
			source: PackageSource::BRANCH
		);
		$plugins = $this->createStub( PluginRepository::class );
		$plugins->method( 'booster_plugin_from_file' )->willReturn( $package );
		$themes     = $this->createStub( ThemeRepository::class );
		$store      = new RuntimeReleaseStore();
		$lock       = new RuntimeUpdaterLock();
		$references = array();
		$providers  = $this->release_metadata_registry(
			list_releases: static function ( string $type, RepositoryReference $repository, string $channel ) use ( &$references ): RepositoryReleaseCandidateList {
				unset( $type, $channel );
				$references[] = $repository;
				if ( 'package-profile' === $repository->credential_id ) {
					throw new RepositoryReleaseReadUnavailable( 'The package credential cannot read this repository.' );
				}

				return new RepositoryReleaseCandidateList( array() );
			}
		);
		$facade     = $this->facade(
			$plugins,
			$themes,
			$store,
			new ManagedReleaseTargetRegistrar( $plugins, $themes, $store, $lock, $providers ),
			$lock,
			$providers,
			static fn (): bool => true,
			static fn ( string $nonce, string $action ): bool => hash_equals( $action, $nonce ),
			metadata_eligible: static fn (): bool => true,
			public_lookup_profile: static fn (): string => 'public-profile'
		);

		$preflight_nonce = $facade->nonce_action( 'preflight', 'plugin', 'example/example.php', 1, 'stable' );
		$preflight       = $facade->preflight( 'plugin', 'example/example.php', 1, 'stable', $preflight_nonce );
		self::assertSame( ReleaseTrackingPreflight::PREFLIGHT_UNAVAILABLE, $preflight?->code() );
		self::assertSame(
			array( 'package-profile' ),
			array_map( static fn ( RepositoryReference $reference ): ?string => $reference->credential_id, $references )
		);

		$references   = array();
		$enable_nonce = $facade->nonce_action( 'enable', 'plugin', 'example/example.php', 1 );
		$result       = $facade->enable( 'plugin', 'example/example.php', 1, 'stable', $enable_nonce );

		self::assertFalse( $result->successful() );
		self::assertSame( ReleaseTrackingPreflight::PREFLIGHT_UNAVAILABLE, $result->code() );
		self::assertSame(
			array( 'package-profile' ),
			array_map( static fn ( RepositoryReference $reference ): ?string => $reference->credential_id, $references )
		);
		self::assertSame( array(), $store->transitions );
	}

	public function test_provider_preflight_fails_closed_across_identity_channel_and_operational_boundaries(): void {
		$package = $this->package(
			'plugin',
			'example/example.php',
			'example',
			DeploymentPolicy::MANUAL,
			source: PackageSource::BRANCH
		);
		$plugins = $this->createStub( PluginRepository::class );
		$plugins->method( 'booster_plugin_from_file' )->willReturn( $package );
		$themes           = $this->createStub( ThemeRepository::class );
		$store            = new RuntimeReleaseStore();
		$lock             = new RuntimeUpdaterLock();
		$mode             = 'release_id';
		$inspection_calls = 0;
		$providers        = $this->release_metadata_registry(
			list_releases: static function () use ( &$mode ): RepositoryReleaseCandidateList {
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
			inspect: static function ( string $type, RepositoryReference $repository, string $release_id, string $tag, string $channel ) use ( &$mode, &$inspection_calls ): RepositoryReleaseInspection {
				++$inspection_calls;
				unset( $type, $repository, $channel );
				if ( 'incompatible_budget' === $mode ) {
					throw RepositoryReleaseInspectionRejected::incompatible();
				}

				return new RepositoryReleaseInspection(
					'release_id' === $mode ? '999' : $release_id,
					'tag' === $mode ? 'v2.0.1' : $tag,
					'version' === $mode ? '2.0.1' : ( 'hyphenated_stable' === $mode ? '2026-08' : '2.0.0' ),
					str_repeat( 'a', 40 ),
					'package_root' === $mode ? 'other' : 'example',
					'main_file' === $mode ? 'other.php' : 'example.php',
					'v1:' . str_repeat( 'b', 64 )
				);
			}
		);
		$facade           = $this->facade(
			$plugins,
			$themes,
			$store,
			$this->registrar(
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
			metadata_eligible: static fn (): bool => true
		);
		$nonce            = $facade->nonce_action( 'preflight', 'plugin', 'example/example.php', 1, 'stable' );

		foreach ( array( 'release_id', 'tag', 'version', 'package_root', 'main_file' ) as $mode ) {
			$result = $facade->preflight( 'plugin', 'example/example.php', 1, 'stable', $nonce );
			self::assertSame( ReleaseTrackingPreflight::INVALID_RELEASE_ASSETS, $result?->code(), $mode );
			self::assertSame( 'release_identity_mismatch', $result?->reason_code(), $mode );
		}

		$mode   = 'channel';
		$result = $facade->preflight( 'plugin', 'example/example.php', 1, 'stable', $nonce );
		self::assertSame( ReleaseTrackingPreflight::INVALID_RELEASE_ASSETS, $result?->code() );
		self::assertSame( 'invalid_release', $result?->reason_code() );

		$mode   = 'none';
		$result = $facade->preflight( 'plugin', 'example/example.php', 1, 'stable', $nonce );
		self::assertSame( ReleaseTrackingPreflight::RELEASE_UNAVAILABLE, $result?->code() );
		self::assertSame( 'no_releases', $result?->reason_code() );

		$mode   = 'operational';
		$result = $facade->preflight( 'plugin', 'example/example.php', 1, 'stable', $nonce );
		self::assertSame( ReleaseTrackingPreflight::PREFLIGHT_UNAVAILABLE, $result?->code() );
		self::assertSame( 'provider_unavailable', $result?->reason_code() );
		self::assertStringNotContainsString( 'token', $result?->reason_code() ?? '' );

		$mode   = 'hyphenated_stable';
		$result = $facade->preflight( 'plugin', 'example/example.php', 1, 'stable', $nonce );
		self::assertSame( ReleaseTrackingPreflight::READY, $result?->code() );
		self::assertSame( '2026-08', $result?->latest_version() );

		$calls_before_budget = $inspection_calls;
		$mode                = 'incompatible_budget';
		$result              = $facade->preflight( 'plugin', 'example/example.php', 1, 'stable', $nonce );
		self::assertSame( ReleaseTrackingPreflight::RELEASE_UNAVAILABLE, $result?->code() );
		self::assertSame( 'release_incompatible', $result?->reason_code() );
		self::assertSame( 2, $inspection_calls - $calls_before_budget );
		self::assertSame( array(), $store->transitions );
		self::assertSame( 0, $lock->acquires );
	}

	public function test_provider_preflight_requires_the_complete_read_facet_set_before_remote_work(): void {
		$package = $this->package(
			'plugin',
			'example/example.php',
			'example',
			DeploymentPolicy::MANUAL,
			provider: 'partial',
			source: PackageSource::BRANCH
		);
		$plugins = $this->createStub( PluginRepository::class );
		$plugins->method( 'booster_plugin_from_file' )->willReturn( $package );
		$themes    = $this->createStub( ThemeRepository::class );
		$provider  = new class() implements RepositoryProvider, RepositoryReleaseMetadata, RepositoryReleaseCandidateListing, RepositoryReleaseNativeTargets {
			use \Tests\RepositoryProvider\Support\SuppliesProviderDiagnostics;

			public int $list_calls = 0;

			public function get_metadata(): ProviderMetadata {
				return new ProviderMetadata( ProviderCode::parse( 'partial' ), 'Partial release fixture', 'https://partial.example/', 'Owner' );
			}

			public function expected_update_uri( RepositoryReference $repository ): string {
				return 'https://partial.example/' . $repository->locator;
			}

			public function release_details_url( RepositoryReference $repository, string $tag ): string {
				return $this->expected_update_uri( $repository ) . '/releases/' . rawurlencode( $tag );
			}

			public function list_release_candidates( string $package_type, RepositoryReference $repository, string $channel ): RepositoryReleaseCandidateList {
				unset( $package_type, $repository, $channel );
				++$this->list_calls;

				return new RepositoryReleaseCandidateList( array() );
			}

			public function has_registered_native_target( string $package_type, string $installed_identifier ): bool {
				unset( $package_type, $installed_identifier );

				return false;
			}

			public function create_native_target(
				string $package_type,
				RepositoryReference $repository,
				string $metadata_file,
				string $package_root,
				string $installed_identifier,
				string $channel,
				string $deployment_policy
			): RepositoryReleaseNativeTarget {
				unset( $package_type, $repository, $metadata_file, $package_root, $installed_identifier, $channel, $deployment_policy );

				throw new \RuntimeException( 'Native target construction must remain inert during branch preflight.' );
			}
		};
		$store     = new RuntimeReleaseStore();
		$lock      = new RuntimeUpdaterLock();
		$providers = new ProviderRegistry( array( $provider ) );
		$providers->seal();
		$facade = $this->facade(
			$plugins,
			$themes,
			$store,
			$this->registrar(
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
			metadata_eligible: static fn (): bool => true
		);
		$nonce  = $facade->nonce_action( 'preflight', 'plugin', 'example/example.php', 1, 'stable' );

		$result = $facade->preflight( 'plugin', 'example/example.php', 1, 'stable', $nonce );

		self::assertSame( ReleaseTrackingPreflight::PREFLIGHT_UNAVAILABLE, $result?->code() );
		self::assertSame( 'provider_unavailable', $result?->reason_code() );
		self::assertSame( 0, $provider->list_calls );
		self::assertSame( array(), $store->transitions );
		self::assertSame( 0, $lock->acquires );
	}

	public function test_complete_external_provider_can_preflight_after_native_target_ownership_lands(): void {
		$package = $this->package(
			'plugin',
			'example/example.php',
			'example',
			DeploymentPolicy::MANUAL,
			provider: 'bb',
			source: PackageSource::BRANCH
		);
		$plugins = $this->createStub( PluginRepository::class );
		$plugins->method( 'booster_plugin_from_file' )->willReturn( $package );
		$themes     = $this->createStub( ThemeRepository::class );
		$list_calls = 0;
		$store      = new RuntimeReleaseStore();
		$lock       = new RuntimeUpdaterLock();
		$facade     = $this->facade(
			$plugins,
			$themes,
			$store,
			$this->registrar(
				$plugins,
				$themes,
				$store,
				$lock,
				$this->release_metadata_registry()
			),
			$lock,
			$this->release_metadata_registry(
				'bb',
				'https://bitbucket.example/',
				static function () use ( &$list_calls ): RepositoryReleaseCandidateList {
					++$list_calls;

					return new RepositoryReleaseCandidateList( array() );
				}
			),
			static fn (): bool => true,
			static fn (): bool => true,
			metadata_eligible: static fn (): bool => true
		);
		$nonce      = $facade->nonce_action( 'preflight', 'plugin', 'example/example.php', 1, 'stable' );

		$result = $facade->preflight( 'plugin', 'example/example.php', 1, 'stable', $nonce );

		self::assertSame( ReleaseTrackingPreflight::RELEASE_UNAVAILABLE, $result?->code() );
		self::assertSame( 'no_releases', $result?->reason_code() );
		self::assertSame( 1, $list_calls );
		self::assertSame( array(), $store->transitions );
		self::assertSame( 0, $lock->acquires );
	}

	public function test_facade_refuses_branch_package_reserved_by_core_self_updater(): void {
		$identifier = 'ran-booster/ran-booster.php';
		$package    = $this->package(
			'plugin',
			$identifier,
			'ran-booster',
			DeploymentPolicy::MANUAL,
			source: PackageSource::BRANCH
		);
		$plugins    = $this->createStub( PluginRepository::class );
		$plugins->method( 'booster_plugin_from_file' )->willReturn( $package );
		$themes    = $this->createStub( ThemeRepository::class );
		$store     = new RuntimeReleaseStore();
		$providers = $this->release_metadata_registry();
		$registrar = $this->registrar(
			$plugins,
			$themes,
			$store,
			new RuntimeUpdaterLock(),
			$providers
		);
		$registrar->reserve_core_self_update_target( $identifier );
		$expected_action = 'ran-booster-release-tracking-enable-plugin-' . $identifier . '-1';
		$facade          = $this->facade(
			$plugins,
			$themes,
			$store,
			$registrar,
			new RuntimeUpdaterLock(),
			$providers,
			static fn (): bool => true,
			static fn ( string $nonce, string $action ): bool => 'valid' === $nonce && $expected_action === $action,
			metadata_eligible: static fn (): bool => true
		);

		$status = $facade->status( 'plugin', $identifier );
		$result = $facade->enable( 'plugin', $identifier, 1, 'stable', 'valid' );

		self::assertSame( ReleaseTrackingEligibility::TARGET_ALREADY_USES_RAN_UPDATER, $status->eligibility()->code() );
		self::assertFalse( $status->eligible() );
		self::assertSame( 'target_already_uses_ran_updater', $result->code() );
		self::assertFalse( $result->successful() );
		self::assertCount( 0, $store->transitions );
	}

	public function test_facade_refuses_branch_package_already_registered_by_the_ranupdater(): void {
		$package = $this->package(
			'plugin',
			'example/example.php',
			'example',
			DeploymentPolicy::MANUAL,
			source: PackageSource::BRANCH
		);
		$plugins = $this->createStub( PluginRepository::class );
		$plugins->method( 'booster_plugin_from_file' )->willReturn( $package );
		$themes          = $this->createStub( ThemeRepository::class );
		$store           = new RuntimeReleaseStore();
		$registrar       = $this->registrar(
			$plugins,
			$themes,
			$store,
			new RuntimeUpdaterLock(),
			$this->release_metadata_registry( collision: true )
		);
		$expected_action = 'ran-booster-release-tracking-enable-plugin-example/example.php-1';
		$facade          = $this->facade(
			$plugins,
			$themes,
			$store,
			$registrar,
			new RuntimeUpdaterLock(),
			$this->release_metadata_registry( collision: true ),
			static fn (): bool => true,
			static fn ( string $nonce, string $action ): bool => 'valid' === $nonce && $expected_action === $action,
			metadata_eligible: static fn (): bool => true
		);

		$status = $facade->status( 'plugin', 'example/example.php' );
		$result = $facade->enable( 'plugin', 'example/example.php', 1, 'stable', 'valid' );

		self::assertSame( ReleaseTrackingEligibility::TARGET_ALREADY_USES_RAN_UPDATER, $status->eligibility()->code() );
		self::assertFalse( $status->eligible() );
		self::assertSame( 'target_already_uses_ran_updater', $result->code() );
		self::assertFalse( $result->successful() );
		self::assertCount( 0, $store->transitions );
	}

	public function test_failed_fresh_release_validation_leaves_source_state_unchanged(): void {
		$package = $this->package(
			'plugin',
			'example/example.php',
			'example',
			DeploymentPolicy::AUTOMATIC,
			source: PackageSource::BRANCH
		);
		$plugins = $this->createStub( PluginRepository::class );
		$plugins->method( 'booster_plugin_from_file' )->willReturn( $package );
		$store     = new RuntimeReleaseStore();
		$registrar = $this->registrar(
			$plugins,
			$this->createStub( ThemeRepository::class ),
			$store,
			new RuntimeUpdaterLock(),
			$this->release_metadata_registry()
		);
		$facade    = $this->facade(
			$plugins,
			$this->createStub( ThemeRepository::class ),
			$store,
			$registrar,
			new RuntimeUpdaterLock(),
			$this->release_metadata_registry(
				inspect: static function (): RepositoryReleaseInspection {
					throw RepositoryReleaseInspectionRejected::invalid_release();
				}
			),
			static fn (): bool => true,
			static fn (): bool => true,
			metadata_eligible: static fn (): bool => true
		);

		$result = $facade->enable( 'plugin', 'example/example.php', 1, 'stable', 'valid' );

		self::assertFalse( $result->successful() );
		self::assertSame( ReleaseTrackingPreflight::INVALID_RELEASE_ASSETS, $result->code() );
		self::assertCount( 0, $store->transitions );
		self::assertSame( PackageSource::BRANCH, $package->get_source() );
		self::assertSame( DeploymentPolicy::AUTOMATIC, $package->get_deployment_policy() );
	}

	public function test_nested_branch_plugin_is_rejected_before_release_provider_work(): void {
		$package = $this->package(
			'plugin',
			'example/example.php',
			'example',
			DeploymentPolicy::MANUAL,
			source: PackageSource::BRANCH,
			subdirectory: 'packages/example'
		);
		$plugins = $this->createStub( PluginRepository::class );
		$plugins->method( 'booster_plugin_from_file' )->willReturn( $package );
		$themes     = $this->createStub( ThemeRepository::class );
		$store      = new RuntimeReleaseStore();
		$list_calls = 0;
		$providers  = $this->release_metadata_registry(
			list_releases: static function () use ( &$list_calls ): RepositoryReleaseCandidateList {
				++$list_calls;

				return new RepositoryReleaseCandidateList( array() );
			}
		);
		$facade     = $this->facade(
			$plugins,
			$themes,
			$store,
			$this->registrar( $plugins, $themes, $store, new RuntimeUpdaterLock(), $providers ),
			new RuntimeUpdaterLock(),
			$providers,
			static fn (): bool => true,
			static fn (): bool => true,
			metadata_eligible: static fn (): bool => true
		);

		$status = $facade->status( 'plugin', 'example/example.php' );
		$result = $facade->enable( 'plugin', 'example/example.php', 1, 'stable', 'nonce' );

		self::assertSame( 'subdirectory_not_supported', $status->eligibility()->code() );
		self::assertSame( 'subdirectory_not_supported', $result->code() );
		self::assertSame( 0, $list_calls );
		self::assertSame( array(), $store->transitions );
	}

	public function test_nested_branch_theme_is_rejected_for_prerelease_before_release_provider_work(): void {
		$package = $this->package(
			'theme',
			'example-theme',
			'example-theme',
			DeploymentPolicy::MANUAL,
			source: PackageSource::BRANCH,
			subdirectory: 'themes/example'
		);
		$plugins = $this->createStub( PluginRepository::class );
		$themes  = $this->createStub( ThemeRepository::class );
		$themes->method( 'booster_theme_from_stylesheet' )->willReturn( $package );
		$store      = new RuntimeReleaseStore();
		$list_calls = 0;
		$providers  = $this->release_metadata_registry(
			list_releases: static function () use ( &$list_calls ): RepositoryReleaseCandidateList {
				++$list_calls;

				return new RepositoryReleaseCandidateList( array() );
			}
		);
		$facade     = $this->facade(
			$plugins,
			$themes,
			$store,
			$this->registrar( $plugins, $themes, $store, new RuntimeUpdaterLock(), $providers ),
			new RuntimeUpdaterLock(),
			$providers,
			static fn (): bool => true,
			static fn (): bool => true,
			metadata_eligible: static fn (): bool => true
		);

		$status = $facade->status( 'theme', 'example-theme' );
		$result = $facade->enable( 'theme', 'example-theme', 1, 'prerelease', 'nonce' );

		self::assertSame( 'subdirectory_not_supported', $status->eligibility()->code() );
		self::assertSame( 'subdirectory_not_supported', $result->code() );
		self::assertSame( 0, $list_calls );
		self::assertSame( array(), $store->transitions );
	}

	public function test_legacy_nested_release_status_and_channel_changes_use_the_same_bounded_reason(): void {
		$package = $this->package(
			'plugin',
			'example/example.php',
			'example',
			DeploymentPolicy::MANUAL,
			subdirectory: 'packages/example'
		);
		$plugins = $this->createStub( PluginRepository::class );
		$plugins->method( 'booster_plugin_from_file' )->willReturn( $package );
		$themes    = $this->createStub( ThemeRepository::class );
		$store     = new RuntimeReleaseStore(
			array( "plugin\0example/example.php" => new ManagedReleaseConfiguration( 'example', 'example.php' ) )
		);
		$lock      = new RuntimeUpdaterLock();
		$providers = $this->release_metadata_registry();
		$facade    = $this->facade(
			$plugins,
			$themes,
			$store,
			$this->registrar( $plugins, $themes, $store, $lock, $providers ),
			$lock,
			$providers,
			static fn (): bool => true,
			static fn (): bool => true,
			metadata_eligible: static fn (): bool => true
		);

		$status = $facade->status( 'plugin', 'example/example.php' );
		foreach ( array( 'stable', 'prerelease' ) as $channel ) {
			$result = $facade->change_channel( 'plugin', 'example/example.php', 1, $channel, 'nonce' );
			self::assertSame( 'subdirectory_not_supported', $result->code() );
		}

		self::assertSame( 'subdirectory_not_supported', $status->eligibility()->code() );
		self::assertSame( 'subdirectory_not_supported', $status->failure_code() );
		self::assertSame( array(), $store->channel_changes );
		self::assertSame( 0, $lock->acquires );
	}

	public function test_facade_projects_native_diagnostics_and_refreshes_only_exact_release_revision(): void {
		$package = $this->package(
			'theme',
			'example-theme',
			'example-theme',
			DeploymentPolicy::AUTOMATIC
		);
		$plugins = $this->createStub( PluginRepository::class );
		$plugins->method( 'all_deployment_plugins' )->willReturn( array() );
		$themes = $this->createStub( ThemeRepository::class );
		$themes->method( 'all_deployment_themes' )->willReturn( array( 'example-theme' => $package ) );
		$themes->method( 'booster_theme_from_stylesheet' )->willReturn( $package );
		$store     = new RuntimeReleaseStore(
			array(
				"theme\0example-theme" => new ManagedReleaseConfiguration( 'example-theme', 'style.css' ),
			)
		);
		$registrar = $this->registrar(
			$plugins,
			$themes,
			$store,
			new RuntimeUpdaterLock(),
			$this->release_metadata_registry(
				// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- The release-runtime fixture callback retains the registered factory or provider callable signature while returning a controlled result.
				target_factory: static fn ( mixed ...$options ): object => new RuntimeUpdaterFacade(
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
		$facade    = $this->facade(
			$plugins,
			$themes,
			$store,
			$registrar,
			$lock,
			$this->release_metadata_registry(),
			static fn (): bool => true,
			static fn (): bool => true,
			static function ( string $type ) use ( &$refreshes ): void {
				$refreshes[] = $type;
			},
			null,
			static fn (): bool => true
		);

		$status = $facade->status( 'theme', 'example-theme' );

		self::assertSame( '123456789', $status->provider_repository_id() );
		self::assertSame( '2.0.0', $status->latest_version() );
		self::assertSame( 'stable', $status->channel() );
		self::assertTrue( $status->update_available() );
		self::assertTrue( $facade->refresh( 'theme', 'example-theme', 1, 'nonce' )->successful() );
		self::assertSame( array( 'theme' ), $refreshes );
		self::assertSame( 1, $registrar->target( 'theme', 'example-theme' )?->refreshes() );
		$registrar->target( 'theme', 'example-theme' )?->reject_refresh();
		self::assertSame( 'refresh_failed', $facade->refresh( 'theme', 'example-theme', 1, 'nonce' )->code() );
		self::assertSame( array( 'theme' ), $refreshes );
		$registrar->target( 'theme', 'example-theme' )?->fail_refresh();
		self::assertSame( 'refresh_failed', $facade->refresh( 'theme', 'example-theme', 1, 'nonce' )->code() );
		self::assertSame( array( 'theme' ), $refreshes );
		self::assertFalse( $facade->refresh( 'theme', 'example-theme', 2, 'nonce' )->successful() );
		self::assertSame( array( 'theme' ), $refreshes );
		self::assertSame( 0, $lock->acquires );
		self::assertSame( array(), $lock->releases );
	}

	public function test_facade_projects_neutral_target_offers_and_validation_failures(): void {
		$package = $this->package( 'theme', 'example-theme', 'example-theme', DeploymentPolicy::MANUAL );
		$plugins = $this->createStub( PluginRepository::class );
		$plugins->method( 'all_deployment_plugins' )->willReturn( array() );
		$themes = $this->createStub( ThemeRepository::class );
		$themes->method( 'all_deployment_themes' )->willReturn( array( 'example-theme' => $package ) );
		$themes->method( 'booster_theme_from_stylesheet' )->willReturn( $package );
		$store   = new RuntimeReleaseStore(
			array(
				"theme\0example-theme" => new ManagedReleaseConfiguration( 'example-theme', 'style.css' ),
			)
		);
		$updater = new class() {
			public function register(): bool {
				return true;
			}

			/** @var array<string, int|string|null> */
			public array $current_status = array(
				'state'                => 'active',
				'declaration_accepted' => true,
				'hooks_registered'     => true,
				'code'                 => 'target_active',
				'native'               => array(
					'candidate_tag'             => 'v2.0.0',
					'candidate_validation_code' => 'archive_identity_verified',
					'candidate_version'         => '2.0.0',
					'candidate_header_version'  => '2.0.0',
					'failure_code'              => null,
					'installed_version'         => '1.0.0',
					'last_check'                => 1_700_000_000,
					'offered_release_identity'  => '42',
					'offered_version'           => '2.0.0',
					'relationship'              => 'newer',
				),
			);

			/** @return array<string, int|string|null> */
			public function status(): array {
				return $this->current_status;
			}
		};
		$target  = new GitHubReleaseNativeTarget(
			new class() {
				public function theme( mixed ...$arguments ): object {
					unset( $arguments );

					return new \stdClass();
				}
			},
			'theme',
			'/wordpress/wp-content/themes/example-theme/style.css',
			'owner/example-theme',
			'123456789',
			null,
			'stable',
			'manual'
		);
		( new \ReflectionProperty( GitHubReleaseNativeTarget::class, 'updater' ) )->setValue( $target, $updater );
		self::assertTrue( $target->status()->active );
		$registrar = $this->registrar(
			$plugins,
			$themes,
			$store,
			new RuntimeUpdaterLock(),
			// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- The release-runtime fixture callback retains the registered factory or provider callable signature while returning a controlled result.
			$this->release_metadata_registry( target_factory: static fn ( mixed ...$options ): object => $target )
		);
		$registrar->register();
		self::assertSame( $target, $registrar->target( 'theme', 'example-theme' ), $registrar->failure_code( 'theme', 'example-theme' ) );
		$facade = $this->facade(
			$plugins,
			$themes,
			$store,
			$registrar,
			new RuntimeUpdaterLock(),
			$this->release_metadata_registry(),
			static fn (): bool => true,
			static fn (): bool => true
		);

		$offer = $facade->status( 'theme', 'example-theme' );

		self::assertSame( '2.0.0', $offer->latest_version() );
		self::assertTrue( $offer->update_available() );
		self::assertSame( '', $offer->failure_code() );

		$updater->current_status = array(
			'state'                => 'active',
			'declaration_accepted' => true,
			'hooks_registered'     => true,
			'code'                 => 'target_active',
			'native'               => array(
				'candidate_tag'             => 'v2.0.0',
				'candidate_validation_code' => 'archive_header_missing',
				'candidate_version'         => '2.0.0',
				'candidate_header_version'  => null,
				'failure_code'              => null,
				'installed_version'         => '1.0.0',
				'last_check'                => 1_700_000_000,
				'offered_release_identity'  => null,
				'offered_version'           => null,
				'relationship'              => 'newer',
			),
		);

		$failure = $facade->status( 'theme', 'example-theme' );

		self::assertSame( '2.0.0', $failure->latest_version() );
		self::assertFalse( $failure->update_available() );
		self::assertSame( ReleaseTrackingPreflight::RELEASE_HEADER_MISSING, $failure->failure_code() );
	}

	public function test_refresh_reports_removed_release_configuration_without_running_the_updater(): void {
		$package = $this->package( 'theme', 'example-theme', 'example-theme', DeploymentPolicy::MANUAL );
		$plugins = $this->createStub( PluginRepository::class );
		$themes  = $this->createStub( ThemeRepository::class );
		$themes->method( 'booster_theme_from_stylesheet' )->willReturn( $package );
		$store     = new class() extends ManagedReleaseStore {
			// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundInExtendedClassAfterLastUsed -- The fixture implementation of configuration retains the production method contract; these inputs do not affect this controlled result.
			public function configuration( string $type, string $identifier ): ?ManagedReleaseConfiguration {
				throw new InvalidArgumentException( 'Retired release configuration.' );
			}
		};
		$registrar = $this->registrar(
			$plugins,
			$themes,
			$store,
			new RuntimeUpdaterLock(),
			$this->release_metadata_registry()
		);
		$facade    = $this->facade(
			$plugins,
			$themes,
			$store,
			$registrar,
			new RuntimeUpdaterLock(),
			$this->release_metadata_registry(),
			static fn (): bool => true,
			static fn (): bool => true
		);

		$result = $facade->refresh( 'theme', 'example-theme', 1, 'nonce' );

		self::assertFalse( $result->successful() );
		self::assertSame( 'release_configuration_invalid', $result->code() );
	}

	public function test_branch_statuses_project_safe_local_state_without_passive_preflight(): void {
		$package = $this->package(
			'plugin',
			'branch/branch.php',
			'branch',
			DeploymentPolicy::MANUAL,
			source: PackageSource::BRANCH
		);
		$plugins = $this->createStub( PluginRepository::class );
		$plugins->method( 'booster_plugin_from_file' )->willReturn( $package );
		$themes          = $this->createStub( ThemeRepository::class );
		$store           = new RuntimeReleaseStore();
		$registrar       = $this->registrar(
			$plugins,
			$themes,
			$store,
			new RuntimeUpdaterLock(),
			$this->release_metadata_registry()
		);
		$preflight_calls = 0;
		$lock            = new RuntimeUpdaterLock();
		$providers       = $this->release_metadata_registry(
			list_releases: static function () use ( &$preflight_calls ): RepositoryReleaseCandidateList {
				++$preflight_calls;

				return new RepositoryReleaseCandidateList( array() );
			}
		);
		$facade          = $this->facade(
			$plugins,
			$themes,
			$store,
			$registrar,
			$lock,
			$providers,
			metadata_eligible: static fn (): bool => true
		);

		$statuses = $facade->statuses(
			'plugin',
			array( 'branch/branch.php' )
		);
		$status   = $facade->status( 'plugin', 'branch/branch.php' );

		self::assertSame( 0, $preflight_calls );
		self::assertSame( 'branch', $statuses['branch/branch.php']->source() );
		self::assertSame( 'stable', $statuses['branch/branch.php']->channel() );
		self::assertTrue( $statuses['branch/branch.php']->eligible() );
		self::assertNull( $statuses['branch/branch.php']->preflight() );
		self::assertSame( 'branch', $statuses['branch/branch.php']->package_root() );
		self::assertSame( '1.0.0', $statuses['branch/branch.php']->installed_version() );
		self::assertSame( '', $statuses['branch/branch.php']->latest_version() );
		self::assertFalse( $statuses['branch/branch.php']->update_available() );
		self::assertNull( $status->preflight() );
		self::assertSame( '', $status->latest_version() );
		self::assertSame( 0, $lock->acquires );
		self::assertSame( array(), $lock->releases );
	}

	public function test_release_action_nonces_are_bound_to_the_source_revision(): void {
		$facade = $this->nonce_facade();

		self::assertSame(
			'ran-booster-release-tracking-refresh-plugin-example/example.php-1',
			$facade->nonce_action( 'refresh', 'plugin', 'example/example.php', 1 )
		);
		self::assertNotSame(
			$facade->nonce_action( 'refresh', 'plugin', 'example/example.php', 1 ),
			$facade->nonce_action( 'refresh', 'plugin', 'example/example.php', 2 )
		);
		self::assertSame(
			'ran-booster-release-tracking-change_channel-plugin-example/example.php-1',
			$facade->nonce_action( 'change_channel', 'plugin', 'example/example.php', 1 )
		);
	}

	public function test_release_managed_status_projects_candidate_mismatch_without_offering_an_update(): void {
		$package = $this->package(
			'plugin',
			'example/example.php',
			'example',
			DeploymentPolicy::MANUAL
		);
		$plugins = $this->createStub( PluginRepository::class );
		$plugins->method( 'all_deployment_plugins' )->willReturn( array( 'example/example.php' => $package ) );
		$plugins->method( 'booster_plugin_from_file' )->willReturn( $package );
		$themes = $this->createStub( ThemeRepository::class );
		$themes->method( 'all_deployment_themes' )->willReturn( array() );
		$store     = new RuntimeReleaseStore(
			array(
				"plugin\0example/example.php" => new ManagedReleaseConfiguration( 'example', 'example.php' ),
			)
		);
		$registrar = $this->registrar(
			$plugins,
			$themes,
			$store,
			new RuntimeUpdaterLock(),
			$this->release_metadata_registry(
				// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- The release-runtime fixture callback retains the registered factory or provider callable signature while returning a controlled result.
				target_factory: static fn ( mixed ...$options ): object => new RuntimeUpdaterFacade(
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
		$facade = $this->facade(
			$plugins,
			$themes,
			$store,
			$registrar,
			new RuntimeUpdaterLock(),
			$this->release_metadata_registry(),
			static fn (): bool => true,
			static fn (): bool => true,
			metadata_eligible: static fn (): bool => true
		);

		$status = $facade->status( 'plugin', 'example/example.php' );

		self::assertSame( 'release_version_mismatch', $status->failure_code() );
		self::assertFalse( $status->update_available() );
		self::assertNotNull( $status->preflight() );
		self::assertSame( 'v2.1.0', $status->preflight()?->release_tag() );
		self::assertSame( '2.0.0', $status->preflight()?->package_header_version() );
	}

	public function test_returning_to_branch_remains_truthful_when_provider_and_native_cache_cleanup_fail(): void {
		$package = $this->package(
			'plugin',
			'example/example.php',
			'example',
			DeploymentPolicy::MANUAL
		);
		$plugins = $this->createStub( PluginRepository::class );
		$plugins->method( 'all_deployment_plugins' )->willReturn( array( 'example/example.php' => $package ) );
		$plugins->method( 'booster_plugin_from_file' )->willReturn( $package );
		$themes = $this->createStub( ThemeRepository::class );
		$themes->method( 'all_deployment_themes' )->willReturn( array() );
		$store     = new RuntimeReleaseStore(
			array(
				"plugin\0example/example.php" => new ManagedReleaseConfiguration( 'example', 'example.php' ),
			)
		);
		$registrar = $this->registrar(
			$plugins,
			$themes,
			$store,
			new RuntimeUpdaterLock(),
			$this->release_metadata_registry(
				target_factory: static fn ( mixed ...$options ): object => new RuntimeUpdaterFacade( $options )
			)
		);
		$registrar->register();
		$registrar->target( 'plugin', 'example/example.php' )?->fail_refresh();
		$invalidated = array();
		$lock        = new RuntimeUpdaterLock();
		$facade      = $this->facade(
			$plugins,
			$themes,
			$store,
			$registrar,
			$lock,
			$this->release_metadata_registry(),
			static fn (): bool => true,
			static fn (): bool => true,
			metadata_eligible: static fn (): bool => true,
			invalidate_native: static function ( string $type ) use ( &$invalidated ): void {
				$invalidated[] = $type;
				throw new \RuntimeException( 'native cache cleanup failed' );
			}
		);

		$result = $facade->return_to_branch( 'plugin', 'example/example.php', 1, 'nonce' );

		self::assertTrue( $result->successful() );
		self::assertSame( PackageSource::BRANCH, $store->transitions[0]['new_source'] );
		self::assertSame( array( 'plugin' ), $invalidated );
		self::assertSame( 0, $registrar->target( 'plugin', 'example/example.php' )?->refreshes() );
		self::assertSame( 1, $lock->acquires );
		self::assertSame( array( 'runtime-lock' ), $lock->releases );

		$store->transition_failure = new ManagedReleaseRepositorySourceUnavailable();
		$unavailable               = $facade->return_to_branch( 'plugin', 'example/example.php', 1, 'nonce' );

		self::assertFalse( $unavailable->successful() );
		self::assertSame( 'release_unavailable', $unavailable->code() );
		self::assertCount( 1, $store->transitions );
	}

	public function test_changing_release_channel_uses_same_source_cas_and_only_invalidates_native_state(): void {
		$package = $this->package(
			'plugin',
			'example/example.php',
			'example',
			DeploymentPolicy::AUTOMATIC
		);
		$plugins = $this->createStub( PluginRepository::class );
		$plugins->method( 'all_deployment_plugins' )->willReturn( array( 'example/example.php' => $package ) );
		$plugins->method( 'booster_plugin_from_file' )->willReturn( $package );
		$themes = $this->createStub( ThemeRepository::class );
		$themes->method( 'all_deployment_themes' )->willReturn( array() );
		$store     = new RuntimeReleaseStore(
			array(
				"plugin\0example/example.php" => new ManagedReleaseConfiguration(
					'example',
					'example.php',
					'prerelease'
				),
			)
		);
		$registrar = $this->registrar(
			$plugins,
			$themes,
			$store,
			new RuntimeUpdaterLock(),
			$this->release_metadata_registry(
				target_factory: static fn ( mixed ...$options ): object => new RuntimeUpdaterFacade( $options )
			)
		);
		$registrar->register();
		$invalidated    = array();
		$expected_nonce = 'ran-booster-release-tracking-change_channel-plugin-example/example.php-1';
		$lock           = new RuntimeUpdaterLock();
		$facade         = $this->facade(
			$plugins,
			$themes,
			$store,
			$registrar,
			$lock,
			$this->release_metadata_registry(),
			static fn (): bool => true,
			static fn ( string $nonce, string $action ): bool =>
				'valid' === $nonce && $expected_nonce === $action,
			invalidate_native: static function ( string $type ) use ( &$invalidated ): void {
				$invalidated[] = $type;
			},
			metadata_eligible: static fn (): bool => true
		);

		self::assertSame( 'prerelease', $facade->status( 'plugin', 'example/example.php' )->channel() );
		$result = $facade->change_channel(
			'plugin',
			'example/example.php',
			1,
			'stable',
			'valid'
		);

		self::assertTrue( $result->successful() );
		self::assertSame( 'release_channel_changed', $result->code() );
		$expected_user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		self::assertSame(
			array(
				array(
					'type'              => 'plugin',
					'identifier'        => 'example/example.php',
					'expected_revision' => 1,
					'channel'           => 'stable',
					'user_id'           => $expected_user_id,
				),
			),
			$store->channel_changes
		);
		self::assertSame( array( 'plugin' ), $invalidated );
		self::assertSame( 0, $registrar->target( 'plugin', 'example/example.php' )?->refreshes() );
		self::assertSame( array(), $store->transitions );
		self::assertSame( 1, $lock->acquires );
		self::assertSame( array( 'runtime-lock' ), $lock->releases );

		$store->channel_change_failure = new ManagedReleaseSubdirectoryNotSupported();
		$store->replace_configuration(
			'plugin',
			'example/example.php',
			new ManagedReleaseConfiguration( 'example', 'example.php', 'stable' )
		);
		$preview_rejected = $facade->change_channel( 'plugin', 'example/example.php', 1, 'prerelease', 'valid' );
		self::assertFalse( $preview_rejected->successful() );
		self::assertSame( 'subdirectory_not_supported', $preview_rejected->code() );
		$store->replace_configuration(
			'plugin',
			'example/example.php',
			new ManagedReleaseConfiguration( 'example', 'example.php', 'prerelease' )
		);
		$stable_rejected = $facade->change_channel( 'plugin', 'example/example.php', 1, 'stable', 'valid' );
		self::assertFalse( $stable_rejected->successful() );
		self::assertSame( 'subdirectory_not_supported', $stable_rejected->code() );
		self::assertCount( 1, $store->channel_changes );
		$store->channel_change_failure = null;

		$store->channel_change_failure = new ManagedReleaseRepositorySourceUnavailable();
		$unavailable                   = $facade->change_channel( 'plugin', 'example/example.php', 1, 'stable', 'valid' );
		self::assertFalse( $unavailable->successful() );
		self::assertSame( 'release_unavailable', $unavailable->code() );
		self::assertCount( 1, $store->channel_changes );
		$store->channel_change_failure = null;

		$lock->release_succeeds = false;
		$release_failed         = $facade->change_channel(
			'plugin',
			'example/example.php',
			1,
			'stable',
			'valid'
		);
		self::assertFalse( $release_failed->successful() );
		self::assertSame( 'release_unavailable', $release_failed->code() );
		self::assertCount( 2, $store->channel_changes );
		self::assertSame( 5, $lock->acquires );
		self::assertSame( array( 'runtime-lock', 'runtime-lock', 'runtime-lock', 'runtime-lock', 'runtime-lock' ), $lock->releases );

		$invalid = $facade->change_channel(
			'plugin',
			'example/example.php',
			1,
			'preview',
			'valid'
		);
		self::assertFalse( $invalid->successful() );
		self::assertSame( 'forbidden', $invalid->code() );
		self::assertCount( 2, $store->channel_changes );
	}

	public function test_changing_to_current_release_channel_is_anoop_but_stale_revision_still_fails(): void {
		$package = $this->package(
			'plugin',
			'example/example.php',
			'example',
			DeploymentPolicy::MANUAL
		);
		$plugins = $this->createStub( PluginRepository::class );
		$plugins->method( 'all_deployment_plugins' )->willReturn( array( 'example/example.php' => $package ) );
		$plugins->method( 'booster_plugin_from_file' )->willReturn( $package );
		$themes = $this->createStub( ThemeRepository::class );
		$themes->method( 'all_deployment_themes' )->willReturn( array() );
		$store       = new RuntimeReleaseStore(
			array(
				"plugin\0example/example.php" => new ManagedReleaseConfiguration( 'example', 'example.php' ),
			)
		);
		$lock        = new RuntimeUpdaterLock();
		$invalidated = array();
		$registrar   = $this->registrar(
			$plugins,
			$themes,
			$store,
			$lock,
			$this->release_metadata_registry()
		);
		$facade      = $this->facade(
			$plugins,
			$themes,
			$store,
			$registrar,
			$lock,
			$this->release_metadata_registry(),
			static fn (): bool => true,
			static fn (): bool => true,
			metadata_eligible: static fn (): bool => true,
			invalidate_native: static function ( string $type ) use ( &$invalidated ): void {
				$invalidated[] = $type;
			}
		);

		$current = $facade->change_channel( 'plugin', 'example/example.php', 1, 'stable', 'valid' );

		self::assertTrue( $current->successful() );
		self::assertSame( 'release_channel_current', $current->code() );
		self::assertSame( 'Stable is already the active release track. No settings were changed.', $current->message() );
		self::assertSame( array(), $store->channel_changes );
		self::assertSame( array(), $invalidated );
		self::assertSame( 0, $lock->acquires );

		$stale = $facade->change_channel( 'plugin', 'example/example.php', 2, 'prerelease', 'valid' );

		self::assertFalse( $stale->successful() );
		self::assertSame( 'source_changed', $stale->code() );
		self::assertStringContainsString( 'Refresh this browser page', $stale->message() );
		self::assertSame( array(), $store->channel_changes );
		self::assertSame( array(), $invalidated );
		self::assertSame( 0, $lock->acquires );
	}

	private function nonce_facade(): NativeReleaseTrackingFacade {
		$plugins = $this->createStub( PluginRepository::class );
		$themes  = $this->createStub( ThemeRepository::class );
		$store   = new RuntimeReleaseStore();

		return $this->facade(
			$plugins,
			$themes,
			$store,
			$this->registrar(
				$plugins,
				$themes,
				$store,
				new RuntimeUpdaterLock(),
				$this->release_metadata_registry()
			),
			new RuntimeUpdaterLock(),
			$this->release_metadata_registry()
		);
	}

	/**
	 * @param callable(string, RepositoryReference, string): RepositoryReleaseCandidateList|null $list_releases
	 * @param callable(string, RepositoryReference, string, string, string): RepositoryReleaseInspection|null $inspect
	 */
	private function release_metadata_registry(
		string $code = 'gh',
		string $base_url = 'https://github.com/',
		?callable $list_releases = null,
		?callable $inspect = null,
		?callable $target_factory = null,
		bool $collision = false
	): ProviderRegistry {
		$registry = new ProviderRegistry(
			array( new RuntimeReleaseProvider( $code, $base_url, $list_releases, $inspect, $target_factory, $collision ) )
		);
		$registry->seal();

		return $registry;
	}

	private function metadata_only_registry( string $code = 'gh', string $base_url = 'https://github.com/' ): ProviderRegistry {
		$provider = new class( $code, $base_url ) implements RepositoryProvider, RepositoryReleaseMetadata {
			use \Tests\RepositoryProvider\Support\SuppliesProviderDiagnostics;

			public function __construct( private string $code, private string $base_url ) {
			}

			public function get_metadata(): ProviderMetadata {
				return new ProviderMetadata( ProviderCode::parse( $this->code ), 'Metadata-only fixture', $this->base_url, 'Owner' );
			}

			public function expected_update_uri( RepositoryReference $repository ): string {
				return $this->base_url . $repository->locator;
			}

			public function release_details_url( RepositoryReference $repository, string $tag ): string {
				return '' === $tag ? '' : $this->expected_update_uri( $repository ) . '/releases/tag/' . rawurlencode( $tag );
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
	private function native_plugin_registrar(
		Package $registered,
		Package|callable|null $live = null
	): array {
		$plugins = $this->createStub( PluginRepository::class );
		$plugins->method( 'all_deployment_plugins' )->willReturn( array( self::NATIVE_PLUGIN => $registered ) );
		$live = $live ?? $registered;
		if ( is_callable( $live ) ) {
			$plugins->method( 'booster_plugin_from_file' )->willReturnCallback( $live );
		} else {
			$plugins->method( 'booster_plugin_from_file' )->willReturn( $live );
		}
		$themes = $this->createStub( ThemeRepository::class );
		$themes->method( 'all_deployment_themes' )->willReturn( array() );
		$lock      = new RuntimeUpdaterLock();
		$facade    = new RuntimeUpdaterFacade();
		$registrar = $this->registrar(
			$plugins,
			$themes,
			new RuntimeReleaseStore(
				array(
					"plugin\0" . self::NATIVE_PLUGIN => new ManagedReleaseConfiguration( 'example', 'example.php' ),
				)
			),
			$lock,
			$this->release_metadata_registry(
				target_factory: static function ( mixed ...$options ) use ( $facade ): object {
					unset( $options );

					return $facade;
				}
			)
		);
		$registrar->register();

		return array( $registrar, $lock, $facade );
	}

	private function assert_native_authority_error( mixed $result ): void {
		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'ran_booster_native_update_authority_changed', $result->get_error_code() );
	}

	private function registrar( mixed ...$arguments ): ManagedReleaseTargetRegistrar {
		return new ManagedReleaseTargetRegistrar( ...array_merge( $arguments, array( 'source_guard' => $this->repository_guard() ) ) );
	}

	private function facade( mixed ...$arguments ): NativeReleaseTrackingFacade {
		return new NativeReleaseTrackingFacade( ...array_merge( $arguments, array( 'source_guard' => $this->repository_guard() ) ) );
	}

	private function repository_guard(): RepositorySourceGuard {
		$database = new class( fn (): array => $this->repository_rows ) {
			public string $last_error = '';
			public function __construct( private \Closure $rows ) {}
			// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundInExtendedClassBeforeLastUsed -- The fixture implementation of prepare retains the production method contract; these inputs do not affect this controlled result.
			public function prepare( string $query, mixed ...$arguments ): array {
				return $arguments;
			}
			public function get_results( array $arguments ): array {
				return array_values( array_filter( ( $this->rows )(), static fn ( object $row ): bool => $row->provider === $arguments[1] && $row->provider_repository_id === $arguments[2] ) );
			}
		};

		return new RepositorySourceGuard( $database, $this->createStub( Database::class ) );
	}

	private function package(
		string $type,
		string $identifier,
		string $slug,
		DeploymentPolicy $policy,
		bool $is_private = false,
		string $credential_id = '',
		string $provider = 'gh',
		PackageSource $source = PackageSource::RELEASE_ASSET,
		int $source_revision = 1,
		?string $subdirectory = null,
		string $repository_id = '123456789',
		?string $provider_repository_id = null
	): Package {
		$package = $this->createStub( Package::class );
		$package->method( 'get_identifier' )->willReturn( $identifier );
		$package->method( 'get_slug' )->willReturn( $slug );
		$package->method( 'get_deployment_policy' )->willReturn( $policy );
		$package->method( 'get_source' )->willReturn( $source );
		$package->method( 'get_source_revision' )->willReturn( $source_revision );
		$package->method( 'get_provider_code' )->willReturn( $provider );
		$package->method( 'get_repository' )->willReturn(
			new ManagedRepository( $provider, 'owner/example', $repository_id, 'main', $is_private, $credential_id )
		);
		$package->method( 'get_provider_repository_id' )->willReturn( $provider_repository_id ?? $repository_id );
		$package->method( 'get_credential_id' )->willReturn( $credential_id );
		$package->method( 'get_private' )->willReturn( $is_private );
		$package->method( 'get_version' )->willReturn( '1.0.0' );
		$package->method( 'get_subdirectory' )->willReturn( $subdirectory );
		$this->repository_rows[ $type . "\0" . $identifier ] = (object) array(
			'type'                   => 'plugin' === $type ? '1' : '2',
			'package'                => $identifier,
			'source'                 => $source->value,
			'provider'               => $provider,
			'provider_repository_id' => $repository_id,
		);

		return $package;
	}
}

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- Focused lock spy belongs with the registrar contract.
final class RuntimeUpdaterLock extends WordPressUpdaterLock {

	public int $acquires          = 0;
	public int $token_reads       = 0;
	public bool $acquire_fails    = false;
	public bool $release_succeeds = true;

	/** @var list<string> */
	public array $releases = array();

	public ?string $token = 'outer-lock';

	public function current_token(): ?string {
		++$this->token_reads;

		return $this->token;
	}

	public function acquire(): string {
		++$this->acquires;
		if ( $this->acquire_fails ) {
			throw new \RuntimeException( 'busy' );
		}

		return 'runtime-lock';
	}

	public function release( string $token ): bool {
		$this->releases[] = $token;

		return $this->release_succeeds;
	}
}
