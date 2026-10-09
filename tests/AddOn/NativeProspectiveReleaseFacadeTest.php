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

	private const FINGERPRINT = 'v2:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

	private ?string $artifact_path                                 = null;
	private ?ProspectiveRepositoryReleaseArtifact $acquisition     = null;
	private ?ProspectiveSourceGuardDatabase $source_guard_database = null;

	public function test_prospective_facade_api_version_tracks_the_opaque_release_id_contract(): void {
		self::assertSame( 8, ProspectiveReleaseFacade::API_VERSION );
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this exact lifecycle override name.
	protected function setUp(): void {
		ProspectiveRepositoryProvider::$resolve_calls     = 0;
		ProspectiveRepositoryProvider::$listing_calls     = 0;
		ProspectiveRepositoryProvider::$inspection_calls  = 0;
		ProspectiveRepositoryProvider::$acquisition_calls = 0;
		ProspectiveRepositoryProvider::$metadata_calls    = 0;
		ProspectiveRepositoryProvider::$inspection_input  = array();
		ProspectiveRepositoryProvider::$acquisition_input = array();
		ProspectiveRepositoryProvider::$acquisition       = null;
		$this->source_guard_database                      = null;

		$GLOBALS['ran_booster_prospective_options']              = array();
		$GLOBALS['ran_booster_package_mutation_guard_multisite'] = false;
		$GLOBALS['ran_booster_package_mutation_guard_file_mods'] = true;
		$GLOBALS['ran_booster_package_mutation_guard_contexts']  = array();
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this exact lifecycle override name.
	protected function tearDown(): void {
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
		$facade->nonce_action( 'discover', 'plugin' );
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

		$result = $facade->install( 'plugin', $this->repository_request(), '42', 'v1.2.3', self::FINGERPRINT, 'stable', 'valid-nonce' );

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

		$result = $facade->install( 'plugin', $this->repository_request(), '42', 'v1.2.3', self::FINGERPRINT, 'stable', 'valid-nonce' );

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
				'valid-nonce'
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
				'valid-nonce'
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
			'valid-nonce'
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
			$facade->nonce_action( 'list_candidates', 'plugin' )
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
				'candidates' => array(
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
				'channel'    => 'prerelease',
			),
			$result->data()
		);
		self::assertSame( 1, ProspectiveRepositoryProvider::$listing_calls );
		self::assertSame( 0, $executor->install_calls );
		self::assertSame( 0, $plugins->adoption_calls );
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
				'valid-nonce'
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
			'valid-nonce'
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
			'valid-nonce'
		);

		self::assertFalse( $result->successful() );
		self::assertSame( 'unable_to_check', $result->code() );
		self::assertSame( array(), $result->data() );
		self::assertSame( 1, ProspectiveRepositoryProvider::$resolve_calls );
		self::assertSame( 1, ProspectiveRepositoryProvider::$acquisition_calls );
		self::assertSame( 0, $executor->install_calls );
		self::assertSame( 0, $plugins->adoption_calls );
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
			'valid-nonce'
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
			'valid-nonce'
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
			'valid-nonce'
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
			'valid-nonce'
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
				'release_id'   => '42',
				'tag'          => 'v1.2.3',
				'version'      => '1.2.3',
				'commit'       => str_repeat( 'a', 40 ),
				'details_url'  => 'https://github.com/owner/example/releases/tag/v1.2.3',
				'package_root' => 'example',
				'main_file'    => 'example.php',
				'fingerprint'  => self::FINGERPRINT,
				'channel'      => 'stable',
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
			array( 'no_releases', RepositoryReleaseInspectionRejected::no_releases() ),
			array( 'release_invalid', RepositoryReleaseInspectionRejected::invalid_release() ),
			array( 'release_invalid', RepositoryReleaseInspectionRejected::incompatible() ),
			array( 'unable_to_check', new RuntimeException( 'provider-secret-message' ) ),
		);

		foreach ( $cases as [ $expected_code, $failure ] ) {
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
			self::assertSame( array(), $result->data() );
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
			'valid-nonce'
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
			'valid-nonce'
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
			'valid-nonce'
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
			'valid-nonce'
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
			'valid-nonce'
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

		$result = $facade->install( 'plugin', $this->repository_request(), '42', 'v1.2.3', self::FINGERPRINT, 'stable', 'valid-nonce' );

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

		$result = $facade->install( 'plugin', $this->repository_request(), '42', 'v1.2.3', self::FINGERPRINT, 'stable', 'valid-nonce' );

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
			'valid-nonce'
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
			'valid-nonce'
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
			'valid-nonce'
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
			'valid-nonce'
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
			'valid-nonce'
		);

		self::assertFalse( $result->successful() );
		self::assertSame( 'installed_but_unmanaged', $result->code() );
		self::assertSame( 0, $plugins->adoption_calls );
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
			'valid-nonce'
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
			'valid-nonce'
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
			'valid-nonce'
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
			'valid-nonce'
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
			'valid-nonce'
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
			'valid-nonce'
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

	private function set_ready_release(): void {
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
			'example.php'
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
		?RepositorySourceGuard $source_guard = null
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
			new ProspectiveThemeRepository(),
			$updater_lock ?? new ProspectiveUpdaterLock(),
			$registry,
			static fn ( string $type ): bool => 'plugin' === $type,
			static fn ( string $nonce, string $action ): bool => 'valid-nonce' === $nonce
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
		private readonly ?Throwable $resolve_failure = null
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
	public int $handoff_calls          = 0;
	public int $discard_calls          = 0;
	public bool $discard_result        = true;
	public ?Throwable $handoff_failure = null;
	private bool $handed_off           = false;
	private bool $discarded            = false;

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

	public int $acquire_calls     = 0;
	public int $release_calls     = 0;
	public bool $release_result   = true;
	public bool $throw_on_acquire = false;
	public bool $throw_on_release = false;
	public ?\Closure $on_acquire  = null;

	public function acquire(): string {
		++$this->acquire_calls;
		if ( $this->throw_on_acquire ) {
			throw new RuntimeException( 'The test lock could not be acquired.' );
		}
		if ( null !== $this->on_acquire ) {
			( $this->on_acquire )();
		}

		return 'test-lock-token';
	}

	public function release( string $token ): bool {
		unset( $token );
		++$this->release_calls;
		if ( $this->throw_on_release ) {
			throw new RuntimeException( 'The test lock could not be released.' );
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
