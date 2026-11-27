<?php

declare(strict_types=1);

namespace RAN\Tests\AddOn;

	require_once __DIR__ . '/../Support/WPError.php';
	require_once __DIR__ . '/../Support/RepositoryAdminWordPressFunctions.php';
	require_once __DIR__ . '/../Support/PackageOperationGlobalWordPressFunctions.php';
	require_once __DIR__ . '/../Deployment/PackageMutationGuardWordPressFunctions.php';
	require_once __DIR__ . '/../Logging/LoggingWordPressFunctions.php';
	require_once __DIR__ . '/../Portability/WpPusherCoexistenceWordPressFunctions.php';
	require_once __DIR__ . '/../Support/ProspectiveReleaseFacadeWordPressFunctions.php';
	require_once __DIR__ . '/../fixtures/ran-booster-release-capability-provider/src/Providers.php';

	use PHPUnit\Framework\Attributes\DataProvider;
	use PHPUnit\Framework\Attributes\PreserveGlobalState;
	use PHPUnit\Framework\Attributes\RunInSeparateProcess;
	use PHPUnit\Framework\TestCase;
	use RAN\AddOn\ReleaseTracking\NativeProspectiveReleaseFacade;
	use RAN\AddOn\ReleaseTracking\ProspectiveReleaseFacade;
	use RAN\Admin\PackageRepositoryRequestResolver;
	use RAN\Deployment\DeploymentPolicy;
	use RAN\Deployment\PreparedArtifact;
use RAN\Deployment\ReleaseArtifactCleanupFailure;
	use RAN\PackageSource;
	use RAN\Plugin;
	use RAN\RepositoryProvider\ArchiveRequest;
	use RAN\RepositoryProvider\PreparedArchive;
	use RAN\RepositoryProvider\ProviderCode;
	use RAN\RepositoryProvider\ProviderDiagnosticRequest;
	use RAN\RepositoryProvider\ProviderDiagnostics;
	use RAN\RepositoryProvider\ProviderFailure;
	use RAN\RepositoryProvider\ProviderRequestFailed;
	use RAN\RepositoryProvider\ProviderMetadata;
	use RAN\RepositoryProvider\ProviderRegistry;
	use RAN\RepositoryProvider\RepositoryDescriptor;
	use RAN\RepositoryProvider\RepositoryLookupRequest;
	use RAN\RepositoryProvider\RepositoryProvider;
	use RAN\RepositoryProvider\RepositoryReference;
	use RAN\RepositoryProvider\RepositoryReleaseAcquirer;
	use RAN\RepositoryProvider\RepositoryReleaseAcquisitionRejected;
	use RAN\RepositoryProvider\RepositoryReleaseArtifact;
	use RAN\RepositoryProvider\RepositoryReleaseCandidate;
	use RAN\RepositoryProvider\RepositoryReleaseCandidateList;
	use RAN\RepositoryProvider\RepositoryReleaseCandidateListing;
	use RAN\RepositoryProvider\RepositoryReleaseInspection;
	use RAN\RepositoryProvider\RepositoryReleaseInspectionRejected;
	use RAN\RepositoryProvider\RepositoryReleaseInspector;
	use RAN\RepositoryProvider\RepositoryReleaseMetadata;
	use RAN\RepositoryProvider\RepositoryReleaseNativeTarget;
	use RAN\RepositoryProvider\RepositoryReleaseNativeTargets;
	use RAN\RepositoryProvider\RepositoryReleaseNativeTargetStatus;
	use RAN\RepositoryProvider\RepositoryReleaseReadUnavailable;
	use RAN\Storage\GitHubSelectionStore;
	use RAN\Storage\PackageMutationResult;
use RAN\Storage\PackageStorageOperation;
use RAN\Storage\Database;
use RAN\Storage\PluginRepository;
use RAN\Storage\RepositorySourceGuard;
	use RAN\Storage\ThemeRepository;
	use RAN\WordPress\CorePackageExecutionResult;
	use RAN\WordPress\CorePackageExecutionFailure;
	use RAN\WordPress\CorePackageExecutor;
	use RAN\WordPress\ManagedReleaseConfiguration;
	use RAN\WordPress\WordPressUpdaterLock;
	use RAN_Booster_ReleaseCapabilityFixture\PartialProvider as ReleaseFixturePartialProvider;
	use RAN_Booster_ReleaseCapabilityFixture\ReleaseProvider as ReleaseFixtureCompleteProvider;
	use RAN_Booster_ReleaseCapabilityFixture\ZeroProvider as ReleaseFixtureZeroProvider;
	use RuntimeException;
	use Throwable;


final class NativeProspectiveReleaseFacadeTest extends TestCase {
	use \RAN\Tests\Support\GitHubSelectionFixture;
	use \RAN\Tests\Support\CapturesBoosterLog;


	private const FINGERPRINT = 'v2:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

	private ?string $artifact_path                                 = null;
	private ?ProspectiveRepositoryReleaseArtifact $acquisition     = null;
	private ?ProspectiveSourceGuardDatabase $source_guard_database = null;

	public function test_prospective_facade_api_version_tracks_the_opaque_release_id_contract(): void {
		self::assertSame( 10, ProspectiveReleaseFacade::API_VERSION );
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this exact lifecycle override name.
	protected function setUp(): void {
		ProspectiveRepositoryProvider::$resolve_calls     = 0;
		ProspectiveRepositoryProvider::$listing_calls     = 0;
		ProspectiveRepositoryProvider::$inspection_calls  = 0;
		ProspectiveRepositoryProvider::$acquisition_calls = 0;
		ProspectiveRepositoryProvider::$metadata_calls    = 0;
		ProspectiveRepositoryProvider::$on_inspect        = null;
		ProspectiveRepositoryProvider::$inspection_input  = array();
		ProspectiveRepositoryProvider::$acquisition_input = array();
		ProspectiveRepositoryProvider::$acquisition       = null;
		$this->source_guard_database                      = null;
		$this->start_log_capture();

		$GLOBALS['ran_booster_prospective_options']              = array();
		$GLOBALS['ran_booster_package_mutation_guard_multisite'] = false;
		$GLOBALS['ran_booster_package_mutation_guard_file_mods'] = true;
		$GLOBALS['ran_booster_package_mutation_guard_contexts']  = array();
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this exact lifecycle override name.
	protected function tearDown(): void {
		$this->stop_log_capture();
		ProspectiveRepositoryProvider::$acquisition = null;
		if ( null !== $this->artifact_path && file_exists( $this->artifact_path ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test-only temporary artifact cleanup.
			unlink( $this->artifact_path );
		}
		$this->artifact_path = null;
		$this->acquisition   = null;
		unset( $GLOBALS['ran_booster_prospective_options'] );
		unset( $GLOBALS['ran_booster_wp_pusher_active_plugins'] );
	}

	public function test_installed_fixture_hands_the_exact_artifact_to_core_once(): void {
		$this->set_ready_release();
		$path    = (string) $this->artifact_path;
		$release = new \RAN_Booster_ReleaseCapabilityFixture\FixtureReleaseArtifact( $path, 'example', 'example.php' );

		$artifact = $release->handoff_to_core();
		self::assertSame( $path, $artifact->get_path() );
		self::assertSame( '2.0.0', $artifact->get_expected_version() );
		self::assertSame(
			hash( 'sha256', 'verified-release-archive' ),
			$artifact->inspect(
				static function ( string $owned_path ): string {
					$digest = hash_file( 'sha256', $owned_path );
					self::assertIsString( $digest );
					return $digest;
				}
			)
		);
		self::assertTrue( $release->discard() );
		self::assertFileExists( $path );
		$artifact->cleanup();
		self::assertFileDoesNotExist( $path );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'The fixture artifact was already handed off.' );
		$release->handoff_to_core();
	}

	public function test_the_prospective_install_records_the_repository_default_branch_by_name(): void {
		$facade     = ( new \ReflectionClass( NativeProspectiveReleaseFacade::class ) )->newInstanceWithoutConstructor();
		$repository = ( new \ReflectionMethod( NativeProspectiveReleaseFacade::class, 'managed_repository' ) )->invoke(
			$facade,
			array(
				'provider'                  => 'gh',
				'repository'                => 'owner/example',
				'provider_repository_id'    => 'repo-42',
				'repository_default_branch' => 'develop',
				'private'                   => '0',
				'credential_id'             => '',
			)
		);

		self::assertSame( 'develop', $repository->branch );
	}

	public function test_supported_provider_codes_are_bounded_and_local(): void {
		$plugins  = new ProspectivePluginRepository();
		$executor = new ProspectiveExecutor();
		$facade   = $this->facade( $plugins, $executor );

		self::assertSame( array( 'gh' ), $facade->supported_provider_codes( 'plugin' ) );
		self::assertSame( array( 'gh' ), $facade->supported_provider_codes( 'theme' ) );
		self::assertSame( array(), $facade->supported_provider_codes( 'invalid' ) );
		self::assertSame( 0, $executor->install_calls );
		self::assertSame( 0, $plugins->adoption_calls );
	}

	public function test_supported_provider_codes_derive_from_every_complete_provider_in_stable_order(): void {
		$facade = $this->facade(
			new ProspectivePluginRepository(),
			new ProspectiveExecutor(),
			provider: array(
				new ProspectiveRepositoryProvider( 'zeta' ),
				new ProspectiveListingOnlyProvider( 'middle' ),
				new ProspectiveRepositoryProvider( 'alpha' ),
			)
		);

		self::assertSame( array( 'alpha', 'zeta' ), $facade->supported_provider_codes( 'plugin' ) );
	}

	public function test_complete_product_placement_requires_all_five_release_facets_on_one_provider(): void {
		$listing     = new ProspectiveListingOnlyProvider( 'listing' );
		$inspection  = new ProspectiveProviderWithoutAcquisition();
		$acquisition = new ProspectiveAcquisitionOnlyProvider( 'acquisition' );
		$partial     = new ReleaseFixturePartialProvider();
		$zero        = new ReleaseFixtureZeroProvider();
		$complete    = new ReleaseFixtureCompleteProvider();
		$facade      = $this->facade(
			new ProspectivePluginRepository(),
			new ProspectiveExecutor(),
			provider: array( $listing, $inspection, $acquisition, $partial, $zero, $complete )
		);
		$registry    = new ProviderRegistry( array( $listing, $inspection, $acquisition, $partial, $zero, $complete ) );

		self::assertSame( array( 'p2-release' ), $facade->supported_provider_codes( 'plugin' ) );
		self::assertSame( $listing, $registry->require_capability( 'listing', RepositoryReleaseCandidateListing::class ) );
		self::assertSame( $inspection, $registry->require_capability( 'gh', RepositoryReleaseInspector::class ) );
		self::assertSame( $inspection, $registry->require_capability( 'gh', RepositoryReleaseMetadata::class ) );
		self::assertSame( $acquisition, $registry->require_capability( 'acquisition', RepositoryReleaseAcquirer::class ) );
		self::assertSame( $partial, $registry->require_capability( 'p2-partial', RepositoryReleaseCandidateListing::class ) );
		self::assertSame( $partial, $registry->require_capability( 'p2-partial', RepositoryReleaseMetadata::class ) );
		self::assertSame( $partial, $registry->require_capability( 'p2-partial', RepositoryReleaseNativeTargets::class ) );
		foreach ( array( RepositoryReleaseCandidateListing::class, RepositoryReleaseInspector::class, RepositoryReleaseAcquirer::class, RepositoryReleaseMetadata::class, RepositoryReleaseNativeTargets::class ) as $capability ) {
			self::assertFalse( ( new \ReflectionClass( $zero ) )->implementsInterface( $capability ) );
			self::assertSame( $complete, $registry->require_capability( 'p2-release', $capability ) );
		}
	}

	public function test_retired_discover_operation_has_no_nonce_scope(): void {
		$facade = $this->facade( new ProspectivePluginRepository(), new ProspectiveExecutor() );

		$this->expectException( \InvalidArgumentException::class );
		$facade->nonce_action( 'discover', 'plugin', null );
	}

	public function test_existing_release_owner_stops_prospective_acquisition_before_filesystem_mutation(): void {
		$database = new class() {
			public string $last_error = '';

			public function prepare( string $query, mixed ...$arguments ): string {
				unset( $arguments );

				return $query;
			}

			/** @return list<object> */
			public function get_results( string $query ): array {
				unset( $query );

				return array(
					(object) array(
						'type'                   => 2,
						'package'                => 'existing-theme',
						'source'                 => PackageSource::RELEASE_ASSET->value,
						'provider'               => 'gh',
						'provider_repository_id' => '123456789',
					),
				);
			}
		};
		$plugins  = new ProspectivePluginRepository();
		$executor = new ProspectiveExecutor();
		$facade   = $this->facade(
			$plugins,
			$executor,
			source_guard: new RepositorySourceGuard( $database, $this->createStub( Database::class ) )
		);

		$result = $facade->install( 'plugin', $this->repository_request(), '42', 'v1.2.3', self::FINGERPRINT, 'stable', 'valid-nonce', $this->expectation_for( $this->repository_request() ) );

		self::assertSame( 'release_repository_conflict', $result->code() );
		self::assertSame( 0, ProspectiveRepositoryProvider::$acquisition_calls );
		self::assertSame( 0, $executor->install_calls );
		self::assertSame( 0, $plugins->adoption_calls );
	}

	public function test_unavailable_repository_relationship_stops_prospective_acquisition_before_filesystem_mutation(): void {
		$database = new class() {
			public string $last_error = 'read failed';

			public function prepare( string $query, mixed ...$arguments ): string {
				unset( $arguments );

				return $query;
			}

			/** @return list<object> */
			public function get_results( string $query ): array {
				unset( $query );

				return array();
			}
		};
		$plugins  = new ProspectivePluginRepository();
		$executor = new ProspectiveExecutor();
		$facade   = $this->facade(
			$plugins,
			$executor,
			source_guard: new RepositorySourceGuard( $database, $this->createStub( Database::class ) )
		);

		$result = $facade->install( 'plugin', $this->repository_request(), '42', 'v1.2.3', self::FINGERPRINT, 'stable', 'valid-nonce', $this->expectation_for( $this->repository_request() ) );

		self::assertSame( 'release_unavailable', $result->code() );
		self::assertSame( 0, ProspectiveRepositoryProvider::$acquisition_calls );
		self::assertSame( 0, $executor->install_calls );
	}

	public function test_unsupported_provider_fails_before_repository_resolution_or_preflight(): void {
		$plugins    = new ProspectivePluginRepository();
		$executor   = new ProspectiveExecutor();
		$facade     = $this->facade( $plugins, $executor );
		$repository = $this->repository_request();

		$repository['provider'] = 'bb';

		$results = array(
			$facade->list_candidates( 'plugin', $repository, 'stable', 'valid-nonce' ),
			$facade->inspect( 'plugin', $repository, '42', 'v1.2.3', 'stable', 'valid-nonce' ),
			$facade->install(
				'plugin',
				$repository,
				'42',
				'v1.2.3',
				self::FINGERPRINT,
				'stable',
				'valid-nonce',
				$this->expectation_for( $repository )
			),
		);

		foreach ( $results as $result ) {
			self::assertFalse( $result->successful() );
			self::assertSame( 'unsupported_provider', $result->code() );
			self::assertSame( array(), $result->data() );
		}
		self::assertSame( 0, ProspectiveRepositoryProvider::$resolve_calls );
		self::assertSame( 0, $executor->install_calls );
		self::assertSame( 0, $plugins->adoption_calls );
	}

	public function test_a_paused_site_refuses_a_preview_install_before_any_release_work(): void {
		$plugins                                      = new ProspectivePluginRepository();
		$executor                                     = new ProspectiveExecutor();
		$facade                                       = $this->facade( $plugins, $executor );
		$repository                                   = $this->repository_request();
		$GLOBALS['ran_booster_test_environment_type'] = 'production';
		$GLOBALS['ran_booster_test_home_url']         = 'https://example.com';
		$directory                                    = sys_get_temp_dir() . '/ran-booster-paused-log-' . bin2hex( random_bytes( 8 ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Isolated capture fixture.
		self::assertTrue( mkdir( $directory, 0700 ) );
		$capture = new \RAN\Logging\TemporaryDebugCapture( $directory . '/capture.json', static fn (): int => strtotime( '2026-11-19T12:00:00Z' ) );
		$capture->start();
		\RAN\Logging\BoosterLogger::configure_capture( $capture );
		try {
			$result  = $facade->install( 'plugin', $repository, '42', 'v1.2.3', self::FINGERPRINT, 'prerelease', 'valid-nonce', $this->expectation_for( $repository ) );
			$entries = array_column( $capture->snapshot()['entries'], 'line' );
		} finally {
			\RAN\Logging\BoosterLogger::configure_capture( null );
			foreach ( (array) glob( $directory . '/*' ) as $path ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Isolated capture fixture.
				unlink( (string) $path );
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Isolated capture fixture.
			rmdir( $directory );
			unset( $GLOBALS['ran_booster_test_environment_type'], $GLOBALS['ran_booster_test_home_url'] );
		}

		self::assertSame( array( false, 'source_paused' ), array( $result->successful(), $result->code() ) );
		self::assertSame( array( '[ran-booster] release operation refused {"event":"release_operation_refused","outcome_code":"source_paused"}' ), $entries );
		self::assertSame( 0, ProspectiveRepositoryProvider::$resolve_calls );
		self::assertSame( 0, $executor->install_calls );
		self::assertSame( 0, $plugins->adoption_calls );
	}

	public function test_opaque_repository_identity_expectation_reaches_prospective_candidate_listing_unchanged(): void {
		$provider   = new ProspectiveRepositoryProvider(
			candidate_list: new RepositoryReleaseCandidateList(
				array( new RepositoryReleaseCandidate( '42', 'v1.2.3', '1.2.3', false, '2026-08-17T12:00:00Z', array( 'example-1.2.3.zip' ) ) )
			),
			repository_id: "opaque\\id'42"
		);
		$facade     = $this->facade( new ProspectivePluginRepository(), new ProspectiveExecutor(), provider: $provider );
		$repository = array_merge( $this->repository_request(), array( 'provider_repository_id' => "opaque\\id'42" ) );

		$result = $facade->list_candidates( 'plugin', $repository, 'stable', 'valid-nonce' );

		self::assertTrue( $result->successful() );
		self::assertSame( 1, ProspectiveRepositoryProvider::$listing_calls );
	}

	public function test_reassigned_opaque_repository_locator_refuses_each_prospective_stage_before_release_activity(): void {
		$plugins    = new ProspectivePluginRepository();
		$executor   = new ProspectiveExecutor();
		$facade     = $this->facade( $plugins, $executor, provider: new ProspectiveRepositoryProvider( repository_id: "opaqueid'42" ) );
		$repository = array_merge( $this->repository_request(), array( 'provider_repository_id' => "opaque\\id'42" ) );

		$listing      = $facade->list_candidates( 'plugin', $repository, 'stable', 'valid-nonce' );
		$inspection   = $facade->inspect( 'plugin', $repository, '42', 'v1.2.3', 'stable', 'valid-nonce' );
		$installation = $facade->install( 'plugin', $repository, '42', 'v1.2.3', self::FINGERPRINT, 'stable', 'valid-nonce', $this->expectation_for( $repository ) );

		self::assertSame( 'unable_to_check', $listing->code() );
		self::assertSame( 'unable_to_check', $inspection->code() );
		self::assertSame( 'install_failed', $installation->code() );
		self::assertSame( 3, ProspectiveRepositoryProvider::$resolve_calls );
		self::assertSame( 0, ProspectiveRepositoryProvider::$listing_calls );
		self::assertSame( 0, ProspectiveRepositoryProvider::$inspection_calls );
		self::assertSame( 0, ProspectiveRepositoryProvider::$acquisition_calls );
		self::assertSame( 0, $executor->install_calls );
		self::assertSame( 0, $plugins->adoption_calls );
	}

	public function test_registered_provider_without_listing_facet_fails_before_repository_resolution_or_remote_work(): void {
		$provider = new ProspectiveRepositoryProviderWithoutListing();
		$facade   = $this->facade(
			new ProspectivePluginRepository(),
			new ProspectiveExecutor(),
			provider: $provider
		);

		self::assertSame( array(), $facade->supported_provider_codes( 'plugin' ) );
		$result = $facade->list_candidates(
			'plugin',
			$this->repository_request(),
			'stable',
			'valid-nonce'
		);

		self::assertFalse( $result->successful() );
		self::assertSame( 'unsupported_provider', $result->code() );
		self::assertSame( array(), $result->data() );
		self::assertSame( 0, $provider->resolve_calls );
	}

	public function test_listing_only_provider_fails_before_repository_resolution_or_candidate_listing(): void {
		$provider               = new ProspectiveListingOnlyProvider( 'forge' );
		$plugins                = new ProspectivePluginRepository();
		$executor               = new ProspectiveExecutor();
		$facade                 = $this->facade( $plugins, $executor, provider: $provider );
		$repository             = $this->repository_request();
		$repository['provider'] = 'forge';

		self::assertSame( array(), $facade->supported_provider_codes( 'plugin' ) );

		$listing = $facade->list_candidates( 'plugin', $repository, 'stable', 'valid-nonce' );
		self::assertFalse( $listing->successful() );
		self::assertSame( 'unsupported_provider', $listing->code() );
		self::assertSame( array(), $listing->data() );

		$results = array(
			$facade->inspect( 'plugin', $repository, '42', 'v1.2.3', 'stable', 'valid-nonce' ),
			$facade->install(
				'plugin',
				$repository,
				'42',
				'v1.2.3',
				self::FINGERPRINT,
				'stable',
				'valid-nonce',
				$this->expectation_for( $repository )
			),
		);

		foreach ( $results as $result ) {
			self::assertFalse( $result->successful() );
			self::assertSame( 'unsupported_provider', $result->code() );
		}
		self::assertSame( 0, ProspectiveRepositoryProvider::$resolve_calls );
		self::assertSame( 0, ProspectiveRepositoryProvider::$listing_calls );
		self::assertSame( 0, $executor->install_calls );
		self::assertSame( 0, $plugins->adoption_calls );
	}

	public function test_partial_provider_fails_before_repository_resolution_or_candidate_listing(): void {
		$provider   = new ProspectiveProviderWithoutAcquisition();
		$facade     = $this->facade(
			new ProspectivePluginRepository(),
			new ProspectiveExecutor(),
			provider: $provider
		);
		$repository = $this->repository_request();

		self::assertSame( array(), $facade->supported_provider_codes( 'plugin' ) );

		$result = $facade->list_candidates( 'plugin', $repository, 'stable', 'valid-nonce' );

		self::assertFalse( $result->successful() );
		self::assertSame( 'unsupported_provider', $result->code() );
		self::assertSame( array(), $result->data() );
		self::assertSame( 0, ProspectiveRepositoryProvider::$resolve_calls );
		self::assertSame( 0, ProspectiveRepositoryProvider::$listing_calls );
	}

	public function test_provider_without_acquisition_fails_install_before_mutation_or_repository_resolution(): void {
		$provider = new ProspectiveProviderWithoutAcquisition();
		$plugins  = new ProspectivePluginRepository();
		$executor = new ProspectiveExecutor();
		$facade   = $this->facade( $plugins, $executor, provider: $provider );
		$GLOBALS['ran_booster_package_mutation_guard_file_mods'] = false;

		self::assertSame( array(), $facade->supported_provider_codes( 'plugin' ) );
		$result = $facade->install(
			'plugin',
			$this->repository_request(),
			'42',
			'v1.2.3',
			self::FINGERPRINT,
			'stable',
			'valid-nonce',
			$this->expectation_for( $this->repository_request() )
		);

		self::assertFalse( $result->successful() );
		self::assertSame( 'unsupported_provider', $result->code() );
		self::assertSame( 0, ProspectiveRepositoryProvider::$resolve_calls );
		self::assertSame( 0, ProspectiveRepositoryProvider::$acquisition_calls );
		self::assertSame( array(), $GLOBALS['ran_booster_package_mutation_guard_contexts'] );
		self::assertSame( 0, $executor->install_calls );
		self::assertSame( 0, $plugins->adoption_calls );
	}

	public function test_candidate_projection_preserves_a_formerly_overflowing_provider_identity(): void {
		$provider = new ProspectiveRepositoryProvider(
			'gh',
			new RepositoryReleaseCandidateList(
				array(
					new RepositoryReleaseCandidate(
						'9999999999999999999',
						'v1.2.3',
						'1.2.3',
						false,
						'2026-08-17T12:00:00Z',
						array( 'example-1.2.3.zip' )
					),
				)
			)
		);
		$facade   = $this->facade(
			new ProspectivePluginRepository(),
			new ProspectiveExecutor(),
			provider: $provider
		);

		$result = $facade->list_candidates(
			'plugin',
			$this->repository_request(),
			'stable',
			'valid-nonce'
		);

		self::assertTrue( $result->successful() );
		self::assertSame( 'release_candidates_available', $result->code() );
		self::assertSame( '9999999999999999999', $result->data()['candidates'][0]['release_id'] ?? null );
		self::assertSame( 1, ProspectiveRepositoryProvider::$resolve_calls );
	}

	public function test_opaque_provider_release_identity_survives_the_prospective_facade(): void {
		$opaque_id  = 'release:opaque/42';
		$provider   = new ProspectiveRepositoryProvider(
			'gh',
			new RepositoryReleaseCandidateList(
				array(
					new RepositoryReleaseCandidate(
						$opaque_id,
						'v1.2.3',
						'1.2.3',
						false,
						'2026-08-17T12:00:00Z',
						array( 'example-1.2.3.zip' )
					),
				)
			)
		);
		$reference  = new RepositoryReference( 'owner/example', '123456789', false, null );
		$candidate  = $provider->list_release_candidates( 'plugin', $reference, 'stable' )->candidates[0];
		$inspection = $provider->inspect_release( 'plugin', $reference, $candidate->provider_release_id, $candidate->tag, 'stable' );
		$plugins    = new ProspectivePluginRepository();
		$executor   = new ProspectiveExecutor();
		$facade     = $this->facade( $plugins, $executor, provider: $provider );

		self::assertSame( $opaque_id, $candidate->provider_release_id );
		self::assertSame( $opaque_id, $inspection->provider_release_id );

		$result = $facade->list_candidates(
			'plugin',
			$this->repository_request(),
			'stable',
			'valid-nonce'
		);

		self::assertTrue( $result->successful() );
		self::assertSame( 'release_candidates_available', $result->code() );
		self::assertSame( $opaque_id, $result->data()['candidates'][0]['release_id'] ?? null );
		self::assertSame( 1, ProspectiveRepositoryProvider::$resolve_calls );
		self::assertSame( 1, ProspectiveRepositoryProvider::$inspection_calls );
		self::assertSame( 0, ProspectiveRepositoryProvider::$acquisition_calls );
		self::assertSame( 0, $executor->install_calls );
		self::assertSame( 0, $plugins->adoption_calls );
	}

	public function test_stable_candidate_projection_rejects_prerelease_evidence(): void {
		$provider = new ProspectiveRepositoryProvider(
			'gh',
			new RepositoryReleaseCandidateList(
				array(
					new RepositoryReleaseCandidate(
						'42',
						'v2.0.0-beta.2',
						'2.0.0-beta.2',
						true,
						'2026-08-17T12:00:00.123Z',
						array( 'example-2.0.0-beta.2.zip' )
					),
				)
			)
		);
		$facade   = $this->facade(
			new ProspectivePluginRepository(),
			new ProspectiveExecutor(),
			provider: $provider
		);

		$result = $facade->list_candidates(
			'plugin',
			$this->repository_request(),
			'stable',
			'valid-nonce'
		);

		self::assertFalse( $result->successful() );
		self::assertSame( 'unable_to_check', $result->code() );
		self::assertSame( array(), $result->data() );
		self::assertSame( 1, ProspectiveRepositoryProvider::$resolve_calls );
	}

	public function test_stable_candidate_projection_accepts_provider_owned_hyphenated_version(): void {
		$provider = new ProspectiveRepositoryProvider(
			'gh',
			new RepositoryReleaseCandidateList(
				array(
					new RepositoryReleaseCandidate(
						'42',
						'v2026-08',
						'2026-08',
						false,
						'2026-08-17T12:00:00Z',
						array( 'example-2026-08.zip' )
					),
				)
			)
		);
		$facade   = $this->facade(
			new ProspectivePluginRepository(),
			new ProspectiveExecutor(),
			provider: $provider
		);

		$result = $facade->list_candidates(
			'plugin',
			$this->repository_request(),
			'stable',
			'valid-nonce'
		);

		self::assertTrue( $result->successful() );
		self::assertSame( 'release_candidates_available', $result->code() );
		self::assertSame( '2026-08', $result->data()['candidates'][0]['version'] ?? null );
		self::assertSame( 1, ProspectiveRepositoryProvider::$resolve_calls );
	}

	public function test_candidate_list_maps_bounded_display_data_without_inspecting_or_installing(): void {
		$provider = new ProspectiveRepositoryProvider(
			candidate_list: new RepositoryReleaseCandidateList(
				array(
					new RepositoryReleaseCandidate( '52', 'v2.0.0-beta.2', '2.0.0-beta.2', true, '2026-07-28T08:00:00Z', array( 'example-2.0.0-beta.2.zip' ) ),
					new RepositoryReleaseCandidate( '42', 'v1.2.3', '1.2.3', false, '2026-07-27T08:00:00Z', array( 'example-1.2.3.zip' ) ),
				)
			)
		);
		$plugins  = new ProspectivePluginRepository();
		$executor = new ProspectiveExecutor();
		$facade   = $this->facade( $plugins, $executor, provider: $provider );

		self::assertSame( array( 'gh' ), $facade->supported_provider_codes( 'plugin' ) );
		self::assertSame(
			'ran-booster-prospective-release-list_candidates-plugin',
			$facade->nonce_action( 'list_candidates', 'plugin', null )
		);
		$result = $facade->list_candidates(
			'plugin',
			$this->repository_request(),
			'prerelease',
			'valid-nonce'
		);

		self::assertTrue( $result->successful() );
		self::assertSame( 'release_candidates_available', $result->code() );
		self::assertSame(
			array(
				'candidates'       => array(
					array(
						'release_id'           => '52',
						'tag'                  => 'v2.0.0-beta.2',
						'version'              => '2.0.0-beta.2',
						'prerelease'           => true,
						'published_at'         => '2026-07-28T08:00:00Z',
						'expected_asset_names' => array( 'example-2.0.0-beta.2.zip' ),
					),
					array(
						'release_id'           => '42',
						'tag'                  => 'v1.2.3',
						'version'              => '1.2.3',
						'prerelease'           => false,
						'published_at'         => '2026-07-27T08:00:00Z',
						'expected_asset_names' => array( 'example-1.2.3.zip' ),
					),
				),
				'channel'          => 'prerelease',
				'search_exhausted' => null,
				'diagnostics'      => null,
			),
			$result->data()
		);
		self::assertSame( 1, ProspectiveRepositoryProvider::$listing_calls );
		self::assertSame( 0, $executor->install_calls );
		self::assertSame( 0, $plugins->adoption_calls );
	}

	/** @param 'stable'|'prerelease' $channel */
	#[DataProvider( 'listing_observation_cases' )]
	public function test_listing_observations_survive_the_real_reader_including_empty_results( string $type, string $channel, int $count, ?bool $search ): void {
		$observed   = null !== $search;
		$candidates = array();
		for ( $index = 0; $index < $count; ++$index ) {
			$candidates[] = new RepositoryReleaseCandidate( (string) ( 42 + $index ), 'v1.2.3', '1.2.3', false, '2026-07-27T08:00:00Z', array( 'example.zip' ) );
		}
		$report   = array(
			'scanned_count' => 4,
			'dropped_count' => 1,
			'drops'         => array(
				array(
					'release_identity' => '41',
					'tag'              => 'V1.2+RC.1',
					'reason'           => 'tag_not_version',
				),
			),
			'truncated'     => false,
		);
		$provider = new ProspectiveRepositoryProvider( candidate_list: new RepositoryReleaseCandidateList( $candidates, $search, $observed ? $report : null ) );
		$plugins  = new ProspectivePluginRepository();
		$executor = new ProspectiveExecutor();
		$facade   = $this->facade( $plugins, $executor, provider: $provider );
		$result   = $facade->list_candidates( $type, $this->repository_request(), $channel, 'valid-nonce' );
		self::assertSame( $count > 0, $result->successful() );
		self::assertSame( 0 === $count ? 'no_releases' : 'release_candidates_available', $result->code() );
		self::assertSame( $channel, $result->data()['channel'] ?? null );
		self::assertCount( $count, $result->data()['candidates'] ?? array() );
		self::assertArrayHasKey( 'search_exhausted', $result->data() );
		self::assertSame( $search, $result->data()['search_exhausted'] );
		self::assertSame(
			$observed ? array(
				'scanned_count' => 4,
				'dropped_count' => 1,
				'drops'         => array(
					array(
						'reason' => 'tag_not_version',
						'tag'    => 'V1.2+RC.1',
					),
				),
				'truncated'     => false,
			) : null,
			$result->data()['diagnostics'] ?? null
		);
		self::assertSame( 1, ProspectiveRepositoryProvider::$listing_calls );
		self::assertSame( 0, ProspectiveRepositoryProvider::$inspection_calls );
		self::assertSame( 0, ProspectiveRepositoryProvider::$acquisition_calls );
		self::assertSame( 0, $executor->install_calls );
		self::assertSame( 0, $plugins->adoption_calls );
	}

	/** @return iterable<string,array{string,string,int,?bool}> */
	public static function listing_observation_cases(): iterable {
		foreach ( array( 'plugin', 'theme' ) as $type ) {
			foreach ( array( 'stable', 'prerelease' ) as $channel ) {
				foreach ( array( 0, 1, 3 ) as $count ) {
					foreach ( array( null, false, true ) as $search ) {
						yield $type . '-' . $channel . '-' . $count . '-' . ( null === $search ? 'legacy' : ( $search ? 'stopped' : 'not-stopped' ) ) => array( $type, $channel, $count, $search );
					}
				}
			}
		}
	}

	public function test_internal_candidate_reader_closes_resolver_and_listing_failures(): void {
		$failures = array(
			'resolver' => new ProspectiveRepositoryProvider( resolve_failure: new RuntimeException( 'resolver-failure' ) ),
			'listing'  => new ProspectiveRepositoryProvider( candidate_list: new RuntimeException( 'listing-failure' ) ),
		);

		foreach ( $failures as $name => $provider ) {
			$facade = $this->facade( new ProspectivePluginRepository(), new ProspectiveExecutor(), provider: $provider );
			$result = $facade->list_candidates( 'plugin', $this->repository_request(), 'stable', 'valid-nonce' );

			self::assertFalse( $result->successful(), $name );
			self::assertSame( 'unable_to_check', $result->code(), $name );
			self::assertSame( array(), $result->data(), $name );
		}
	}

	public function test_candidate_reader_marks_only_an_anonymous_rate_limited_listing_for_the_lookup_hint(): void {
		$cases = array(
			'rate limited'    => array( new ProviderFailure( 'rate_limited', 429, 90 ), 90 ),
			'throttled'       => array( new ProviderFailure( 'rate_limited_secondary', 403, 60 ), 60 ),
			'no time'         => array( new ProviderFailure( 'rate_limited', 429 ), null ),
			'unrelated'       => array( new ProviderFailure( 'http_5xx', 502 ), false ),
			'no typed reason' => array( null, false ),
		);

		foreach ( $cases as $name => [ $failure, $delay ] ) {
			$provider = new ProspectiveRepositoryProvider( candidate_list: new RepositoryReleaseReadUnavailable( 'listing-failure', 0, null, $failure ) );
			$facade   = $this->facade( new ProspectivePluginRepository(), new ProspectiveExecutor(), provider: $provider );
			$before   = time();
			$result   = $facade->list_candidates( 'plugin', $this->repository_request(), 'stable', 'valid-nonce' );
			$after    = time();

			self::assertFalse( $result->successful(), $name );
			self::assertSame( 'unable_to_check', $result->code(), $name );
			if ( false === $delay ) {
				self::assertSame( array(), $result->data(), $name );
				continue;
			}
			$data = $result->data();
			self::assertSame( array( 'rate_limit', 'rate_limited_provider' ), array_keys( $data ), $name );
			self::assertSame( 'gh', $data['rate_limited_provider'], $name );
			self::assertSame( 'Prospective provider', $data['rate_limit']['provider'], $name );
			if ( null === $delay ) {
				self::assertNull( $data['rate_limit']['retry_at'], $name );
				continue;
			}
			self::assertGreaterThanOrEqual( $before + $delay, $data['rate_limit']['retry_at'], $name );
			self::assertLessThanOrEqual( $after + $delay, $data['rate_limit']['retry_at'], $name );
		}
	}

	public function test_an_anonymous_rate_limit_with_a_retry_time_pauses_the_next_anonymous_listing(): void {
		require_once __DIR__ . '/../Internal/AnonymousLookupCooldownWordPressFunctions.php';
		$GLOBALS['ran_booster_cooldown_transients'] = array();
		try {
			$provider = new ProspectiveRepositoryProvider( candidate_list: new RepositoryReleaseReadUnavailable( 'listing-failure', 0, null, new ProviderFailure( 'rate_limited', 429, 3540 ) ) );
			$facade   = $this->facade( new ProspectivePluginRepository(), new ProspectiveExecutor(), provider: $provider );
			$first    = $facade->list_candidates( 'plugin', $this->repository_request(), 'stable', 'valid-nonce' )->data();
			$retry_at = $first['rate_limit']['retry_at'] ?? null;
			self::assertIsInt( $retry_at );
			self::assertSame( $retry_at, $GLOBALS['ran_booster_cooldown_transients']['ran_booster_anonymous_cooldown_gh']['value'] ?? null );
			self::assertSame( 1, ProspectiveRepositoryProvider::$resolve_calls );
			self::assertSame( 1, ProspectiveRepositoryProvider::$listing_calls );

			// The next anonymous listing sends neither the repository resolution nor the listing, and reports the same limit.
			$second = $facade->list_candidates( 'plugin', $this->repository_request(), 'stable', 'valid-nonce' );
			self::assertSame( 'unable_to_check', $second->code() );
			self::assertSame(
				array(
					'rate_limit'            => array(
						'provider' => 'Prospective provider',
						'retry_at' => $retry_at,
					),
					'rate_limited_provider' => 'gh',
				),
				$second->data()
			);
			self::assertSame( 1, ProspectiveRepositoryProvider::$resolve_calls );
			self::assertSame( 1, ProspectiveRepositoryProvider::$listing_calls );
		} finally {
			unset( $GLOBALS['ran_booster_cooldown_transients'] );
		}
	}

	public function test_a_rate_limited_anonymous_repository_resolution_names_the_provider_and_pauses_lookups(): void {
		require_once __DIR__ . '/../Internal/AnonymousLookupCooldownWordPressFunctions.php';
		$GLOBALS['ran_booster_cooldown_transients'] = array();
		try {
			$provider = new ProspectiveRepositoryProvider( resolve_failure: new ProviderRequestFailed( new ProviderFailure( 'rate_limited', 429, 3540 ) ) );
			$facade   = $this->facade( new ProspectivePluginRepository(), new ProspectiveExecutor(), provider: $provider );
			$first    = $facade->list_candidates( 'plugin', $this->repository_request(), 'stable', 'valid-nonce' );
			$retry_at = $first->data()['rate_limit']['retry_at'] ?? null;

			self::assertSame( 'unable_to_check', $first->code() );
			self::assertIsInt( $retry_at );
			self::assertSame( 'gh', $first->data()['rate_limited_provider'] ?? null );
			self::assertSame( 'Prospective provider', $first->data()['rate_limit']['provider'] ?? null );
			self::assertSame( $retry_at, $GLOBALS['ran_booster_cooldown_transients']['ran_booster_anonymous_cooldown_gh']['value'] ?? null );

			$facade->list_candidates( 'plugin', $this->repository_request(), 'stable', 'valid-nonce' );
			self::assertSame( 1, ProspectiveRepositoryProvider::$resolve_calls );
			self::assertSame( 0, ProspectiveRepositoryProvider::$listing_calls );

			// A slashed blank credential is anonymous to the resolver, so it waits too.
			$facade->list_candidates( 'plugin', array( 'credential_id' => '\\' ) + $this->repository_request(), 'stable', 'valid-nonce' );
			self::assertSame( 1, ProspectiveRepositoryProvider::$resolve_calls );

			// A credentialed lookup still reaches the provider during the anonymous pause.
			$facade->list_candidates( 'plugin', array( 'credential_id' => 'profile' ) + $this->repository_request(), 'stable', 'valid-nonce' );
			self::assertSame( 2, ProspectiveRepositoryProvider::$resolve_calls );
		} finally {
			unset( $GLOBALS['ran_booster_cooldown_transients'] );
		}
	}

	public function test_invalid_channel_fails_before_any_prospective_release_work(): void {
		$facade = $this->facade(
			new ProspectivePluginRepository(),
			new ProspectiveExecutor()
		);

		$results = array(
			$facade->list_candidates(
				'plugin',
				$this->repository_request(),
				'preview', // @phpstan-ignore argument.type (Deliberately invalid channel proves rejection before prospective release work.)
				'valid-nonce'
			),
			$facade->inspect(
				'plugin',
				$this->repository_request(),
				'42',
				'v1.2.3',
				'preview', // @phpstan-ignore argument.type (Deliberately invalid channel proves rejection before prospective release work.)
				'valid-nonce'
			),
			$facade->install(
				'plugin',
				$this->repository_request(),
				'42',
				'v1.2.3',
				self::FINGERPRINT,
				'preview', // @phpstan-ignore argument.type (Deliberately invalid channel proves rejection before prospective release work.)
				'valid-nonce',
				$this->expectation_for( $this->repository_request() )
			),
		);

		foreach ( $results as $result ) {
			self::assertFalse( $result->successful() );
			self::assertSame( 'forbidden', $result->code() );
		}
	}

	public function test_failed_exact_validation_does_not_execute_or_persist_anything(): void {
		ProspectiveRepositoryProvider::$acquisition = RepositoryReleaseAcquisitionRejected::invalid_release();
		$plugins                                    = new ProspectivePluginRepository();
		$executor                                   = new ProspectiveExecutor();
		$facade                                     = $this->facade( $plugins, $executor );

		$result = $facade->install(
			'plugin',
			$this->repository_request(),
			'42',
			'v1.2.3',
			self::FINGERPRINT,
			'stable',
			'valid-nonce',
			$this->expectation_for( $this->repository_request() )
		);

		self::assertFalse( $result->successful() );
		self::assertSame( 'release_invalid', $result->code() );
		self::assertSame( 1, ProspectiveRepositoryProvider::$acquisition_calls );
		self::assertSame( 0, $executor->install_calls );
		self::assertSame( 0, $plugins->adoption_calls );
	}

	public function test_operational_acquisition_failure_returns_unable_before_install(): void {
		ProspectiveRepositoryProvider::$acquisition = new RuntimeException( 'provider-secret-message' );
		$plugins                                    = new ProspectivePluginRepository();
		$executor                                   = new ProspectiveExecutor();
		$facade                                     = $this->facade( $plugins, $executor );

		$result = $facade->install(
			'plugin',
			$this->repository_request(),
			'42',
			'v1.2.3',
			self::FINGERPRINT,
			'stable',
			'valid-nonce',
			$this->expectation_for( $this->repository_request() )
		);

		self::assertFalse( $result->successful() );
		self::assertSame( 'unable_to_check', $result->code() );
		self::assertSame( array(), $result->data() );
		self::assertSame( 1, ProspectiveRepositoryProvider::$resolve_calls );
		self::assertSame( 1, ProspectiveRepositoryProvider::$acquisition_calls );
		self::assertSame( 0, $executor->install_calls );
		self::assertSame( 0, $plugins->adoption_calls );
	}

	public function test_a_changed_release_at_acquisition_is_reported_as_changed_before_install(): void {
		ProspectiveRepositoryProvider::$acquisition = RepositoryReleaseAcquisitionRejected::changed();
		$plugins                                    = new ProspectivePluginRepository();
		$executor                                   = new ProspectiveExecutor();
		$facade                                     = $this->facade( $plugins, $executor );

		$result = $facade->install(
			'plugin',
			$this->repository_request(),
			'42',
			'v1.2.3',
			self::FINGERPRINT,
			'stable',
			'valid-nonce',
			$this->expectation_for( $this->repository_request() )
		);

		self::assertFalse( $result->successful() );
		self::assertSame( 'release_changed', $result->code() );
		self::assertSame( 0, $executor->install_calls );
		self::assertSame( 0, $plugins->adoption_calls );
	}

	public function test_an_acquisition_provider_failure_keeps_the_closed_code_and_logs_only_its_reason(): void {
		ProspectiveRepositoryProvider::$acquisition = new ProviderRequestFailed( new ProviderFailure( 'credential_expired' ) );
		$facade                                     = $this->facade( new ProspectivePluginRepository(), new ProspectiveExecutor() );

		$result = $facade->install(
			'plugin',
			$this->repository_request(),
			'42',
			'v1.2.3',
			self::FINGERPRINT,
			'stable',
			'valid-nonce',
			$this->expectation_for( $this->repository_request() )
		);

		self::assertSame( 'unable_to_check', $result->code() );
		$lines = $this->captured_log_lines();
		self::assertCount( 1, $lines );
		self::assertStringContainsString( '"reason":"credential_expired","operation":"acquire_release"', $lines[0] );
		self::assertStringContainsString( '"exception_code":"401"', $lines[0] );
		self::assertStringContainsString( '"operation":"acquire_release","provider":"gh"', $lines[0] );
	}

	public function test_an_inspection_read_failure_is_logged_with_its_reason_and_keeps_the_closed_code(): void {
		$provider = new ProspectiveRepositoryProvider(
			inspection: new RepositoryReleaseReadUnavailable( 'sentinel-provider-message ghp_abcdefghijklmnop1234', 0, null, new ProviderFailure( 'rate_limited', 429, 90 ) )
		);
		$facade   = $this->facade( new ProspectivePluginRepository(), new ProspectiveExecutor(), provider: $provider );

		$result = $facade->inspect( 'plugin', $this->repository_request(), '42', 'v1.2.3', 'stable', 'valid-nonce' );

		self::assertFalse( $result->successful() );
		self::assertSame( 'unable_to_check', $result->code() );
		$lines = $this->captured_log_lines();
		self::assertCount( 1, $lines );
		self::assertStringContainsString( '"reason":"rate_limited","http_status":429,"retry_after":90', $lines[0] );
		self::assertStringContainsString( '"operation":"inspect_release","provider":"gh"', $lines[0] );
		self::assertStringNotContainsString( 'sentinel-provider-message', $lines[0] );
		self::assertStringNotContainsString( 'ghp_', $lines[0] );
	}

	public function test_provider_acquisition_cleanup_failure_is_preserved_before_install(): void {
		ProspectiveRepositoryProvider::$acquisition = RepositoryReleaseAcquisitionRejected::cleanup_failed();
		$plugins                                    = new ProspectivePluginRepository();
		$executor                                   = new ProspectiveExecutor();
		$facade                                     = $this->facade( $plugins, $executor );

		$result = $facade->install(
			'plugin',
			$this->repository_request(),
			'42',
			'v1.2.3',
			self::FINGERPRINT,
			'stable',
			'valid-nonce',
			$this->expectation_for( $this->repository_request() )
		);

		self::assertFalse( $result->successful() );
		self::assertSame( 'installation_cleanup_failed', $result->code() );
		self::assertSame( 0, $executor->install_calls );
		self::assertSame( 0, $plugins->adoption_calls );
	}

	public function test_core_custody_cleanup_failure_is_preserved_through_install_facade(): void {
		$plugins  = new ProspectivePluginRepository();
		$executor = new ProspectiveExecutor();
		$lock     = new ProspectiveUpdaterLock();
		$facade   = $this->facade( $plugins, $executor, 7, $lock );
		$this->set_ready_release();
		self::assertNotNull( $this->acquisition );
		$this->acquisition->handoff_failure = new ReleaseArtifactCleanupFailure();

		$result = $facade->install(
			'plugin',
			$this->repository_request(),
			'42',
			'v1.2.3',
			self::FINGERPRINT,
			'stable',
			'valid-nonce',
			$this->expectation_for( $this->repository_request() )
		);

		self::assertFalse( $result->successful() );
		self::assertSame( 'installation_cleanup_failed', $result->code() );
		self::assertSame( 0, $executor->install_calls );
		self::assertSame( 0, $plugins->adoption_calls );
		self::assertSame( 1, $this->acquisition->handoff_calls );
		self::assertSame( 1, $this->acquisition->discard_calls );
		self::assertSame( 1, $lock->release_calls );
		self::assertFileDoesNotExist( (string) $this->artifact_path );
	}

	public function test_handoff_source_conflict_refuses_before_marker_and_executor_and_cleans_custody(): void {
		$plugins  = new ProspectivePluginRepository();
		$executor = new ProspectiveExecutor();
		$lock     = new ProspectiveUpdaterLock();
		$facade   = $this->facade( $plugins, $executor, updater_lock: $lock );
		$this->set_ready_release();
		self::assertNotNull( $this->acquisition );
		$this->acquisition->on_handoff = function (): void {
			self::assertNotNull( $this->source_guard_database );
			$this->source_guard_database->rows = array(
				(object) array(
					'type'                   => 2,
					'package'                => 'other-theme',
					'source'                 => PackageSource::RELEASE_ASSET->value,
					'provider'               => 'gh',
					'provider_repository_id' => '123456789',
				),
			);
		};
		$result                        = $facade->install( 'plugin', $this->repository_request(), '42', 'v1.2.3', self::FINGERPRINT, 'stable', 'valid-nonce', $this->expectation_for( $this->repository_request() ) );
		self::assertSame( 'release_repository_conflict', $result->code() );
		self::assertSame( 0, $executor->install_calls );
		self::assertSame( 0, $plugins->adoption_calls );
		self::assertSame( 1, $lock->release_calls );
		self::assertFileDoesNotExist( (string) $this->artifact_path );
		self::assertNotNull( $this->native_selection_store );
		self::assertArrayNotHasKey( 'native_mutation', $this->native_selection_store->read() );
	}

	public function test_handoff_target_creation_refuses_before_marker_and_executor_and_cleans_custody(): void {
		$plugins  = new ProspectivePluginRepository();
		$executor = new ProspectiveExecutor();
		$lock     = new ProspectiveUpdaterLock();
		$facade   = $this->facade( $plugins, $executor, updater_lock: $lock );
		$this->set_ready_release();
		self::assertNotNull( $this->acquisition );
		$this->acquisition->on_handoff = static function () use ( $plugins ): void {
			$plugins->installed = true;
		};
		$result                        = $facade->install( 'plugin', $this->repository_request(), '42', 'v1.2.3', self::FINGERPRINT, 'stable', 'valid-nonce', $this->expectation_for( $this->repository_request() ) );
		self::assertSame( 'package_already_exists', $result->code() );
		self::assertSame( 0, $executor->install_calls );
		self::assertSame( 0, $plugins->adoption_calls );
		self::assertSame( 1, $lock->release_calls );
		self::assertFileDoesNotExist( (string) $this->artifact_path );
		self::assertNotNull( $this->native_selection_store );
		self::assertArrayNotHasKey( 'native_mutation', $this->native_selection_store->read() );
	}

	public function test_successful_install_uses_core_executor_and_adopts_release_asset_with_manual_policy(): void {
		$plugins  = new ProspectivePluginRepository();
		$executor = new ProspectiveExecutor();
		$lock     = new ProspectiveUpdaterLock();
		$facade   = $this->facade( $plugins, $executor, 17, $lock );
		$this->set_ready_release();

		$result = $facade->install(
			'plugin',
			$this->repository_request(),
			'42',
			'v1.2.3',
			self::FINGERPRINT,
			'prerelease',
			'valid-nonce',
			$this->expectation_for( $this->repository_request() )
		);

		self::assertTrue( $result->successful() );
		self::assertSame( 'installed', $result->code() );
		self::assertSame(
			array(
				'identifier' => 'example/example.php',
				'version'    => '1.2.3',
			),
			$result->data()
		);
		self::assertSame( 1, $executor->install_calls );
		self::assertSame( 'example', $executor->package_slug );
		self::assertNull( $executor->subdirectory );
		self::assertSame( 1, $plugins->adoption_calls );
		self::assertSame( 17, $plugins->adoption_user_id );
		self::assertInstanceOf( Plugin::class, $plugins->adopted_package );
		self::assertSame( PackageSource::RELEASE_ASSET, $plugins->adopted_package->get_source() );
		self::assertSame( 1, $plugins->adopted_package->get_source_revision() );
		self::assertSame( DeploymentPolicy::MANUAL, $plugins->adopted_package->get_deployment_policy() );
		self::assertSame( 'gh', $plugins->adopted_package->get_provider_code() );
		self::assertSame( 'owner/example', (string) $plugins->adopted_package->get_repository() );
		self::assertSame( '123456789', $plugins->adopted_package->get_provider_repository_id() );
		self::assertSame( 'main', $plugins->adopted_package->get_branch() );
		self::assertSame( 'example', $plugins->adopted_configuration?->package_root() );
		self::assertSame( 'example.php', $plugins->adopted_configuration->metadata_file() );
		self::assertSame( 'prerelease', $plugins->adopted_configuration->channel() );
		self::assertSame( 'plugin', ProspectiveRepositoryProvider::$acquisition_input['package_type'] ?? null );
		if ( ! isset( ProspectiveRepositoryProvider::$acquisition_input['repository'] ) ) {
			self::fail( 'Expected the repository in the recorded release request.' );
		}
		self::assertSame( 'owner/example', ProspectiveRepositoryProvider::$acquisition_input['repository']->locator );
		self::assertSame( '123456789', ProspectiveRepositoryProvider::$acquisition_input['repository']->provider_repository_id );
		self::assertSame( '42', ProspectiveRepositoryProvider::$acquisition_input['release_id'] ?? null );
		self::assertSame( 'v1.2.3', ProspectiveRepositoryProvider::$acquisition_input['tag'] ?? null );
		self::assertSame( self::FINGERPRINT, ProspectiveRepositoryProvider::$acquisition_input['fingerprint'] ?? null );
		self::assertSame( 'prerelease', ProspectiveRepositoryProvider::$acquisition_input['channel'] ?? null );
		self::assertSame( 1, $this->acquisition?->handoff_calls );
		self::assertSame( 0, $this->acquisition->discard_calls );
		self::assertSame( 1, $lock->acquire_calls );
		self::assertSame( 1, $lock->release_calls );
		self::assertFileDoesNotExist( (string) $this->artifact_path );
		self::assertNotNull( $this->native_selection_store );
		self::assertArrayNotHasKey( 'native_mutation', $this->native_selection_store->read() );
	}

	public function test_opaque_release_id_propagates_exactly_through_facade_inspection_and_installation(): void {
		$opaque_id = 'gid://forge/release/{042}:leading-000';
		$plugins   = new ProspectivePluginRepository();
		$executor  = new ProspectiveExecutor();
		$facade    = $this->facade( $plugins, $executor );
		$this->set_ready_release();

		$inspection = $facade->inspect(
			'plugin',
			$this->repository_request(),
			$opaque_id,
			'v1.2.3',
			'stable',
			'valid-nonce'
		);

		self::assertTrue( $inspection->successful() );
		self::assertSame( $opaque_id, $inspection->data()['release_id'] ?? null );
		self::assertSame( $opaque_id, ProspectiveRepositoryProvider::$inspection_input['release_id'] ?? null );

		$installation = $facade->install(
			'plugin',
			$this->repository_request(),
			$opaque_id,
			'v1.2.3',
			(string) ( $inspection->data()['fingerprint'] ?? '' ),
			'stable',
			'valid-nonce',
			$this->expectation_for( $this->repository_request() )
		);

		self::assertTrue( $installation->successful() );
		self::assertSame( 'installed', $installation->code() );
		self::assertSame( $opaque_id, ProspectiveRepositoryProvider::$acquisition_input['release_id'] ?? null );
	}

	public function test_inspect_returns_the_fingerprint_required_for_install_continuity(): void {
		$plugins = new ProspectivePluginRepository();
		$facade  = $this->facade( $plugins, new ProspectiveExecutor() );

		$result = $facade->inspect(
			'plugin',
			$this->repository_request(),
			'42',
			'v1.2.3',
			'stable',
			'valid-nonce'
		);

		self::assertTrue( $result->successful() );
		self::assertSame( 'release_ready', $result->code() );
		self::assertSame(
			array(
				'release_id'           => '42',
				'tag'                  => 'v1.2.3',
				'version'              => '1.2.3',
				'commit'               => str_repeat( 'a', 40 ),
				'details_url'          => 'https://github.com/owner/example/releases/tag/v1.2.3',
				'provider_expectation' => $this->github_selection()->expectation(),
				'package_root'         => 'example',
				'main_file'            => 'example.php',
				'fingerprint'          => self::FINGERPRINT,
				'channel'              => 'stable',
			),
			$result->data()
		);
		self::assertSame( 1, ProspectiveRepositoryProvider::$inspection_calls );
		self::assertSame( 1, ProspectiveRepositoryProvider::$metadata_calls );
		self::assertSame( 'plugin', ProspectiveRepositoryProvider::$inspection_input['package_type'] ?? null );
		if ( ! isset( ProspectiveRepositoryProvider::$inspection_input['repository'] ) ) {
			self::fail( 'Expected the repository in the recorded release request.' );
		}
		self::assertSame( 'owner/example', ProspectiveRepositoryProvider::$inspection_input['repository']->locator );
		self::assertSame( '123456789', ProspectiveRepositoryProvider::$inspection_input['repository']->provider_repository_id );
		self::assertSame( '42', ProspectiveRepositoryProvider::$inspection_input['release_id'] ?? null );
		self::assertSame( 'v1.2.3', ProspectiveRepositoryProvider::$inspection_input['tag'] ?? null );
		self::assertSame( 'stable', ProspectiveRepositoryProvider::$inspection_input['channel'] ?? null );
	}

	public function test_inspect_requires_both_provider_facets_before_repository_resolution(): void {
		$metadata_only  = new class() extends ProspectivePartialReleaseProvider implements RepositoryReleaseMetadata {
			public int $metadata_calls = 0;

			public function expected_update_uri( RepositoryReference $repository ): string {
				unset( $repository );
				++$this->metadata_calls;

				return 'https://example.com/owner/example';
			}

			public function release_details_url( RepositoryReference $repository, string $tag ): string {
				unset( $repository, $tag );
				++$this->metadata_calls;

				return 'https://example.com/owner/example/releases/tag/v1.2.3';
			}
		};
		$inspector_only = new class() extends ProspectivePartialReleaseProvider implements RepositoryReleaseInspector {
			public int $inspection_calls = 0;

			public function inspect_release(
				string $package_type,
				RepositoryReference $repository,
				string $provider_release_id,
				string $tag,
				string $channel
			): RepositoryReleaseInspection {
				unset( $package_type, $repository, $provider_release_id, $tag, $channel );
				++$this->inspection_calls;

				return ProspectiveRepositoryProvider::default_inspection();
			}
		};

		foreach ( array( $metadata_only, $inspector_only ) as $provider ) {
			$facade = $this->facade(
				new ProspectivePluginRepository(),
				new ProspectiveExecutor(),
				provider: $provider
			);
			$result = $facade->inspect(
				'plugin',
				$this->repository_request(),
				'42',
				'v1.2.3',
				'stable',
				'valid-nonce'
			);

			self::assertFalse( $result->successful() );
			self::assertSame( 'unsupported_provider', $result->code() );
			self::assertSame( 0, $provider->resolve_calls );
		}
		self::assertSame( 0, $metadata_only->metadata_calls );
		self::assertSame( 0, $inspector_only->inspection_calls );
	}

	public function test_inspect_maps_only_closed_provider_rejections(): void {
		$cases = array(
			array( 'no_releases', RepositoryReleaseInspectionRejected::no_releases(), array() ),
			array( 'release_invalid', RepositoryReleaseInspectionRejected::invalid_release(), array() ),
			array( 'release_invalid', RepositoryReleaseInspectionRejected::incompatible(), array() ),
			array( 'release_invalid', RepositoryReleaseInspectionRejected::incompatible( 'digest_missing' ), array( 'rejection_reason' => 'digest_missing' ) ),
			array( 'release_invalid', RepositoryReleaseInspectionRejected::incompatible( 'future_reason' ), array() ),
			array( 'release_invalid', RepositoryReleaseInspectionRejected::incompatible( 'digest_missing_remote_suffix' ), array() ),
			array( 'unable_to_check', new RuntimeException( 'provider-secret-message' ), array() ),
		);

		foreach ( $cases as [ $expected_code, $failure, $expected_data ] ) {
			$provider = new ProspectiveRepositoryProvider( inspection: $failure );
			$facade   = $this->facade(
				new ProspectivePluginRepository(),
				new ProspectiveExecutor(),
				provider: $provider
			);
			$result   = $facade->inspect(
				'plugin',
				$this->repository_request(),
				'42',
				'v1.2.3',
				'stable',
				'valid-nonce'
			);

			self::assertFalse( $result->successful() );
			self::assertSame( $expected_code, $result->code() );
			self::assertSame( $expected_data, $result->data() );
		}
		self::assertSame( 0, ProspectiveRepositoryProvider::$metadata_calls );
	}

	public function test_inspect_rejects_provider_identity_drift_before_metadata_projection(): void {
		$cases = array(
			new RepositoryReleaseInspection(
				'43',
				'v1.2.3',
				'1.2.3',
				str_repeat( 'a', 40 ),
				'example',
				'example.php',
				self::FINGERPRINT
			),
			new RepositoryReleaseInspection(
				'42',
				'v1.2.4',
				'1.2.4',
				str_repeat( 'b', 40 ),
				'example',
				'example.php',
				self::FINGERPRINT
			),
		);

		foreach ( $cases as $inspection ) {
			$provider = new ProspectiveRepositoryProvider( inspection: $inspection );
			$facade   = $this->facade(
				new ProspectivePluginRepository(),
				new ProspectiveExecutor(),
				provider: $provider
			);
			$result   = $facade->inspect(
				'plugin',
				$this->repository_request(),
				'42',
				'v1.2.3',
				'stable',
				'valid-nonce'
			);

			self::assertFalse( $result->successful() );
			self::assertSame( 'release_invalid', $result->code() );
		}
		self::assertSame( 0, ProspectiveRepositoryProvider::$metadata_calls );
	}

	public function test_inspection_bounds_the_complete_type_specific_identity_before_readiness(): void {
		foreach ( array( array( 'plugin', 150, str_repeat( 'b', 100 ) . '.php', true ), array( 'plugin', 151, str_repeat( 'b', 100 ) . '.php', false ), array( 'plugin', 100, 'style.css', false ), array( 'theme', 191, 'style.css', true ), array( 'theme', 100, 'example.php', false ) ) as [$type, $root_length, $main_file, $accepted] ) {
			$inspection = new RepositoryReleaseInspection( '42', 'v1.2.3', '1.2.3', str_repeat( 'a', 40 ), str_repeat( 'A', $root_length ), $main_file, self::FINGERPRINT );
			$provider   = new ProspectiveRepositoryProvider( inspection: $inspection );
			$facade     = $this->facade( new ProspectivePluginRepository(), new ProspectiveExecutor(), provider: $provider );
			$result     = $facade->inspect( $type, $this->repository_request(), '42', 'v1.2.3', 'stable', 'valid-nonce' );
			self::assertSame( $accepted, $result->successful(), $type . ':' . strlen( $inspection->package_root . '/' . $main_file ) );
			self::assertSame( $accepted ? 'release_ready' : 'release_invalid', $result->code() );
			self::assertSame( $accepted ? 1 : 0, ProspectiveRepositoryProvider::$metadata_calls );
			ProspectiveRepositoryProvider::$metadata_calls = 0;
		}
		self::assertSame( 0, ProspectiveRepositoryProvider::$acquisition_calls );
	}

	public function test_acquired_type_and_complete_identity_are_checked_without_an_expected_identifier(): void {
		foreach ( array( array( 'plugin', 150, str_repeat( 'b', 100 ) . '.php', true ), array( 'plugin', 151, str_repeat( 'b', 100 ) . '.php', false ), array( 'plugin', 100, 'style.css', false ), array( 'theme', 191, 'style.css', true ), array( 'theme', 100, 'example.php', false ) ) as [$type, $root_length, $main_file, $accepted] ) {
			$plugins  = new ProspectivePluginRepository();
			$executor = new ProspectiveExecutor();
			$lock     = new ProspectiveUpdaterLock();
			$facade   = $this->facade( $plugins, $executor, updater_lock: $lock );
			$this->set_ready_release();
			self::assertNotNull( $this->artifact_path );
			$acquisition                                = new ProspectiveRepositoryReleaseArtifact( $this->artifact_path, '1.2.3', str_repeat( 'a', 40 ), str_repeat( 'A', $root_length ), $main_file );
			ProspectiveRepositoryProvider::$acquisition = $acquisition;
			$result                                     = $facade->install( $type, $this->repository_request(), '42', 'v1.2.3', self::FINGERPRINT, 'stable', 'valid-nonce', $this->expectation_for( $this->repository_request() ) );
			if ( $accepted ) {
				self::assertNotSame( 'package_identity_mismatch', $result->code() );
				self::assertSame( 1, $lock->acquire_calls );
			} else {
				self::assertSame( 'package_identity_mismatch', $result->code() );
				self::assertSame( 0, $lock->acquire_calls );
				self::assertSame( 0, $executor->install_calls );
				self::assertSame( 0, $plugins->adoption_calls );
				self::assertSame( 0, $acquisition->handoff_calls );
				self::assertSame( 1, $acquisition->discard_calls );
			}
		}
	}

	public function test_fingerprint_mismatch_cannot_reach_core(): void {
		$plugins  = new ProspectivePluginRepository();
		$executor = new ProspectiveExecutor();
		$facade   = $this->facade( $plugins, $executor );
		$this->set_ready_release();

		$result = $facade->install(
			'plugin',
			$this->repository_request(),
			'42',
			'v1.2.3',
			'v2:' . str_repeat( 'b', 64 ),
			'stable',
			'valid-nonce',
			$this->expectation_for( $this->repository_request() )
		);

		self::assertFalse( $result->successful() );
		self::assertSame( 'release_invalid', $result->code() );
		self::assertSame( 0, $executor->install_calls );
		self::assertSame( 0, $plugins->adoption_calls );
	}

	public function test_identity_that_appears_after_lock_acquisition_stops_before_handoff(): void {
		$plugins          = new ProspectivePluginRepository();
		$executor         = new ProspectiveExecutor();
		$lock             = new ProspectiveUpdaterLock();
		$lock->on_acquire = static function () use ( $plugins ): void {
			$plugins->installed = true;
		};
		$facade           = $this->facade( $plugins, $executor, 7, $lock );
		$this->set_ready_release();

		$result = $facade->install(
			'plugin',
			$this->repository_request(),
			'42',
			'v1.2.3',
			self::FINGERPRINT,
			'stable',
			'valid-nonce',
			$this->expectation_for( $this->repository_request() )
		);

		self::assertFalse( $result->successful() );
		self::assertSame( 'package_already_exists', $result->code() );
		self::assertSame( 0, $this->acquisition?->handoff_calls );
		self::assertSame( 1, $this->acquisition->discard_calls );
		self::assertSame( 0, $executor->install_calls );
		self::assertSame( 1, $lock->release_calls );
	}

	public function test_wp_pusher_activated_after_lock_acquisition_stops_before_handoff(): void {
		$plugins          = new ProspectivePluginRepository();
		$executor         = new ProspectiveExecutor();
		$lock             = new ProspectiveUpdaterLock();
		$lock->on_acquire = static function (): void {
			$GLOBALS['ran_booster_wp_pusher_active_plugins'] = array( 'wppusher/wppusher.php' );
		};
		$facade           = $this->facade( $plugins, $executor, 7, $lock );
		$this->set_ready_release();

		$result = $facade->install(
			'plugin',
			$this->repository_request(),
			'42',
			'v1.2.3',
			self::FINGERPRINT,
			'stable',
			'valid-nonce',
			$this->expectation_for( $this->repository_request() )
		);

		self::assertFalse( $result->successful() );
		self::assertSame( 'install_failed', $result->code() );
		self::assertSame( 0, $this->acquisition?->handoff_calls );
		self::assertSame( 1, $this->acquisition->discard_calls );
		self::assertSame( 0, $executor->install_calls );
		self::assertSame( 1, $lock->release_calls );
	}

	public function test_conflict_discovered_after_lock_acquisition_returns_cleanup_failure_when_lock_release_fails(): void {
		$plugins              = new ProspectivePluginRepository();
		$executor             = new ProspectiveExecutor();
		$lock                 = new ProspectiveUpdaterLock();
		$lock->release_result = false;
		$database             = new SequencedSourceGuardDatabase(
			array(
				array(),
				array(),
				array(
					(object) array(
						'type'                   => 2,
						'package'                => 'other/other.php',
						'source'                 => PackageSource::RELEASE_ASSET->value,
						'provider'               => 'gh',
						'provider_repository_id' => '123456789',
					),
				),
			)
		);
		$source_guard         = new RepositorySourceGuard( $database, $this->createStub( Database::class ) );
		$this->set_ready_release();
		$facade = $this->facade( $plugins, $executor, 7, $lock, null, $source_guard );

		$result = $facade->install(
			'plugin',
			$this->repository_request(),
			'42',
			'v1.2.3',
			self::FINGERPRINT,
			'stable',
			'valid-nonce',
			$this->expectation_for( $this->repository_request() )
		);

		self::assertFalse( $result->successful() );
		self::assertSame( 'installation_cleanup_failed', $result->code() );
		self::assertSame( array(), $result->data() );
		self::assertSame( 1, $lock->acquire_calls );
		self::assertSame( 1, $lock->release_calls );
		self::assertSame( 3, $database->reads );
		self::assertSame( 1, $this->acquisition?->discard_calls );
	}

	public function test_conflict_discovered_before_lock_acquisition_returns_cleanup_failure_when_discard_fails(): void {
		$plugins      = new ProspectivePluginRepository();
		$executor     = new ProspectiveExecutor();
		$database     = new SequencedSourceGuardDatabase(
			array(
				array(),
				array(
					(object) array(
						'type'                   => 2,
						'package'                => 'other/other.php',
						'source'                 => PackageSource::RELEASE_ASSET->value,
						'provider'               => 'gh',
						'provider_repository_id' => '123456789',
					),
				),
			)
		);
		$lock         = new ProspectiveUpdaterLock();
		$source_guard = new RepositorySourceGuard( $database, $this->createStub( Database::class ) );
		$this->set_ready_release();
		self::assertNotNull( $this->acquisition );
		$this->acquisition->discard_result = false;
		$facade                            = $this->facade( $plugins, $executor, 7, $lock, null, $source_guard );

		$result = $facade->install(
			'plugin',
			$this->repository_request(),
			'42',
			'v1.2.3',
			self::FINGERPRINT,
			'stable',
			'valid-nonce',
			$this->expectation_for( $this->repository_request() )
		);

		self::assertFalse( $result->successful() );
		self::assertSame( 'installation_cleanup_failed', $result->code() );
		self::assertSame( array(), $result->data() );
		self::assertSame( 0, $lock->acquire_calls );
		self::assertSame( 0, $lock->release_calls );
		self::assertSame( 0, $this->acquisition->handoff_calls );
		self::assertSame( 1, $this->acquisition->discard_calls );
		self::assertSame( 2, $database->reads );
		self::assertSame( 0, $executor->install_calls );
	}

	public function test_unavailable_relationship_before_lock_acquisition_stops_without_mutation(): void {
		$plugins      = new ProspectivePluginRepository();
		$executor     = new ProspectiveExecutor();
		$database     = new SequencedSourceGuardDatabase( array( array(), array( (object) array() ) ) );
		$lock         = new ProspectiveUpdaterLock();
		$source_guard = new RepositorySourceGuard( $database, $this->createStub( Database::class ) );
		$this->set_ready_release();
		$facade = $this->facade( $plugins, $executor, 7, $lock, null, $source_guard );

		$result = $facade->install( 'plugin', $this->repository_request(), '42', 'v1.2.3', self::FINGERPRINT, 'stable', 'valid-nonce', $this->expectation_for( $this->repository_request() ) );

		self::assertSame( 'release_unavailable', $result->code() );
		self::assertSame( 0, $lock->acquire_calls );
		self::assertSame( 0, $executor->install_calls );
	}

	public function test_unavailable_relationship_after_lock_acquisition_stops_without_mutation(): void {
		$plugins      = new ProspectivePluginRepository();
		$executor     = new ProspectiveExecutor();
		$database     = new SequencedSourceGuardDatabase( array( array(), array(), array( (object) array() ) ) );
		$lock         = new ProspectiveUpdaterLock();
		$source_guard = new RepositorySourceGuard( $database, $this->createStub( Database::class ) );
		$this->set_ready_release();
		$facade = $this->facade( $plugins, $executor, 7, $lock, null, $source_guard );

		$result = $facade->install( 'plugin', $this->repository_request(), '42', 'v1.2.3', self::FINGERPRINT, 'stable', 'valid-nonce', $this->expectation_for( $this->repository_request() ) );

		self::assertSame( 'release_unavailable', $result->code() );
		self::assertSame( 1, $lock->acquire_calls );
		self::assertSame( 1, $lock->release_calls );
		self::assertSame( 0, $executor->install_calls );
	}

	public function test_unrelated_concurrent_activation_does_not_block_adoption(): void {
		$plugins              = new ProspectivePluginRepository();
		$executor             = new ProspectiveExecutor();
		$executor->on_install = static function (): void {
			$GLOBALS['ran_booster_prospective_options']['active_plugins'] = array( 'unrelated/unrelated.php' );
		};
		$facade               = $this->facade( $plugins, $executor );
		$this->set_ready_release();

		$result = $facade->install(
			'plugin',
			$this->repository_request(),
			'42',
			'v1.2.3',
			self::FINGERPRINT,
			'stable',
			'valid-nonce',
			$this->expectation_for( $this->repository_request() )
		);

		self::assertTrue( $result->successful() );
		self::assertSame( 'installed', $result->code() );
		self::assertSame( 1, $plugins->adoption_calls );
		self::assertFileDoesNotExist( (string) $this->artifact_path );
	}

	public function test_target_activation_change_reports_installed_but_unmanaged_without_adoption(): void {
		$plugins              = new ProspectivePluginRepository();
		$executor             = new ProspectiveExecutor();
		$executor->on_install = static function (): void {
			$GLOBALS['ran_booster_prospective_options']['active_plugins'] = array( 'example/example.php' );
		};
		$facade               = $this->facade( $plugins, $executor );
		$this->set_ready_release();

		$result = $facade->install(
			'plugin',
			$this->repository_request(),
			'42',
			'v1.2.3',
			self::FINGERPRINT,
			'stable',
			'valid-nonce',
			$this->expectation_for( $this->repository_request() )
		);

		self::assertFalse( $result->successful() );
		self::assertSame( 'installed_but_unmanaged', $result->code() );
		self::assertSame( 0, $plugins->adoption_calls );
		self::assertFileDoesNotExist( (string) $this->artifact_path );
	}

	public function test_wrong_installed_version_is_reported_with_its_actual_identity(): void {
		$plugins                    = new ProspectivePluginRepository();
		$plugins->installed_version = '9.9.9';
		$executor                   = new ProspectiveExecutor();
		$facade                     = $this->facade( $plugins, $executor );
		$this->set_ready_release();

		$result = $facade->install(
			'plugin',
			$this->repository_request(),
			'42',
			'v1.2.3',
			self::FINGERPRINT,
			'stable',
			'valid-nonce',
			$this->expectation_for( $this->repository_request() )
		);

		self::assertFalse( $result->successful() );
		self::assertSame( 'installed_but_unmanaged', $result->code() );
		self::assertSame(
			array(
				'identifier' => 'example/example.php',
				'version'    => '9.9.9',
			),
			$result->data()
		);
		self::assertSame( 0, $plugins->adoption_calls );
	}

	public function test_lock_acquisition_exception_reports_updater_cleanup_failure(): void {
		$plugins                = new ProspectivePluginRepository();
		$executor               = new ProspectiveExecutor();
		$lock                   = new ProspectiveUpdaterLock();
		$lock->throw_on_acquire = true;
		$facade                 = $this->facade( $plugins, $executor, 7, $lock );
		$this->set_ready_release();
		self::assertNotNull( $this->acquisition );
		$this->acquisition->discard_result = false;

		$result = $facade->install(
			'plugin',
			$this->repository_request(),
			'42',
			'v1.2.3',
			self::FINGERPRINT,
			'stable',
			'valid-nonce',
			$this->expectation_for( $this->repository_request() )
		);

		self::assertFalse( $result->successful() );
		self::assertSame( 'installation_cleanup_failed', $result->code() );
		self::assertSame( 1, $this->acquisition->discard_calls );
		self::assertSame( 0, $lock->release_calls );
		self::assertFileExists( (string) $this->artifact_path );
	}

	public function test_core_failure_with_exact_package_present_reports_installed_but_unmanaged(): void {
		$plugins          = new ProspectivePluginRepository();
		$executor         = new ProspectiveExecutor();
		$executor->result = CorePackageExecutionResult::failed( CorePackageExecutionFailure::WORDPRESS_FAILED );
		$facade           = $this->facade( $plugins, $executor );
		$this->set_ready_release();

		$result = $facade->install(
			'plugin',
			$this->repository_request(),
			'42',
			'v1.2.3',
			self::FINGERPRINT,
			'stable',
			'valid-nonce',
			$this->expectation_for( $this->repository_request() )
		);

		self::assertFalse( $result->successful() );
		self::assertSame( 'installed_but_unmanaged', $result->code() );
		self::assertSame( 0, $plugins->adoption_calls );
		self::assertNotNull( $this->native_selection_store );
		self::assertArrayHasKey( 'native_mutation', $this->native_selection_store->read() );
	}

	public function test_core_failure_returns_ordinary_failure_only_when_target_is_proven_absent(): void {
		$plugins                  = new ProspectivePluginRepository();
		$executor                 = new ProspectiveExecutor();
		$executor->mark_installed = false;
		$executor->result         = CorePackageExecutionResult::failed( CorePackageExecutionFailure::WORDPRESS_FAILED );
		$facade                   = $this->facade( $plugins, $executor );
		$this->set_ready_release();

		$result = $facade->install(
			'plugin',
			$this->repository_request(),
			'42',
			'v1.2.3',
			self::FINGERPRINT,
			'stable',
			'valid-nonce',
			$this->expectation_for( $this->repository_request() )
		);

		self::assertFalse( $result->successful() );
		self::assertSame( 'wordpress_failed', $result->code() );
		self::assertSame( 0, $plugins->adoption_calls );
	}

	public function test_absent_target_ignores_unrelated_activation_side_effect(): void {
		$plugins                  = new ProspectivePluginRepository();
		$executor                 = new ProspectiveExecutor();
		$executor->mark_installed = false;
		$executor->result         = CorePackageExecutionResult::failed( CorePackageExecutionFailure::WORDPRESS_FAILED );
		$executor->on_install     = static function (): void {
			$GLOBALS['ran_booster_prospective_options']['active_plugins'] = array( 'unrelated/unrelated.php' );
		};
		$facade                   = $this->facade( $plugins, $executor );
		$this->set_ready_release();

		$result = $facade->install(
			'plugin',
			$this->repository_request(),
			'42',
			'v1.2.3',
			self::FINGERPRINT,
			'stable',
			'valid-nonce',
			$this->expectation_for( $this->repository_request() )
		);

		self::assertFalse( $result->successful() );
		self::assertSame( 'wordpress_failed', $result->code() );
		self::assertSame( array(), $result->data() );
		self::assertSame( 0, $plugins->adoption_calls );
	}

	public function test_unreadable_installed_target_reports_bounded_uncertainty(): void {
		$plugins                              = new ProspectivePluginRepository();
		$plugins->installed_package_available = false;
		$executor                             = new ProspectiveExecutor();
		$facade                               = $this->facade( $plugins, $executor );
		$this->set_ready_release();

		$result = $facade->install(
			'plugin',
			$this->repository_request(),
			'42',
			'v1.2.3',
			self::FINGERPRINT,
			'stable',
			'valid-nonce',
			$this->expectation_for( $this->repository_request() )
		);

		self::assertFalse( $result->successful() );
		self::assertSame( 'management_state_uncertain', $result->code() );
		self::assertSame( array( 'identifier' => 'example/example.php' ), $result->data() );
		self::assertSame( 0, $plugins->adoption_calls );
	}

	public function test_release_exception_converts_installed_outcome_to_cleanup_failure(): void {
		$plugins                = new ProspectivePluginRepository();
		$executor               = new ProspectiveExecutor();
		$lock                   = new ProspectiveUpdaterLock();
		$lock->throw_on_release = true;
		$facade                 = $this->facade( $plugins, $executor, 7, $lock );
		$this->set_ready_release();

		$result = $facade->install(
			'plugin',
			$this->repository_request(),
			'42',
			'v1.2.3',
			self::FINGERPRINT,
			'stable',
			'valid-nonce',
			$this->expectation_for( $this->repository_request() )
		);

		self::assertFalse( $result->successful() );
		self::assertSame( 'installation_cleanup_failed', $result->code() );
		self::assertSame(
			array(
				'identifier' => 'example/example.php',
				'version'    => '1.2.3',
			),
			$result->data()
		);
		self::assertSame( 1, $lock->release_calls );
		self::assertFileDoesNotExist( (string) $this->artifact_path );
	}

	public function test_false_lock_release_converts_installed_outcome_to_cleanup_failure(): void {
		$plugins              = new ProspectivePluginRepository();
		$executor             = new ProspectiveExecutor();
		$lock                 = new ProspectiveUpdaterLock();
		$lock->release_result = false;
		$facade               = $this->facade( $plugins, $executor, 7, $lock );
		$this->set_ready_release();

		$result = $facade->install(
			'plugin',
			$this->repository_request(),
			'42',
			'v1.2.3',
			self::FINGERPRINT,
			'stable',
			'valid-nonce',
			$this->expectation_for( $this->repository_request() )
		);

		self::assertFalse( $result->successful() );
		self::assertSame( 'installation_cleanup_failed', $result->code() );
		self::assertSame(
			array(
				'identifier' => 'example/example.php',
				'version'    => '1.2.3',
			),
			$result->data()
		);
		self::assertFileDoesNotExist( (string) $this->artifact_path );
	}

	public function test_persistence_failure_after_install_reports_management_state_uncertain(): void {
		$plugins                  = new ProspectivePluginRepository();
		$plugins->adoption_result = PackageMutationResult::failed(
			PackageStorageOperation::INSERT,
			'ran_booster_storage_write_failed',
			'The release management record could not be saved.',
			true
		);
		$executor                 = new ProspectiveExecutor();
		$facade                   = $this->facade( $plugins, $executor );
		$this->set_ready_release();

		$result = $facade->install(
			'plugin',
			$this->repository_request(),
			'42',
			'v1.2.3',
			self::FINGERPRINT,
			'stable',
			'valid-nonce',
			$this->expectation_for( $this->repository_request() )
		);

		self::assertFalse( $result->successful() );
		self::assertSame( 'installed_but_unmanaged', $result->code() );
		self::assertSame(
			array(
				'identifier' => 'example/example.php',
				'version'    => '1.2.3',
			),
			$result->data()
		);
		self::assertSame( 1, $executor->install_calls );
		self::assertSame( 1, $plugins->adoption_calls );
		self::assertSame( PackageSource::RELEASE_ASSET, $plugins->adopted_package?->get_source() );
		self::assertSame( DeploymentPolicy::MANUAL, $plugins->adopted_package->get_deployment_policy() );
		self::assertFileDoesNotExist( (string) $this->artifact_path );
	}

	public function test_new_request_selection_change_refuses_before_resolution_or_acquisition(): void {
		$selection = $this->github_selection();
		$reviewed  = $selection->expectation();
		self::assertNotNull( $this->native_selection_store );
		$state = $this->native_selection_store->read();
		$next  = $state;
		++$next['selection_generation'];
		$next['declaration']['implementation_id'] = 'native-test-b';
		self::assertTrue( $this->native_selection_store->compare_and_swap( $state, $next ) );
		$this->native_selection = new \RAN\RepositoryProvider\GitHubProviderSelection( $this->native_selection_store, dirname( __DIR__ ) . '/WordPress/fixtures/ran-booster-managed-active', new \RAN\RepositoryProvider\GitHubReplacementDeclaration( $next['declaration'] ) );
		$registry               = new ProviderRegistry( array( new ProspectiveRepositoryProvider() ) );
		$this->native_selection->bind_registry( $registry );
		$this->native_selection->complete_collection();
		$registry->seal();
		$facade = $this->facade( new ProspectivePluginRepository(), new ProspectiveExecutor() );
		$result = $facade->install( 'plugin', $this->repository_request(), '42', 'v1.2.3', self::FINGERPRINT, 'stable', 'valid-nonce', $reviewed );
		self::assertSame( 'provider_selection_changed', $result->code() );
		self::assertSame( 0, ProspectiveRepositoryProvider::$resolve_calls + ProspectiveRepositoryProvider::$acquisition_calls );
	}

	public function test_missing_or_malformed_context_refuses_before_provider_work(): void {
		$facade = $this->facade( new ProspectivePluginRepository(), new ProspectiveExecutor() );
		foreach ( array( null, array(), array( 'provider_code' => 'bb' ) ) as $expectation ) {
			$result = $facade->install( 'plugin', $this->repository_request(), '42', 'v1.2.3', self::FINGERPRINT, 'stable', 'valid-nonce', $expectation );
			self::assertFalse( $result->successful() );
		}
		self::assertSame( 0, ProspectiveRepositoryProvider::$resolve_calls + ProspectiveRepositoryProvider::$acquisition_calls );
	}

	public function test_inspection_selection_drift_never_publishes_ready_evidence(): void {
		$facade                                    = $this->facade( new ProspectivePluginRepository(), new ProspectiveExecutor() );
		ProspectiveRepositoryProvider::$on_inspect = function (): void {
			self::assertNotNull( $this->native_selection_store );
			$state = $this->native_selection_store->read();
			$next  = $state;
			++$next['selection_generation'];
			$this->native_selection_store->compare_and_swap( $state, $next );
		};
		$result                                    = $facade->inspect( 'plugin', $this->repository_request(), '42', 'v1.2.3', 'stable', 'valid-nonce' );
		self::assertSame( 'provider_unavailable', $result->code() );
		self::assertSame( array(), $result->data() );
	}

	public function test_disabled_initial_install_preserves_plugin_and_theme_identity(): void {
		foreach ( array( 'plugin', 'theme' ) as $type ) {
			$plugins  = new ProspectivePluginRepository();
			$executor = new ProspectiveExecutor();
			$themes   = $this->createMock( ThemeRepository::class );
			$theme    = $this->createPartialMock( \RAN\Theme::class, array( 'get_identifier', 'get_version' ) );
			$theme->method( 'get_identifier' )->willReturn( 'example' );
			$theme->method( 'get_version' )->willReturn( '1.2.3' );
			$themes->method( 'is_installed' )->willReturnCallback( static fn (): bool => $executor->install_calls > 0 );
			$themes->method( 'has_management_record' )->willReturn( false );
			$themes->method( 'installed_theme_from_stylesheet' )->willReturn( $theme );
			$themes->expects( 'theme' === $type ? self::once() : self::never() )->method( 'adopt_release' )->willReturnCallback(
				static function ( \RAN\Theme $package, ManagedReleaseConfiguration $configuration, int $user_id ): PackageMutationResult {
					self::assertSame( DeploymentPolicy::DISABLED, $package->get_deployment_policy() );
					self::assertSame( 'example', $package->get_identifier() );
					self::assertSame( 'style.css', $configuration->metadata_file() );
					self::assertSame( 7, $user_id );
					return PackageMutationResult::changed( PackageStorageOperation::INSERT );
				}
			);
			$facade = $this->facade( $plugins, $executor, themes: $themes, updater_lock: $lock = new ProspectiveUpdaterLock() );
			$this->set_ready_release( 'theme' === $type ? 'style.css' : 'example.php' );
			$identifier = 'theme' === $type ? 'example' : 'example/example.php';
			$result     = $facade->install( $type, $this->repository_request(), '42', 'v1.2.3', self::FINGERPRINT, 'stable', 'valid-nonce', $this->expectation_for( $this->repository_request() ), $identifier, DeploymentPolicy::DISABLED );
			self::assertTrue( $result->successful() );
			self::assertSame( 1, $lock->token_reads );
			self::assertSame( 2, $lock->sql_token_reads );
			self::assertSame( $identifier, $result->data()['identifier'] );
			self::assertSame( 1, $executor->install_calls );
			if ( 'plugin' === $type ) {
				self::assertSame( DeploymentPolicy::DISABLED, $plugins->adopted_package?->get_deployment_policy() );
			}
			self::assertFileDoesNotExist( (string) $this->artifact_path );
			self::assertNotNull( $this->native_selection_store );
			self::assertArrayNotHasKey( 'native_mutation', $this->native_selection_store->read() );
		}
	}

	public function test_requested_identity_mismatch_discards_before_lock_or_mutation(): void {
		$plugins  = new ProspectivePluginRepository();
		$executor = new ProspectiveExecutor();
		$lock     = new ProspectiveUpdaterLock();
		$facade   = $this->facade( $plugins, $executor, updater_lock: $lock );
		$this->set_ready_release();
		$result = $facade->install( 'plugin', $this->repository_request(), '42', 'v1.2.3', self::FINGERPRINT, 'stable', 'valid-nonce', $this->expectation_for( $this->repository_request() ), 'other/other.php', DeploymentPolicy::DISABLED );
		self::assertSame( 'package_identity_mismatch', $result->code() );
		self::assertSame( 0, $lock->acquire_calls );
		self::assertSame( 0, $executor->install_calls );
		self::assertSame( 0, $plugins->adoption_calls );
		self::assertSame( 0, $this->acquisition?->handoff_calls );
		self::assertSame( 1, $this->acquisition->discard_calls );
		self::assertNotNull( $this->native_selection_store );
		self::assertArrayNotHasKey( 'native_mutation', $this->native_selection_store->read() );
		$this->set_ready_release();
		self::assertNotNull( $this->acquisition );
		$this->acquisition->discard_result = false;
		$failed_cleanup                    = $facade->install( 'plugin', $this->repository_request(), '42', 'v1.2.3', self::FINGERPRINT, 'stable', 'valid-nonce', $this->expectation_for( $this->repository_request() ), 'other/other.php', DeploymentPolicy::DISABLED );
		self::assertSame( 'installation_cleanup_failed', $failed_cleanup->code() );
		self::assertSame( 0, $executor->install_calls );
	}

	public function test_acquired_identity_mismatch_is_discarded_before_lock_or_handoff(): void {
		$plugins  = new ProspectivePluginRepository();
		$executor = new ProspectiveExecutor();
		$lock     = new ProspectiveUpdaterLock();
		$facade   = $this->facade( $plugins, $executor, updater_lock: $lock );
		foreach ( array( 'Example/example.php', 'different/example.php', str_repeat( 'a', 151 ) . '/' . str_repeat( 'b', 100 ) . '.php' ) as $identity ) {
			$this->set_ready_release();
			$acquisition = $this->acquisition;
			self::assertNotNull( $acquisition );
			$acquisition->identifier_override = $identity;
			$result                           = $facade->install( 'plugin', $this->repository_request(), '42', 'v1.2.3', self::FINGERPRINT, 'stable', 'valid-nonce', $this->expectation_for( $this->repository_request() ) );
			self::assertSame( 'package_identity_mismatch', $result->code() );
			self::assertSame( 0, $lock->acquire_calls );
			self::assertSame( 0, $executor->install_calls );
			self::assertSame( 0, $acquisition->handoff_calls );
			self::assertSame( 1, $acquisition->discard_calls );
		}
	}

	public function test_install_intent_nonce_changes_and_invalid_intent_refuses_before_provider_reads(): void {
		$facade  = $this->facade( new ProspectivePluginRepository(), new ProspectiveExecutor() );
		$context = $this->expectation_for( $this->repository_request() );
		$manual  = $facade->nonce_action( 'install', 'plugin', $context, 'example/example.php' );
		self::assertNotSame( $manual, $facade->nonce_action( 'install', 'plugin', $context, 'example/example.php', DeploymentPolicy::DISABLED ) );
		self::assertNotSame( $manual, $facade->nonce_action( 'install', 'plugin', $context, 'other/other.php' ) );
		foreach ( array( array( null, DeploymentPolicy::DISABLED ), array( '../bad.php', DeploymentPolicy::DISABLED ), array( 'example/example.php', DeploymentPolicy::AUTOMATIC ) ) as [$identifier, $policy] ) {
			$result = $facade->install( 'plugin', $this->repository_request(), '42', 'v1.2.3', self::FINGERPRINT, 'stable', 'valid-nonce', $context, $identifier, $policy );
			self::assertSame( 'forbidden', $result->code() );
		}
		self::assertSame( 0, ProspectiveRepositoryProvider::$resolve_calls );
		self::assertSame( 0, ProspectiveRepositoryProvider::$acquisition_calls );
		$this->expectException( \InvalidArgumentException::class );
		$facade->nonce_action( 'inspect', 'plugin', null, 'example/example.php', DeploymentPolicy::DISABLED );
	}

	public function test_install_intent_bounds_complete_plugin_identifier(): void {
		$facade  = $this->facade( new ProspectivePluginRepository(), new ProspectiveExecutor() );
		$context = $this->expectation_for( $this->repository_request() );
		foreach ( array( 195, 255 ) as $length ) {
			$identity = 195 === $length ? str_repeat( 'A', 100 ) . '/' . str_repeat( 'b', 90 ) . '.php' : str_repeat( 'A', 150 ) . '/' . str_repeat( 'b', 100 ) . '.php';
			self::assertSame( $length, strlen( $identity ) );
			self::assertNotSame( '', $facade->nonce_action( 'install', 'plugin', $context, $identity, DeploymentPolicy::MANUAL ) );
		}
		$this->expectException( \InvalidArgumentException::class );
		$facade->nonce_action( 'install', 'plugin', $context, str_repeat( 'A', 151 ) . '/' . str_repeat( 'b', 100 ) . '.php', DeploymentPolicy::MANUAL );
	}

	public function test_changed_install_intent_nonce_refuses_before_repository_resolution(): void {
		$expected_action = '';
		$facade          = $this->facade(
			new ProspectivePluginRepository(),
			new ProspectiveExecutor(),
			verify_nonce: static function ( string $nonce, string $action ) use ( &$expected_action ): bool {
				return 'valid-nonce' === $nonce && $action === $expected_action;
			}
		);
		$context         = $this->expectation_for( $this->repository_request() );
		$expected_action = $facade->nonce_action( 'install', 'plugin', $context, 'example/example.php', DeploymentPolicy::DISABLED );
		foreach ( array( array( 'other/other.php', DeploymentPolicy::DISABLED ), array( 'example/example.php', DeploymentPolicy::MANUAL ) ) as [$identifier, $policy] ) {
			$result = $facade->install( 'plugin', $this->repository_request(), '42', 'v1.2.3', self::FINGERPRINT, 'stable', 'valid-nonce', $context, $identifier, $policy );
			self::assertSame( 'forbidden', $result->code() );
		}
		self::assertSame( 0, ProspectiveRepositoryProvider::$resolve_calls );
		self::assertSame( 0, ProspectiveRepositoryProvider::$acquisition_calls );
	}

	public function test_install_nonce_binds_exact_review_expectation(): void {
		$facade = $this->facade( new ProspectivePluginRepository(), new ProspectiveExecutor() );
		$first  = $this->github_selection()->expectation();
		self::assertNotNull( $first );
		$second = $first;
		++$second['selection_generation'];
		self::assertNotSame( $facade->nonce_action( 'install', 'plugin', $first ), $facade->nonce_action( 'install', 'plugin', $second ) );
		self::assertNotSame( $facade->nonce_action( 'install', 'plugin', $first ), $facade->nonce_action( 'install', 'plugin', null ) );
	}

	/** @return iterable<string, array{string}> */
	public static function native_mutation_hold_phases(): iterable {
		yield 'started' => array( 'started' );
		yield 'finished' => array( 'finished' );
	}

	#[DataProvider( 'native_mutation_hold_phases' )]
	public function test_non_github_install_refuses_when_github_native_mutation_hold_collides_before_handoff( string $phase ): void {
		$plugins  = new ProspectivePluginRepository();
		$executor = new ProspectiveExecutor();
		$lock     = new ProspectiveUpdaterLock();
		$facade   = $this->facade( $plugins, $executor, updater_lock: $lock, provider: new ProspectiveRepositoryProvider( 'bb' ) );
		$this->seed_github_native_mutation_hold( 'plugin', 'example/example.php', $phase );
		$this->set_ready_release( 'alt.php' );
		self::assertNotNull( $this->acquisition );

		$request = array(
			'provider'      => 'bb',
			'repository'    => 'owner/example',
			'credential_id' => '',
			'branch'        => 'main',
		);
		$result  = $facade->install( 'plugin', $request, '42', 'v1.2.3', self::FINGERPRINT, 'stable', 'valid-nonce', null );

		self::assertFalse( $result->successful(), $phase );
		self::assertSame( 'install_failed', $result->code(), $phase );
		self::assertSame( 0, $this->acquisition->handoff_calls, $phase );
		self::assertSame( 1, $this->acquisition->discard_calls, $phase );
		self::assertSame( 0, $executor->install_calls, $phase );
		self::assertSame( 1, $lock->release_calls, $phase );
		self::assertNotNull( $this->native_selection_store );
		self::assertArrayHasKey( 'native_mutation', $this->native_selection_store->read( true ), $phase );
	}

	public function test_non_github_install_refuses_when_colliding_github_native_mutation_hold_appears_during_handoff(): void {
		$plugins  = new ProspectivePluginRepository();
		$executor = new ProspectiveExecutor();
		$lock     = new ProspectiveUpdaterLock();
		$facade   = $this->facade( $plugins, $executor, updater_lock: $lock, provider: new ProspectiveRepositoryProvider( 'bb' ) );
		$this->set_ready_release( 'alt.php' );
		self::assertNotNull( $this->acquisition );
		$this->acquisition->on_handoff = function (): void {
			$this->seed_github_native_mutation_hold( 'plugin', 'example/example.php', 'started' );
		};

		$request = array(
			'provider'      => 'bb',
			'repository'    => 'owner/example',
			'credential_id' => '',
			'branch'        => 'main',
		);
		$result  = $facade->install( 'plugin', $request, '42', 'v1.2.3', self::FINGERPRINT, 'stable', 'valid-nonce', null );

		self::assertFalse( $result->successful() );
		self::assertSame( 'install_failed', $result->code() );
		self::assertSame( 1, $this->acquisition->handoff_calls );
		self::assertSame( 0, $executor->install_calls );
		self::assertSame( 1, $lock->release_calls );
		self::assertFileDoesNotExist( (string) $this->artifact_path );
		self::assertNotNull( $this->native_selection_store );
		self::assertArrayHasKey( 'native_mutation', $this->native_selection_store->read( true ) );
	}

	public function test_non_github_install_succeeds_for_unrelated_folder_during_github_native_mutation_hold(): void {
		$plugins  = new ProspectivePluginRepository();
		$executor = new ProspectiveExecutor();
		$lock     = new ProspectiveUpdaterLock();
		$facade   = $this->facade( $plugins, $executor, updater_lock: $lock, provider: new ProspectiveRepositoryProvider( 'bb' ) );
		$this->seed_github_native_mutation_hold( 'plugin', 'other/other.php', 'finished' );
		$this->set_ready_release( 'example.php' );

		$request = array(
			'provider'      => 'bb',
			'repository'    => 'owner/example',
			'credential_id' => '',
			'branch'        => 'main',
		);
		$result  = $facade->install( 'plugin', $request, '42', 'v1.2.3', self::FINGERPRINT, 'stable', 'valid-nonce', null );

		self::assertTrue( $result->successful() );
		self::assertSame( 'installed', $result->code() );
		self::assertSame( 1, $executor->install_calls );
		self::assertSame( 1, $lock->release_calls );
		self::assertNotNull( $this->native_selection_store );
		self::assertArrayHasKey( 'native_mutation', $this->native_selection_store->read( true ) );
	}

	public function test_install_refuses_without_reclaiming_or_releasing_held_updater_lock(): void {
		$plugins         = new ProspectivePluginRepository();
		$executor        = new ProspectiveExecutor();
		$lock            = new ProspectiveUpdaterLock();
		$lock->lock_held = true;
		$facade          = $this->facade( $plugins, $executor, updater_lock: $lock );
		$this->set_ready_release();
		self::assertNotNull( $this->acquisition );

		$result = $facade->install(
			'plugin',
			$this->repository_request(),
			'42',
			'v1.2.3',
			self::FINGERPRINT,
			'stable',
			'valid-nonce',
			$this->expectation_for( $this->repository_request() )
		);

		self::assertFalse( $result->successful() );
		self::assertSame( 'install_failed', $result->code() );
		self::assertSame( 2, $lock->acquire_calls );
		self::assertSame( 0, $lock->reclaim_calls );
		self::assertSame( 0, $lock->cache_invalidation_calls );
		self::assertTrue( $lock->lock_held );
		self::assertSame( 0, $lock->release_calls );
		self::assertSame( 0, $this->acquisition->handoff_calls );
		self::assertSame( 1, $this->acquisition->discard_calls );
		self::assertSame( 0, $executor->install_calls );
	}

	public function test_install_reclaims_expired_updater_lock_when_no_github_hold_exists(): void {
		foreach ( array( 'gh', 'bb' ) as $provider ) {
			$events                      = array();
			$plugins                     = new ProspectivePluginRepository();
			$executor                    = new ProspectiveExecutor();
			$lock                        = new ProspectiveUpdaterLock();
			$lock->expired_lock_held     = true;
			$lock->on_reclaim_sql        = static function () use ( &$events ): void {
				$events[] = 'reclaim_sql';
			};
			$lock->on_cache_invalidation = static function () use ( &$events ): void {
				$events[] = 'invalidate_cache';
			};
			$facade                      = $this->facade( $plugins, $executor, updater_lock: $lock, provider: new ProspectiveRepositoryProvider( $provider ) );
			$this->set_ready_release();
			$request = 'gh' === $provider
				? $this->repository_request()
				: array(
					'provider'      => 'bb',
					'repository'    => 'owner/example',
					'credential_id' => '',
					'branch'        => 'main',
				);

			$result = $facade->install(
				'plugin',
				$request,
				'42',
				'v1.2.3',
				self::FINGERPRINT,
				'stable',
				'valid-nonce',
				$this->expectation_for( $request )
			);

			self::assertTrue( $result->successful(), $provider );
			self::assertSame( 'installed', $result->code(), $provider );
			self::assertSame( 1, $lock->reclaim_calls, $provider );
			self::assertSame( 1, $lock->cache_invalidation_calls, $provider );
			self::assertSame( array( 'reclaim_sql', 'invalidate_cache' ), $events, $provider );
			self::assertFalse( $lock->expired_lock_held, $provider );
			self::assertSame( 1, $executor->install_calls, $provider );
			self::assertSame( 1, $lock->release_calls, $provider );
		}
	}

	#[DataProvider( 'native_mutation_hold_phases' )]
	public function test_install_refuses_expired_updater_lock_without_reclaim_when_github_hold_exists( string $phase ): void {
		$plugins                 = new ProspectivePluginRepository();
		$executor                = new ProspectiveExecutor();
		$lock                    = new ProspectiveUpdaterLock();
		$lock->expired_lock_held = true;
		$facade                  = $this->facade( $plugins, $executor, updater_lock: $lock, provider: new ProspectiveRepositoryProvider( 'bb' ) );
		$this->seed_github_native_mutation_hold( 'plugin', 'other/other.php', $phase );
		$this->set_ready_release();
		self::assertNotNull( $this->acquisition );

		$request = array(
			'provider'      => 'bb',
			'repository'    => 'owner/example',
			'credential_id' => '',
			'branch'        => 'main',
		);
		$result  = $facade->install( 'plugin', $request, '42', 'v1.2.3', self::FINGERPRINT, 'stable', 'valid-nonce', null );

		self::assertFalse( $result->successful(), $phase );
		self::assertSame( 'install_failed', $result->code(), $phase );
		self::assertSame( 1, $lock->acquire_calls, $phase );
		self::assertSame( 0, $lock->reclaim_calls, $phase );
		self::assertSame( 0, $lock->cache_invalidation_calls, $phase );
		self::assertTrue( $lock->expired_lock_held, $phase );
		self::assertSame( 0, $lock->release_calls, $phase );
		self::assertSame( 0, $this->acquisition->handoff_calls, $phase );
		self::assertSame( 1, $this->acquisition->discard_calls, $phase );
		self::assertSame( 0, $executor->install_calls, $phase );
	}

	public function test_non_github_install_refuses_when_handoff_replaces_updater_lock_without_creating_hold(): void {
		$plugins                    = new ProspectivePluginRepository();
		$executor                   = new ProspectiveExecutor();
		$lock                       = new ProspectiveUpdaterLock();
		$lock->strict_release_token = true;
		$facade                     = $this->facade( $plugins, $executor, updater_lock: $lock, provider: new ProspectiveRepositoryProvider( 'bb' ) );
		$this->set_ready_release();
		self::assertNotNull( $this->acquisition );
		$this->acquisition->on_handoff = static function () use ( $lock ): void {
			$lock->active_token = '1700009999';
		};

		$request = array(
			'provider'      => 'bb',
			'repository'    => 'owner/example',
			'credential_id' => '',
			'branch'        => 'main',
		);
		$result  = $facade->install( 'plugin', $request, '42', 'v1.2.3', self::FINGERPRINT, 'stable', 'valid-nonce', null );

		self::assertFalse( $result->successful() );
		self::assertSame( 'installation_cleanup_failed', $result->code() );
		self::assertSame( 1, $this->acquisition->handoff_calls );
		self::assertSame( 0, $executor->install_calls );
		self::assertSame( 1, $lock->release_calls );
		self::assertFileDoesNotExist( (string) $this->artifact_path );
		self::assertSame( '1700009999', $lock->current_token() );
		self::assertNotNull( $this->native_selection_store );
		self::assertArrayNotHasKey( 'native_mutation', $this->native_selection_store->read( true ) );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_install_releases_committed_reclaimed_lock_when_selection_cache_invalidation_throws(): void {
		foreach ( array( 'gh', 'bb' ) as $provider ) {
			$this->native_selection       = null;
			$this->native_selection_store = null;
			$this->native_selection_db    = null;
			$plugins                      = new ProspectivePluginRepository();
			$executor                     = new ProspectiveExecutor();
			$lock                         = new ProspectiveUpdaterLock();
			$lock->expired_lock_held      = true;
			$lock->strict_release_token   = true;
			$facade                       = $this->facade( $plugins, $executor, updater_lock: $lock, provider: new ProspectiveRepositoryProvider( $provider ) );
			$this->set_ready_release();
			self::assertNotNull( $this->acquisition );
			$request = 'gh' === $provider
				? $this->repository_request()
				: array(
					'provider'      => 'bb',
					'repository'    => 'owner/example',
					'credential_id' => '',
					'branch'        => 'main',
				);

			$GLOBALS['ran_booster_attempt_on_cache_delete'] = static function ( int|string $key, string $group ): void {
				if ( GitHubSelectionStore::OPTION === $key && 'options' === $group ) {
					throw new RuntimeException( 'Selection cache invalidation failed after COMMIT.' );
				}
			};
			try {
				$result = $facade->install(
					'plugin',
					$request,
					'42',
					'v1.2.3',
					self::FINGERPRINT,
					'stable',
					'valid-nonce',
					$this->expectation_for( $request )
				);
			} finally {
				unset( $GLOBALS['ran_booster_attempt_on_cache_delete'] );
			}

			self::assertFalse( $result->successful(), $provider );
			self::assertSame( 'install_failed', $result->code(), $provider );
			self::assertSame( 1, $lock->reclaim_calls, $provider );
			self::assertSame( 1, $lock->release_calls, $provider );
			self::assertNull( $lock->active_token, $provider );
			self::assertSame( 0, $lock->cache_invalidation_calls, $provider );
			self::assertSame( 0, $executor->install_calls, $provider );
			self::assertSame( 0, $this->acquisition->handoff_calls, $provider );
			self::assertSame( 1, $this->acquisition->discard_calls, $provider );
		}
	}

	public function test_install_reports_uncertain_cleanup_without_blind_release_when_reclaim_commit_is_uncertain(): void {
		foreach ( array( 'fail_commit', 'throw_commit' ) as $mode ) {
			foreach ( array( 'gh', 'bb' ) as $provider ) {
				$this->native_selection       = null;
				$this->native_selection_store = null;
				$this->native_selection_db    = null;
				$plugins                      = new ProspectivePluginRepository();
				$executor                     = new ProspectiveExecutor();
				$lock                         = new ProspectiveUpdaterLock();
				$lock->expired_lock_held      = true;
				$facade                       = $this->facade( $plugins, $executor, updater_lock: $lock, provider: new ProspectiveRepositoryProvider( $provider ) );
				$this->set_ready_release();
				self::assertNotNull( $this->acquisition );
				self::assertNotNull( $this->native_selection_db );
				$this->native_selection_db->selection->fail_commit  = 'fail_commit' === $mode;
				$this->native_selection_db->selection->throw_commit = 'throw_commit' === $mode;

				$request = 'gh' === $provider
					? $this->repository_request()
					: array(
						'provider'      => 'bb',
						'repository'    => 'owner/example',
						'credential_id' => '',
						'branch'        => 'main',
					);
				$result  = $facade->install(
					'plugin',
					$request,
					'42',
					'v1.2.3',
					self::FINGERPRINT,
					'stable',
					'valid-nonce',
					$this->expectation_for( $request )
				);

				self::assertFalse( $result->successful(), $mode . ':' . $provider );
				self::assertSame( 'installation_cleanup_failed', $result->code(), $mode . ':' . $provider );
				self::assertSame( 1, $lock->reclaim_calls, $mode . ':' . $provider );
				self::assertSame( 0, $lock->release_calls, $mode . ':' . $provider );
				self::assertSame( '1700000004', $lock->active_token, $mode . ':' . $provider );
				self::assertSame( 0, $lock->cache_invalidation_calls, $mode . ':' . $provider );
				self::assertSame( 0, $executor->install_calls, $mode . ':' . $provider );
				self::assertSame( 0, $this->acquisition->handoff_calls, $mode . ':' . $provider );
				self::assertSame( 1, $this->acquisition->discard_calls, $mode . ':' . $provider );
			}
		}
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_install_resolves_native_hold_before_releasing_lock_when_begin_cache_invalidation_throws(): void {
		foreach ( array( 'one_shot', 'persistent', 'begin_cache_throws_then_complete_commit_fails' ) as $case ) {
			$this->native_selection       = null;
			$this->native_selection_store = null;
			$this->native_selection_db    = null;
			$plugins                      = new ProspectivePluginRepository();
			$executor                     = new ProspectiveExecutor();
			$lock                         = new ProspectiveUpdaterLock();
			$lock->strict_release_token   = true;
			$facade                       = $this->facade( $plugins, $executor, updater_lock: $lock );
			$this->set_ready_release();
			self::assertNotNull( $this->acquisition );
			self::assertNotNull( $this->native_selection_db );
			self::assertNotNull( $this->native_selection_store );

			$phase_at_release = null;
			$store            = $this->native_selection_store;
			$lock->on_release = function () use ( $store, &$phase_at_release ): void {
				$state            = $store->read( true );
				$phase_at_release = is_array( $state['native_mutation'] ?? null ) ? $state['native_mutation']['phase'] : null;
			};

			$cache_throw_count = 0;
			$db                = $this->native_selection_db;
			$GLOBALS['ran_booster_attempt_on_cache_delete'] = static function ( int|string $key, string $group ) use ( $case, $db, &$cache_throw_count ): void {
				if ( GitHubSelectionStore::OPTION !== $key || 'options' !== $group ) {
					return;
				}
				++$cache_throw_count;
				if ( 1 === $cache_throw_count && 'begin_cache_throws_then_complete_commit_fails' === $case ) {
					$db->selection->fail_commit = true;
				}
				if ( 'persistent' === $case || 1 === $cache_throw_count ) {
					throw new RuntimeException( 'Selection cache invalidation failed after hold COMMIT.' );
				}
			};
			try {
				$request = $this->repository_request();
				$result  = $facade->install(
					'plugin',
					$request,
					'42',
					'v1.2.3',
					self::FINGERPRINT,
					'stable',
					'valid-nonce',
					$this->expectation_for( $request )
				);
			} finally {
				unset( $GLOBALS['ran_booster_attempt_on_cache_delete'] );
				$this->native_selection_db->selection->fail_commit = false;
			}

			self::assertFalse( $result->successful(), $case );
			self::assertSame( 0, $executor->install_calls, $case );
			self::assertSame( 1, $lock->token_reads, $case );
			self::assertSame( 2, $lock->sql_token_reads, $case );
			self::assertFileDoesNotExist( (string) $this->artifact_path, $case );

			if ( 'begin_cache_throws_then_complete_commit_fails' === $case ) {
				self::assertSame( 'installation_cleanup_failed', $result->code(), $case );
				self::assertSame( 0, $lock->release_calls, $case );
				self::assertNull( $phase_at_release, $case );
				self::assertSame( '1700000004', $lock->active_token, $case );
				self::assertSame( 'started', $this->native_selection_store->read( true )['native_mutation']['phase'] ?? null, $case );
			} else {
				self::assertSame( 'persistent' === $case ? 'installation_cleanup_failed' : 'install_failed', $result->code(), $case );
				self::assertSame( 1, $lock->release_calls, $case );
				self::assertSame( 'finished', $phase_at_release, $case );
				self::assertNull( $lock->active_token, $case );
				self::assertSame( 'finished', $this->native_selection_store->read( true )['native_mutation']['phase'] ?? null, $case );
			}
		}
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_install_preserves_updater_lock_when_native_hold_begin_commit_or_rollback_fails(): void {
		foreach ( array( 'fail_commit', 'throw_commit' ) as $commit_mode ) {
			foreach ( array( 'rollback_ok', 'rollback_applied_commit', 'fail_rollback', 'throw_rollback' ) as $rollback_mode ) {
				$this->native_selection       = null;
				$this->native_selection_store = null;
				$this->native_selection_db    = null;
				$plugins                      = new ProspectivePluginRepository();
				$executor                     = new ProspectiveExecutor();
				$lock                         = new ProspectiveUpdaterLock();
				$facade                       = $this->facade( $plugins, $executor, updater_lock: $lock );
				$this->set_ready_release();
				self::assertNotNull( $this->acquisition );
				self::assertNotNull( $this->native_selection_db );
				self::assertNotNull( $this->native_selection );
				self::assertNotNull( $this->native_selection_store );

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
				$selection_db->commit_applied                   = 'rollback_applied_commit' === $rollback_mode;
				$selection_db->fail_rollback                    = 'fail_rollback' === $rollback_mode;
				$selection_db->throw_rollback                   = 'throw_rollback' === $rollback_mode;
				try {
					$request = $this->repository_request();
					$result  = $facade->install(
						'plugin',
						$request,
						'42',
						'v1.2.3',
						self::FINGERPRINT,
						'stable',
						'valid-nonce',
						$this->expectation_for( $request )
					);
				} finally {
					unset( $GLOBALS['ran_booster_attempt_on_cache_delete'] );
					$selection_db->fail_commit    = false;
					$selection_db->throw_commit   = false;
					$selection_db->commit_applied = false;
					$selection_db->fail_rollback  = false;
					$selection_db->throw_rollback = false;
				}

				$case = "$commit_mode/$rollback_mode";
				self::assertFalse( $result->successful(), $case );
				self::assertSame( 'installation_cleanup_failed', $result->code(), $case );
				self::assertSame( 0, $executor->install_calls, $case );
				self::assertSame( 1, $lock->token_reads, $case );
				self::assertSame( 2, $lock->sql_token_reads, $case );
				self::assertSame( 0, $lock->release_calls, $case );
				self::assertSame( '1700000004', $lock->active_token, $case );
				self::assertFileDoesNotExist( (string) $this->artifact_path, $case );
				self::assertTrue( $this->native_selection->has_uncertain_commit(), $case );

				if ( 'fail_rollback' === $rollback_mode || 'throw_rollback' === $rollback_mode ) {
					self::assertTrue( $this->native_selection->has_unclosed_transaction(), $case );
					self::assertNotNull( $selection_db->transaction_rows, $case );
					self::assertSame( 0, $cache_deletes_after_tx, $case );
					self::assertTrue( $selection_db->query( 'ROLLBACK' ), $case );
				} else {
					self::assertFalse( $this->native_selection->has_unclosed_transaction(), $case );
					if ( 'rollback_applied_commit' === $rollback_mode ) {
						self::assertSame( 'started', $this->native_selection_store->read( true )['native_mutation']['phase'] ?? null, $case );
					}
				}
			}
		}
	}

	private function seed_github_native_mutation_hold( string $type, string $identity, string $phase ): void {
		$this->github_selection();
		self::assertNotNull( $this->native_selection_store );
		$state                   = $this->native_selection_store->read( true );
		$next                    = $state;
		$next['native_mutation'] = array(
			'selection_generation' => $state['selection_generation'],
			'owner_tag'            => str_repeat( '0', 16 ) . str_repeat( 'a', 16 ),
			'target_type'          => $type,
			'installed_identity'   => $identity,
			'phase'                => 'started',
		);
		self::assertTrue( $this->native_selection_store->compare_and_swap( $state, $next ) );
		if ( 'finished' === $phase ) {
			$started                              = $this->native_selection_store->read( true );
			$finished                             = $started;
			$finished['native_mutation']['phase'] = 'finished';
			self::assertTrue( $this->native_selection_store->compare_and_swap( $started, $finished ) );
		}
	}

	/** @param array<string,mixed> $repository
	 * @return array<string,mixed>|null
	 */
	private function expectation_for( array $repository ): ?array {
		return 'gh' === ( $repository['provider'] ?? null ) ? $this->github_selection()->expectation() : null;
	}

	private function set_ready_release( string $main_file = 'example.php' ): void {
		$artifact_path = tempnam( sys_get_temp_dir(), 'ran-booster-prospective-' );
		if ( false === $artifact_path ) {
			throw new RuntimeException( 'The test release artifact could not be created.' );
		}
		$this->artifact_path = $artifact_path;
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test-only temporary artifact.
		file_put_contents( $this->artifact_path, 'verified-release-archive' );
		$this->acquisition                          = new ProspectiveRepositoryReleaseArtifact(
			$this->artifact_path,
			'1.2.3',
			str_repeat( 'a', 40 ),
			'example',
			$main_file
		);
		ProspectiveRepositoryProvider::$acquisition = $this->acquisition;
	}

	/** @param RepositoryProvider|iterable<RepositoryProvider>|null $provider */
	private function facade(
		ProspectivePluginRepository $plugins,
		ProspectiveExecutor $executor,
		int $user_id = 7,
		?ProspectiveUpdaterLock $updater_lock = null,
		RepositoryProvider|iterable|null $provider = null,
		?RepositorySourceGuard $source_guard = null,
		?ThemeRepository $themes = null,
		?\Closure $verify_nonce = null
	): NativeProspectiveReleaseFacade {
		$providers         = null === $provider
			? array( new ProspectiveRepositoryProvider() )
			: ( $provider instanceof RepositoryProvider ? array( $provider ) : $provider );
		$registry          = new ProviderRegistry( $providers );
		$resolver          = new PackageRepositoryRequestResolver( $registry );
		$executor->plugins = $plugins;
		$source_guard    ??= $this->source_guard();

		return new NativeProspectiveReleaseFacade(
			$resolver,
			$executor,
			$plugins,
			$themes ?? new ProspectiveThemeRepository(),
			$updater_lock ?? new ProspectiveUpdaterLock(),
			$registry,
			$this->github_selection(),
			static fn ( string $type ): bool => in_array( $type, array( 'plugin', 'theme' ), true ),
			$verify_nonce ?? static fn ( string $nonce, string $action ): bool => 'valid-nonce' === $nonce
					&& str_starts_with( $action, 'ran-booster-prospective-release-' ),
			static fn (): int => $user_id,
			$source_guard
		);
	}

	private function source_guard(): RepositorySourceGuard {
		$this->source_guard_database ??= new ProspectiveSourceGuardDatabase();

		return new RepositorySourceGuard( $this->source_guard_database, $this->createStub( Database::class ) );
	}

	/** @return array<string, string> */
	private function repository_request( string $branch = 'main' ): array {
		return array(
			'provider'      => 'gh',
			'repository'    => 'owner/example',
			'credential_id' => '',
			'branch'        => $branch,
		);
	}
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- This concrete test collaborator stays beside the contract it exercises.
final class ProspectiveRepositoryProvider implements RepositoryProvider, RepositoryReleaseCandidateListing, RepositoryReleaseInspector, RepositoryReleaseAcquirer, RepositoryReleaseMetadata, RepositoryReleaseNativeTargets {
	private const EXPECTED_FINGERPRINT = 'v2:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

	public static ?\Closure $on_inspect                                 = null;
	public static int $resolve_calls                                    = 0;
	public static int $listing_calls                                    = 0;
	public static int $inspection_calls                                 = 0;
	public static int $acquisition_calls                                = 0;
	public static int $metadata_calls                                   = 0;
	public static RepositoryReleaseArtifact|Throwable|null $acquisition = null;

	/** @var array{package_type?: string, repository?: RepositoryReference, release_id?: string, tag?: string, channel?: string} */
	public static array $inspection_input = array();

	/** @var array{package_type?: string, repository?: RepositoryReference, release_id?: string, tag?: string, fingerprint?: string, channel?: string} */
	public static array $acquisition_input = array();

	public function __construct(
		private readonly string $code = 'gh',
		private readonly RepositoryReleaseCandidateList|Throwable|null $candidate_list = null,
		private readonly RepositoryReleaseInspection|Throwable|null $inspection = null,
		private readonly ?Throwable $resolve_failure = null,
		private readonly string $repository_id = '123456789'
	) {
	}

	public function get_metadata(): ProviderMetadata {
		return new ProviderMetadata(
			ProviderCode::parse( $this->code ),
			'Prospective provider',
			'https://example.com/',
			'Owner'
		);
	}

	public function get_provider_diagnostics(): ProviderDiagnostics {
		return new class() implements ProviderDiagnostics {
			public function diagnose( ProviderDiagnosticRequest $request ): array {
				unset( $request );

				return array();
			}
		};
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

		return new class() implements RepositoryReleaseNativeTarget {
			public function matches_check_source( string $runtime_provider, string $repository_identity, string $channel ): bool {
				return false;
			}

			public function register(): bool {
				return true;
			}

			public function status(): RepositoryReleaseNativeTargetStatus {
				return new RepositoryReleaseNativeTargetStatus( true );
			}

			public function refresh(): bool {
				return true;
			}
		};
	}

	public function resolve_repository( RepositoryLookupRequest $request ): RepositoryDescriptor {
		++self::$resolve_calls;
		if ( null !== $this->resolve_failure ) {
			throw $this->resolve_failure;
		}

		return new RepositoryDescriptor(
			ProviderCode::parse( $this->code ),
			$request->locator,
			'example',
			$this->repository_id,
			false,
			'main',
			null
		);
	}

	public function prepare_archive( ArchiveRequest $request ): PreparedArchive {
		unset( $request );

		throw new RuntimeException( 'Branch archive preparation is outside this test.' );
	}

	public function list_release_candidates(
		string $package_type,
		RepositoryReference $repository,
		string $channel
	): RepositoryReleaseCandidateList {
		++self::$listing_calls;
		unset( $package_type, $repository, $channel );
		if ( $this->candidate_list instanceof Throwable ) {
			throw $this->candidate_list;
		}

		return $this->candidate_list ?? new RepositoryReleaseCandidateList( array() );
	}

	public function inspect_release(
		string $package_type,
		RepositoryReference $repository,
		string $provider_release_id,
		string $tag,
		string $channel
	): RepositoryReleaseInspection {
		++self::$inspection_calls;
		self::$on_inspect?->__invoke();
		self::$inspection_input = array(
			'package_type' => $package_type,
			'repository'   => $repository,
			'release_id'   => $provider_release_id,
			'tag'          => $tag,
			'channel'      => $channel,
		);
		if ( $this->inspection instanceof Throwable ) {
			throw $this->inspection;
		}

		return $this->inspection ?? self::default_inspection( $provider_release_id, $tag );
	}

	public function acquire_release(
		string $package_type,
		RepositoryReference $repository,
		string $provider_release_id,
		string $tag,
		string $expected_fingerprint,
		string $channel
	): RepositoryReleaseArtifact {
		++self::$acquisition_calls;
		self::$acquisition_input = array(
			'package_type' => $package_type,
			'repository'   => $repository,
			'release_id'   => $provider_release_id,
			'tag'          => $tag,
			'fingerprint'  => $expected_fingerprint,
			'channel'      => $channel,
		);
		if ( self::$acquisition instanceof Throwable ) {
			throw self::$acquisition;
		}
		if ( self::EXPECTED_FINGERPRINT !== $expected_fingerprint ) {
			throw RepositoryReleaseAcquisitionRejected::invalid_release();
		}
		if ( ! self::$acquisition instanceof RepositoryReleaseArtifact ) {
			throw new RuntimeException( 'Release acquisition fixture is unavailable.' );
		}

		return self::$acquisition;
	}

	public function expected_update_uri( RepositoryReference $repository ): string {
		++self::$metadata_calls;

		return ( 'gh' === $this->code ? 'https://github.com/' : 'https://example.com/' ) . $repository->locator;
	}

	public function release_details_url( RepositoryReference $repository, string $tag ): string {
		++self::$metadata_calls;

		return $this->expected_update_uri_without_tracking( $repository ) . '/releases/tag/' . rawurlencode( $tag );
	}

	public static function default_inspection( string $provider_release_id = '42', string $tag = 'v1.2.3' ): RepositoryReleaseInspection {
		return new RepositoryReleaseInspection(
			$provider_release_id,
			$tag,
			'1.2.3',
			str_repeat( 'a', 40 ),
			'example',
			'example.php',
			'v2:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'
		);
	}

	private function expected_update_uri_without_tracking( RepositoryReference $repository ): string {
		return ( 'gh' === $this->code ? 'https://github.com/' : 'https://example.com/' ) . $repository->locator;
	}
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- This concrete test collaborator stays beside the contract it exercises.
final class ProspectiveListingOnlyProvider implements RepositoryProvider, RepositoryReleaseCandidateListing {
	private ProspectiveRepositoryProvider $provider;

	public function __construct( string $code ) {
		$this->provider = new ProspectiveRepositoryProvider( $code );
	}

	public function get_metadata(): ProviderMetadata {
		return $this->provider->get_metadata();
	}

	public function get_provider_diagnostics(): ProviderDiagnostics {
		return $this->provider->get_provider_diagnostics();
	}

	public function resolve_repository( RepositoryLookupRequest $request ): RepositoryDescriptor {
		return $this->provider->resolve_repository( $request );
	}

	public function prepare_archive( ArchiveRequest $request ): PreparedArchive {
		return $this->provider->prepare_archive( $request );
	}

	public function list_release_candidates(
		string $package_type,
		RepositoryReference $repository,
		string $channel
	): RepositoryReleaseCandidateList {
		return $this->provider->list_release_candidates( $package_type, $repository, $channel );
	}
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- This concrete test collaborator stays beside the contract it exercises.
final class ProspectiveProviderWithoutAcquisition implements RepositoryProvider, RepositoryReleaseCandidateListing, RepositoryReleaseInspector, RepositoryReleaseMetadata {
	private ProspectiveRepositoryProvider $provider;

	public function __construct() {
		$this->provider = new ProspectiveRepositoryProvider();
	}

	public function get_metadata(): ProviderMetadata {
		return $this->provider->get_metadata();
	}

	public function get_provider_diagnostics(): ProviderDiagnostics {
		return $this->provider->get_provider_diagnostics();
	}

	public function resolve_repository( RepositoryLookupRequest $request ): RepositoryDescriptor {
		return $this->provider->resolve_repository( $request );
	}

	public function prepare_archive( ArchiveRequest $request ): PreparedArchive {
		return $this->provider->prepare_archive( $request );
	}

	public function list_release_candidates(
		string $package_type,
		RepositoryReference $repository,
		string $channel
	): RepositoryReleaseCandidateList {
		return $this->provider->list_release_candidates( $package_type, $repository, $channel );
	}

	public function inspect_release(
		string $package_type,
		RepositoryReference $repository,
		string $provider_release_id,
		string $tag,
		string $channel
	): RepositoryReleaseInspection {
		return $this->provider->inspect_release( $package_type, $repository, $provider_release_id, $tag, $channel );
	}

	public function expected_update_uri( RepositoryReference $repository ): string {
		return $this->provider->expected_update_uri( $repository );
	}

	public function release_details_url( RepositoryReference $repository, string $tag ): string {
		return $this->provider->release_details_url( $repository, $tag );
	}
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- This concrete test collaborator stays beside the contract it exercises.
abstract class ProspectivePartialReleaseProvider implements RepositoryProvider {
	public int $resolve_calls = 0;

	public function get_metadata(): ProviderMetadata {
		return new ProviderMetadata(
			ProviderCode::parse( 'gh' ),
			'Partial release provider',
			'https://example.com/',
			'Owner'
		);
	}

	public function get_provider_diagnostics(): ProviderDiagnostics {
		return new class() implements ProviderDiagnostics {
			public function diagnose( ProviderDiagnosticRequest $request ): array {
				unset( $request );

				return array();
			}
		};
	}

	public function resolve_repository( RepositoryLookupRequest $request ): RepositoryDescriptor {
		++$this->resolve_calls;

		return new RepositoryDescriptor(
			ProviderCode::parse( 'gh' ),
			$request->locator,
			'example',
			'123456789',
			false,
			'main',
			null
		);
	}

	public function prepare_archive( ArchiveRequest $request ): PreparedArchive {
		unset( $request );

		throw new RuntimeException( 'Branch archive preparation is outside this test.' );
	}
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- This concrete test collaborator stays beside the contract it exercises.
final class ProspectiveAcquisitionOnlyProvider implements RepositoryProvider, RepositoryReleaseAcquirer {
	private ProspectiveRepositoryProvider $provider;

	public function __construct( string $code ) {
		$this->provider = new ProspectiveRepositoryProvider( $code );
	}

	public function get_metadata(): ProviderMetadata {
		return $this->provider->get_metadata();
	}

	public function get_provider_diagnostics(): ProviderDiagnostics {
		return $this->provider->get_provider_diagnostics();
	}

	public function resolve_repository( RepositoryLookupRequest $request ): RepositoryDescriptor {
		return $this->provider->resolve_repository( $request );
	}

	public function prepare_archive( ArchiveRequest $request ): PreparedArchive {
		return $this->provider->prepare_archive( $request );
	}

	public function acquire_release(
		string $package_type,
		RepositoryReference $repository,
		string $provider_release_id,
		string $tag,
		string $expected_fingerprint,
		string $channel
	): RepositoryReleaseArtifact {
		return $this->provider->acquire_release( $package_type, $repository, $provider_release_id, $tag, $expected_fingerprint, $channel );
	}
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- This concrete test collaborator stays beside the contract it exercises.
final class ProspectiveRepositoryProviderWithoutListing implements RepositoryProvider {

	public int $resolve_calls = 0;

	public function get_metadata(): ProviderMetadata {
		return new ProviderMetadata(
			ProviderCode::parse( 'gh' ),
			'GitHub without release listing',
			'https://github.com/',
			'Owner'
		);
	}

	public function get_provider_diagnostics(): ProviderDiagnostics {
		return new class() implements ProviderDiagnostics {
			public function diagnose( ProviderDiagnosticRequest $request ): array {
				unset( $request );

				return array();
			}
		};
	}

	public function resolve_repository( RepositoryLookupRequest $request ): RepositoryDescriptor {
		++$this->resolve_calls;

		return new RepositoryDescriptor(
			ProviderCode::parse( 'gh' ),
			$request->locator,
			'example',
			'123456789',
			false,
			'main',
			null
		);
	}

	public function prepare_archive( ArchiveRequest $request ): PreparedArchive {
		unset( $request );

		throw new RuntimeException( 'Branch archive preparation is outside this test.' );
	}
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- This concrete test collaborator stays beside the contract it exercises.
final class ProspectiveRepositoryReleaseArtifact implements RepositoryReleaseArtifact {
	public ?string $identifier_override = null;
	public int $handoff_calls           = 0;
	public int $discard_calls           = 0;
	public bool $discard_result         = true;
	public ?Throwable $handoff_failure  = null;
	public ?\Closure $on_handoff        = null;
	private bool $handed_off            = false;
	private bool $discarded             = false;

	public function __construct(
		private string $path,
		private string $release_version,
		private string $commit,
		private string $root,
		private string $metadata_file
	) {
	}

	public function discard(): bool {
		++$this->discard_calls;
		if ( $this->handed_off || $this->discarded ) {
			return true;
		}
		if ( ! $this->discard_result ) {
			return false;
		}
		if ( file_exists( $this->path ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test-only temporary artifact cleanup.
			$this->discarded = unlink( $this->path );
		} else {
			$this->discarded = true;
		}

		return $this->discarded;
	}

	public function handoff_to_core(): PreparedArtifact {
		if ( $this->handed_off || $this->discarded ) {
			throw new RuntimeException( 'The release artifact is unavailable.' );
		}
		++$this->handoff_calls;
		if ( null !== $this->on_handoff ) {
			( $this->on_handoff )();
		}
		if ( null !== $this->handoff_failure ) {
			throw $this->handoff_failure;
		}
		$identity = PreparedArtifact::regular_file_identity( $this->path );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_hash_file -- Test-only immutable artifact evidence.
		$digest = hash_file( 'sha256', $this->path );
		if ( null === $identity || ! is_string( $digest ) ) {
			throw new RuntimeException( 'The release artifact could not be prepared.' );
		}
		$this->handed_off = true;

		return new PreparedArtifact(
			$this->path,
			$this->commit,
			$this->release_version,
			$digest,
			$identity['device'],
			$identity['inode'],
			$identity['size'],
			$identity['permissions'],
			$identity['links']
		);
	}

	public function version(): string {
		return $this->release_version;
	}

	public function provider_commit_id(): string {
		return $this->commit;
	}

	public function package_root(): string {
		return $this->root;
	}

	public function main_file(): string {
		return $this->metadata_file;
	}

	public function identifier( string $package_type ): string {
		if ( null !== $this->identifier_override ) {
			return $this->identifier_override;
		}
		return match ( $package_type ) {
			'plugin' => $this->root . '/' . $this->metadata_file,
			'theme' => $this->root,
			default => throw new RuntimeException( 'The package type is invalid.' ),
		};
	}
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- This concrete test collaborator stays beside the contract it exercises.
final class ProspectiveExecutor extends CorePackageExecutor {

	public int $install_calls          = 0;
	public string $package_slug        = '';
	public ?string $subdirectory       = null;
	public ?PreparedArtifact $artifact = null;
	public CorePackageExecutionResult $result;
	public ?\Closure $on_install                 = null;
	public ?ProspectivePluginRepository $plugins = null;
	public bool $mark_installed                  = true;

	public function __construct() {
		$this->result = CorePackageExecutionResult::succeeded();
	}

	public function install_plugin(
		PreparedArtifact $artifact,
		string $package_slug,
		?string $subdirectory
	): CorePackageExecutionResult {
		++$this->install_calls;
		$this->artifact     = $artifact;
		$this->package_slug = $package_slug;
		$this->subdirectory = $subdirectory;
		if ( $this->mark_installed && null !== $this->plugins ) {
			$this->plugins->installed = true;
		}
		if ( null !== $this->on_install ) {
			( $this->on_install )();
		}

		return $this->result;
	}
	public function install_theme( PreparedArtifact $artifact, string $package_slug, ?string $subdirectory ): CorePackageExecutionResult {
		return $this->install_plugin( $artifact, $package_slug, $subdirectory );
	}
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- This concrete test collaborator stays beside the contract it exercises.
final class ProspectivePluginRepository extends PluginRepository {

	public int $adoption_calls                                 = 0;
	public int $adoption_user_id                               = 0;
	public ?Plugin $adopted_package                            = null;
	public ?ManagedReleaseConfiguration $adopted_configuration = null;
	public PackageMutationResult $adoption_result;
	public bool $installed                   = false;
	public bool $managed                     = false;
	public bool $installed_package_available = true;
	public string $installed_version         = '1.2.3';

	public function __construct() {
		$this->adoption_result = PackageMutationResult::changed( PackageStorageOperation::INSERT );
	}

	public function is_installed( string $identifier ): bool {
		unset( $identifier );

		return $this->installed;
	}

	/** @phpstan-pure */
	public function has_management_record( mixed $identifier ): bool {
		unset( $identifier );

		return $this->managed;
	}

	public function installed_plugin_from_file( string $file ): Plugin {
		if ( ! $this->installed_package_available ) {
			throw new RuntimeException( 'The installed plugin is unavailable.' );
		}

		return new ProspectiveInstalledPlugin( $file, $this->installed_version );
	}

	public function adopt_release(
		Plugin $plugin,
		ManagedReleaseConfiguration $configuration,
		int $user_id
	): PackageMutationResult {
		++$this->adoption_calls;
		$this->adopted_package       = $plugin;
		$this->adopted_configuration = $configuration;
		$this->adoption_user_id      = $user_id;

		return $this->adoption_result;
	}
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- This concrete test collaborator stays beside the contract it exercises.
final class ProspectiveThemeRepository extends ThemeRepository {

	public function __construct() {
	}
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- This concrete test collaborator stays beside the contract it exercises.
final class ProspectiveUpdaterLock extends WordPressUpdaterLock {

	public int $acquire_calls               = 0;
	public int $reclaim_calls               = 0;
	public int $cache_invalidation_calls    = 0;
	public int $release_calls               = 0;
	public int $token_reads                 = 0;
	public int $sql_token_reads             = 0;
	public bool $release_result             = true;
	public bool $lock_held                  = false;
	public bool $expired_lock_held          = false;
	public bool $strict_release_token       = false;
	public ?string $active_token            = null;
	public bool $throw_on_acquire           = false;
	public bool $throw_on_release           = false;
	public ?\Closure $on_acquire            = null;
	public ?\Closure $on_reclaim_sql        = null;
	public ?\Closure $on_cache_invalidation = null;
	public ?\Closure $on_release            = null;

	public function current_token(): ?string {
		++$this->token_reads;
		return $this->read_active_token();
	}

	/**
	 * @param callable(object, string): bool                                                                   $same_connection
	 * @param callable(string, list<int|string>, 'affected'|'var'|'results'): (int|string|list<object>|null) $execute
	 */
	public function current_token_sql( callable $same_connection, callable $execute ): ?string {
		unset( $same_connection, $execute );
		++$this->sql_token_reads;
		return $this->read_active_token();
	}

	private function read_active_token(): ?string {
		if ( null !== $this->active_token ) {
			return $this->active_token;
		}

		return ! $this->lock_held && ! $this->expired_lock_held && $this->acquire_calls > $this->release_calls ? '1700000004' : null;
	}

	public function try_acquire_without_reclaim( string $owner_operation = WordPressUpdaterLock::DEFAULT_OPERATION ): ?string {
		if ( $this->lock_held || $this->expired_lock_held ) {
			++$this->acquire_calls;
			return null;
		}

		return $this->acquire();
	}

	/**
	 * @param callable(object, string): bool                                       $same_connection
	 * @param callable(string, list<string>, 'affected'|'var'): (int|string|null) $execute
	 */
	public function acquire_or_reclaim_expired_sql( callable $same_connection, callable $execute, string $owner_operation = WordPressUpdaterLock::DEFAULT_OPERATION ): ?string {
		unset( $same_connection, $execute );
		++$this->acquire_calls;
		if ( $this->throw_on_acquire ) {
			throw new RuntimeException( 'The test lock could not be acquired.' );
		}
		if ( $this->lock_held ) {
			return null;
		}
		if ( null !== $this->on_reclaim_sql ) {
			( $this->on_reclaim_sql )();
		}
		if ( $this->expired_lock_held ) {
			$this->expired_lock_held = false;
			++$this->reclaim_calls;
		}
		$this->active_token = '1700000004';
		if ( null !== $this->on_acquire ) {
			( $this->on_acquire )();
		}

		return '1700000004';
	}

	public function invalidate_lock_cache(): void {
		++$this->cache_invalidation_calls;
		if ( null !== $this->on_cache_invalidation ) {
			( $this->on_cache_invalidation )();
		}
	}

	public function acquire( string $owner_operation = WordPressUpdaterLock::DEFAULT_OPERATION ): string {
		++$this->acquire_calls;
		if ( $this->throw_on_acquire || $this->lock_held ) {
			throw new RuntimeException( 'The test lock could not be acquired.' );
		}
		if ( $this->expired_lock_held ) {
			$this->expired_lock_held = false;
			++$this->reclaim_calls;
		}
		$this->active_token = '1700000004';
		if ( null !== $this->on_acquire ) {
			( $this->on_acquire )();
		}

		return '1700000004';
	}

	public function release( string $token ): bool {
		++$this->release_calls;
		if ( null !== $this->on_release ) {
			( $this->on_release )( $token );
		}
		if ( $this->throw_on_release ) {
			throw new RuntimeException( 'The test lock could not be released.' );
		}
		if ( $this->strict_release_token && $this->active_token !== $token ) {
			return false;
		}
		if ( $this->active_token === $token ) {
			$this->active_token = null;
		}

		return $this->release_result;
	}
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- This concrete test collaborator stays beside the contract it exercises.
final class SequencedSourceGuardDatabase {
	/** @param list<list<object>> $rows_by_read */
	public function __construct( public array $rows_by_read ) {
	}

	public int $reads             = 0;
	public string $prepared_query = '';

	public function prepare( string $query, mixed ...$arguments ): string {
		unset( $arguments );
		$this->prepared_query = $query;

		return $query;
	}

	/** @return list<object> */
	public function get_results( string $query ): array {
		unset( $query );
		$read = $this->rows_by_read[ $this->reads ] ?? array();
		++$this->reads;

		return $read;
	}
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- This concrete test collaborator stays beside the contract it exercises.
final class ProspectiveSourceGuardDatabase {
	public string $last_error = '';

	/** @var list<object> */
	public array $rows = array();

	public function prepare( string $query, mixed ...$arguments ): string {
		unset( $arguments );

		return $query;
	}

	/** @return list<object> */
	public function get_results( string $query ): array {
		unset( $query );

		return $this->rows;
	}
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- This concrete test collaborator stays beside the contract it exercises.
final class ProspectiveInstalledPlugin extends Plugin {

	public function __construct( string $file = '', string $version = '' ) {
		$this->file    = $file;
		$this->name    = 'Example';
		$this->version = $version;
	}
}
