<?php

declare(strict_types=1);

namespace RAN\Tests\WordPress;

require_once __DIR__ . '/ManagedReleaseRuntimeWordPressFunctions.php';
require_once __DIR__ . '/RuntimeReleaseStore.php';
require_once __DIR__ . '/RuntimeUpdaterFacade.php';
require_once __DIR__ . '/../Activity/ActivityConnection.php';
require_once __DIR__ . '/../Activity/InMemoryActivityConnection.php';
require_once __DIR__ . '/../Activity/ActivityWordPressFunctions.php';
require_once __DIR__ . '/../Storage/StorageTestEnvironment.php';
require_once __DIR__ . '/RuntimeReleaseProvider.php';
require_once __DIR__ . '/../Support/WPError.php';
require_once __DIR__ . '/../Support/WordPressUpgraderSkins.php';
require_once __DIR__ . '/../Support/InMemoryPublicRepositoryLookupProfileStore.php';
require_once __DIR__ . '/../Portability/WpPusherCoexistenceWordPressFunctions.php';

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
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
use RAN\RepositoryProvider\GitHubProviderSelection;
use RAN\RepositoryProvider\ProviderCode;
use RAN\RepositoryProvider\ProviderMetadata;
use RAN\RepositoryProvider\ProviderFailure;
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
use RAN\Tests\Support\InMemoryPublicRepositoryLookupProfileStore;

final class ManagedReleaseRuntimeTest extends TestCase {
	use \RAN\Tests\Support\GitHubSelectionFixture;
	use \RAN\Tests\Support\CapturesBoosterLog;


	/** @var array<string, \stdClass> Exact persistent rows represented by this test's packages. */
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
		$this->start_log_capture();
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this exact lifecycle override name.
	protected function tearDown(): void {
		$this->stop_log_capture();
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
				self::addToAssertionCount( 1 );
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

	public function test_invalid_installed_plugin_header_refuses_before_metadata_read(): void {
		$identifier = 'example/' . str_repeat( 'a', 101 ) . '.php';
		$reflection = new \ReflectionClass( \RAN\Plugin::class );
		$package    = $reflection->newInstanceWithoutConstructor();
		$reflection->getProperty( 'file' )->setValue( $package, $identifier );
		$package->set_repository( new ManagedRepository( 'gh', 'owner/example', '123456789', 'main' ) );
		$package->set_source( PackageSource::BRANCH, 1 );
		$package->set_deployment_policy( DeploymentPolicy::MANUAL );
		$plugins = $this->createStub( PluginRepository::class );
		$plugins->method( 'booster_plugin_from_file' )->willReturn( $package );
		$themes    = $this->createStub( ThemeRepository::class );
		$store     = new RuntimeReleaseStore();
		$lock      = new RuntimeUpdaterLock();
		$providers = $this->release_metadata_registry();
		$registrar = $this->registrar( $plugins, $themes, $store, $lock, $providers );
		$reads     = 0;
		$facade    = $this->facade(
			$plugins,
			$themes,
			$store,
			$registrar,
			$lock,
			$providers,
			metadata_eligible: static function () use ( &$reads ): bool {
				++$reads;
				return true;
			}
		);
		$status    = $facade->status( 'plugin', $identifier );
		self::assertSame( ReleaseTrackingEligibility::INVALID_PACKAGE_IDENTITY, $status->eligibility()->code() );
		self::assertSame( 0, $reads );
		self::assertSame( 0, $lock->acquires );
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
				self::addToAssertionCount( 1 );
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
				"plugin\0installed-example/example.php" => new ManagedReleaseConfiguration( 'installed-example', 'example.php' ),
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
			self::assertSame( 'installed-example', $targets[0]['pluginSlug'] );
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

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_registrar_carries_a_paused_preview_track_as_disabled_and_leaves_stable_alone(): void {
		$GLOBALS['ran_booster_test_environment_type'] = 'production';
		$GLOBALS['ran_booster_test_home_url']         = 'https://example.com';
		$GLOBALS['ran_booster_storage_test_options']  = array();
		$plugin                                       = $this->package( 'plugin', 'stable/stable.php', 'stable', DeploymentPolicy::MANUAL, repository_id: 'stable-repository' );
		$theme                                        = $this->package( 'theme', 'preview-theme', 'preview-theme', DeploymentPolicy::AUTOMATIC, repository_id: 'preview-repository' );
		$plugins                                      = $this->createStub( PluginRepository::class );
		$plugins->method( 'all_deployment_plugins' )->willReturn( array( 'stable/stable.php' => $plugin ) );
		$themes = $this->createStub( ThemeRepository::class );
		$themes->method( 'all_deployment_themes' )->willReturn( array( 'preview-theme' => $theme ) );
		$store     = new RuntimeReleaseStore(
			array(
				"plugin\0stable/stable.php" => new ManagedReleaseConfiguration( 'stable', 'stable.php' ),
				"theme\0preview-theme"      => new ManagedReleaseConfiguration( 'preview-theme', 'style.css', 'prerelease' ),
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

		self::assertSame( '', $registrar->failure_code( 'theme', 'preview-theme' ) );
		self::assertSame( array( 'manual', 'disabled' ), array_column( $targets, 'autoUpdatePolicy' ) );
		self::assertSame( array( 'stable', 'prerelease' ), array_column( $targets, 'channel' ) );
		self::assertSame( DeploymentPolicy::AUTOMATIC, $theme->get_deployment_policy(), 'The saved policy is untouched.' );
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
		self::assertCount( 7, $GLOBALS['ran_booster_runtime_actions'] );
		self::assertSame(
			array(
				'ran_wp_release_updater_check_started_v1',
				'ran_wp_release_updater_check_completed_v1',
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

			/** @return array<array-key, mixed> */
			// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundInExtendedClassBeforeLastUsed -- The fixture implementation of prepare retains the production method contract; these inputs do not affect this controlled result.
			public function prepare( string $query, mixed ...$arguments ): array {
				return $arguments;
			}

			/**
			 * @param array<array-key, mixed> $arguments
			 * @return list<object>
			 */
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
			$this->github_selection(),
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

		$owner = $registrar->begin_provider_native_mutation( 'plugin', self::NATIVE_PLUGIN );
		self::assertSame( 1, $lock->token_reads );
		self::assertSame( 2, $lock->sql_token_reads );
		$registrar->complete_native_mutation( $upgrader, self::NATIVE_EXTRA );

		self::assertSame( array(), $lock->releases );
		self::assertNotNull( $this->native_selection_store );
		self::assertArrayHasKey( 'native_mutation', $this->native_selection_store->read() );
		$registrar->complete_provider_native_mutation( $owner, 'plugin', self::NATIVE_PLUGIN, 'verified_success' );
		self::assertArrayNotHasKey( 'native_mutation', $this->native_selection_store->read() );
		self::assertSame( array( '1700000002' ), $lock->releases );
	}

	public function test_active_github_native_mutation_hold_blocks_change_channel_and_return_to_branch(): void {
		$package = $this->package( 'plugin', self::NATIVE_PLUGIN, 'example', DeploymentPolicy::MANUAL );
		$plugins = $this->createStub( PluginRepository::class );
		$plugins->method( 'all_booster_plugins' )->willReturn( array( self::NATIVE_PLUGIN => $package ) );
		$plugins->method( 'booster_plugin_from_file' )->willReturn( $package );
		$themes = $this->createStub( ThemeRepository::class );
		$themes->method( 'all_booster_themes' )->willReturn( array() );

		$store = new class() extends ManagedReleaseStore {
			public int $channel_changes = 0;
			public int $transitions     = 0;

			public function __construct() {}

			public function configuration( string $type, string $identifier ): ManagedReleaseConfiguration {
				unset( $type, $identifier );
				return new ManagedReleaseConfiguration( 'example', 'example/example.php', 'stable' );
			}

			public function change_channel( string $type, string $identifier, int $expected_revision, string $channel, int $user_id, string $expected_adoption_token ): bool {
				unset( $type, $identifier, $expected_revision, $channel, $user_id, $expected_adoption_token );
				++$this->channel_changes;
				return true;
			}

			public function transition( string $type, string $identifier, PackageSource $expected_source, int $expected_revision, PackageSource $new_source, ?ManagedReleaseConfiguration $configuration, int $user_id, string $expected_adoption_token, ?callable $native_complete = null ): bool {
				unset( $type, $identifier, $expected_source, $expected_revision, $new_source, $configuration, $user_id, $expected_adoption_token, $native_complete );
				++$this->transitions;
				return true;
			}
		};

		$lock      = new class() extends WordPressUpdaterLock {
			public ?string $token = null;
			public function try_acquire_without_reclaim(): string {
				$this->token = 'hold-test-lock';
				return $this->token;
			}
			public function current_token(): ?string {
				return $this->token;
			}
			public function release( string $token ): bool {
				if ( $this->token === $token ) {
					$this->token = null;
					return true;
				}
				return false;
			}
		};
		$selection = $this->github_selection();
		self::assertNotNull( $this->native_selection_store );
		$state                   = $this->native_selection_store->read( true );
		$next                    = $state;
		$next['native_mutation'] = array(
			'selection_generation' => $state['selection_generation'],
			'owner_tag'            => str_repeat( '0', 16 ) . str_repeat( 'b', 16 ),
			'target_type'          => 'plugin',
			'installed_identity'   => self::NATIVE_PLUGIN,
			'phase'                => 'started',
		);
		self::assertTrue( $this->native_selection_store->compare_and_swap( $state, $next ) );

		$registry  = $this->release_metadata_registry();
		$registrar = $this->registrar( $plugins, $themes, $store, $lock, $registry );
		$facade    = $this->facade(
			$plugins,
			$themes,
			$store,
			$registrar,
			$lock,
			$registry,
			static fn (): bool => true,
			static fn (): bool => true,
			github_selection: $selection
		);

		$channel = $facade->change_channel( 'plugin', self::NATIVE_PLUGIN, 1, 'prerelease', 'valid' );
		self::assertFalse( $channel->successful() );
		self::assertSame( 'release_unavailable', $channel->code() );

		$return = $facade->return_to_branch( 'plugin', self::NATIVE_PLUGIN, 1, 'valid' );
		self::assertFalse( $return->successful() );
		self::assertSame( 'release_unavailable', $return->code() );

		self::assertSame( 0, $store->channel_changes );
		self::assertSame( 0, $store->transitions );
		self::assertNull( $lock->token );
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
		$owner = $registrar->begin_provider_native_mutation( 'plugin', self::NATIVE_PLUGIN );
		$registrar->complete_native_mutation( $upgrader, $extra );

		self::assertSame( array(), $lock->releases );
		// Only Release's private finalization after restoration may complete ownership.
		$registrar->complete_provider_native_mutation( $owner, 'plugin', self::NATIVE_PLUGIN, 'uncertain' );
		self::assertNotNull( $this->native_selection_store );
		self::assertSame( 'finished', $this->native_selection_store->read()['native_mutation']['phase'] ?? null );
		self::assertSame( array( '1700000002' ), $lock->releases );
	}

	public function test_retained_uncertain_native_marker_rejects_later_begin_and_public_completion_releases_later_lock(): void {
		[ $registrar, $lock ] = $this->native_plugin_registrar(
			$this->package( 'plugin', self::NATIVE_PLUGIN, 'example', DeploymentPolicy::MANUAL )
		);
		$first_upgrader       = new \stdClass();
		$second_upgrader      = new \stdClass();

		self::assertFalse( $registrar->authorize_native_download( false, 'package.zip', $first_upgrader, self::NATIVE_EXTRA ) );
		self::assertFalse( $registrar->fence_native_mutation( false, self::NATIVE_EXTRA ) );
		$owner = $registrar->begin_provider_native_mutation( 'plugin', self::NATIVE_PLUGIN );
		$registrar->complete_native_mutation( $first_upgrader, self::NATIVE_EXTRA );
		$registrar->complete_provider_native_mutation( $owner, 'plugin', self::NATIVE_PLUGIN, 'uncertain' );

		self::assertSame( array( '1700000002' ), $lock->releases );
		self::assertNotNull( $this->native_selection_store );
		$selection = $this->native_selection_store->read();
		self::assertArrayHasKey( 'native_mutation', $selection );

		self::assertFalse( $registrar->authorize_native_download( false, 'package.zip', $second_upgrader, self::NATIVE_EXTRA ) );
		self::assertFalse( $registrar->fence_native_mutation( false, self::NATIVE_EXTRA ) );
		self::assertSame( 2, $lock->acquires );
		try {
			$registrar->begin_provider_native_mutation( 'plugin', self::NATIVE_PLUGIN );
			self::fail( 'Retained uncertain native marker admitted a later mutation.' );
		} catch ( \RuntimeException ) {
			self::addToAssertionCount( 1 );
		}

		$registrar->complete_native_mutation( $second_upgrader, self::NATIVE_EXTRA );

		self::assertSame( array( '1700000002', '1700000002' ), $lock->releases );
		self::assertSame( $selection, $this->native_selection_store->read() );
	}

	public function test_native_completion_rejects_wrong_owner_and_releases_the_lock_only_after_completion(): void {
		[ $registrar, $lock ] = $this->native_plugin_registrar( $this->package( 'plugin', self::NATIVE_PLUGIN, 'example', DeploymentPolicy::MANUAL ) );
		self::assertFalse( $registrar->authorize_native_download( false, 'package.zip', new \stdClass(), self::NATIVE_EXTRA ) );
		self::assertFalse( $registrar->fence_native_mutation( false, self::NATIVE_EXTRA ) );
		$owner = $registrar->begin_provider_native_mutation( 'plugin', self::NATIVE_PLUGIN );
		try {
			$registrar->complete_provider_native_mutation( 'wrong', 'plugin', self::NATIVE_PLUGIN, 'verified_success' );
			self::fail( 'Wrong owner released the lock.' );
		} catch ( \RuntimeException ) {
			self::assertSame( array(), $lock->releases );
		}
		$lock->release_succeeds = false;
		try {
			$registrar->complete_provider_native_mutation( $owner, 'plugin', self::NATIVE_PLUGIN, 'verified_success' );
			self::fail( 'Failed exact release was not reported.' );
		} catch ( \RuntimeException $failure ) {
			self::assertSame( 'GitHub native lock release failed.', $failure->getMessage() );
			// The hold ended before the lock was released, so no hold is left owning a lock it released.
			self::assertNotNull( $this->native_selection_store );
			self::assertArrayNotHasKey( 'native_mutation', $this->native_selection_store->read() );
		}
	}

	public function test_failed_native_completion_keeps_the_lock_for_the_hold(): void {
		[ $registrar, $lock ] = $this->native_plugin_registrar( $this->package( 'plugin', self::NATIVE_PLUGIN, 'example', DeploymentPolicy::MANUAL ) );
		self::assertFalse( $registrar->authorize_native_download( false, 'package.zip', new \stdClass(), self::NATIVE_EXTRA ) );
		self::assertFalse( $registrar->fence_native_mutation( false, self::NATIVE_EXTRA ) );
		$owner = $registrar->begin_provider_native_mutation( 'plugin', self::NATIVE_PLUGIN );
		self::assertNotNull( $this->native_selection_store );
		$held   = $this->native_selection_store->read();
		$marker = $held['native_mutation'];
		self::assertIsArray( $marker );
		self::assertSame( '1700000002', GitHubProviderSelection::native_lock_token( $marker ) );
		// Another writer changes the hold, so this request's completion cannot be recorded.
		$changed                    = $held;
		$changed['native_mutation'] = array_replace( $marker, array( 'phase' => 'finished' ) );
		self::assertTrue( $this->native_selection_store->compare_and_swap( $held, $changed ) );
		try {
			$registrar->complete_provider_native_mutation( $owner, 'plugin', self::NATIVE_PLUGIN, 'uncertain' );
			self::fail( 'A changed hold was completed.' );
		} catch ( \RuntimeException ) {
			self::assertSame( array(), $lock->releases );
		}
	}

	private function reset_native_selection_fixture(): void {
		$this->native_selection       = null;
		$this->native_selection_store = null;
		$this->native_selection_db    = null;
		$this->repository_rows        = array();
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_provider_native_begin_uses_pinned_sql_and_finishes_committed_hold_on_abort(): void {
		foreach ( array( 'one_shot', 'persistent', 'complete_commit_fails', 'automatic' ) as $case ) {
			$this->reset_native_selection_fixture();
			$automatic            = 'automatic' === $case;
			[ $registrar, $lock ] = $this->native_plugin_registrar(
				$this->package( 'plugin', self::NATIVE_PLUGIN, 'example', $automatic ? DeploymentPolicy::AUTOMATIC : DeploymentPolicy::MANUAL )
			);
			$upgrader             = $automatic ? (object) array( 'skin' => new \Automatic_Upgrader_Skin() ) : new \stdClass();
			if ( $automatic ) {
				$GLOBALS['ran_booster_runtime_action'] = 'wp_maybe_auto_update';
			}

			try {
				self::assertFalse( $registrar->authorize_native_download( false, 'package.zip', $upgrader, self::NATIVE_EXTRA ), $case );
				self::assertFalse( $registrar->fence_native_mutation( false, self::NATIVE_EXTRA ), $case );

				$before_begin_reads = $lock->token_reads;
				$phase_at_release   = null;
				$store              = $this->native_selection_store;
				self::assertNotNull( $store );
				self::assertNotNull( $this->native_selection_db );
				$lock->on_release = static function () use ( $store, &$phase_at_release ): void {
					$state            = $store->read( true );
					$phase_at_release = is_array( $state['native_mutation'] ?? null ) ? $state['native_mutation']['phase'] : null;
				};

				$cache_throw_count                              = 0;
				$selection_db                                   = $this->native_selection_db->selection;
				$GLOBALS['ran_booster_attempt_on_cache_delete'] = static function ( int|string $key, string $group ) use ( $case, $selection_db, &$cache_throw_count ): void {
					if ( \RAN\Storage\GitHubSelectionStore::OPTION !== $key || 'options' !== $group ) {
						return;
					}
					++$cache_throw_count;
					if ( 1 === $cache_throw_count && 'complete_commit_fails' === $case ) {
						$selection_db->fail_commit = true;
					}
					if ( 'persistent' === $case || 1 === $cache_throw_count ) {
						throw new \RuntimeException( 'Selection cache invalidation failed after hold COMMIT.' );
					}
				};

				$caught = null;
				try {
					$registrar->begin_provider_native_mutation( 'plugin', self::NATIVE_PLUGIN );
				} catch ( \RuntimeException $failure ) {
					$caught = $failure;
				} finally {
					unset( $GLOBALS['ran_booster_attempt_on_cache_delete'] );
					$selection_db->fail_commit = false;
				}

				self::assertInstanceOf( \RuntimeException::class, $caught, $case );
				self::assertSame( 2, $lock->sql_token_reads, $case );
				self::assertSame( $before_begin_reads + 1, $lock->token_reads, $case );

				if ( 'complete_commit_fails' === $case ) {
					self::assertSame( 'started', $store->read( true )['native_mutation']['phase'] ?? null, $case );
					self::assertNull( $phase_at_release, $case );
					self::assertSame( array(), $lock->releases, $case );
				} elseif ( $automatic ) {
					self::assertSame( 'finished', $store->read( true )['native_mutation']['phase'] ?? null, $case );
					self::assertNull( $phase_at_release, $case );
					self::assertSame( array(), $lock->releases, $case );
					self::assertSame( '1700000001', $lock->token, $case );
				} else {
					self::assertSame( 'finished', $store->read( true )['native_mutation']['phase'] ?? null, $case );
					self::assertSame( 'finished', $phase_at_release, $case );
					self::assertSame( array( '1700000002' ), $lock->releases, $case );
				}
				$lock->releases = array();
				$registrar->complete_native_mutation( new \stdClass(), self::NATIVE_EXTRA );
				self::assertSame( array(), $lock->releases, $case );
			} finally {
				unset( $GLOBALS['ran_booster_runtime_action'] );
			}
		}
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_provider_native_begin_preserves_manual_lock_when_commit_or_rollback_fails(): void {
		foreach ( array( 'fail_commit', 'throw_commit' ) as $commit_mode ) {
			foreach ( array( 'rollback_ok', 'fail_rollback', 'throw_rollback' ) as $rollback_mode ) {
				$this->reset_native_selection_fixture();
				[ $registrar, $lock ] = $this->native_plugin_registrar(
					$this->package( 'plugin', self::NATIVE_PLUGIN, 'example', DeploymentPolicy::MANUAL )
				);

				self::assertFalse( $registrar->authorize_native_download( false, 'package.zip', new \stdClass(), self::NATIVE_EXTRA ) );
				self::assertFalse( $registrar->fence_native_mutation( false, self::NATIVE_EXTRA ) );
				self::assertNotNull( $this->native_selection );
				self::assertNotNull( $this->native_selection_db );
				$selection_db                                   = $this->native_selection_db->selection;
				$selection_db->queries                          = array();
				$cache_deletes_after_tx                         = 0;
				$GLOBALS['ran_booster_attempt_on_cache_delete'] = static function () use ( $selection_db, &$cache_deletes_after_tx ): void {
					if ( in_array( 'START TRANSACTION', $selection_db->queries, true ) ) {
						++$cache_deletes_after_tx;
					}
				};
				$selection_db->fail_commit                      = 'fail_commit' === $commit_mode;
				$selection_db->throw_commit                     = 'throw_commit' === $commit_mode;
				$selection_db->fail_rollback                    = 'fail_rollback' === $rollback_mode;
				$selection_db->throw_rollback                   = 'throw_rollback' === $rollback_mode;

				$caught = null;
				try {
					$registrar->begin_provider_native_mutation( 'plugin', self::NATIVE_PLUGIN );
				} catch ( \Throwable $failure ) {
					$caught = $failure;
				} finally {
					unset( $GLOBALS['ran_booster_attempt_on_cache_delete'] );
					$selection_db->fail_commit    = false;
					$selection_db->throw_commit   = false;
					$selection_db->fail_rollback  = false;
					$selection_db->throw_rollback = false;
				}

				$case = "$commit_mode/$rollback_mode";
				self::assertInstanceOf( \Throwable::class, $caught, $case );
				self::assertSame( 2, $lock->sql_token_reads, $case );
				self::assertSame( 1, $lock->token_reads, $case );
				self::assertSame( array(), $lock->releases, $case );
				self::assertTrue( $this->native_selection->has_uncertain_commit(), $case );
				$registrar->complete_native_mutation( new \stdClass(), self::NATIVE_EXTRA );
				self::assertSame( array(), $lock->releases, $case );

				if ( 'fail_rollback' === $rollback_mode || 'throw_rollback' === $rollback_mode ) {
					self::assertTrue( $this->native_selection->has_unclosed_transaction(), $case );
					self::assertNotNull( $selection_db->transaction_rows, $case );
					self::assertSame( 0, $cache_deletes_after_tx, $case );
					self::assertTrue( $selection_db->query( 'ROLLBACK' ), $case );
				} else {
					self::assertFalse( $this->native_selection->has_unclosed_transaction(), $case );
				}
			}
		}
	}

	public function test_switch_permit_admits_one_branch_package_for_this_request_only(): void {
		$branch        = $this->package( 'plugin', self::NATIVE_PLUGIN, 'example', DeploymentPolicy::MANUAL, source: PackageSource::BRANCH, source_revision: 3 );
		$configuration = new ManagedReleaseConfiguration( 'example', 'example.php', 'prerelease' );
		[ $registrar ] = $this->native_plugin_registrar( $branch );
		$offers        = (object) array( 'response' => array( self::NATIVE_PLUGIN => (object) array( 'new_version' => '1.0.0' ) ) );

		self::assertNull( $registrar->target( 'plugin', self::NATIVE_PLUGIN ) );
		self::assertArrayNotHasKey( self::NATIVE_PLUGIN, $registrar->suppress_unauthorized_plugin_offers( clone $offers )->response );
		self::assertTrue( $registrar->admit_switch( 'plugin', $branch, $configuration, 7 ) );
		self::assertNotNull( $registrar->target( 'plugin', self::NATIVE_PLUGIN ) );
		self::assertFalse( $registrar->admit_switch( 'plugin', $branch, $configuration, 7 ) );
		self::assertArrayHasKey( self::NATIVE_PLUGIN, $registrar->suppress_unauthorized_plugin_offers( clone $offers )->response );
		self::assertFalse( $registrar->authorize_native_download( false, 'package.zip', new \stdClass(), self::NATIVE_EXTRA ) );
		self::assertFalse( $registrar->fence_native_mutation( false, self::NATIVE_EXTRA ) );
		self::assertNotSame( '', $registrar->begin_provider_native_mutation( 'plugin', self::NATIVE_PLUGIN ) );
	}

	public function test_switch_permit_refuses_release_subdirectory_and_reserved_packages(): void {
		$configuration = new ManagedReleaseConfiguration( 'example', 'example.php' );
		foreach ( array(
			'release'      => $this->package( 'plugin', self::NATIVE_PLUGIN, 'example', DeploymentPolicy::MANUAL ),
			'subdirectory' => $this->package( 'plugin', self::NATIVE_PLUGIN, 'example', DeploymentPolicy::MANUAL, source: PackageSource::BRANCH, subdirectory: 'packages/example' ),
			'reserved'     => $this->package( 'plugin', self::NATIVE_PLUGIN, 'example', DeploymentPolicy::MANUAL, source: PackageSource::BRANCH ),
		) as $case => $package ) {
			[ $registrar ] = $this->native_plugin_registrar( $package );
			if ( 'reserved' === $case ) {
				$registrar->reserve_core_self_update_target( self::NATIVE_PLUGIN );
			}
			self::assertFalse( $registrar->admit_switch( 'plugin', $package, $configuration, 7 ), $case );
		}
	}

	public function test_withdrawn_or_changed_switch_refuses_the_download_and_the_offer(): void {
		$branch        = $this->package( 'plugin', self::NATIVE_PLUGIN, 'example', DeploymentPolicy::MANUAL, source: PackageSource::BRANCH, source_revision: 3 );
		$changed       = $this->package( 'plugin', self::NATIVE_PLUGIN, 'example', DeploymentPolicy::MANUAL, source: PackageSource::BRANCH, source_revision: 4 );
		$configuration = new ManagedReleaseConfiguration( 'example', 'example.php' );
		$offers        = (object) array( 'response' => array( self::NATIVE_PLUGIN => (object) array( 'new_version' => '1.0.0' ) ) );

		[ $registrar ] = $this->native_plugin_registrar( $branch );
		self::assertTrue( $registrar->admit_switch( 'plugin', $branch, $configuration, 7 ) );
		$registrar->withdraw_switch( 'plugin', self::NATIVE_PLUGIN );
		self::assertArrayNotHasKey( self::NATIVE_PLUGIN, $registrar->suppress_unauthorized_plugin_offers( clone $offers )->response );
		$this->assert_native_authority_error( $registrar->authorize_native_download( false, 'package.zip', new \stdClass(), self::NATIVE_EXTRA ) );

		$current       = $branch;
		[ $registrar ] = $this->native_plugin_registrar(
			$branch,
			static function () use ( &$current ): Package {
				return $current;
			}
		);
		self::assertTrue( $registrar->admit_switch( 'plugin', $branch, $configuration, 7 ) );
		$current = $changed;
		self::assertArrayNotHasKey( self::NATIVE_PLUGIN, $registrar->suppress_unauthorized_plugin_offers( clone $offers )->response );
		$this->assert_native_authority_error( $registrar->authorize_native_download( false, 'package.zip', new \stdClass(), self::NATIVE_EXTRA ) );
	}

	public function test_switch_source_changes_only_with_verified_native_completion(): void {
		$configuration = new ManagedReleaseConfiguration( 'example', 'example.php', 'prerelease' );
		foreach ( array( 'verified_success', 'uncertain' ) as $outcome ) {
			$branch                         = $this->package( 'plugin', self::NATIVE_PLUGIN, 'example', DeploymentPolicy::AUTOMATIC, source: PackageSource::BRANCH, source_revision: 3 );
			$this->native_selection         = null;
			[ $registrar, $lock, , $store ] = $this->native_plugin_registrar( $branch );
			self::assertTrue( $registrar->admit_switch( 'plugin', $branch, $configuration, 7 ) );
			self::assertFalse( $registrar->authorize_native_download( false, 'package.zip', new \stdClass(), self::NATIVE_EXTRA ) );
			self::assertFalse( $registrar->fence_native_mutation( false, self::NATIVE_EXTRA ) );
			$owner = $registrar->begin_provider_native_mutation( 'plugin', self::NATIVE_PLUGIN );
			self::assertSame( array(), $store->transitions );
			$registrar->complete_provider_native_mutation( $owner, 'plugin', self::NATIVE_PLUGIN, $outcome );
			self::assertNotNull( $this->native_selection_store );
			self::assertSame( array( '1700000002' ), $lock->releases );
			if ( 'uncertain' === $outcome ) {
				self::assertSame( array(), $store->transitions );
				self::assertArrayHasKey( 'native_mutation', $this->native_selection_store->read() );
				continue;
			}
			self::assertArrayNotHasKey( 'native_mutation', $this->native_selection_store->read() );
			self::assertSame(
				array(
					array(
						'type'              => 'plugin',
						'identifier'        => self::NATIVE_PLUGIN,
						'expected_source'   => PackageSource::BRANCH,
						'expected_revision' => 3,
						'new_source'        => PackageSource::RELEASE_ASSET,
						'configuration'     => $configuration,
						'user_id'           => 7,
						'adoption_token'    => (string) \RAN\AdoptionIdentity::of( $branch ),
					),
				),
				$store->transitions
			);
			// The permit ends with the request's one completion.
			$this->assert_native_authority_error( $registrar->authorize_native_download( false, 'package.zip', new \stdClass(), self::NATIVE_EXTRA ) );
		}
	}

	public function test_switch_completion_preserves_hold_and_manual_lock_when_pinned_commit_fails(): void {
		$configuration        = new ManagedReleaseConfiguration( 'example', 'example.php', 'prerelease' );
		$branch               = $this->package( 'plugin', self::NATIVE_PLUGIN, 'example', DeploymentPolicy::MANUAL, source: PackageSource::BRANCH, source_revision: 3 );
		[ $registrar, $lock ] = $this->native_plugin_registrar( $branch );
		self::assertTrue( $registrar->admit_switch( 'plugin', $branch, $configuration, 7 ) );
		self::assertFalse( $registrar->authorize_native_download( false, 'package.zip', new \stdClass(), self::NATIVE_EXTRA ) );
		self::assertFalse( $registrar->fence_native_mutation( false, self::NATIVE_EXTRA ) );
		$owner = $registrar->begin_provider_native_mutation( 'plugin', self::NATIVE_PLUGIN );
		self::assertNotNull( $this->native_selection_db );
		self::assertNotNull( $this->native_selection_store );
		self::assertNotNull( $this->native_selection );

		$this->native_selection_db->selection->fail_commit = true;
		try {
			$registrar->complete_provider_native_mutation( $owner, 'plugin', self::NATIVE_PLUGIN, 'verified_success' );
			self::fail( 'Failed source-switch COMMIT must throw.' );
		} catch ( \RAN\Storage\DatabaseLifecycleFailure $failure ) {
			self::assertSame( 'selection_commit_uncertain', $failure->reason() );
		} finally {
			$this->native_selection_db->selection->fail_commit = false;
		}

		self::assertSame( array(), $lock->releases );
		self::assertTrue( $this->native_selection->has_uncertain_commit() );
		self::assertSame( 'started', $this->native_selection_store->read()['native_mutation']['phase'] ?? null );
		$registrar->complete_native_mutation( new \stdClass(), self::NATIVE_EXTRA );
		self::assertSame( array(), $lock->releases );
	}

	public function test_staging_authority_or_policy_change_refuses_native_begin(): void {
		foreach ( array( 'source_revision', 'disabled' ) as $change ) {
			$first                = $this->package( 'plugin', self::NATIVE_PLUGIN, 'example', DeploymentPolicy::MANUAL );
			$changed              = $this->package( 'plugin', self::NATIVE_PLUGIN, 'example', 'disabled' === $change ? DeploymentPolicy::DISABLED : DeploymentPolicy::MANUAL, source_revision: 'source_revision' === $change ? 2 : 1 );
			$current              = $first;
			[ $registrar, $lock ] = $this->native_plugin_registrar(
				$first,
				static function () use ( &$current ): Package {
					return $current;
				}
			);
			self::assertFalse( $registrar->authorize_native_download( false, 'package.zip', new \stdClass(), self::NATIVE_EXTRA ) );
			self::assertFalse( $registrar->fence_native_mutation( false, self::NATIVE_EXTRA ) );
			$current = $changed;
			try {
				$registrar->begin_provider_native_mutation( 'plugin', self::NATIVE_PLUGIN );
				self::fail( 'Staging changed captured authority.' );
			} catch ( \RuntimeException ) {
				self::assertNotNull( $this->native_selection_store );
				self::assertArrayNotHasKey( 'native_mutation', $this->native_selection_store->read() );
				self::assertSame( array(), $lock->releases );
			}
		}
	}

	public function test_staging_mutation_guard_change_refuses_native_begin(): void {
		[ $registrar, $lock ] = $this->native_plugin_registrar( $this->package( 'plugin', self::NATIVE_PLUGIN, 'example', DeploymentPolicy::MANUAL ) );
		self::assertFalse( $registrar->authorize_native_download( false, 'package.zip', new \stdClass(), self::NATIVE_EXTRA ) );
		self::assertFalse( $registrar->fence_native_mutation( false, self::NATIVE_EXTRA ) );
		$GLOBALS['ran_booster_wp_pusher_active_plugins'] = array( 'wppusher/wppusher.php' );
		try {
			$registrar->begin_provider_native_mutation( 'plugin', self::NATIVE_PLUGIN );
			self::fail( 'Revoked mutation permission admitted.' );
		} catch ( \RuntimeException ) {
			self::assertNotNull( $this->native_selection_store );
			self::assertArrayNotHasKey( 'native_mutation', $this->native_selection_store->read() );
			self::assertSame( array(), $lock->releases );
		}
	}

	public function test_staging_target_unavailability_refuses_native_begin(): void {
		[ $registrar, $lock, $target ] = $this->native_plugin_registrar( $this->package( 'plugin', self::NATIVE_PLUGIN, 'example', DeploymentPolicy::MANUAL ) );
		self::assertFalse( $registrar->authorize_native_download( false, 'package.zip', new \stdClass(), self::NATIVE_EXTRA ) );
		self::assertFalse( $registrar->fence_native_mutation( false, self::NATIVE_EXTRA ) );
		$target->fail_diagnostics();
		try {
			$registrar->begin_provider_native_mutation( 'plugin', self::NATIVE_PLUGIN );
			self::fail( 'Unavailable target admitted.' );
		} catch ( \RuntimeException ) {
			self::assertNotNull( $this->native_selection_store );
			self::assertArrayNotHasKey( 'native_mutation', $this->native_selection_store->read() );
			self::assertSame( array(), $lock->releases );
		}
	}

	public function test_final_authority_read_cannot_replace_lock_then_admit_native_mutation(): void {
		$package              = $this->package( 'plugin', self::NATIVE_PLUGIN, 'example', DeploymentPolicy::MANUAL );
		$race                 = new class() {
			public bool $after_staging       = false;
			public ?RuntimeUpdaterLock $lock = null;
		};
		[ $registrar, $lock ] = $this->native_plugin_registrar(
			$package,
			static function () use ( $package, $race ): Package {
				if ( $race->after_staging && null !== $race->lock ) {
					$race->lock->token = '1700000003';
				}
				return $package;
			}
		);
		$race->lock           = $lock;
		self::assertFalse( $registrar->authorize_native_download( false, 'package.zip', new \stdClass(), self::NATIVE_EXTRA ) );
		self::assertFalse( $registrar->fence_native_mutation( false, self::NATIVE_EXTRA ) );
		$race->after_staging = true;
		try {
			$registrar->begin_provider_native_mutation( 'plugin', self::NATIVE_PLUGIN );
			self::fail( 'Foreign authority read replaced the lock.' );
		} catch ( \RuntimeException ) {
			self::assertNotNull( $this->native_selection_store );
			self::assertArrayNotHasKey( 'native_mutation', $this->native_selection_store->read() );
			self::assertSame( array(), $lock->releases );
		}
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
		self::assertSame( array( '1700000002' ), $lock->releases );
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

	public function test_real_installed_identity_rejects_wrong_configuration_at_registration_and_fresh_fence(): void {
		foreach ( array( 'plugin', 'theme' ) as $type ) {
			$this->repository_rows = array();
			$identifier            = 'plugin' === $type ? 'example/example.php' : 'example-theme';
			$root                  = 'plugin' === $type ? 'example' : 'example-theme';
			$header                = 'plugin' === $type ? 'example.php' : 'style.css';
			$this->package( $type, $identifier, $root, DeploymentPolicy::MANUAL ); // Seed the source guard's management row.
			$reflection = new \ReflectionClass( 'plugin' === $type ? \RAN\Plugin::class : \RAN\Theme::class );
			$package    = $reflection->newInstanceWithoutConstructor();
			$reflection->getProperty( 'plugin' === $type ? 'file' : 'stylesheet' )->setValue( $package, $identifier );
			$package->set_repository( new ManagedRepository( 'gh', 'owner/example', '123456789', 'main' ) );
			$package->set_adoption_token( 'gh:123456789:11111111-1111-4111-8111-111111111111' );
			$package->set_source( PackageSource::RELEASE_ASSET, 1 );
			$package->set_deployment_policy( DeploymentPolicy::MANUAL );
			$plugins = $this->createStub( PluginRepository::class );
			$themes  = $this->createStub( ThemeRepository::class );
			$plugins->method( 'all_deployment_plugins' )->willReturn( 'plugin' === $type ? array( $identifier => $package ) : array() );
			$themes->method( 'all_deployment_themes' )->willReturn( 'theme' === $type ? array( $identifier => $package ) : array() );
			if ( 'plugin' === $type ) {
				$plugins->method( 'booster_plugin_from_file' )->willReturn( $package );
			} else {
				$themes->method( 'booster_theme_from_stylesheet' )->willReturn( $package );
			}
			$store     = new RuntimeReleaseStore( array( $type . "\0" . $identifier => new ManagedReleaseConfiguration( strtoupper( $root ), $header ) ) );
			$lock      = new RuntimeUpdaterLock();
			$registrar = $this->registrar( $plugins, $themes, $store, $lock, $this->release_metadata_registry() );
			$registrar->register();
			self::assertNull( $registrar->target( $type, $identifier ) );
			$store->replace_configuration( $type, $identifier, new ManagedReleaseConfiguration( $root, $header ) );
			$registrar = $this->registrar( $plugins, $themes, $store, $lock, $this->release_metadata_registry() );
			$registrar->register();
			self::assertNotNull( $registrar->target( $type, $identifier ) );
			$extra = array(
				$type    => $identifier,
				'action' => 'update',
				'type'   => $type,
			);
			self::assertFalse( $registrar->authorize_native_download( false, 'package.zip', new \stdClass(), $extra ) );
			$store->replace_configuration( $type, $identifier, new ManagedReleaseConfiguration( 'different', $header ) );
			$this->assert_native_authority_error( $registrar->fence_native_mutation( false, $extra ) );
			self::assertSame( array( '1700000002' ), $lock->releases );
		}
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

	public function test_absent_offer_costs_no_authority_read_but_present_unauthorized_offer_is_removed(): void {
		$package = $this->package( 'plugin', self::NATIVE_PLUGIN, 'example', DeploymentPolicy::MANUAL );
		$reads   = 0;
		$plugins = $this->createStub( PluginRepository::class );
		$plugins->method( 'all_deployment_plugins' )->willReturn( array( self::NATIVE_PLUGIN => $package ) );
		$plugins->method( 'booster_plugin_from_file' )->willReturnCallback(
			static function () use ( &$reads, $package ): Package {
				++$reads;

				return $package;
			}
		);
		$themes = $this->createStub( ThemeRepository::class );
		$themes->method( 'all_deployment_themes' )->willReturn( array() );
		// No managed release configuration, so the package has no authority and its offer is unauthorized.
		$registrar = $this->registrar( $plugins, $themes, new RuntimeReleaseStore(), new RuntimeUpdaterLock(), $this->release_metadata_registry() );

		$absent = $registrar->suppress_unauthorized_plugin_offers( (object) array( 'response' => array( 'unmanaged/other.php' => (object) array() ) ) );
		self::assertSame( 0, $reads );
		self::assertArrayHasKey( 'unmanaged/other.php', $absent->response );

		$present = $registrar->suppress_unauthorized_plugin_offers(
			(object) array(
				'response' => array(
					self::NATIVE_PLUGIN   => (object) array(),
					'unmanaged/other.php' => (object) array(),
				),
			)
		);
		self::assertSame( 1, $reads );
		self::assertArrayNotHasKey( self::NATIVE_PLUGIN, $present->response );
		self::assertArrayHasKey( 'unmanaged/other.php', $present->response );
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

	public function test_automatic_native_update_omits_wordpress_lock_token_from_its_hold(): void {
		$package                               = $this->package( 'plugin', self::NATIVE_PLUGIN, 'example', DeploymentPolicy::AUTOMATIC );
		[ $registrar, $lock ]                  = $this->native_plugin_registrar( $package );
		$upgrader                              = (object) array( 'skin' => new \Automatic_Upgrader_Skin() );
		$GLOBALS['ran_booster_runtime_action'] = 'wp_maybe_auto_update';

		self::assertFalse( $registrar->authorize_native_download( false, 'package.zip', $upgrader, self::NATIVE_EXTRA ) );
		self::assertFalse( $registrar->fence_native_mutation( false, self::NATIVE_EXTRA ) );
		$owner = $registrar->begin_provider_native_mutation( 'plugin', self::NATIVE_PLUGIN );
		self::assertNotNull( $this->native_selection_store );
		$marker = $this->native_selection_store->read()['native_mutation'] ?? null;
		self::assertIsArray( $marker );
		self::assertNull( GitHubProviderSelection::native_lock_token( $marker ) );

		$registrar->complete_provider_native_mutation( $owner, 'plugin', self::NATIVE_PLUGIN, 'uncertain' );
		$finished = $this->native_selection_store->read()['native_mutation'] ?? null;
		self::assertIsArray( $finished );
		self::assertSame( 'finished', $finished['phase'] );
		self::assertNull( GitHubProviderSelection::native_lock_token( $finished ) );
		self::assertSame( array(), $lock->releases );
		self::assertSame( '1700000001', $lock->token );
	}

	public function test_automatic_native_update_rejects_a_missing_or_replaced_outer_token_without_releasing_it(): void {
		$package                               = $this->package( 'plugin', 'example/example.php', 'example', DeploymentPolicy::AUTOMATIC );
		$GLOBALS['ran_booster_runtime_action'] = 'wp_maybe_auto_update';
		foreach ( array( null, 'replacement-outer-lock' ) as $replacement ) {
			[ $registrar, $lock ] = $this->native_plugin_registrar( $package );
			$upgrader             = (object) array( 'skin' => new \Automatic_Upgrader_Skin() );
			self::assertFalse( $registrar->authorize_native_download( false, 'package.zip', $upgrader, self::NATIVE_EXTRA ) );
			$lock->token = $replacement;
			$blocked     = $registrar->fence_native_mutation( false, self::NATIVE_EXTRA );
			self::assertInstanceOf( \WP_Error::class, $blocked );
			self::assertSame( 'ran_booster_native_update_authority_changed', $blocked->get_error_code() );
			self::assertSame( 2, $lock->token_reads );
			self::assertSame( 0, $lock->acquires );
			self::assertSame( array(), $lock->releases );
			self::assertSame( $replacement, $lock->token );
		}
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

		$owner = $registrar->begin_provider_native_mutation( 'plugin', self::NATIVE_PLUGIN );
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
		// Only Release's private finalization after restoration may complete ownership.
		$registrar->complete_provider_native_mutation( $owner, 'plugin', self::NATIVE_PLUGIN, 'uncertain' );
		self::assertNotNull( $this->native_selection_store );
		self::assertArrayHasKey( 'native_mutation', $this->native_selection_store->read() );
		self::assertSame( array( '1700000002' ), $lock->releases );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_enable_installs_the_chosen_release_and_leaves_the_source_to_native_completion(): void {
		$expected_action                       = 'ran-booster-release-tracking-enable-plugin-example/example.php-1-gh:123456789:11111111-1111-4111-8111-111111111111';
		$installs                              = array();
		[ $facade, $store, $registrar, $lock ] = $this->switch_facade(
			static function ( string $type, string $identifier, string $identity, string $tag, bool $allow_older ) use ( &$installs ): string {
				$installs[] = array( $type, $identifier, $identity, $tag, $allow_older );

				return 'installed';
			},
			static fn ( string $nonce, string $action ): bool => 'valid' === $nonce && $expected_action === $action
		);

		self::assertSame( $expected_action, $facade->nonce_action( 'enable', 'plugin', 'example/example.php', 1 ) );
		$denied = $facade->enable( 'plugin', 'example/example.php', 1, 'prerelease', 'wrong' );
		self::assertSame( 'forbidden', $denied->code() );
		self::assertCount( 0, $installs );

		$result = $facade->enable( 'plugin', 'example/example.php', 1, 'prerelease', 'valid' );
		self::assertTrue( $result->successful() );
		self::assertSame( 'release_enabled', $result->code() );
		self::assertSame( array( array( 'plugin', 'example/example.php', '101', 'v2.0.0', false ) ), $installs );
		// The source changes only in native completion, never in this request's reply.
		self::assertSame( array(), $store->transitions );
		self::assertSame( 0, $lock->acquires );
		self::assertNotNull( $registrar->target( 'plugin', 'example/example.php' ) );
		self::assertSame( array(), $this->shutdown_rechecks() );
	}

	/** @return iterable<string, array{string, string}> */
	public static function refused_switches(): iterable {
		yield 'older release' => array( 'release_older', 'release_older' );
		yield 'updates off' => array( 'policy_disabled', 'policy_disabled' );
		yield 'forced off' => array( 'policy_forced_off', 'policy_forced_off' );
		yield 'updater refusal' => array( 'candidate_not_eligible', 'release_unavailable' );
		yield 'older updater copy' => array( 'unsupported', 'service_unavailable' );
		yield 'updater busy' => array( 'runtime_busy', 'service_unavailable' );
		yield 'install failed' => array( 'failed', 'wordpress_failed' );
	}

	#[DataProvider( 'refused_switches' )]
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_refused_or_failed_switch_withdraws_and_rechecks_updates_at_shutdown( string $install, string $code ): void {
		$invalidated                    = array();
		[ $facade, $store, $registrar ] = $this->switch_facade(
			static fn (): string => $install,
			invalidate_native: static function ( string $type ) use ( &$invalidated ): void {
				$invalidated[] = $type;
			}
		);

		$result = $facade->enable( 'plugin', 'example/example.php', 1, 'stable', 'valid' );
		self::assertFalse( $result->successful() );
		self::assertSame( $code, $result->code() );
		self::assertSame( array(), $store->transitions );
		self::assertSame( array(), $invalidated );
		$rechecks = $this->shutdown_rechecks();
		self::assertCount( 1, $rechecks );
		self::assertSame( PHP_INT_MAX, $rechecks[0]['priority'] );
		( $rechecks[0]['callback'] )();
		self::assertSame( array( 'plugin' ), $invalidated );
		$this->assert_native_authority_error( $registrar->authorize_native_download( false, 'package.zip', new \stdClass(), self::NATIVE_EXTRA ) );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_a_throw_during_the_install_leaves_the_source_to_shutdown_verification(): void {
		[ $facade, $store, $registrar ] = $this->switch_facade(
			static function (): string {
				throw new \RuntimeException( 'A hook threw after the upgrader ran.' );
			}
		);

		$result = $facade->enable( 'plugin', 'example/example.php', 1, 'stable', 'valid' );
		self::assertFalse( $result->successful() );
		self::assertSame( 'management_state_uncertain', $result->code() );
		self::assertSame( array(), $store->transitions );
		self::assertCount( 1, $this->shutdown_rechecks() );
		// The permit stays: WordPress may have installed, and only a verified install commits the source.
		self::assertFalse( $registrar->authorize_native_download( false, 'package.zip', new \stdClass(), self::NATIVE_EXTRA ) );
	}

	/** @return iterable<string, array{mixed, string}> */
	public static function updater_offer_answers(): iterable {
		yield 'older updater copy' => array( null, 'service_unavailable' );
		yield 'refusal' => array(
			array(
				'status' => 'refused',
				'code'   => 'release_older',
			),
			'release_older',
		);
		yield 'refusal spelled as an outcome' => array(
			array(
				'status' => 'refused',
				'code'   => 'installed',
			),
			'service_unavailable',
		);
	}

	#[DataProvider( 'updater_offer_answers' )]
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_the_default_install_trusts_only_an_authorised_offer( mixed $answer, string $code ): void {
		require_once __DIR__ . '/ReadOnlyCheckWordPressFunctions.php';
		$GLOBALS['ran_booster_read_only_check'] = array(
			'calls'      => array(),
			'transients' => array(),
			'answer'     => $answer,
		);
		[ $facade, $store ]                     = $this->switch_facade( null );

		$result = $facade->enable( 'plugin', 'example/example.php', 1, 'stable', 'valid' );
		self::assertFalse( $result->successful() );
		self::assertSame( $code, $result->code() );
		self::assertSame( array(), $store->transitions );
		// One exact-release offer for the newest eligible release, then nothing more.
		self::assertSame( array( array( 'ran_wp_release_updater_offer_release_v1', null, 'plugin', 'example/example.php', '101', 'v2.0.0' ) ), $GLOBALS['ran_booster_read_only_check']['calls'] );
	}

	/** @return iterable<string, array{string}> */
	public static function unfinished_switches(): iterable {
		yield 'install failed' => array( 'failed' );
		yield 'updater refused' => array( 'release_older' );
	}

	#[DataProvider( 'unfinished_switches' )]
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_unfinished_switch_blocks_a_second_attempt_in_its_request_and_leaves_the_next_request_clean( string $install ): void {
		$installs                       = 0;
		[ $facade, $store, $registrar ] = $this->switch_facade(
			static function () use ( &$installs, $install ): string {
				++$installs;

				return $install;
			}
		);
		self::assertFalse( $facade->enable( 'plugin', 'example/example.php', 1, 'stable', 'valid' )->successful() );
		$again = $facade->enable( 'plugin', 'example/example.php', 1, 'stable', 'valid' );
		self::assertFalse( $again->successful() );
		self::assertSame( 'release_unavailable', $again->code() );
		self::assertSame( 1, $installs );
		$this->assert_native_authority_error( $registrar->authorize_native_download( false, 'package.zip', new \stdClass(), self::NATIVE_EXTRA ) );
		self::assertSame( array(), $store->transitions );

		// Nothing about the switch is stored, so the next request admits it again.
		[ $next ] = $this->switch_facade(
			static function () use ( &$installs ): string {
				++$installs;

				return 'installed';
			},
			store: $store
		);
		self::assertSame( 'release_enabled', $next->enable( 'plugin', 'example/example.php', 1, 'stable', 'valid' )->code() );
		self::assertSame( 2, $installs );
	}

	/**
	 * @param (callable(string, string, string, string, bool): string)|null $switch_install Null runs the facade's own offer and upgrader.
	 * @param (callable(string, string): bool)|null            $verify_nonce
	 * @param (callable(string): void)|null                    $invalidate_native
	 * @param RuntimeReleaseStore|null                         $store             Shared with an earlier request's facade.
	 * @return array{NativeReleaseTrackingFacade, RuntimeReleaseStore, ManagedReleaseTargetRegistrar, RuntimeUpdaterLock}
	 */
	private function switch_facade( ?callable $switch_install, ?callable $verify_nonce = null, ?callable $invalidate_native = null, ?RuntimeReleaseStore $store = null ): array {
		$package = $this->package( 'plugin', 'example/example.php', 'example', DeploymentPolicy::MANUAL, source: PackageSource::BRANCH );
		$plugins = $this->createStub( PluginRepository::class );
		$plugins->method( 'booster_plugin_from_file' )->willReturn( $package );
		$plugins->method( 'all_deployment_plugins' )->willReturn( array() );
		$themes    = $this->createStub( ThemeRepository::class );
		$store   ??= new RuntimeReleaseStore();
		$providers = $this->release_metadata_registry();
		$registrar = $this->registrar( $plugins, $themes, $store, new RuntimeUpdaterLock(), $providers );
		$lock      = new RuntimeUpdaterLock();
		$facade    = $this->facade(
			$plugins,
			$themes,
			$store,
			$registrar,
			$lock,
			$providers,
			static fn ( string $type ): bool => 'plugin' === $type,
			$verify_nonce ?? static fn ( string $nonce ): bool => 'valid' === $nonce,
			metadata_eligible: static fn (): bool => true,
			invalidate_native: $invalidate_native ?? static function (): void {
			},
			switch_install: $switch_install
		);

		return array( $facade, $store, $registrar, $lock );
	}

	/** @return list<array{hook: string, callback: callable, priority: int, acceptedArgs: int}> */
	private function shutdown_rechecks(): array {
		return array_values( array_filter( $GLOBALS['ran_booster_runtime_actions'] ?? array(), static fn ( array $action ): bool => 'shutdown' === $action['hook'] ) );
	}

	#[DataProvider( 'release_adoption_targets' )]
	public function test_release_adoption_readiness_accepts_unmanaged_plugin_and_theme_targets( string $type, string $identifier, ManagedReleaseConfiguration $configuration ): void {
		$package = $this->package( $type, $identifier, 'plugin' === $type ? 'example' : 'example-theme', DeploymentPolicy::DISABLED );
		$plugins = $this->createStub( PluginRepository::class );
		$plugins->method( 'booster_plugin_from_file' )->willReturn( $package );
		$themes = $this->createStub( ThemeRepository::class );
		$themes->method( 'booster_theme_from_stylesheet' )->willReturn( $package );
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
			metadata_eligible: static fn (): bool => true
		);

		self::assertTrue( $facade->release_adoption_ready( $type, $package, $configuration ) );
		self::assertSame( 0, $lock->acquires );
		self::assertSame( array(), $store->transitions );
	}

	/** @return iterable<string, array{string,string,ManagedReleaseConfiguration}> */
	public static function release_adoption_targets(): iterable {
		yield 'plugin' => array( 'plugin', 'example/example.php', new ManagedReleaseConfiguration( 'example', 'example.php' ) );
		yield 'theme' => array( 'theme', 'example-theme', new ManagedReleaseConfiguration( 'example-theme', 'style.css' ) );
	}

	public function test_release_adoption_readiness_refuses_a_provider_native_target_collision(): void {
		$package = $this->package( 'plugin', 'example/example.php', 'example', DeploymentPolicy::DISABLED );
		$plugins = $this->createStub( PluginRepository::class );
		$plugins->method( 'booster_plugin_from_file' )->willReturn( $package );
		$themes    = $this->createStub( ThemeRepository::class );
		$store     = new RuntimeReleaseStore();
		$lock      = new RuntimeUpdaterLock();
		$providers = $this->release_metadata_registry( collision: true );
		$facade    = $this->facade(
			$plugins,
			$themes,
			$store,
			$this->registrar( $plugins, $themes, $store, $lock, $providers ),
			$lock,
			$providers,
			metadata_eligible: static fn (): bool => true
		);

		self::assertFalse( $facade->release_adoption_ready( 'plugin', $package, new ManagedReleaseConfiguration( 'example', 'example.php' ) ) );
		self::assertSame( 0, $lock->acquires );
		self::assertSame( array(), $store->transitions );
	}

	public function test_release_adoption_readiness_fails_closed_when_the_native_target_probe_is_unavailable(): void {
		$package = $this->package( 'plugin', 'example/example.php', 'example', DeploymentPolicy::DISABLED );
		$plugins = $this->createStub( PluginRepository::class );
		$plugins->method( 'booster_plugin_from_file' )->willReturn( $package );
		$themes    = $this->createStub( ThemeRepository::class );
		$store     = new RuntimeReleaseStore();
		$lock      = new RuntimeUpdaterLock();
		$providers = $this->release_metadata_registry( collision: new \RuntimeException( 'native target query unavailable' ) );
		$facade    = $this->facade(
			$plugins,
			$themes,
			$store,
			$this->registrar( $plugins, $themes, $store, $lock, $providers ),
			$lock,
			$providers,
			metadata_eligible: static fn (): bool => true
		);

		self::assertFalse( $facade->release_adoption_ready( 'plugin', $package, new ManagedReleaseConfiguration( 'example', 'example.php' ) ) );
		self::assertSame( 0, $lock->acquires );
		self::assertSame( array(), $store->transitions );
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
		self::assertSame( 'ran-booster-release-tracking-preflight-plugin-example/example.php-1-gh:123456789:11111111-1111-4111-8111-111111111111-prerelease', $plugin_nonce );
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

	public function test_switch_download_is_refused_when_a_repository_conflict_appears_after_admission(): void {
		$branch        = $this->package( 'plugin', self::NATIVE_PLUGIN, 'example', DeploymentPolicy::MANUAL, source: PackageSource::BRANCH );
		[ $registrar ] = $this->native_plugin_registrar( $branch );
		self::assertTrue( $registrar->admit_switch( 'plugin', $branch, new ManagedReleaseConfiguration( 'example', 'example.php' ), 7 ) );
		$this->repository_rows["plugin\0other/other.php"] = (object) array(
			'type'                   => '1',
			'package'                => 'other/other.php',
			'source'                 => PackageSource::BRANCH->value,
			'provider'               => 'gh',
			'provider_repository_id' => '123456789',
		);

		$this->assert_native_authority_error( $registrar->authorize_native_download( false, 'package.zip', new \stdClass(), self::NATIVE_EXTRA ) );
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
		$failure_mode       = new class() {
			public string $value = 'typed';
		};
		$providers          = $this->release_metadata_registry(
			// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed,Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- The release-runtime fixture callback retains the registered factory or provider callable signature while returning a controlled result.
			list_releases: static function ( string $type, RepositoryReference $repository, string $channel ) use ( &$references, &$failure_mode ): RepositoryReleaseCandidateList {
				$references[] = $repository;
				if ( in_array( $repository->credential_id, array( 'package-profile', 'private-profile' ), true ) ) {
					if ( 'typed' === $failure_mode->value ) {
						throw new RepositoryReleaseReadUnavailable( 'Fixture access failure.' );
					}
					if ( 'domain' === $failure_mode->value ) {
						throw new \RuntimeException( 'Fixture domain failure.' );
					}
				}

				return new RepositoryReleaseCandidateList( array() );
			},
			// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed,Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- The release-runtime fixture callback retains the registered factory or provider callable signature while returning a controlled result.
			inspect: static function ( string $type, RepositoryReference $repository, string $release_id, string $tag, string $channel ) use ( &$references, &$failure_mode ): RepositoryReleaseInspection {
				$references[] = $repository;
				if ( in_array( $failure_mode->value, array( 'digest_missing', 'future_reason' ), true ) ) {
					throw RepositoryReleaseInspectionRejected::incompatible( $failure_mode->value );
				}
				if ( in_array( $repository->credential_id, array( 'package-profile', 'private-profile' ), true ) ) {
					if ( 'typed' === $failure_mode->value ) {
						throw new RepositoryReleaseReadUnavailable( 'Fixture access failure.' );
					}
					if ( 'domain' === $failure_mode->value ) {
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

		foreach ( array( 'digest_missing', 'future_reason' ) as $mode ) {
			$failure_mode->value = $mode;
			$inspection          = $facade->inspect_candidate( 'plugin', 'example/example.php', 1, '102', 'v2.0.0', 'stable', $nonce );
			if ( 'digest_missing' === $mode ) {
				self::assertInstanceOf( ReleaseTrackingPreflight::class, $inspection );
				self::assertSame( 'digest_missing', $inspection->reason_code() );
				self::assertFalse( $inspection->ready() );
			} else {
				self::assertNull( $inspection );
			}
		}
		$failure_mode->value = 'typed';

		$current    = 'private';
		$references = array();
		$nonce      = $facade->nonce_action( 'list_candidates', 'plugin', 'example/example.php', 1, 'stable' );
		self::assertNull( $facade->list_candidates( 'plugin', 'example/example.php', 1, 'stable', $nonce ) );
		$nonce = $facade->nonce_action( 'inspect_candidate', 'plugin', 'example/example.php', 1, 'stable' );
		self::assertNull( $facade->inspect_candidate( 'plugin', 'example/example.php', 1, '101', 'v2.0.0', 'stable', $nonce ) );
		self::assertSame( array( 'private-profile', 'private-profile' ), array_map( static fn ( RepositoryReference $reference ): ?string => $reference->credential_id, $references ) );
		self::assertSame( array( true, true ), array_map( static fn ( RepositoryReference $reference ): bool => $reference->private, $references ) );

		// Every unreadable release read was logged by operation with the exception class and code only, never its message.
		$lines = $this->captured_log_lines();
		self::assertNotSame( array(), $lines );
		foreach ( $lines as $line ) {
			self::assertStringStartsWith( '[ran-booster] release read failed {"operation":"', $line );
			self::assertStringContainsString( '"provider":"gh"', $line );
			self::assertStringContainsString( 'RepositoryReleaseReadUnavailable', $line );
			self::assertStringNotContainsString( 'Fixture access failure', $line );
		}
		self::assertSame( array( 'list_release_candidates', 'inspect_release' ), array_slice( array_map( static fn ( string $line ): string => (string) preg_replace( '/\A.*\{"operation":"([a-z_]+)".*\z/', '$1', $line ), $lines ), -2 ) );

		$failure_mode->value = 'none';
		$current             = 'explicit';
		$references          = array();
		$nonce               = $facade->nonce_action( 'list_candidates', 'plugin', 'example/example.php', 1, 'stable' );
		self::assertInstanceOf( RepositoryReleaseCandidateList::class, $facade->list_candidates( 'plugin', 'example/example.php', 1, 'stable', $nonce ) );
		$nonce = $facade->nonce_action( 'inspect_candidate', 'plugin', 'example/example.php', 1, 'stable' );
		self::assertTrue( $facade->inspect_candidate( 'plugin', 'example/example.php', 1, '101', 'v2.0.0', 'stable', $nonce )?->ready() );
		self::assertSame( array( 'package-profile', 'package-profile' ), array_map( static fn ( RepositoryReference $reference ): ?string => $reference->credential_id, $references ) );

		$failure_mode->value = 'domain';
		$references          = array();
		$nonce               = $facade->nonce_action( 'list_candidates', 'plugin', 'example/example.php', 1, 'stable' );
		self::assertNull( $facade->list_candidates( 'plugin', 'example/example.php', 1, 'stable', $nonce ) );
		self::assertSame( array( 'package-profile' ), array_map( static fn ( RepositoryReference $reference ): ?string => $reference->credential_id, $references ) );

		$failure_mode->value = 'none';
		$current             = 'private_lookup';
		$references          = array();
		$nonce               = $facade->nonce_action( 'list_candidates', 'plugin', 'example/example.php', 1, 'stable' );
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
		self::assertNotNull( $preflight );
		self::assertSame( ReleaseTrackingPreflight::RELEASE_UNAVAILABLE, $preflight->code() );
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
		$failure_mode->value         = 'typed';
		$nonce                       = $facade->nonce_action( 'preflight', 'plugin', 'example/example.php', 1, 'stable' );
		$preflight                   = $facade->preflight( 'plugin', 'example/example.php', 1, 'stable', $nonce );
		self::assertNotNull( $preflight );
		self::assertSame( ReleaseTrackingPreflight::RELEASE_UNAVAILABLE, $preflight->code() );
		self::assertSame( array( 'package-profile', 'public-profile' ), array_map( static fn ( RepositoryReference $reference ): ?string => $reference->credential_id, $references ) );
		self::assertSame( 'package-profile', $packages['branch_explicit']->get_repository()->reference->credential_id );
	}

	public function test_only_an_anonymous_rate_limited_public_listing_reports_a_lookup_hint_provider(): void {
		$packages = array(
			'public'   => $this->package( 'plugin', 'example/example.php', 'example', DeploymentPolicy::MANUAL ),
			'explicit' => $this->package( 'plugin', 'example/example.php', 'example', DeploymentPolicy::MANUAL, credential_id: 'package-profile' ),
		);
		$current  = 'public';
		$plugins  = $this->createStub( PluginRepository::class );
		$plugins->method( 'booster_plugin_from_file' )->willReturnCallback(
			static function () use ( &$packages, &$current ): Package {
				return $packages[ $current ];
			}
		);
		$themes    = $this->createStub( ThemeRepository::class );
		$store     = new RuntimeReleaseStore(
			array( "plugin\0example/example.php" => new ManagedReleaseConfiguration( 'example', 'example.php' ) )
		);
		$lock      = new RuntimeUpdaterLock();
		$profiles  = new InMemoryPublicRepositoryLookupProfileStore();
		$reason    = new class() {
			public string $value = 'rate_limited';
		};
		$providers = $this->release_metadata_registry(
			// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed,Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- The release-runtime fixture callback retains the registered factory or provider callable signature while returning a controlled result.
			list_releases: static function ( string $type, RepositoryReference $repository, string $channel ) use ( $reason ): RepositoryReleaseCandidateList {
				if ( 'none' === $reason->value ) {
					return new RepositoryReleaseCandidateList( array() );
				}

				throw new RepositoryReleaseReadUnavailable( 'Fixture access failure.', 0, null, new ProviderFailure( $reason->value, 'http_5xx' === $reason->value ? 502 : 429 ) );
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
		$list   = static function () use ( $facade ): ?RepositoryReleaseCandidateList {
			return $facade->list_candidates( 'plugin', 'example/example.php', 1, 'stable', $facade->nonce_action( 'list_candidates', 'plugin', 'example/example.php', 1, 'stable' ) );
		};

		// Anonymous public lookup that was rate-limited: the provider is reported, secondary throttling included.
		self::assertNull( $list() );
		self::assertSame( 'gh', $facade->anonymous_rate_limit_provider() );
		$reason->value = 'rate_limited_secondary';
		self::assertNull( $list() );
		self::assertSame( 'gh', $facade->anonymous_rate_limit_provider() );

		// An unrelated outage is not helped by a lookup credential.
		$reason->value = 'http_5xx';
		self::assertNull( $list() );
		self::assertNull( $facade->anonymous_rate_limit_provider() );

		// A selected lookup credential, or a package credential, is already in use.
		$reason->value      = 'rate_limited';
		$profiles->profiles = array( 'gh' => 'public-profile' );
		self::assertNull( $list() );
		self::assertNull( $facade->anonymous_rate_limit_provider() );
		$profiles->profiles = array();
		$current            = 'explicit';
		self::assertNull( $list() );
		self::assertNull( $facade->anonymous_rate_limit_provider() );

		// A successful listing clears the previous report.
		$current = 'public';
		self::assertNull( $list() );
		self::assertSame( 'gh', $facade->anonymous_rate_limit_provider() );
		$reason->value = 'none';
		self::assertInstanceOf( RepositoryReleaseCandidateList::class, $list() );
		self::assertNull( $facade->anonymous_rate_limit_provider() );
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
			new ManagedReleaseTargetRegistrar( $plugins, $themes, $store, $lock, $providers, $this->github_selection() ),
			$lock,
			$providers,
			static fn (): bool => true,
			static fn ( string $nonce, string $action ): bool => hash_equals( $action, $nonce ),
			metadata_eligible: static fn (): bool => true,
			public_lookup_profile: static fn (): string => 'public-profile'
		);

		$preflight_nonce = $facade->nonce_action( 'preflight', 'plugin', 'example/example.php', 1, 'stable' );
		$preflight       = $facade->preflight( 'plugin', 'example/example.php', 1, 'stable', $preflight_nonce );
		self::assertNotNull( $preflight );
		self::assertSame( ReleaseTrackingPreflight::PREFLIGHT_UNAVAILABLE, $preflight->code() );
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
		$mode             = new class() {
			public string $value = 'release_id';
		};
		$inspection_calls = 0;
		$providers        = $this->release_metadata_registry(
			list_releases: static function () use ( &$mode ): RepositoryReleaseCandidateList {
				if ( 'operational' === $mode->value ) {
					throw new \RuntimeException( 'token=must-not-escape' );
				}
				if ( 'none' === $mode->value ) {
					return new RepositoryReleaseCandidateList( array() );
				}
				if ( 'fallback' === $mode->value ) {
					return new RepositoryReleaseCandidateList(
						array(
							new RepositoryReleaseCandidate( '101', 'v3.0.0', '3.0.0', false, '2026-08-17T12:00:00Z', array() ),
							new RepositoryReleaseCandidate( '102', 'v2.0.0', '2.0.0', false, '2026-08-16T12:00:00Z', array( 'example.zip' ) ),
						)
					);
				}
				if ( 'incompatible_budget' === $mode->value ) {
					return new RepositoryReleaseCandidateList(
						array(
							new RepositoryReleaseCandidate( '101', 'v3.0.0', '3.0.0', false, '2026-08-17T12:00:00Z', array( 'example.zip' ) ),
							new RepositoryReleaseCandidate( '102', 'v2.5.0', '2.5.0', false, '2026-08-16T12:00:00Z', array( 'example.zip' ) ),
							new RepositoryReleaseCandidate( '103', 'v2.0.0', '2.0.0', false, '2026-08-15T12:00:00Z', array( 'example.zip' ) ),
						)
					);
				}

				$prerelease = 'channel' === $mode->value;
				$version    = 'hyphenated_stable' === $mode->value ? '2026-08' : ( $prerelease ? '2.0.0-beta' : '2.0.0' );
				return new RepositoryReleaseCandidateList(
					array(
						new RepositoryReleaseCandidate(
							'101',
							$prerelease ? 'v2.0.0-beta' : ( 'hyphenated_stable' === $mode->value ? 'v2026-08' : 'v2.0.0' ),
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
				if ( 'incompatible_budget' === $mode->value || ( 'fallback' === $mode->value && '101' === $release_id ) ) {
					throw RepositoryReleaseInspectionRejected::incompatible();
				}

				return new RepositoryReleaseInspection(
					'release_id' === $mode->value ? '999' : $release_id,
					'tag' === $mode->value ? 'v2.0.1' : $tag,
					'version' === $mode->value ? '2.0.1' : ( 'hyphenated_stable' === $mode->value ? '2026-08' : '2.0.0' ),
					str_repeat( 'a', 40 ),
					'package_root' === $mode->value ? 'other' : 'example',
					'main_file' === $mode->value ? 'other.php' : 'example.php',
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

		foreach ( array( 'release_id', 'tag', 'version', 'package_root', 'main_file' ) as $mode->value ) {
			$result = $facade->preflight( 'plugin', 'example/example.php', 1, 'stable', $nonce );
			self::assertNotNull( $result );
			self::assertSame( ReleaseTrackingPreflight::INVALID_RELEASE_ASSETS, $result->code(), $mode->value );
			self::assertSame( 'release_identity_mismatch', $result->reason_code(), $mode->value );
			// The ZIP's layout is named only when that is what differs.
			self::assertSame(
				array(
					'package_root' => 'other/example.php',
					'main_file'    => 'example/other.php',
				)[ $mode->value ] ?? '',
				$result->detail_text(),
				$mode->value
			);
		}

		$calls_before_fallback = $inspection_calls;
		$mode->value           = 'fallback';
		$result                = $facade->preflight( 'plugin', 'example/example.php', 1, 'stable', $nonce );
		self::assertNotNull( $result );
		self::assertSame( ReleaseTrackingPreflight::READY, $result->code() );
		self::assertSame( 'v2.0.0', $result->release_tag() );
		self::assertSame( 2, $inspection_calls - $calls_before_fallback );

		$mode->value = 'channel';
		$result      = $facade->preflight( 'plugin', 'example/example.php', 1, 'stable', $nonce );
		self::assertNotNull( $result );
		self::assertSame( ReleaseTrackingPreflight::INVALID_RELEASE_ASSETS, $result->code() );
		self::assertSame( 'invalid_release', $result->reason_code() );

		$mode->value = 'none';
		$result      = $facade->preflight( 'plugin', 'example/example.php', 1, 'stable', $nonce );
		self::assertNotNull( $result );
		self::assertSame( ReleaseTrackingPreflight::RELEASE_UNAVAILABLE, $result->code() );
		self::assertSame( 'no_releases', $result->reason_code() );

		$mode->value = 'operational';
		$result      = $facade->preflight( 'plugin', 'example/example.php', 1, 'stable', $nonce );
		self::assertNotNull( $result );
		self::assertSame( ReleaseTrackingPreflight::PREFLIGHT_UNAVAILABLE, $result->code() );
		self::assertSame( 'provider_unavailable', $result->reason_code() );
		self::assertStringNotContainsString( 'token', $result->reason_code() );

		$mode->value = 'hyphenated_stable';
		$result      = $facade->preflight( 'plugin', 'example/example.php', 1, 'stable', $nonce );
		self::assertNotNull( $result );
		self::assertSame( ReleaseTrackingPreflight::READY, $result->code() );
		self::assertSame( '2026-08', $result->latest_version() );

		$calls_before_budget = $inspection_calls;
		$mode->value         = 'incompatible_budget';
		$result              = $facade->preflight( 'plugin', 'example/example.php', 1, 'stable', $nonce );
		self::assertNotNull( $result );
		self::assertSame( ReleaseTrackingPreflight::RELEASE_UNAVAILABLE, $result->code() );
		self::assertSame( 'release_incompatible', $result->reason_code() );
		self::assertSame( 'v3.0.0', $result->detail_text() );
		self::assertSame( 2, $inspection_calls - $calls_before_budget );
		self::assertSame( array(), $store->transitions );
		self::assertSame( 0, $lock->acquires );
	}

	public function test_enable_reports_why_no_release_was_eligible_and_inspects_zip_less_releases(): void {
		$package = $this->package(
			'plugin',
			'example/example.php',
			'example',
			DeploymentPolicy::MANUAL,
			source: PackageSource::BRANCH
		);
		$plugins = $this->createStub( PluginRepository::class );
		$plugins->method( 'booster_plugin_from_file' )->willReturn( $package );
		$themes      = $this->createStub( ThemeRepository::class );
		$store       = new RuntimeReleaseStore();
		$lock        = new RuntimeUpdaterLock();
		$mode        = new class() {
			public string $value = 'none';
		};
		$inspections = 0;
		$providers   = $this->release_metadata_registry(
			list_releases: static function () use ( &$mode ): RepositoryReleaseCandidateList {
				// The asset names the listing shows for the newest release and for the one before it.
				$names = array(
					'no_zip'           => array( array(), array() ),
					'two_zips'         => array( array( 'a.zip', 'b.zip' ), array( 'a.zip', 'b.zip' ) ),
					'incompatible'     => array( array( 'example.zip' ), array( 'example.zip' ) ),
					'newest_one'       => array( array( 'example.zip' ), array() ),
					'newest_none'      => array( array(), array( 'a.zip', 'b.zip' ) ),
					'detail_known'     => array( array( 'example.zip' ), array() ),
					'detail_explained' => array( array( 'example.zip' ), array() ),
					'plus_tag'         => array( array(), array() ),
					'detail_other'     => array( array( 'example.zip' ), array() ),
					'detail_bad'       => array( array( 'a.zip', 'b.zip' ), array() ),
					'failure_missing'  => array( array( 'a.zip', 'b.zip' ), array() ),
					'failure_generic'  => array( array( 'a.zip', 'b.zip' ), array() ),
					'failure_wins'     => array( array( 'example.zip' ), array() ),
					'failure_other'    => array( array( 'a.zip', 'b.zip' ), array() ),
				)[ $mode->value ] ?? array( array(), array() );
				return new RepositoryReleaseCandidateList(
					'none' === $mode->value
						? array()
						: array(
							new RepositoryReleaseCandidate( '101', 'plus_tag' === $mode->value ? 'v3.0.0+build.1' : 'v3.0.0', '3.0.0', false, '2026-08-17T12:00:00Z', $names[0] ),
							new RepositoryReleaseCandidate( '102', 'v2.0.0', '2.0.0', false, '2026-08-16T12:00:00Z', $names[1] ),
							new RepositoryReleaseCandidate( '103', 'v1.0.0', '1.0.0', false, '2026-08-15T12:00:00Z', $names[0] ),
						)
				);
			},
			inspect: static function () use ( &$inspections, &$mode ): RepositoryReleaseInspection {
				++$inspections;
				throw RepositoryReleaseInspectionRejected::incompatible(
					array(
						'detail_known'        => 'package_header_missing',
						'detail_explained'    => 'package_asset_ambiguous',
						'digest_missing'      => 'digest_missing',
						'digest_failure_wins' => 'digest_missing',
						'detail_other'        => 'a_future_reason',
						'detail_bad'          => 'Not-A-Reason',
						'failure_wins'        => 'package_asset_ambiguous',
					)[ $mode->value ] ?? null,
					match ( $mode->value ) {
						'failure_missing', 'failure_wins' => new ProviderFailure( 'package_asset_missing' ),
						'failure_generic', 'digest_failure_wins' => new ProviderFailure( 'release_incompatible' ),
						'failure_other' => new ProviderFailure( 'release_invalid' ),
						default => null,
					}
				);
			}
		);
		$facade      = $this->facade(
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

		foreach ( array(
			'none'                => array( 'no_releases', 0, '' ),
			// The listing's asset names are not trusted to be complete, so a release listed without a ZIP is still inspected.
			// They only choose the wording after inspection has rejected the release.
			'no_zip'              => array( 'package_asset_missing', 2, 'v3.0.0' ),
			'two_zips'            => array( 'package_asset_ambiguous', 2, 'v3.0.0' ),
			'incompatible'        => array( 'release_incompatible', 2, 'v3.0.0' ),
			// The newest release names the reason even when the one before it was rejected differently.
			'newest_one'          => array( 'release_incompatible', 2, 'v3.0.0' ),
			'newest_none'         => array( 'package_asset_missing', 2, 'v3.0.0' ),
			// A provider's own reason wins only when Core has copy for it; a known code without copy, an unknown
			// one and a malformed one fall back to the reason the listing shows.
			'detail_explained'    => array( 'package_asset_ambiguous', 2, 'v3.0.0' ),
			'digest_missing'      => array( 'digest_missing', 2, 'v3.0.0' ),
			'digest_failure_wins' => array( 'release_incompatible', 2, 'v3.0.0' ),
			'detail_known'        => array( 'release_incompatible', 2, 'v3.0.0' ),
			// A tag with a character a redirect cannot carry gives no detail, so the generic copy is used.
			'plus_tag'            => array( 'package_asset_missing', 2, '' ),
			'detail_other'        => array( 'release_incompatible', 2, 'v3.0.0' ),
			'detail_bad'          => array( 'package_asset_ambiguous', 2, 'v3.0.0' ),
			// A failure the provider carries wins over its legacy detail and over the listing's ZIP count; one Core has
			// no wording for falls back to the listing.
			'failure_missing'     => array( 'package_asset_missing', 2, 'v3.0.0' ),
			'failure_generic'     => array( 'release_incompatible', 2, 'v3.0.0' ),
			'failure_wins'        => array( 'package_asset_missing', 2, 'v3.0.0' ),
			'failure_other'       => array( 'package_asset_ambiguous', 2, 'v3.0.0' ),
		) as $case => $expected ) {
			$mode->value                                = $case;
			[ $reason, $expected_inspections, $detail ] = $expected;
			$inspections                                = 0;
			$result                                     = $facade->enable( 'plugin', 'example/example.php', 1, 'stable', 'valid' );

			self::assertFalse( $result->successful(), $case );
			self::assertSame( 'release_unavailable', $result->code(), $case );
			self::assertSame( $reason, $result->reason(), $case );
			self::assertSame( $detail, $result->detail(), $case );
			self::assertSame( $expected_inspections, $inspections, $case );
		}
		self::assertSame( array(), $store->transitions );

		$this->repository_rows["plugin\0example/example.php"]->source = 'unknown';
		$guard = $facade->enable( 'plugin', 'example/example.php', 1, 'stable', 'valid' );
		self::assertSame( 'release_unavailable', $guard->code() );
		self::assertSame( '', $guard->reason() );
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
			use \RAN\Tests\RepositoryProvider\Support\SuppliesProviderDiagnostics;

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
		self::assertNotNull( $result );

		self::assertSame( ReleaseTrackingPreflight::PREFLIGHT_UNAVAILABLE, $result->code() );
		self::assertSame( 'provider_unavailable', $result->reason_code() );
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
		self::assertNotNull( $result );

		self::assertSame( ReleaseTrackingPreflight::RELEASE_UNAVAILABLE, $result->code() );
		self::assertSame( 'no_releases', $result->reason_code() );
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
		$expected_action = 'ran-booster-release-tracking-enable-plugin-' . $identifier . '-1-gh:123456789:11111111-1111-4111-8111-111111111111';
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
		$expected_action = 'ran-booster-release-tracking-enable-plugin-example/example.php-1-gh:123456789:11111111-1111-4111-8111-111111111111';
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

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_a_paused_site_refuses_switching_into_preview_or_a_branch(): void {
		$GLOBALS['ran_booster_test_environment_type'] = 'production';
		$GLOBALS['ran_booster_test_home_url']         = 'https://example.com';
		$GLOBALS['ran_booster_storage_test_options']  = array();
		$package                                      = $this->package( 'plugin', 'example/example.php', 'example', DeploymentPolicy::MANUAL );
		$plugins                                      = $this->createStub( PluginRepository::class );
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

		$directory = sys_get_temp_dir() . '/ran-booster-paused-log-' . bin2hex( random_bytes( 8 ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Isolated capture fixture.
		self::assertTrue( mkdir( $directory, 0700 ) );
		$capture = new \RAN\Logging\TemporaryDebugCapture( $directory . '/capture.json', static fn (): int => strtotime( '2026-11-19T12:00:00Z' ) );
		$capture->start();
		\RAN\Logging\BoosterLogger::configure_capture( $capture );
		try {
			self::assertSame(
				array( 'source_paused', 'source_paused', 'source_paused', 'release_channel_current' ),
				array(
					$facade->change_channel( 'plugin', 'example/example.php', 1, 'prerelease', 'nonce' )->code(),
					$facade->enable( 'plugin', 'example/example.php', 1, 'prerelease', 'nonce' )->code(),
					$facade->return_to_branch( 'plugin', 'example/example.php', 1, 'nonce' )->code(),
					$facade->change_channel( 'plugin', 'example/example.php', 1, 'stable', 'nonce' )->code(),
				)
			);
			$entries = array_column( $capture->snapshot()['entries'], 'line' );
		} finally {
			\RAN\Logging\BoosterLogger::configure_capture( null );
			foreach ( (array) glob( $directory . '/*' ) as $path ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Isolated capture fixture.
				unlink( (string) $path );
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Isolated capture fixture.
			rmdir( $directory );
		}
		self::assertSame( array(), $store->channel_changes );
		self::assertSame( array(), $store->transitions );
		// Each refusal writes one line holding only its code.
		self::assertSame( array_fill( 0, 3, '[ran-booster] release operation refused {"event":"release_operation_refused","outcome_code":"source_paused"}' ), $entries );
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
		$target = $registrar->target( 'theme', 'example-theme' );
		self::assertInstanceOf( RuntimeUpdaterFacade::class, $target );
		self::assertSame( 1, $target->refreshes() );
		$target->reject_refresh();
		self::assertSame( 'refresh_failed', $facade->refresh( 'theme', 'example-theme', 1, 'nonce' )->code() );
		self::assertSame( array( 'theme' ), $refreshes );
		$target->fail_refresh();
		self::assertSame( 'refresh_failed', $facade->refresh( 'theme', 'example-theme', 1, 'nonce' )->code() );
		self::assertSame( array( 'theme' ), $refreshes );
		self::assertFalse( $facade->refresh( 'theme', 'example-theme', 2, 'nonce' )->successful() );
		self::assertSame( array( 'theme' ), $refreshes );
		self::assertSame( 0, $lock->acquires );
		self::assertSame( array(), $lock->releases );
	}

	/** @return array<string, array{string, string, string}> */
	public static function observed_check_outcomes(): array {
		return array(
			'disabled'     => array( 'skipped', 'policy_disabled', 'refresh_skipped_disabled' ),
			'forced off'   => array( 'skipped', 'policy_forced_off', 'refresh_skipped_forced_off' ),
			'not admitted' => array( 'skipped', 'discovery_not_admitted', 'refresh_unchecked' ),
			'rejected'     => array( 'rejected', 'candidate_rejected', 'refresh_rejected' ),
			'failed'       => array( 'failed', 'release_list_failed', 'refresh_check_failed' ),
		);
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'observed_check_outcomes' )]
	public function test_refresh_never_reports_success_for_a_check_that_did_not_succeed( string $status, string $reason, string $code ): void {
		$package = $this->package( 'plugin', self::NATIVE_PLUGIN, 'example', DeploymentPolicy::MANUAL );
		// No Activity store: the outcome must not depend on Activity recording.
		[$registrar] = $this->native_plugin_registrar( $package );
		$plugins     = $this->createStub( PluginRepository::class );
		$plugins->method( 'booster_plugin_from_file' )->willReturn( $package );
		$store  = new RuntimeReleaseStore( array( "plugin\0" . self::NATIVE_PLUGIN => new ManagedReleaseConfiguration( 'example', 'example.php' ) ) );
		$facade = $this->facade(
			$plugins,
			$this->createStub( ThemeRepository::class ),
			$store,
			$registrar,
			new RuntimeUpdaterLock(),
			$this->release_metadata_registry(),
			static fn (): bool => true,
			static fn (): bool => true,
			static function () use ( $registrar, $status, $reason ): void {
				$check_id = str_repeat( 'c', 32 );
				$registrar->activity_check_started(
					array(
						'check_id'            => $check_id,
						'network_id'          => 1,
						'blog_id'             => 1,
						'target_type'         => 'plugin',
						'installed_identity'  => self::NATIVE_PLUGIN,
						'provider_code'       => 'github',
						'repository_identity' => '123456789',
						'channel'             => 'stable',
						'binding_hash'        => str_repeat( 'b', 64 ),
					)
				);
				$registrar->activity_check_completed(
					array(
						'check_id'                  => $check_id,
						'status'                    => $status,
						'reason'                    => $reason,
						'relationship'              => null,
						'installed_version'         => '1.0.0',
						'candidate_version'         => null,
						'offered_version'           => null,
						'candidate_validation_code' => null,
					)
				);
			},
			null,
			static fn (): bool => true
		);

		$result = $facade->refresh( 'plugin', self::NATIVE_PLUGIN, 1, 'nonce' );

		self::assertFalse( $result->successful() );
		self::assertSame( $code, $result->code() );
		self::assertSame( $reason, $result->check_reason() );
	}

	public function test_a_refresh_that_observes_no_check_does_not_reuse_the_previous_outcome(): void {
		$package     = $this->package( 'plugin', self::NATIVE_PLUGIN, 'example', DeploymentPolicy::MANUAL );
		[$registrar] = $this->native_plugin_registrar( $package );
		$plugins     = $this->createStub( PluginRepository::class );
		$plugins->method( 'booster_plugin_from_file' )->willReturn( $package );
		$store         = new RuntimeReleaseStore( array( "plugin\0" . self::NATIVE_PLUGIN => new ManagedReleaseConfiguration( 'example', 'example.php' ) ) );
		$updater       = new \stdClass();
		$updater->fire = true;
		$facade        = $this->facade(
			$plugins,
			$this->createStub( ThemeRepository::class ),
			$store,
			$registrar,
			new RuntimeUpdaterLock(),
			$this->release_metadata_registry(),
			static fn (): bool => true,
			static fn (): bool => true,
			static function () use ( $registrar, $updater ): void {
				if ( ! $updater->fire ) {
					return;
				}
				$check_id = str_repeat( 'd', 32 );
				$registrar->activity_check_started(
					array(
						'check_id'            => $check_id,
						'network_id'          => 1,
						'blog_id'             => 1,
						'target_type'         => 'plugin',
						'installed_identity'  => self::NATIVE_PLUGIN,
						'provider_code'       => 'github',
						'repository_identity' => '123456789',
						'channel'             => 'stable',
						'binding_hash'        => str_repeat( 'b', 64 ),
					)
				);
				$registrar->activity_check_completed(
					array(
						'check_id'                  => $check_id,
						'status'                    => 'skipped',
						'reason'                    => 'policy_disabled',
						'relationship'              => null,
						'installed_version'         => '1.0.0',
						'candidate_version'         => null,
						'offered_version'           => null,
						'candidate_validation_code' => null,
					)
				);
			},
			null,
			static fn (): bool => true
		);

		self::assertSame( 'refresh_skipped_disabled', $facade->refresh( 'plugin', self::NATIVE_PLUGIN, 1, 'nonce' )->code() );
		$updater->fire = false;
		self::assertSame( 'release_refreshed', $facade->refresh( 'plugin', self::NATIVE_PLUGIN, 1, 'nonce' )->code() );
	}

	/** @return array<string, array{DeploymentPolicy, ?array<string, mixed>, string, string, bool}> */
	public static function read_only_check_answers(): array {
		$report = static fn ( string $status, string $reason, ?string $candidate = null ): array => array(
			'status'                    => $status,
			'reason'                    => $reason,
			'policy'                    => 'disabled',
			'relationship'              => null === $candidate ? null : 'newer',
			'installed_version'         => '1.0.0',
			'candidate_version'         => $candidate,
			'candidate_validation_code' => null,
			'offered'                   => false,
		);

		return array(
			'newer release'          => array( DeploymentPolicy::DISABLED, $report( 'succeeded', 'newer_release_observed', '2.0.0' ), 'refresh_newer_while_off', 'newer_release_observed', true ),
			'no newer release'       => array( DeploymentPolicy::DISABLED, $report( 'succeeded', 'no_newer_candidate' ), 'refresh_current_while_off', 'no_newer_candidate', true ),
			'no candidates'          => array( DeploymentPolicy::DISABLED, $report( 'succeeded', 'no_candidates' ), 'refresh_current_while_off', 'no_candidates', true ),
			'rejected'               => array( DeploymentPolicy::DISABLED, $report( 'rejected', 'candidate_rejected' ), 'refresh_rejected', 'candidate_rejected', true ),
			'failed'                 => array( DeploymentPolicy::DISABLED, $report( 'failed', 'release_list_failed' ), 'refresh_check_failed', 'release_list_failed', true ),
			'busy'                   => array( DeploymentPolicy::DISABLED, $report( 'failed', 'runtime_busy' ), 'refresh_check_failed', 'runtime_busy', true ),
			'forced off'             => array( DeploymentPolicy::DISABLED, $report( 'skipped', 'policy_forced_off' ), 'refresh_skipped_forced_off', 'policy_forced_off', true ),
			'older runtime'          => array( DeploymentPolicy::DISABLED, null, 'release_refreshed', '', false ),
			'offer claimed'          => array( DeploymentPolicy::DISABLED, array_replace( $report( 'succeeded', 'newer_release_observed', '2.0.0' ), array( 'offered' => true ) ), 'release_refreshed', '', false ),
			'not an array'           => array( DeploymentPolicy::DISABLED, null, 'release_refreshed', '', false ),
			'unobserved claim'       => array( DeploymentPolicy::DISABLED, $report( 'succeeded', 'no_newer_candidate' ) + array( 'unobserved' => true ), 'refresh_unchecked', 'check_not_observed', true ),
			'bad reason'             => array( DeploymentPolicy::DISABLED, $report( 'succeeded', 'Bad Reason' ), 'release_refreshed', '', false ),
			'unknown reason'         => array( DeploymentPolicy::DISABLED, $report( 'succeeded', 'something_new' ), 'refresh_unchecked', 'something_new', true ),
			'manual is not asked'    => array( DeploymentPolicy::MANUAL, $report( 'succeeded', 'newer_release_observed', '2.0.0' ), 'release_refreshed', '', false ),
			'automatic is not asked' => array( DeploymentPolicy::AUTOMATIC, $report( 'succeeded', 'newer_release_observed', '2.0.0' ), 'release_refreshed', '', false ),
		);
	}

	/**
	 * @param array<string, mixed>|null $report What the updater's read-only check filter answered.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'read_only_check_answers' )]
	public function test_check_releases_asks_the_read_only_check_only_while_updates_are_off( DeploymentPolicy $policy, ?array $report, string $code, string $check_reason, bool $answered ): void {
		$package                                     = $this->package( 'plugin', self::NATIVE_PLUGIN, 'example', $policy );
		$connection                                  = new \RAN\Tests\Activity\ActivityConnection();
		$activity                                    = new \RAN\Activity\ActivityStore( $connection );
		$GLOBALS['ran_booster_storage_test_options'] = array();
		$GLOBALS['ran_booster_activity_options']     = array( \RAN\Activity\ActivityStore::VERSION_OPTION => '1' );
		[$registrar, , $target]                      = $this->native_plugin_registrar( $package, activity: $activity );
		$plugins                                     = $this->createStub( PluginRepository::class );
		$plugins->method( 'booster_plugin_from_file' )->willReturn( $package );
		$store       = new RuntimeReleaseStore( array( "plugin\0" . self::NATIVE_PLUGIN => new ManagedReleaseConfiguration( 'example', 'example.php' ) ) );
		$asked       = 0;
		$native      = 0;
		$invalidated = 0;
		$identity    = array(
			'network_id'          => 1,
			'blog_id'             => 1,
			'target_type'         => 'plugin',
			'installed_identity'  => self::NATIVE_PLUGIN,
			'provider_code'       => 'github',
			'repository_identity' => '123456789',
			'channel'             => 'stable',
			'binding_hash'        => str_repeat( 'b', 64 ),
		);
		$facade      = $this->facade(
			$plugins,
			$this->createStub( ThemeRepository::class ),
			$store,
			$registrar,
			new RuntimeUpdaterLock(),
			$this->release_metadata_registry(),
			static fn (): bool => true,
			static fn (): bool => true,
			static function () use ( &$native ): void {
				++$native;
			},
			null,
			static function () use ( &$invalidated ): void {
				++$invalidated;
			},
			check_without_offer: static function () use ( &$asked, $registrar, $report, $identity ): ?array {
				++$asked;
				// The updater emits the same observation actions as a native check.
				if ( null !== $report && ! isset( $report['unobserved'] ) && ! in_array( $report['reason'], array( 'policy_forced_off', 'runtime_busy', 'Bad Reason', 'something_new' ), true ) ) {
					$registrar->activity_check_started( array( 'check_id' => str_repeat( 'e', 32 ) ) + $identity );
					$registrar->activity_check_completed(
						array(
							'check_id'                  => str_repeat( 'e', 32 ),
							'status'                    => $report['status'],
							'reason'                    => $report['reason'],
							'relationship'              => $report['relationship'],
							'installed_version'         => $report['installed_version'],
							'candidate_version'         => $report['candidate_version'],
							'offered_version'           => null,
							'candidate_validation_code' => null,
						)
					);
				}

				return $report;
			}
		);

		$result = $facade->refresh( 'plugin', self::NATIVE_PLUGIN, 1, 'nonce' );

		self::assertSame( $code, $result->code() );
		self::assertSame( $check_reason, $result->check_reason() );
		self::assertSame( DeploymentPolicy::DISABLED === $policy ? 1 : 0, $asked );
		// An answered check never also runs the native check, so Activity records it once.
		self::assertSame( $answered ? 0 : 1, $native );
		// Nor refreshes the target, whose refresh clears WordPress's cached update check. Check
		// releases never uses the facade's own cache invalidation on any path.
		self::assertSame( $answered ? 0 : 1, $target->refreshes() );
		self::assertSame( 0, $invalidated );
		if ( 'release_refreshed' === $code ) {
			// Nothing answered, or updates are on: today's native path, never an up-to-date claim.
			self::assertTrue( $result->successful() );
			return;
		}
		self::assertFalse( $result->successful(), 'A check made while updates are off is never a success.' );
		self::assertSame( 'refresh_newer_while_off' === $code ? '2.0.0' : '', $result->detail() );
		if ( 'newer_release_observed' !== $check_reason ) {
			return;
		}
		$completions = array_values( array_filter( $connection->prepared, static fn ( array $query ): bool => str_starts_with( $query['sql'], 'UPDATE' ) ) );
		self::assertCount( 1, $completions );
		self::assertSame( 'succeeded', $completions[0]['args'][0] );
		self::assertSame( 'newer_release_observed', $completions[0]['args'][1] );
		self::assertSame( 'established', $completions[0]['args'][5] );
		// The Activity facts feed the past-observation row, which is never an offer.
		self::assertSame(
			array(
				'version' => '2.0.0',
				'at'      => '2026-11-13 10:00:00',
			),
			\RAN\Admin\PastReleaseObservation::from_record(
				array(
					'facts'   => $completions[0]['args'][4],
					'sort_at' => '2026-11-13 10:00:00',
				)
			)
		);
	}

	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	#[\PHPUnit\Framework\Attributes\PreserveGlobalState( false )]
	public function test_the_default_read_only_check_calls_the_updater_filter_once_a_minute_per_package(): void {
		require_once __DIR__ . '/ReadOnlyCheckWordPressFunctions.php';
		$report                                 = array(
			'status'            => 'succeeded',
			'reason'            => 'no_newer_candidate',
			'offered'           => false,
			'candidate_version' => null,
		);
		$GLOBALS['ran_booster_read_only_check'] = array(
			'calls'      => array(),
			'transients' => array(),
			'answer'     => null,
		);
		$package                                = $this->package( 'plugin', self::NATIVE_PLUGIN, 'example', DeploymentPolicy::DISABLED );
		[$registrar]                            = $this->native_plugin_registrar( $package );
		$plugins                                = $this->createStub( PluginRepository::class );
		$plugins->method( 'booster_plugin_from_file' )->willReturn( $package );
		$store  = new RuntimeReleaseStore( array( "plugin\0" . self::NATIVE_PLUGIN => new ManagedReleaseConfiguration( 'example', 'example.php' ) ) );
		$facade = $this->facade( $plugins, $this->createStub( ThemeRepository::class ), $store, $registrar, new RuntimeUpdaterLock(), $this->release_metadata_registry(), static fn (): bool => true, static fn (): bool => true, static function (): void {}, null, static fn (): bool => true );
		$state  = &$GLOBALS['ran_booster_read_only_check'];

		// An updater that does not answer, or answers with something that is not a report, is not
		// throttled, and falls back to the native path.
		$unanswered = array(
			null,
			'yes',
			true,
			array(
				'reason'  => 'no_newer_candidate',
				'offered' => false,
			),
		);
		foreach ( $unanswered as $answer ) {
			$state['answer'] = $answer;
			self::assertSame( 'release_refreshed', $facade->refresh( 'plugin', self::NATIVE_PLUGIN, 1, 'nonce' )->code() );
		}
		self::assertCount( 4, $state['calls'] );
		self::assertSame( array(), $state['transients'] );
		self::assertSame( array( 'ran_wp_release_updater_check_releases_v1', null, 'plugin', self::NATIVE_PLUGIN ), $state['calls'][0] );

		// A failed, unadmitted, unobserved or forced-off check starts no cooldown, so each retry reaches the updater again.
		$retried = array(
			array( 'failed', 'release_list_failed', 'refresh_check_failed' ),
			array( 'skipped', 'discovery_not_admitted', 'refresh_unchecked' ),
			array( 'succeeded', 'no_newer_candidate', 'refresh_unchecked' ),
			array( 'skipped', 'policy_forced_off', 'refresh_skipped_forced_off' ),
		);
		foreach ( $retried as [$status, $reason, $code] ) {
			$state['answer'] = array_replace(
				$report,
				array(
					'status' => $status,
					'reason' => $reason,
				)
			);

			$failed = $facade->refresh( 'plugin', self::NATIVE_PLUGIN, 1, 'nonce' );
			$retry  = $facade->refresh( 'plugin', self::NATIVE_PLUGIN, 1, 'nonce' );
			self::assertSame( $code, $failed->code() );
			self::assertSame( $code, $retry->code() );
		}
		self::assertCount( 12, $state['calls'] );
		self::assertSame( array(), $state['transients'] );

		// Each completed check, observed as the updater observes it, starts the cooldown.
		$completed = array(
			array( 'succeeded', 'newer_release_observed', 'newer', '2.0.0', 'refresh_newer_while_off' ),
			array( 'succeeded', 'no_newer_candidate', null, null, 'refresh_current_while_off' ),
			array( 'rejected', 'candidate_rejected', null, null, 'refresh_rejected' ),
		);
		foreach ( $completed as $index => [$status, $reason, $relationship, $candidate, $code] ) {
			$state['transients'] = array();
			$state['answer']     = static function () use ( $registrar, $report, $status, $reason, $relationship, $candidate ): array {
				$check_id = str_repeat( 'e', 32 );
				$registrar->activity_check_started(
					array(
						'check_id'            => $check_id,
						'network_id'          => 1,
						'blog_id'             => 1,
						'target_type'         => 'plugin',
						'installed_identity'  => self::NATIVE_PLUGIN,
						'provider_code'       => 'github',
						'repository_identity' => '123456789',
						'channel'             => 'stable',
						'binding_hash'        => str_repeat( 'b', 64 ),
					)
				);
				$registrar->activity_check_completed(
					array(
						'check_id'                  => $check_id,
						'status'                    => $status,
						'reason'                    => $reason,
						'relationship'              => $relationship,
						'installed_version'         => '1.0.0',
						'candidate_version'         => $candidate,
						'offered_version'           => null,
						'candidate_validation_code' => null,
					)
				);

				return array_replace(
					$report,
					array(
						'status'            => $status,
						'reason'            => $reason,
						'candidate_version' => $candidate,
					)
				);
			};

			$first  = $facade->refresh( 'plugin', self::NATIVE_PLUGIN, 1, 'nonce' );
			$second = $facade->refresh( 'plugin', self::NATIVE_PLUGIN, 1, 'nonce' );

			self::assertSame( $code, $first->code() );
			self::assertCount( 13 + $index, $state['calls'], 'The second click inside the minute never reaches the updater.' );
			self::assertCount( 1, $state['transients'] );
			self::assertSame( 60, current( $state['transients'] ) );
			self::assertSame( 'refresh_recent', $second->code() );
			self::assertSame( 'cooldown_active', $second->check_reason() );
		}
	}

	public function test_the_vendored_updater_still_registers_the_read_only_check_this_adoption_consumes(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads a vendored local source file, not a remote URL.
		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/vendor/ran/wp-release-updater/src/WordPress/NativePackageUpdater.php' );

		self::assertStringContainsString( "add_filter( 'ran_wp_release_updater_check_releases_v1'", $source );
		foreach ( array( 'status', 'reason', 'policy', 'relationship', 'installed_version', 'candidate_version', 'candidate_validation_code' ) as $key ) {
			self::assertStringContainsString( "'$key'", $source );
		}
		self::assertMatchesRegularExpression( "/'offered'\s*=>\s*false/", $source );
		foreach ( array( 'newer_release_observed', 'runtime_busy', 'policy_forced_off' ) as $reason ) {
			self::assertStringContainsString( "'$reason'", $source );
		}
		// Only the target refresh clears WordPress's cached update check, so a Check releases that
		// never refreshes the target leaves that cache alone.
		if ( 1 !== preg_match( '/public function refresh\(\): bool \{.*?\n\t\}/s', $source, $refresh ) ) {
			self::fail( 'The vendored updater no longer declares refresh().' );
		}
		self::assertSame( 1, substr_count( $refresh[0], 'delete_site_transient(' ) );
		self::assertSame( 1, substr_count( $source, 'delete_site_transient(' ) );
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

			/** @var array<string, mixed> */
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

	public function test_refresh_rejects_missing_registered_target_before_running_the_updater(): void {
		$package = $this->package( 'theme', 'example-theme', 'example-theme', DeploymentPolicy::MANUAL );
		$plugins = $this->createStub( PluginRepository::class );
		$themes  = $this->createStub( ThemeRepository::class );
		$themes->method( 'booster_theme_from_stylesheet' )->willReturn( $package );
		$store     = new RuntimeReleaseStore(
			array( "theme\0example-theme" => new ManagedReleaseConfiguration( 'example-theme', 'style.css' ) )
		);
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
		self::assertSame( 'source_changed', $result->code() );
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
			'ran-booster-release-tracking-refresh-plugin-example/example.php-1-gh:123456789:11111111-1111-4111-8111-111111111111',
			$facade->nonce_action( 'refresh', 'plugin', 'example/example.php', 1 )
		);
		self::assertNotSame(
			$facade->nonce_action( 'refresh', 'plugin', 'example/example.php', 1 ),
			$facade->nonce_action( 'refresh', 'plugin', 'example/example.php', 2 )
		);
		self::assertSame(
			'ran-booster-release-tracking-change_channel-plugin-example/example.php-1-gh:123456789:11111111-1111-4111-8111-111111111111',
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
		self::assertSame( 'v2.1.0', $status->preflight()->release_tag() );
		self::assertSame( '2.0.0', $status->preflight()->package_header_version() );
	}

	/** @param array<string, mixed> $diagnostics */
	private function release_status_for_diagnostics( array $diagnostics ): \RAN\AddOn\ReleaseTracking\ReleaseTrackingStatus {
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
				target_factory: static fn ( mixed ...$options ): object => new RuntimeUpdaterFacade( diagnostics: $diagnostics )
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

		return $facade->status( 'plugin', 'example/example.php' );
	}

	public function test_release_managed_status_treats_a_candidate_that_is_not_newer_as_a_completed_check_with_nothing_to_offer(): void {
		foreach ( array( 'same', 'older' ) as $relationship ) {
			$status = $this->release_status_for_diagnostics(
				array(
					'state'                => 'ready',
					'code'                 => 'release_available',
					'offered_version'      => null,
					'last_check'           => 1790000000,
					'version_relationship' => $relationship,
					'candidate_validation' => array(
						'code'                   => 'github_updater_release_incompatible',
						'release_tag'            => 'v2.0.0',
						'release_version'        => '2.0.0',
						'package_header_version' => '',
					),
				)
			);

			self::assertSame( '', $status->failure_code(), $relationship );
			self::assertFalse( $status->update_available(), $relationship );
			self::assertNull( $status->preflight(), $relationship );
			self::assertNotSame( '', $status->last_checked_at(), $relationship );
		}
	}

	public function test_release_managed_status_still_reports_a_newer_candidate_that_failed_eligibility(): void {
		$status = $this->release_status_for_diagnostics(
			array(
				'state'                => 'ready',
				'code'                 => 'release_available',
				'offered_version'      => null,
				'last_check'           => 1790000000,
				'version_relationship' => 'newer',
				'candidate_validation' => array(
					'code'                   => 'github_updater_release_incompatible',
					'release_tag'            => 'v3.0.0',
					'release_version'        => '3.0.0',
					'package_header_version' => '',
				),
			)
		);

		self::assertSame( 'release_unavailable', $status->failure_code() );
		self::assertNotNull( $status->preflight() );
		self::assertSame( 'release_incompatible', $status->preflight()->reason_code() );
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
		$target = $registrar->target( 'plugin', 'example/example.php' );
		self::assertInstanceOf( RuntimeUpdaterFacade::class, $target );
		$target->fail_refresh();
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
		$target = $registrar->target( 'plugin', 'example/example.php' );
		self::assertInstanceOf( RuntimeUpdaterFacade::class, $target );
		self::assertSame( 0, $target->refreshes() );
		self::assertSame( 1, $lock->acquires );
		self::assertSame( array( '1700000002' ), $lock->releases );

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
		$expected_nonce = 'ran-booster-release-tracking-change_channel-plugin-example/example.php-1-gh:123456789:11111111-1111-4111-8111-111111111111';
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
					'adoption_token'    => 'gh:123456789:11111111-1111-4111-8111-111111111111',
				),
			),
			$store->channel_changes
		);
		self::assertSame( array( 'plugin' ), $invalidated );
		$target = $registrar->target( 'plugin', 'example/example.php' );
		self::assertInstanceOf( RuntimeUpdaterFacade::class, $target );
		self::assertSame( 0, $target->refreshes() );
		self::assertSame( array(), $store->transitions );
		self::assertSame( 1, $lock->acquires );
		self::assertSame( array( '1700000002' ), $lock->releases );

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
		self::assertSame( array( '1700000002', '1700000002', '1700000002', '1700000002', '1700000002' ), $lock->releases );

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

	public function test_recreated_native_adoption_rejects_registration_and_pending_download_for_both_types(): void {
		foreach ( array( 'plugin', 'theme' ) as $type ) {
			foreach ( array( false, true ) as $download_started ) {
				$this->repository_rows = array();
				$identifier            = 'plugin' === $type ? self::NATIVE_PLUGIN : 'example-theme';
				$registered            = $this->package( $type, $identifier, 'example', DeploymentPolicy::MANUAL );
				$replacement           = $this->package( $type, $identifier, 'example', DeploymentPolicy::MANUAL, uuid: '22222222-2222-4222-8222-222222222222' );
				$live                  = $registered;
				$plugins               = $this->createStub( PluginRepository::class );
				$themes                = $this->createStub( ThemeRepository::class );
				$plugins->method( 'all_deployment_plugins' )->willReturn( 'plugin' === $type ? array( $identifier => $registered ) : array() );
				$themes->method( 'all_deployment_themes' )->willReturn( 'theme' === $type ? array( $identifier => $registered ) : array() );
				$read = static function () use ( &$live ): Package {
					return $live;
				};
				$plugins->method( 'booster_plugin_from_file' )->willReturnCallback( $read );
				$themes->method( 'booster_theme_from_stylesheet' )->willReturnCallback( $read );
				$store     = new RuntimeReleaseStore( array( $type . "\0" . $identifier => new ManagedReleaseConfiguration( 'plugin' === $type ? 'example' : 'example-theme', 'plugin' === $type ? 'example.php' : 'style.css' ) ) );
				$lock      = new RuntimeUpdaterLock();
				$registrar = $this->registrar( $plugins, $themes, $store, $lock, $this->release_metadata_registry() );
				$registrar->register();
				$extra = array(
					$type    => $identifier,
					'action' => 'update',
					'type'   => $type,
				);
				if ( $download_started ) {
					self::assertFalse( $registrar->authorize_native_download( false, 'package.zip', new \stdClass(), $extra ) );
				}
				$live = $replacement;
				$this->assert_native_authority_error(
					$download_started
					? $registrar->fence_native_mutation( false, $extra )
					: $registrar->authorize_native_download( false, 'package.zip', new \stdClass(), $extra )
				);
				self::assertSame( $download_started ? 1 : 0, $lock->acquires );
				self::assertSame( $download_started ? array( '1700000002' ) : array(), $lock->releases );
			}
		}
	}

	public function test_recreated_adoption_rejects_old_release_settings_forms_and_replacement_during_authorization(): void {
		foreach ( array( 'plugin', 'theme' ) as $type ) {
			foreach ( array( PackageSource::BRANCH, PackageSource::RELEASE_ASSET ) as $source ) {
				foreach ( array( false, true ) as $replace_after_nonce ) {
					$this->repository_rows = array();
					$identifier            = 'plugin' === $type ? self::NATIVE_PLUGIN : 'example-theme';
					$old                   = $this->package( $type, $identifier, 'example', DeploymentPolicy::MANUAL, source: $source );
					$new                   = $this->package( $type, $identifier, 'example', DeploymentPolicy::MANUAL, source: $source, uuid: '22222222-2222-4222-8222-222222222222' );
					$live                  = $old;
					$plugins               = $this->createStub( PluginRepository::class );
					$themes                = $this->createStub( ThemeRepository::class );
					$read                  = static function () use ( &$live ): Package {
						return $live;
					};
					$plugins->method( 'booster_plugin_from_file' )->willReturnCallback( $read );
					$themes->method( 'booster_theme_from_stylesheet' )->willReturnCallback( $read );
					$store     = new RuntimeReleaseStore( array( $type . "\0" . $identifier => new ManagedReleaseConfiguration( 'plugin' === $type ? 'example' : 'example-theme', 'plugin' === $type ? 'example.php' : 'style.css' ) ) );
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
						static function ( string $nonce, string $action ) use ( &$live, $new, $replace_after_nonce ): bool {
							$valid = $nonce === $action;
							if ( $valid && $replace_after_nonce ) {
								$live = $new;
							}
							return $valid;
						},
						metadata_eligible: static fn (): bool => true
					);
					$operation = PackageSource::BRANCH === $source ? 'enable' : 'return_to_branch';
					$nonce     = $facade->nonce_action( $operation, $type, $identifier, 1 );
					$run       = static fn () => PackageSource::BRANCH === $source
					? $facade->enable( $type, $identifier, 1, 'stable', $nonce )
					: $facade->return_to_branch( $type, $identifier, 1, $nonce );
					$live      = $replace_after_nonce ? $old : $new;
					self::assertSame( $replace_after_nonce ? 'source_changed' : 'forbidden', $run()->code() );
					self::assertSame( array(), $store->transitions );
				}
			}
		}
	}

	private function nonce_facade(): NativeReleaseTrackingFacade {
		$plugins = $this->createStub( PluginRepository::class );
		$plugins->method( 'booster_plugin_from_file' )->willReturn( $this->package( 'plugin', 'example/example.php', 'example', DeploymentPolicy::MANUAL ) );
		$themes = $this->createStub( ThemeRepository::class );
		$store  = new RuntimeReleaseStore();

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
		bool|\Throwable $collision = false
	): ProviderRegistry {
		$registry = new ProviderRegistry(
			array( new RuntimeReleaseProvider( $code, $base_url, $list_releases, $inspect, $target_factory, $collision ) )
		);
		$registry->seal();

		return $registry;
	}

	private function metadata_only_registry( string $code = 'gh', string $base_url = 'https://github.com/' ): ProviderRegistry {
		$provider = new class( $code, $base_url ) implements RepositoryProvider, RepositoryReleaseMetadata {
			use \RAN\Tests\RepositoryProvider\Support\SuppliesProviderDiagnostics;

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

	public function test_native_check_envelopes_capture_before_work_and_reject_foreign_or_malformed_reports(): void {
		$package                                     = $this->package( 'plugin', self::NATIVE_PLUGIN, 'example', DeploymentPolicy::MANUAL );
		$connection                                  = new \RAN\Tests\Activity\ActivityConnection();
		$activity                                    = new \RAN\Activity\ActivityStore( $connection );
		$GLOBALS['ran_booster_storage_test_options'] = array();
		$GLOBALS['ran_booster_activity_options']     = array( \RAN\Activity\ActivityStore::VERSION_OPTION => '1' );
		[$registrar]                                 = $this->native_plugin_registrar( $package, activity: $activity );
		self::assertNotNull( $registrar->target( 'plugin', self::NATIVE_PLUGIN ) );
		$context = ( new \RAN\Admin\RepositoryBranchCheckEvidenceStore() )->activity_context( 'plugin', $package, 'release.check' );
		self::assertNotNull( $context['adoption_token'] );
		$started = array(
			'check_id'            => str_repeat( 'a', 32 ),
			'network_id'          => 1,
			'blog_id'             => 1,
			'target_type'         => 'plugin',
			'installed_identity'  => self::NATIVE_PLUGIN,
			'provider_code'       => 'github',
			'repository_identity' => '123456789',
			'channel'             => 'stable',
			'binding_hash'        => str_repeat( 'b', 64 ),
		);
		foreach ( array( array( 'blog_id' => 2 ), array( 'repository_identity' => 'foreign' ), array( 'channel' => 'prerelease' ), array( 'binding_hash' => 'bad' ) ) as $wrong ) {
			$registrar->activity_check_started( array_replace( $started, $wrong ) );
		}
		self::assertCount( 0, $connection->inserted );
		$registrar->activity_check_started( $started );
		$registrar->activity_check_started( $started );
		self::assertCount( 1, $connection->inserted );
		self::assertSame( \RAN\AdoptionIdentity::of( $package ), $connection->inserted[0]['adoption_token'] );
		$completed = array(
			'check_id'                  => $started['check_id'],
			'status'                    => 'succeeded',
			'reason'                    => 'candidate_rejected',
			'relationship'              => 'newer',
			'installed_version'         => '1.0.0',
			'candidate_version'         => '2.0.0',
			'offered_version'           => null,
			'candidate_validation_code' => 'candidate_validation_failed',
		);
		$count     = count( $connection->queries );
		$registrar->activity_check_completed( $completed );
		self::assertCount( $count, $connection->queries );
		$completed['status'] = 'rejected';
		$registrar->activity_check_completed( $completed );
		$completion = end( $connection->prepared );
		self::assertIsArray( $completion );
		self::assertSame( 'rejected', $completion['args'][0] );
		self::assertSame( 'none', $completion['args'][5] );
		self::assertSame( 1, $completion['args'][7] );
		$missing = $completed;
		unset( $missing['offered_version'] );
		$missing['extra'] = null;
		$count            = count( $connection->queries );
		$registrar->activity_check_completed( $missing );
		self::assertCount( $count, $connection->queries );
	}

	public function test_unmanaged_and_removed_targets_do_not_create_native_check_history(): void {
		$package                                 = $this->package( 'plugin', self::NATIVE_PLUGIN, 'example', DeploymentPolicy::MANUAL );
		$connection                              = new \RAN\Tests\Activity\ActivityConnection();
		$GLOBALS['ran_booster_activity_options'] = array( \RAN\Activity\ActivityStore::VERSION_OPTION => '1' );
		$removed                                 = false;
		$reads                                   = 0;
		[$registrar]                             = $this->native_plugin_registrar(
			$package,
			static function () use ( $package, &$removed, &$reads ): Package {
				++$reads;
				// @phpstan-ignore if.alwaysFalse (This callback runs again after the captured flag changes below.)
				if ( $removed ) {
					throw new \RAN\Storage\PluginNotFound();
				}
				return $package;
			},
			new \RAN\Activity\ActivityStore( $connection )
		);
		$event                                   = array(
			'check_id'            => str_repeat( 'a', 32 ),
			'network_id'          => 1,
			'blog_id'             => 1,
			'target_type'         => 'plugin',
			'installed_identity'  => 'unmanaged/plugin.php',
			'provider_code'       => 'github',
			'repository_identity' => '123456789',
			'channel'             => 'stable',
			'binding_hash'        => str_repeat( 'b', 64 ),
		);
		$before                                  = $reads;
		$registrar->activity_check_started( $event );
		self::assertSame( $before, $reads );
		$removed                     = true;
		$event['installed_identity'] = self::NATIVE_PLUGIN;
		$registrar->activity_check_started( $event );
		self::assertSame( array(), $connection->inserted );
	}

	public function test_native_refresh_and_stale_registration_do_not_invent_check_history(): void {
		$package                                     = $this->package( 'plugin', self::NATIVE_PLUGIN, 'example', DeploymentPolicy::MANUAL );
		$replacement                                 = $this->package( 'plugin', self::NATIVE_PLUGIN, 'example', DeploymentPolicy::MANUAL, uuid: '22222222-2222-4222-8222-222222222222' );
		$connection                                  = new \RAN\Tests\Activity\ActivityConnection();
		$activity                                    = new \RAN\Activity\ActivityStore( $connection );
		$GLOBALS['ran_booster_storage_test_options'] = array();
		$GLOBALS['ran_booster_activity_options']     = array( \RAN\Activity\ActivityStore::VERSION_OPTION => '1' );
		[$registrar, , $target]                      = $this->native_plugin_registrar( $package, $replacement, $activity );
		$target->refresh();
		$registrar->activity_check_started(
			array(
				'check_id'            => str_repeat( 'a', 32 ),
				'network_id'          => 1,
				'blog_id'             => 1,
				'target_type'         => 'plugin',
				'installed_identity'  => self::NATIVE_PLUGIN,
				'provider_code'       => 'github',
				'repository_identity' => '123456789',
				'channel'             => 'stable',
				'binding_hash'        => str_repeat( 'b', 64 ),
			)
		);
		self::assertSame( array(), $connection->inserted );
	}

	/** @return array{0: ManagedReleaseTargetRegistrar, 1: \RAN\Activity\ActivityStore, 2: \RAN\Tests\Activity\InMemoryActivityConnection, 3: Package} */
	private function recording_world(): array {
		$package                                     = $this->package( 'plugin', self::NATIVE_PLUGIN, 'example', DeploymentPolicy::DISABLED );
		$connection                                  = new \RAN\Tests\Activity\InMemoryActivityConnection();
		$activity                                    = new \RAN\Activity\ActivityStore( $connection );
		$GLOBALS['ran_booster_storage_test_options'] = array();
		$GLOBALS['ran_booster_activity_options']     = array( \RAN\Activity\ActivityStore::VERSION_OPTION => '1' );
		[$registrar]                                 = $this->native_plugin_registrar( $package, activity: $activity );

		return array( $registrar, $activity, $connection, $package );
	}

	/** Emits the updater's start and completion actions for one check. */
	private function emit_check( ManagedReleaseTargetRegistrar $registrar, string $status, string $reason ): void {
		static $sequence = 0;
		$check_id        = str_pad( dechex( ++$sequence ), 32, '0', STR_PAD_LEFT );
		$registrar->activity_check_started(
			array(
				'check_id'            => $check_id,
				'network_id'          => 1,
				'blog_id'             => 1,
				'target_type'         => 'plugin',
				'installed_identity'  => self::NATIVE_PLUGIN,
				'provider_code'       => 'github',
				'repository_identity' => '123456789',
				'channel'             => 'stable',
				'binding_hash'        => str_repeat( 'b', 64 ),
			)
		);
		$registrar->activity_check_completed(
			array(
				'check_id'                  => $check_id,
				'status'                    => $status,
				'reason'                    => $reason,
				'relationship'              => null,
				'installed_version'         => null,
				'candidate_version'         => null,
				'offered_version'           => null,
				'candidate_validation_code' => null,
			)
		);
	}

	/** @return list<string> status:reason of every stored row, oldest first. */
	private function recorded( \RAN\Tests\Activity\InMemoryActivityConnection $connection ): array {
		return array_map( static fn ( array $row ): string => $row['status'] . ':' . $row['reason'], $connection->rows );
	}

	private function latest_check_row( \RAN\Activity\ActivityStore $activity, Package $package ): string {
		$context = ( new \RAN\Admin\RepositoryBranchCheckEvidenceStore() )->activity_context( 'plugin', $package, 'release.check' );
		self::assertNotNull( $context['adoption_token'] );
		$latest = $activity->summary( 'plugin', $context['subject_identity'], $context['adoption_token'], $context['context'], 'release.check' )['latest'];

		return null === $latest ? 'none' : $latest['status'] . ':' . $latest['reason'];
	}

	public function test_background_skipped_checks_record_once_per_kind_inside_the_heartbeat(): void {
		[$registrar, , $connection] = $this->recording_world();
		$this->emit_check( $registrar, 'succeeded', 'no_newer_candidate' );
		$this->emit_check( $registrar, 'skipped', 'policy_disabled' );
		$this->emit_check( $registrar, 'skipped', 'policy_disabled' );
		$this->emit_check( $registrar, 'skipped', 'policy_disabled' );
		self::assertSame( array( 'succeeded:no_newer_candidate', 'skipped:policy_disabled' ), $this->recorded( $connection ) );
		// A different skip reason is a different fact; the repeat of it is dropped in turn.
		$this->emit_check( $registrar, 'skipped', 'policy_forced_off' );
		$this->emit_check( $registrar, 'skipped', 'policy_forced_off' );
		self::assertSame( array( 'succeeded:no_newer_candidate', 'skipped:policy_disabled', 'skipped:policy_forced_off' ), $this->recorded( $connection ) );
		// Nothing is left unfinished by a dropped repeat.
		self::assertNotContains( 'incomplete', array_column( $connection->rows, 'status' ) );
	}

	public function test_a_repeated_skip_older_than_the_heartbeat_records_one_more_row(): void {
		[$registrar, , $connection] = $this->recording_world();
		$this->emit_check( $registrar, 'skipped', 'policy_disabled' );
		$connection->rows[0]['started_at'] = gmdate( 'Y-m-d H:i:s', time() - \RAN\Activity\ActivityStore::SKIPPED_HEARTBEAT - 60 );
		$this->emit_check( $registrar, 'skipped', 'policy_disabled' );
		$this->emit_check( $registrar, 'skipped', 'policy_disabled' );
		self::assertSame( array( 'skipped:policy_disabled', 'skipped:policy_disabled' ), $this->recorded( $connection ) );
		$connection->rows[1]['started_at'] = gmdate( 'Y-m-d H:i:s', time() - \RAN\Activity\ActivityStore::SKIPPED_HEARTBEAT + 120 );
		$this->emit_check( $registrar, 'skipped', 'policy_disabled' );
		self::assertCount( 2, $connection->rows, 'Just inside the interval is still a repeat.' );
	}

	public function test_a_manual_check_records_every_row_and_never_reads_history_first(): void {
		[$registrar, , $connection] = $this->recording_world();
		$this->emit_check( $registrar, 'skipped', 'policy_disabled' );
		$registrar->mark_manual_check( 'plugin', self::NATIVE_PLUGIN );
		$connection->fail_reads = true;
		$this->emit_check( $registrar, 'skipped', 'policy_disabled' );
		$this->emit_check( $registrar, 'skipped', 'policy_disabled' );
		self::assertSame( array( 'skipped:policy_disabled', 'skipped:policy_disabled', 'skipped:policy_disabled' ), $this->recorded( $connection ) );
	}

	public function test_an_unreadable_history_drops_a_background_skip_instead_of_recording_blind(): void {
		[$registrar, , $connection] = $this->recording_world();
		$connection->fail_reads     = true;
		$this->emit_check( $registrar, 'skipped', 'policy_disabled' );
		self::assertSame( array(), $this->recorded( $connection ) );
		// Only the policy skips are thinned: a real check result is never dropped by this rule.
		$this->emit_check( $registrar, 'failed', 'release_list_failed' );
		self::assertSame( array( 'failed:release_list_failed' ), $this->recorded( $connection ) );
	}

	public function test_last_recorded_check_leads_with_the_latest_established_result(): void {
		[$registrar, $activity, $connection, $package] = $this->recording_world();
		self::assertSame( 'none', $this->latest_check_row( $activity, $package ) );
		$this->emit_check( $registrar, 'skipped', 'policy_disabled' );
		self::assertSame( 'skipped:policy_disabled', $this->latest_check_row( $activity, $package ), 'With nothing established the skip is shown.' );
		$registrar->mark_manual_check( 'plugin', self::NATIVE_PLUGIN );
		$this->emit_check( $registrar, 'succeeded', 'newer_release_observed' );
		// A later background skip of another kind is recorded, yet the manual result still leads.
		$this->emit_check( $registrar, 'skipped', 'policy_forced_off' );
		self::assertSame( array( 'skipped:policy_disabled', 'succeeded:newer_release_observed', 'skipped:policy_forced_off' ), $this->recorded( $connection ) );
		self::assertSame( 'succeeded:newer_release_observed', $this->latest_check_row( $activity, $package ) );
		$this->emit_check( $registrar, 'rejected', 'candidate_rejected' );
		self::assertSame( 'rejected:candidate_rejected', $this->latest_check_row( $activity, $package ), 'A newer rejected or failed result leads over the older one and over skips.' );
	}

	public function test_each_package_context_thins_its_skips_independently(): void {
		$connection                              = new \RAN\Tests\Activity\InMemoryActivityConnection();
		$store                                   = new \RAN\Activity\ActivityStore( $connection );
		$GLOBALS['ran_booster_activity_options'] = array( \RAN\Activity\ActivityStore::VERSION_OPTION => '1' );
		$kept                                    = array();
		$tokens                                  = array(
			'one/one.php' => \RAN\AdoptionIdentity::create( 'gh', '123' ),
			'two/two.php' => \RAN\AdoptionIdentity::create( 'gh', '123' ),
		);
		foreach ( array( 'one/one.php', 'two/two.php', 'one/one.php', 'two/two.php' ) as $identity ) {
			$capture = array(
				'subject_type'     => 'plugin',
				'subject_identity' => $identity,
				'adoption_token'   => $tokens[ $identity ],
				'context'          => 'source/access',
				'purpose'          => 'release.check',
				'provenance'       => 'core',
			);
			$id      = $store->begin( 'gh', new \RAN\Activity\ActivityRequest( 'release.check', new RepositoryReference( 'owner/repository', '123', false, null ), 'plugin', $identity ), $capture );
			self::assertNotNull( $id );
			if ( ! $store->discard_repeated_skip( $id, 'policy_disabled' ) ) {
				$store->complete( $id, 'skipped', 'policy_disabled' );
				$kept[] = $identity;
			}
		}
		self::assertSame( array( 'one/one.php', 'two/two.php' ), $kept );
	}

	public function test_manual_check_releases_records_after_background_skips_and_repeats_inside_the_cooldown(): void {
		[$registrar, $activity, $connection, $package] = $this->recording_world();
		$plugins                                       = $this->createStub( PluginRepository::class );
		$plugins->method( 'booster_plugin_from_file' )->willReturn( $package );
		$store  = new RuntimeReleaseStore( array( "plugin\0" . self::NATIVE_PLUGIN => new ManagedReleaseConfiguration( 'example', 'example.php' ) ) );
		$facade = $this->facade(
			$plugins,
			$this->createStub( ThemeRepository::class ),
			$store,
			$registrar,
			new RuntimeUpdaterLock(),
			$this->release_metadata_registry(),
			static fn (): bool => true,
			static fn (): bool => true,
			static function (): void {},
			null,
			static fn (): bool => true,
			check_without_offer: function () use ( $registrar ): array {
				$this->emit_check( $registrar, 'skipped', 'policy_disabled' );

				return array(
					'status'  => 'skipped',
					'reason'  => 'policy_disabled',
					'offered' => false,
				);
			}
		);
		$this->emit_check( $registrar, 'skipped', 'policy_disabled' );
		self::assertCount( 1, $connection->rows );
		$codes = array(
			$facade->refresh( 'plugin', self::NATIVE_PLUGIN, 1, 'nonce' )->code(),
			$facade->refresh( 'plugin', self::NATIVE_PLUGIN, 1, 'nonce' )->code(),
		);
		self::assertSame( array( 'refresh_skipped_disabled', 'refresh_skipped_disabled' ), $codes );
		self::assertCount( 3, $connection->rows, 'Both manual checks that ran are recorded next to the earlier background skip.' );
		self::assertSame( 'skipped:policy_disabled', $this->latest_check_row( $activity, $package ) );
	}

	/**
	 * @param Package|callable(): Package|null $live
	 * @return array{ManagedReleaseTargetRegistrar, RuntimeUpdaterLock, RuntimeUpdaterFacade, RuntimeReleaseStore}
	 */
	private function native_plugin_registrar(
		Package $registered,
		Package|callable|null $live = null,
		?\RAN\Activity\ActivityStore $activity = null
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
		$store     = new RuntimeReleaseStore(
			array(
				"plugin\0" . self::NATIVE_PLUGIN => new ManagedReleaseConfiguration( 'example', 'example.php' ),
			)
		);
		$registrar = $this->registrar(
			$plugins,
			$themes,
			$store,
			$lock,
			$this->release_metadata_registry(
				target_factory: static function ( mixed ...$options ) use ( $facade ): object {
					unset( $options );

					return $facade;
				}
			),
			activity: $activity
		);
		$registrar->register();
		$store->native_connection = $this->native_selection_db;

		return array( $registrar, $lock, $facade, $store );
	}

	private function assert_native_authority_error( mixed $result ): void {
		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'ran_booster_native_update_authority_changed', $result->get_error_code() );
	}

	private function registrar( mixed ...$arguments ): ManagedReleaseTargetRegistrar {
		return new ManagedReleaseTargetRegistrar(
			...array_merge(
				$arguments,
				array(
					'github_selection' => $this->github_selection(),
					'source_guard'     => $this->repository_guard(),
				)
			)
		);
	}

	private function facade( mixed ...$arguments ): NativeReleaseTrackingFacade {
		return new NativeReleaseTrackingFacade( ...array_merge( $arguments, array( 'source_guard' => $this->repository_guard() ) ) );
	}

	private function repository_guard(): RepositorySourceGuard {
		$database = new class( fn (): array => $this->repository_rows ) {
			public string $last_error = '';
			/** @param \Closure(): array<string, \stdClass> $rows */
			public function __construct( private \Closure $rows ) {}
			/** @return array<array-key, mixed> */
			// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundInExtendedClassBeforeLastUsed -- The fixture implementation of prepare retains the production method contract; these inputs do not affect this controlled result.
			public function prepare( string $query, mixed ...$arguments ): array {
				return $arguments;
			}
			/**
			 * @param array<array-key, mixed> $arguments
			 * @return list<object>
			 */
			public function get_results( array $arguments ): array {
				return array_values( array_filter( ( $this->rows )(), static fn ( \stdClass $row ): bool => $row->provider === $arguments[1] && $row->provider_repository_id === $arguments[2] ) );
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
		?string $provider_repository_id = null,
		string $uuid = '11111111-1111-4111-8111-111111111111'
	): Package {
		$package = $this->createStub( \RAN\AbstractPackage::class );
		$package->method( 'get_adoption_token' )->willReturn( $provider . ':' . rawurlencode( $provider_repository_id ?? $repository_id ) . ':' . $uuid );
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

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_enable_forwards_allow_older_flag_to_offer_filter_only_when_explicitly_confirmed(): void {
		require_once __DIR__ . '/ReadOnlyCheckWordPressFunctions.php';
		$GLOBALS['ran_booster_read_only_check'] = array(
			'calls'      => array(),
			'transients' => array(),
			'answer'     => array(
				'status' => 'refused',
				'code'   => 'release_older',
			),
		);
		[ $facade ]                             = $this->switch_facade( null );

		$refused = $facade->enable( 'plugin', 'example/example.php', 1, 'stable', 'valid' );
		self::assertFalse( $refused->successful() );
		self::assertSame( 'release_older', $refused->code() );
		self::assertCount( 1, $this->shutdown_rechecks() );
		$GLOBALS['ran_booster_runtime_actions'] = array();

		// Mismatched older identity or tag fails closed before admission or updater offer.
		[ $mismatched_facade ] = $this->switch_facade( null );
		$mismatched            = $mismatched_facade->enable( 'plugin', 'example/example.php', 1, 'stable', 'valid', 'wrong-id', 'v2.0.0' );
		self::assertFalse( $mismatched->successful() );
		self::assertSame( 'release_older', $mismatched->code() );
		self::assertCount( 1, $this->shutdown_rechecks() );
		$GLOBALS['ran_booster_runtime_actions'] = array();

		$GLOBALS['ran_booster_read_only_check']['answer'] = array(
			'status' => 'refused',
			'code'   => 'candidate_not_eligible',
		);

		[ $confirmed_facade ] = $this->switch_facade( null );
		$allowed              = $confirmed_facade->enable( 'plugin', 'example/example.php', 1, 'stable', 'valid', '101', 'v2.0.0' );
		self::assertFalse( $allowed->successful() );

		self::assertSame(
			array(
				array( 'ran_wp_release_updater_offer_release_v1', null, 'plugin', 'example/example.php', '101', 'v2.0.0' ),
				array( 'ran_wp_release_updater_offer_release_v1', null, 'plugin', 'example/example.php', '101', 'v2.0.0', true ),
			),
			$GLOBALS['ran_booster_read_only_check']['calls']
		);
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_enable_refuses_disabled_policy_and_active_github_native_hold_before_install(): void {
		$installs      = 0;
		$invalidated   = array();
		$disabled      = $this->package( 'plugin', 'example/example.php', 'example', DeploymentPolicy::DISABLED, source: PackageSource::BRANCH );
		$disabled_repo = $this->createStub( PluginRepository::class );
		$disabled_repo->method( 'booster_plugin_from_file' )->willReturn( $disabled );
		$disabled_repo->method( 'all_deployment_plugins' )->willReturn( array() );
		$themes          = $this->createStub( ThemeRepository::class );
		$store           = new RuntimeReleaseStore();
		$providers       = $this->release_metadata_registry();
		$registrar       = $this->registrar( $disabled_repo, $themes, $store, new RuntimeUpdaterLock(), $providers );
		$disabled_facade = $this->facade(
			$disabled_repo,
			$themes,
			$store,
			$registrar,
			new RuntimeUpdaterLock(),
			$providers,
			static fn (): bool => true,
			static fn ( string $nonce ): bool => 'valid' === $nonce,
			metadata_eligible: static fn (): bool => true,
			invalidate_native: static function ( string $type ) use ( &$invalidated ): void {
				$invalidated[] = $type;
			},
			switch_install: static function () use ( &$installs ): string {
				++$installs;
				return 'installed';
			}
		);
		$disabled_result = $disabled_facade->enable( 'plugin', 'example/example.php', 1, 'stable', 'valid' );
		self::assertFalse( $disabled_result->successful() );
		self::assertSame( 'policy_disabled', $disabled_result->code() );
		self::assertSame( 0, $installs );
		$disabled_rechecks = $this->shutdown_rechecks();
		self::assertCount( 1, $disabled_rechecks );
		( $disabled_rechecks[0]['callback'] )();
		self::assertSame( array( 'plugin' ), $invalidated );
		$GLOBALS['ran_booster_runtime_actions'] = array();
		$invalidated                            = array();

		$manual      = $this->package( 'plugin', 'example/example.php', 'example', DeploymentPolicy::MANUAL, source: PackageSource::BRANCH );
		$manual_repo = $this->createStub( PluginRepository::class );
		$manual_repo->method( 'booster_plugin_from_file' )->willReturn( $manual );
		$manual_repo->method( 'all_deployment_plugins' )->willReturn( array() );
		$selection = $this->github_selection();
		self::assertNotNull( $this->native_selection_store );
		$state                     = $this->native_selection_store->read( true );
		$marked                    = $state;
		$marked['native_mutation'] = array(
			'selection_generation' => 0,
			'owner_tag'            => str_repeat( '0', 16 ) . str_repeat( 'a', 16 ),
			'target_type'          => 'plugin',
			'installed_identity'   => 'other/other.php',
			'phase'                => 'started',
		);
		self::assertTrue( $this->native_selection_store->compare_and_swap( $state, $marked ) );

		$held_registrar = $this->registrar( $manual_repo, $themes, $store, new RuntimeUpdaterLock(), $providers );
		$held_facade    = $this->facade(
			$manual_repo,
			$themes,
			$store,
			$held_registrar,
			new RuntimeUpdaterLock(),
			$providers,
			static fn (): bool => true,
			static fn ( string $nonce ): bool => 'valid' === $nonce,
			metadata_eligible: static fn (): bool => true,
			invalidate_native: static function ( string $type ) use ( &$invalidated ): void {
				$invalidated[] = $type;
			},
			switch_install: static function () use ( &$installs ): string {
				++$installs;
				return 'installed';
			},
			github_selection: $selection
		);
		$held_result    = $held_facade->enable( 'plugin', 'example/example.php', 1, 'stable', 'valid' );
		self::assertFalse( $held_result->successful() );
		self::assertSame( 'native_hold', $held_result->code() );
		self::assertSame( 0, $installs );
		$held_rechecks = $this->shutdown_rechecks();
		self::assertCount( 1, $held_rechecks );
		( $held_rechecks[0]['callback'] )();
		self::assertSame( array( 'plugin' ), $invalidated );
		$GLOBALS['ran_booster_runtime_actions'] = array();
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_enable_refuses_when_file_mods_are_disabled_by_site_configuration(): void {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Exercise the WordPress-owned constant without changing its runtime identity.
		define( 'DISALLOW_FILE_MODS', true );
		$installs    = 0;
		$invalidated = array();
		[ $facade ]  = $this->switch_facade(
			static function () use ( &$installs ): string {
				++$installs;
				return 'installed';
			},
			invalidate_native: static function ( string $type ) use ( &$invalidated ): void {
				$invalidated[] = $type;
			}
		);
		$result      = $facade->enable( 'plugin', 'example/example.php', 1, 'stable', 'valid' );
		self::assertFalse( $result->successful() );
		self::assertSame( 'policy_forced_off', $result->code() );
		self::assertSame( 0, $installs );
		$rechecks = $this->shutdown_rechecks();
		self::assertCount( 1, $rechecks );
		( $rechecks[0]['callback'] )();
		self::assertSame( array( 'plugin' ), $invalidated );
	}
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- This exact fixture spy remains beside the contract it exercises; its existing class identity and load order are intentional.
final class RuntimeUpdaterLock extends WordPressUpdaterLock {

	public int $acquires          = 0;
	public int $token_reads       = 0;
	public int $sql_token_reads   = 0;
	public bool $acquire_fails    = false;
	public bool $release_succeeds = true;
	public ?\Closure $on_release  = null;

	/** @var list<string> */
	public array $releases = array();

	public ?string $token = '1700000001';

	public function current_token(): ?string {
		++$this->token_reads;

		return $this->token;
	}

	/**
	 * @param callable(object, string): bool                                                                   $same_connection
	 * @param callable(string, list<int|string>, 'affected'|'var'|'results'): (int|string|list<object>|null) $execute
	 */
	public function current_token_sql( callable $same_connection, callable $execute ): ?string {
		unset( $same_connection, $execute );
		++$this->sql_token_reads;

		return $this->token;
	}

	public function acquire(): string {
		++$this->acquires;
		if ( $this->acquire_fails ) {
			throw new \RuntimeException( 'busy' );
		}

		$this->token = '1700000002';
		return '1700000002';
	}

	public function release( string $token ): bool {
		if ( null !== $this->on_release ) {
			( $this->on_release )( $token );
		}
		$this->releases[] = $token;

		return $this->release_succeeds;
	}
}
