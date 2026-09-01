<?php

declare(strict_types=1);

namespace Tests\AddOn;

	require_once __DIR__ . '/../Support/WPError.php';
	require_once __DIR__ . '/../Support/RepositoryAdminWordPressFunctions.php';
	require_once __DIR__ . '/../Support/PackageOperationGlobalWordPressFunctions.php';
	require_once __DIR__ . '/../Deployment/PackageMutationGuardWordPressFunctions.php';
	require_once __DIR__ . '/../Logging/LoggingWordPressFunctions.php';
	require_once __DIR__ . '/../Portability/WpPusherCoexistenceWordPressFunctions.php';
	require_once __DIR__ . '/../Support/ProspectiveReleaseFacadeWordPressFunctions.php';
	require_once __DIR__ . '/../Support/ProspectiveReleaseUpdaterFixtures.php';
	require_once __DIR__ . '/../fixtures/ran-booster-release-capability-provider/src/Providers.php';

	use PHPUnit\Framework\TestCase;
	use RAN\AddOn\ReleaseTracking\NativeProspectiveReleaseFacade;
	use RAN\Admin\PackageRepositoryRequestResolver;
	use RAN\Deployment\DeploymentPolicy;
	use RAN\Deployment\PreparedArtifact;
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
	use RAN\WPGitHubReleaseUpdater\V1\WordPress\ProspectiveCandidateFixture;
	use RAN\WPGitHubReleaseUpdater\V1\WordPress\ProspectiveInspectionFixture;
	use RAN\WPGitHubReleaseUpdater\V1\WordPress\ReleaseCandidatePreflight;
	use RANBoosterReleaseCapabilityFixture\PartialProvider as ReleaseFixturePartialProvider;
	use RANBoosterReleaseCapabilityFixture\ReleaseProvider as ReleaseFixtureCompleteProvider;
	use RANBoosterReleaseCapabilityFixture\ZeroProvider as ReleaseFixtureZeroProvider;
	use RuntimeException;
	use Throwable;

	// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- Focused collaborators stay with the facade contract tests.

final class NativeProspectiveReleaseFacadeTest extends TestCase {

	private const FINGERPRINT = 'v1:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

	private ?string $artifactPath                                = null;
	private ?ProspectiveRepositoryReleaseArtifact $acquisition   = null;
	private ?ProspectiveSourceGuardDatabase $sourceGuardDatabase = null;

	protected function setUp(): void {
		ReleaseCandidatePreflight::reset();
		ProspectiveRepositoryProvider::$resolveCalls     = 0;
		ProspectiveRepositoryProvider::$listingCalls     = 0;
		ProspectiveRepositoryProvider::$inspectionCalls  = 0;
		ProspectiveRepositoryProvider::$acquisitionCalls = 0;
		ProspectiveRepositoryProvider::$metadataCalls    = 0;
		ProspectiveRepositoryProvider::$inspectionInput  = array();
		ProspectiveRepositoryProvider::$acquisitionInput = array();
		ProspectiveRepositoryProvider::$acquisition      = null;
		$this->sourceGuardDatabase                       = null;

		$GLOBALS['ran_booster_prospective_options']              = array();
		$GLOBALS['ran_booster_package_mutation_guard_multisite'] = false;
		$GLOBALS['ran_booster_package_mutation_guard_file_mods'] = true;
		$GLOBALS['ran_booster_package_mutation_guard_contexts']  = array();
	}

	protected function tearDown(): void {
		ProspectiveRepositoryProvider::$acquisition = null;
		if ( null !== $this->artifactPath && file_exists( $this->artifactPath ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test-only temporary artifact cleanup.
			unlink( $this->artifactPath );
		}
		$this->artifactPath = null;
		$this->acquisition  = null;
		unset( $GLOBALS['ran_booster_prospective_options'] );
		unset( $GLOBALS['ran_booster_wp_pusher_active_plugins'] );
		ReleaseCandidatePreflight::reset();
	}

	public function testSupportedProviderCodesAreBoundedAndLocal(): void {
		$plugins  = new ProspectivePluginRepository();
		$executor = new ProspectiveExecutor();
		$facade   = $this->facade( $plugins, $executor );

		self::assertSame( array( 'gh' ), $facade->supportedProviderCodes( 'plugin' ) );
		self::assertSame( array( 'gh' ), $facade->supportedProviderCodes( 'theme' ) );
		self::assertSame( array(), $facade->supportedProviderCodes( 'invalid' ) );
		self::assertSame( 0, ReleaseCandidatePreflight::$listCalls );
		self::assertSame( 0, ReleaseCandidatePreflight::$discoverCalls );
		self::assertSame( 0, ReleaseCandidatePreflight::$inspectCalls );
		self::assertSame( 0, ReleaseCandidatePreflight::$acquireCalls );
		self::assertSame( 0, $executor->installCalls );
		self::assertSame( 0, $plugins->adoptionCalls );
	}

	public function testSupportedProviderCodesDeriveFromEveryCompleteProviderInStableOrder(): void {
		$facade = $this->facade(
			new ProspectivePluginRepository(),
			new ProspectiveExecutor(),
			provider: array(
				new ProspectiveRepositoryProvider( 'zeta' ),
				new ProspectiveListingOnlyProvider( 'middle' ),
				new ProspectiveRepositoryProvider( 'alpha' ),
			)
		);

		self::assertSame( array( 'alpha', 'zeta' ), $facade->supportedProviderCodes( 'plugin' ) );
	}

	public function testCompleteProductPlacementRequiresAllFiveReleaseFacetsOnOneProvider(): void {
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

		self::assertSame( array( 'p2-release' ), $facade->supportedProviderCodes( 'plugin' ) );
		self::assertSame( $listing, $registry->requireCapability( 'listing', RepositoryReleaseCandidateListing::class ) );
		self::assertSame( $inspection, $registry->requireCapability( 'gh', RepositoryReleaseInspector::class ) );
		self::assertSame( $inspection, $registry->requireCapability( 'gh', RepositoryReleaseMetadata::class ) );
		self::assertSame( $acquisition, $registry->requireCapability( 'acquisition', RepositoryReleaseAcquirer::class ) );
		self::assertSame( $partial, $registry->requireCapability( 'p2-partial', RepositoryReleaseCandidateListing::class ) );
		self::assertSame( $partial, $registry->requireCapability( 'p2-partial', RepositoryReleaseMetadata::class ) );
		self::assertSame( $partial, $registry->requireCapability( 'p2-partial', RepositoryReleaseNativeTargets::class ) );
		foreach ( array( RepositoryReleaseCandidateListing::class, RepositoryReleaseInspector::class, RepositoryReleaseAcquirer::class, RepositoryReleaseMetadata::class, RepositoryReleaseNativeTargets::class ) as $capability ) {
			self::assertNotInstanceOf( $capability, $zero );
			self::assertSame( $complete, $registry->requireCapability( 'p2-release', $capability ) );
		}
	}

	public function testRetiredDiscoverOperationHasNoNonceScope(): void {
		$facade = $this->facade( new ProspectivePluginRepository(), new ProspectiveExecutor() );

		$this->expectException( \InvalidArgumentException::class );
		$facade->nonceAction( 'discover', 'plugin' );
	}

	public function testExistingReleaseOwnerStopsProspectiveAcquisitionBeforeFilesystemMutation(): void {
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
			sourceGuard: new RepositorySourceGuard( $database, $this->createStub( Database::class ) )
		);

		$result = $facade->install( 'plugin', $this->repositoryRequest(), 42, 'v1.2.3', self::FINGERPRINT, 'stable', 'valid-nonce' );

		self::assertSame( 'release_repository_conflict', $result->code() );
		self::assertSame( 0, ProspectiveRepositoryProvider::$acquisitionCalls );
		self::assertSame( 0, $executor->installCalls );
		self::assertSame( 0, $plugins->adoptionCalls );
	}

	public function testUnavailableRepositoryRelationshipStopsProspectiveAcquisitionBeforeFilesystemMutation(): void {
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
			sourceGuard: new RepositorySourceGuard( $database, $this->createStub( Database::class ) )
		);

		$result = $facade->install( 'plugin', $this->repositoryRequest(), 42, 'v1.2.3', self::FINGERPRINT, 'stable', 'valid-nonce' );

		self::assertSame( 'release_unavailable', $result->code() );
		self::assertSame( 0, ProspectiveRepositoryProvider::$acquisitionCalls );
		self::assertSame( 0, $executor->installCalls );
	}

	public function testUnsupportedProviderFailsBeforeRepositoryResolutionOrPreflight(): void {
		$plugins    = new ProspectivePluginRepository();
		$executor   = new ProspectiveExecutor();
		$facade     = $this->facade( $plugins, $executor );
		$repository = $this->repositoryRequest();

		$repository['provider'] = 'bb';

		$results = array(
			$facade->listCandidates( 'plugin', $repository, 'stable', 'valid-nonce' ),
			$facade->inspect( 'plugin', $repository, 42, 'v1.2.3', 'stable', 'valid-nonce' ),
			$facade->install(
				'plugin',
				$repository,
				42,
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
		self::assertSame( 0, ReleaseCandidatePreflight::$listCalls );
		self::assertSame( 0, ReleaseCandidatePreflight::$discoverCalls );
		self::assertSame( 0, ReleaseCandidatePreflight::$inspectCalls );
		self::assertSame( 0, ReleaseCandidatePreflight::$acquireCalls );
		self::assertSame( array(), ReleaseCandidatePreflight::$target );
		self::assertSame( 0, ProspectiveRepositoryProvider::$resolveCalls );
		self::assertSame( 0, $executor->installCalls );
		self::assertSame( 0, $plugins->adoptionCalls );
	}

	public function testRegisteredProviderWithoutListingFacetFailsBeforeRepositoryResolutionOrRemoteWork(): void {
		$provider = new ProspectiveRepositoryProviderWithoutListing();
		$facade   = $this->facade(
			new ProspectivePluginRepository(),
			new ProspectiveExecutor(),
			provider: $provider
		);

		self::assertSame( array(), $facade->supportedProviderCodes( 'plugin' ) );
		$result = $facade->listCandidates(
			'plugin',
			$this->repositoryRequest(),
			'stable',
			'valid-nonce'
		);

		self::assertFalse( $result->successful() );
		self::assertSame( 'unsupported_provider', $result->code() );
		self::assertSame( array(), $result->data() );
		self::assertSame( 0, $provider->resolveCalls );
		self::assertSame( 0, ReleaseCandidatePreflight::$listCalls );
		self::assertSame( array(), ReleaseCandidatePreflight::$target );
	}

	public function testListingOnlyProviderFailsBeforeRepositoryResolutionOrCandidateListing(): void {
		ReleaseCandidatePreflight::$candidates = array(
			new ProspectiveCandidateFixture(
				42,
				'v1.2.3',
				'1.2.3',
				false,
				'2026-08-17T12:00:00Z',
				array( 'example-1.2.3.zip' )
			),
		);
		$provider                              = new ProspectiveListingOnlyProvider( 'forge' );
		$plugins                               = new ProspectivePluginRepository();
		$executor                              = new ProspectiveExecutor();
		$facade                                = $this->facade( $plugins, $executor, provider: $provider );
		$repository                            = $this->repositoryRequest();
		$repository['provider']                = 'forge';

		self::assertSame( array(), $facade->supportedProviderCodes( 'plugin' ) );

		$listing = $facade->listCandidates( 'plugin', $repository, 'stable', 'valid-nonce' );
		self::assertFalse( $listing->successful() );
		self::assertSame( 'unsupported_provider', $listing->code() );
		self::assertSame( array(), $listing->data() );

		$results = array(
			$facade->inspect( 'plugin', $repository, 42, 'v1.2.3', 'stable', 'valid-nonce' ),
			$facade->install(
				'plugin',
				$repository,
				42,
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
		self::assertSame( 0, ProspectiveRepositoryProvider::$resolveCalls );
		self::assertSame( 0, ProspectiveRepositoryProvider::$listingCalls );
		self::assertSame( 0, ReleaseCandidatePreflight::$listCalls );
		self::assertSame( 0, ReleaseCandidatePreflight::$discoverCalls );
		self::assertSame( 0, ReleaseCandidatePreflight::$inspectCalls );
		self::assertSame( 0, ReleaseCandidatePreflight::$acquireCalls );
		self::assertSame( 0, $executor->installCalls );
		self::assertSame( 0, $plugins->adoptionCalls );
	}

	public function testPartialProviderFailsBeforeRepositoryResolutionOrCandidateListing(): void {
		$provider   = new ProspectiveProviderWithoutAcquisition();
		$facade     = $this->facade(
			new ProspectivePluginRepository(),
			new ProspectiveExecutor(),
			provider: $provider
		);
		$repository = $this->repositoryRequest();

		self::assertSame( array(), $facade->supportedProviderCodes( 'plugin' ) );

		$result = $facade->listCandidates( 'plugin', $repository, 'stable', 'valid-nonce' );

		self::assertFalse( $result->successful() );
		self::assertSame( 'unsupported_provider', $result->code() );
		self::assertSame( array(), $result->data() );
		self::assertSame( 0, ProspectiveRepositoryProvider::$resolveCalls );
		self::assertSame( 0, ProspectiveRepositoryProvider::$listingCalls );
		self::assertSame( 0, ReleaseCandidatePreflight::$listCalls );
	}

	public function testProviderWithoutAcquisitionFailsInstallBeforeMutationOrRepositoryResolution(): void {
		$provider = new ProspectiveProviderWithoutAcquisition();
		$plugins  = new ProspectivePluginRepository();
		$executor = new ProspectiveExecutor();
		$facade   = $this->facade( $plugins, $executor, provider: $provider );
		$GLOBALS['ran_booster_package_mutation_guard_file_mods'] = false;

		self::assertSame( array(), $facade->supportedProviderCodes( 'plugin' ) );
		$result = $facade->install(
			'plugin',
			$this->repositoryRequest(),
			42,
			'v1.2.3',
			self::FINGERPRINT,
			'stable',
			'valid-nonce'
		);

		self::assertFalse( $result->successful() );
		self::assertSame( 'unsupported_provider', $result->code() );
		self::assertSame( 0, ProspectiveRepositoryProvider::$resolveCalls );
		self::assertSame( 0, ProspectiveRepositoryProvider::$acquisitionCalls );
		self::assertSame( array(), $GLOBALS['ran_booster_package_mutation_guard_contexts'] );
		self::assertSame( 0, ReleaseCandidatePreflight::$acquireCalls );
		self::assertSame( 0, $executor->installCalls );
		self::assertSame( 0, $plugins->adoptionCalls );
	}

	public function testLegacyCandidateProjectionRejectsAnOverflowingProviderIdentity(): void {
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

		$result = $facade->listCandidates(
			'plugin',
			$this->repositoryRequest(),
			'stable',
			'valid-nonce'
		);

		self::assertFalse( $result->successful() );
		self::assertSame( 'unable_to_check', $result->code() );
		self::assertSame( array(), $result->data() );
		self::assertSame( 1, ProspectiveRepositoryProvider::$resolveCalls );
		self::assertSame( 0, ReleaseCandidatePreflight::$listCalls );
	}

	public function testOpaqueProviderReleaseIdentitySurvivesDirectInspectionButNotTheNumericFacade(): void {
		$opaqueId   = 'release:opaque/42';
		$provider   = new ProspectiveRepositoryProvider(
			'gh',
			new RepositoryReleaseCandidateList(
				array(
					new RepositoryReleaseCandidate(
						$opaqueId,
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
		$candidate  = $provider->listReleaseCandidates( 'plugin', $reference, 'stable' )->candidates[0];
		$inspection = $provider->inspectRelease( 'plugin', $reference, $candidate->providerReleaseId, $candidate->tag, 'stable' );
		$plugins    = new ProspectivePluginRepository();
		$executor   = new ProspectiveExecutor();
		$facade     = $this->facade( $plugins, $executor, provider: $provider );

		self::assertSame( $opaqueId, $candidate->providerReleaseId );
		self::assertSame( $opaqueId, $inspection->providerReleaseId );

		$result = $facade->listCandidates(
			'plugin',
			$this->repositoryRequest(),
			'stable',
			'valid-nonce'
		);

		self::assertFalse( $result->successful() );
		self::assertSame( 'unable_to_check', $result->code() );
		self::assertSame( array(), $result->data() );
		self::assertSame( 1, ProspectiveRepositoryProvider::$resolveCalls );
		self::assertSame( 1, ProspectiveRepositoryProvider::$inspectionCalls, 'Only the direct Provider API inspection may receive the opaque ID.' );
		self::assertSame( 0, ProspectiveRepositoryProvider::$acquisitionCalls );
		self::assertSame( 0, $executor->installCalls );
		self::assertSame( 0, $plugins->adoptionCalls );
	}

	public function testStableCandidateProjectionRejectsPrereleaseEvidence(): void {
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

		$result = $facade->listCandidates(
			'plugin',
			$this->repositoryRequest(),
			'stable',
			'valid-nonce'
		);

		self::assertFalse( $result->successful() );
		self::assertSame( 'unable_to_check', $result->code() );
		self::assertSame( array(), $result->data() );
		self::assertSame( 1, ProspectiveRepositoryProvider::$resolveCalls );
		self::assertSame( 0, ReleaseCandidatePreflight::$listCalls );
	}

	public function testStableCandidateProjectionAcceptsProviderOwnedHyphenatedVersion(): void {
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

		$result = $facade->listCandidates(
			'plugin',
			$this->repositoryRequest(),
			'stable',
			'valid-nonce'
		);

		self::assertTrue( $result->successful() );
		self::assertSame( 'release_candidates_available', $result->code() );
		self::assertSame( '2026-08', $result->data()['candidates'][0]['version'] ?? null );
		self::assertSame( 1, ProspectiveRepositoryProvider::$resolveCalls );
		self::assertSame( 0, ReleaseCandidatePreflight::$listCalls );
	}

	public function testCandidateListMapsBoundedDisplayDataWithoutInspectingOrInstalling(): void {
		ReleaseCandidatePreflight::$candidates = array(
			new ProspectiveCandidateFixture(
				52,
				'v2.0.0-beta.2',
				'2.0.0-beta.2',
				true,
				'2026-07-28T08:00:00Z',
				array( 'example-2.0.0-beta.2.zip' )
			),
			new ProspectiveCandidateFixture(
				42,
				'v1.2.3',
				'1.2.3',
				false,
				'2026-07-27T08:00:00Z',
				array( 'example-1.2.3.zip' )
			),
		);
		$plugins                               = new ProspectivePluginRepository();
		$executor                              = new ProspectiveExecutor();
		$facade                                = $this->facade( $plugins, $executor );

		self::assertSame( array( 'gh' ), $facade->supportedProviderCodes( 'plugin' ) );
		self::assertSame(
			'ran-booster-prospective-release-list_candidates-plugin',
			$facade->nonceAction( 'list_candidates', 'plugin' )
		);
		$result = $facade->listCandidates(
			'plugin',
			$this->repositoryRequest(),
			'prerelease',
			'valid-nonce'
		);

		self::assertTrue( $result->successful() );
		self::assertSame( 'release_candidates_available', $result->code() );
		self::assertSame(
			array(
				'candidates' => array(
					array(
						'release_id'           => 52,
						'tag'                  => 'v2.0.0-beta.2',
						'version'              => '2.0.0-beta.2',
						'prerelease'           => true,
						'published_at'         => '2026-07-28T08:00:00Z',
						'expected_asset_names' => array( 'example-2.0.0-beta.2.zip' ),
					),
					array(
						'release_id'           => 42,
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
		self::assertSame( 1, ReleaseCandidatePreflight::$listCalls );
		self::assertSame( 1, ProspectiveRepositoryProvider::$listingCalls );
		self::assertSame( 0, ReleaseCandidatePreflight::$inspectCalls );
		self::assertSame( 0, ReleaseCandidatePreflight::$acquireCalls );
		self::assertSame( 0, $executor->installCalls );
		self::assertSame( 0, $plugins->adoptionCalls );
	}

	public function testInternalCandidateReaderClosesResolverAndListingFailures(): void {
		$failures = array(
			'resolver' => new ProspectiveRepositoryProvider( resolveFailure: new RuntimeException( 'resolver-failure' ) ),
			'listing'  => new ProspectiveRepositoryProvider( candidateList: new RuntimeException( 'listing-failure' ) ),
		);

		foreach ( $failures as $name => $provider ) {
			$facade = $this->facade( new ProspectivePluginRepository(), new ProspectiveExecutor(), provider: $provider );
			$result = $facade->listCandidates( 'plugin', $this->repositoryRequest(), 'stable', 'valid-nonce' );

			self::assertFalse( $result->successful(), $name );
			self::assertSame( 'unable_to_check', $result->code(), $name );
			self::assertSame( array(), $result->data(), $name );
		}
	}

	public function testInvalidChannelFailsBeforeAnyProspectiveReleaseWork(): void {
		$facade = $this->facade(
			new ProspectivePluginRepository(),
			new ProspectiveExecutor()
		);

		$results = array(
			$facade->listCandidates(
				'plugin',
				$this->repositoryRequest(),
				'preview',
				'valid-nonce'
			),
			$facade->inspect(
				'plugin',
				$this->repositoryRequest(),
				42,
				'v1.2.3',
				'preview',
				'valid-nonce'
			),
			$facade->install(
				'plugin',
				$this->repositoryRequest(),
				42,
				'v1.2.3',
				self::FINGERPRINT,
				'preview',
				'valid-nonce'
			),
		);

		foreach ( $results as $result ) {
			self::assertFalse( $result->successful() );
			self::assertSame( 'forbidden', $result->code() );
		}
		self::assertSame( 0, ReleaseCandidatePreflight::$listCalls );
		self::assertSame( 0, ReleaseCandidatePreflight::$discoverCalls );
		self::assertSame( 0, ReleaseCandidatePreflight::$inspectCalls );
		self::assertSame( 0, ReleaseCandidatePreflight::$acquireCalls );
		self::assertSame( array(), ReleaseCandidatePreflight::$target );
	}

	public function testFailedExactValidationDoesNotExecuteOrPersistAnything(): void {
		ProspectiveRepositoryProvider::$acquisition = RepositoryReleaseAcquisitionRejected::invalidRelease();
		$plugins                                    = new ProspectivePluginRepository();
		$executor                                   = new ProspectiveExecutor();
		$facade                                     = $this->facade( $plugins, $executor );

		$result = $facade->install(
			'plugin',
			$this->repositoryRequest(),
			42,
			'v1.2.3',
			self::FINGERPRINT,
			'stable',
			'valid-nonce'
		);

		self::assertFalse( $result->successful() );
		self::assertSame( 'release_invalid', $result->code() );
		self::assertSame( 0, ReleaseCandidatePreflight::$inspectCalls );
		self::assertSame( 0, ReleaseCandidatePreflight::$acquireCalls );
		self::assertSame( 1, ProspectiveRepositoryProvider::$acquisitionCalls );
		self::assertSame( 0, $executor->installCalls );
		self::assertSame( 0, $plugins->adoptionCalls );
	}

	public function testOperationalAcquisitionFailureReturnsUnableBeforeInstall(): void {
		ProspectiveRepositoryProvider::$acquisition = new RuntimeException( 'provider-secret-message' );
		$plugins                                    = new ProspectivePluginRepository();
		$executor                                   = new ProspectiveExecutor();
		$facade                                     = $this->facade( $plugins, $executor );

		$result = $facade->install(
			'plugin',
			$this->repositoryRequest(),
			42,
			'v1.2.3',
			self::FINGERPRINT,
			'stable',
			'valid-nonce'
		);

		self::assertFalse( $result->successful() );
		self::assertSame( 'unable_to_check', $result->code() );
		self::assertSame( array(), $result->data() );
		self::assertSame( 1, ProspectiveRepositoryProvider::$resolveCalls );
		self::assertSame( 1, ProspectiveRepositoryProvider::$acquisitionCalls );
		self::assertSame( 0, ReleaseCandidatePreflight::$acquireCalls );
		self::assertSame( 0, $executor->installCalls );
		self::assertSame( 0, $plugins->adoptionCalls );
	}

	public function testProviderAcquisitionCleanupFailureIsPreservedBeforeInstall(): void {
		ProspectiveRepositoryProvider::$acquisition = RepositoryReleaseAcquisitionRejected::cleanupFailed();
		$plugins                                    = new ProspectivePluginRepository();
		$executor                                   = new ProspectiveExecutor();
		$facade                                     = $this->facade( $plugins, $executor );

		$result = $facade->install(
			'plugin',
			$this->repositoryRequest(),
			42,
			'v1.2.3',
			self::FINGERPRINT,
			'stable',
			'valid-nonce'
		);

		self::assertFalse( $result->successful() );
		self::assertSame( 'installation_cleanup_failed', $result->code() );
		self::assertSame( 0, $executor->installCalls );
		self::assertSame( 0, $plugins->adoptionCalls );
	}

	public function testSuccessfulInstallUsesCoreExecutorAndAdoptsReleaseAssetWithManualPolicy(): void {
		$plugins  = new ProspectivePluginRepository();
		$executor = new ProspectiveExecutor();
		$lock     = new ProspectiveUpdaterLock();
		$facade   = $this->facade( $plugins, $executor, 17, $lock );
		$this->setReadyRelease();

		$result = $facade->install(
			'plugin',
			$this->repositoryRequest(),
			42,
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
		self::assertSame( 1, $executor->installCalls );
		self::assertSame( 'example', $executor->packageSlug );
		self::assertNull( $executor->subdirectory );
		self::assertSame( 1, $plugins->adoptionCalls );
		self::assertSame( 17, $plugins->adoptionUserId );
		self::assertInstanceOf( Plugin::class, $plugins->adoptedPackage );
		self::assertSame( PackageSource::RELEASE_ASSET, $plugins->adoptedPackage?->getSource() );
		self::assertSame( 1, $plugins->adoptedPackage?->getSourceRevision() );
		self::assertSame( DeploymentPolicy::MANUAL, $plugins->adoptedPackage?->getDeploymentPolicy() );
		self::assertSame( 'gh', $plugins->adoptedPackage?->getProviderCode() );
		self::assertSame( 'owner/example', (string) $plugins->adoptedPackage?->getRepository() );
		self::assertSame( '123456789', $plugins->adoptedPackage?->getProviderRepositoryId() );
		self::assertSame( 'main', $plugins->adoptedPackage?->getBranch() );
		self::assertSame( 'example', $plugins->adoptedConfiguration?->packageRoot() );
		self::assertSame( 'example.php', $plugins->adoptedConfiguration?->metadataFile() );
		self::assertSame( 'prerelease', $plugins->adoptedConfiguration?->channel() );
		self::assertSame( 'plugin', ProspectiveRepositoryProvider::$acquisitionInput['package_type'] ?? null );
		self::assertSame( 'owner/example', ProspectiveRepositoryProvider::$acquisitionInput['repository']?->locator );
		self::assertSame( '123456789', ProspectiveRepositoryProvider::$acquisitionInput['repository']?->providerRepositoryId );
		self::assertSame( '42', ProspectiveRepositoryProvider::$acquisitionInput['release_id'] ?? null );
		self::assertSame( 'v1.2.3', ProspectiveRepositoryProvider::$acquisitionInput['tag'] ?? null );
		self::assertSame( self::FINGERPRINT, ProspectiveRepositoryProvider::$acquisitionInput['fingerprint'] ?? null );
		self::assertSame( 'prerelease', ProspectiveRepositoryProvider::$acquisitionInput['channel'] ?? null );
		self::assertSame( 0, ReleaseCandidatePreflight::$acquireCalls );
		self::assertSame( 1, $this->acquisition?->handoffCalls );
		self::assertSame( 0, $this->acquisition?->discardCalls );
		self::assertSame( 1, $lock->acquireCalls );
		self::assertSame( 1, $lock->releaseCalls );
		self::assertFileDoesNotExist( (string) $this->artifactPath );
	}

	public function testInspectReturnsTheFingerprintRequiredForInstallContinuity(): void {
		$plugins = new ProspectivePluginRepository();
		$facade  = $this->facade( $plugins, new ProspectiveExecutor() );

		$result = $facade->inspect(
			'plugin',
			$this->repositoryRequest(),
			42,
			'v1.2.3',
			'stable',
			'valid-nonce'
		);

		self::assertTrue( $result->successful() );
		self::assertSame( 'release_ready', $result->code() );
		self::assertSame(
			array(
				'release_id'   => 42,
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
		self::assertSame( 1, ProspectiveRepositoryProvider::$inspectionCalls );
		self::assertSame( 1, ProspectiveRepositoryProvider::$metadataCalls );
		self::assertSame( 'plugin', ProspectiveRepositoryProvider::$inspectionInput['package_type'] ?? null );
		self::assertSame( 'owner/example', ProspectiveRepositoryProvider::$inspectionInput['repository']?->locator );
		self::assertSame( '123456789', ProspectiveRepositoryProvider::$inspectionInput['repository']?->providerRepositoryId );
		self::assertSame( '42', ProspectiveRepositoryProvider::$inspectionInput['release_id'] ?? null );
		self::assertSame( 'v1.2.3', ProspectiveRepositoryProvider::$inspectionInput['tag'] ?? null );
		self::assertSame( 'stable', ProspectiveRepositoryProvider::$inspectionInput['channel'] ?? null );
		self::assertSame( 0, ReleaseCandidatePreflight::$inspectCalls );
	}

	public function testInspectRequiresBothProviderFacetsBeforeRepositoryResolution(): void {
		$metadataOnly  = new class() extends ProspectivePartialReleaseProvider implements RepositoryReleaseMetadata {
			public int $metadataCalls = 0;

			public function expectedUpdateUri( RepositoryReference $repository ): string {
				unset( $repository );
				++$this->metadataCalls;

				return 'https://example.com/owner/example';
			}

			public function releaseDetailsUrl( RepositoryReference $repository, string $tag ): string {
				unset( $repository, $tag );
				++$this->metadataCalls;

				return 'https://example.com/owner/example/releases/tag/v1.2.3';
			}
		};
		$inspectorOnly = new class() extends ProspectivePartialReleaseProvider implements RepositoryReleaseInspector {
			public int $inspectionCalls = 0;

			public function inspectRelease(
				string $packageType,
				RepositoryReference $repository,
				string $providerReleaseId,
				string $tag,
				string $channel
			): RepositoryReleaseInspection {
				unset( $packageType, $repository, $providerReleaseId, $tag, $channel );
				++$this->inspectionCalls;

				return ProspectiveRepositoryProvider::defaultInspection();
			}
		};

		foreach ( array( $metadataOnly, $inspectorOnly ) as $provider ) {
			$facade = $this->facade(
				new ProspectivePluginRepository(),
				new ProspectiveExecutor(),
				provider: $provider
			);
			$result = $facade->inspect(
				'plugin',
				$this->repositoryRequest(),
				42,
				'v1.2.3',
				'stable',
				'valid-nonce'
			);

			self::assertFalse( $result->successful() );
			self::assertSame( 'unsupported_provider', $result->code() );
			self::assertSame( 0, $provider->resolveCalls );
		}
		self::assertSame( 0, $metadataOnly->metadataCalls );
		self::assertSame( 0, $inspectorOnly->inspectionCalls );
		self::assertSame( 0, ReleaseCandidatePreflight::$inspectCalls );
		self::assertSame( array(), ReleaseCandidatePreflight::$target );
	}

	public function testInspectMapsOnlyClosedProviderRejections(): void {
		$cases = array(
			array( 'no_releases', RepositoryReleaseInspectionRejected::noReleases() ),
			array( 'release_invalid', RepositoryReleaseInspectionRejected::invalidRelease() ),
			array( 'release_invalid', RepositoryReleaseInspectionRejected::incompatible() ),
			array( 'unable_to_check', new RuntimeException( 'provider-secret-message' ) ),
		);

		foreach ( $cases as [ $expectedCode, $failure ] ) {
			$provider = new ProspectiveRepositoryProvider( inspection: $failure );
			$facade   = $this->facade(
				new ProspectivePluginRepository(),
				new ProspectiveExecutor(),
				provider: $provider
			);
			$result   = $facade->inspect(
				'plugin',
				$this->repositoryRequest(),
				42,
				'v1.2.3',
				'stable',
				'valid-nonce'
			);

			self::assertFalse( $result->successful() );
			self::assertSame( $expectedCode, $result->code() );
			self::assertSame( array(), $result->data() );
		}
		self::assertSame( 0, ProspectiveRepositoryProvider::$metadataCalls );
		self::assertSame( 0, ReleaseCandidatePreflight::$inspectCalls );
	}

	public function testInspectRejectsProviderIdentityDriftBeforeMetadataProjection(): void {
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
				$this->repositoryRequest(),
				42,
				'v1.2.3',
				'stable',
				'valid-nonce'
			);

			self::assertFalse( $result->successful() );
			self::assertSame( 'release_invalid', $result->code() );
		}
		self::assertSame( 0, ProspectiveRepositoryProvider::$metadataCalls );
		self::assertSame( 0, ReleaseCandidatePreflight::$inspectCalls );
	}

	public function testFingerprintMismatchCannotReachCore(): void {
		$plugins  = new ProspectivePluginRepository();
		$executor = new ProspectiveExecutor();
		$facade   = $this->facade( $plugins, $executor );
		$this->setReadyRelease();

		$result = $facade->install(
			'plugin',
			$this->repositoryRequest(),
			42,
			'v1.2.3',
			'v1:' . str_repeat( 'b', 64 ),
			'stable',
			'valid-nonce'
		);

		self::assertFalse( $result->successful() );
		self::assertSame( 'release_invalid', $result->code() );
		self::assertSame( 0, $executor->installCalls );
		self::assertSame( 0, $plugins->adoptionCalls );
	}

	public function testIdentityThatAppearsAfterLockAcquisitionStopsBeforeHandoff(): void {
		$plugins         = new ProspectivePluginRepository();
		$executor        = new ProspectiveExecutor();
		$lock            = new ProspectiveUpdaterLock();
		$lock->onAcquire = static function () use ( $plugins ): void {
			$plugins->installed = true;
		};
		$facade          = $this->facade( $plugins, $executor, 7, $lock );
		$this->setReadyRelease();

		$result = $facade->install(
			'plugin',
			$this->repositoryRequest(),
			42,
			'v1.2.3',
			self::FINGERPRINT,
			'stable',
			'valid-nonce'
		);

		self::assertFalse( $result->successful() );
		self::assertSame( 'package_already_exists', $result->code() );
		self::assertSame( 0, $this->acquisition?->handoffCalls );
		self::assertSame( 1, $this->acquisition?->discardCalls );
		self::assertSame( 0, $executor->installCalls );
		self::assertSame( 1, $lock->releaseCalls );
	}

	public function testWpPusherActivatedAfterLockAcquisitionStopsBeforeHandoff(): void {
		$plugins         = new ProspectivePluginRepository();
		$executor        = new ProspectiveExecutor();
		$lock            = new ProspectiveUpdaterLock();
		$lock->onAcquire = static function (): void {
			$GLOBALS['ran_booster_wp_pusher_active_plugins'] = array( 'wppusher/wppusher.php' );
		};
		$facade          = $this->facade( $plugins, $executor, 7, $lock );
		$this->setReadyRelease();

		$result = $facade->install(
			'plugin',
			$this->repositoryRequest(),
			42,
			'v1.2.3',
			self::FINGERPRINT,
			'stable',
			'valid-nonce'
		);

		self::assertFalse( $result->successful() );
		self::assertSame( 'install_failed', $result->code() );
		self::assertSame( 0, $this->acquisition?->handoffCalls );
		self::assertSame( 1, $this->acquisition?->discardCalls );
		self::assertSame( 0, $executor->installCalls );
		self::assertSame( 1, $lock->releaseCalls );
	}

	public function testConflictDiscoveredAfterLockAcquisitionReturnsCleanupFailureWhenLockReleaseFails(): void {
		$plugins             = new ProspectivePluginRepository();
		$executor            = new ProspectiveExecutor();
		$lock                = new ProspectiveUpdaterLock();
		$lock->releaseResult = false;
		$database            = new SequencedSourceGuardDatabase(
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
		$sourceGuard         = new RepositorySourceGuard( $database, $this->createStub( Database::class ) );
		$this->setReadyRelease();
		$facade = $this->facade( $plugins, $executor, 7, $lock, null, $sourceGuard );

		$result = $facade->install(
			'plugin',
			$this->repositoryRequest(),
			42,
			'v1.2.3',
			self::FINGERPRINT,
			'stable',
			'valid-nonce'
		);

		self::assertFalse( $result->successful() );
		self::assertSame( 'installation_cleanup_failed', $result->code() );
		self::assertSame( array(), $result->data() );
		self::assertSame( 1, $lock->acquireCalls );
		self::assertSame( 1, $lock->releaseCalls );
		self::assertSame( 3, $database->reads );
		self::assertSame( 1, $this->acquisition?->discardCalls );
	}

	public function testConflictDiscoveredBeforeLockAcquisitionReturnsCleanupFailureWhenDiscardFails(): void {
		$plugins     = new ProspectivePluginRepository();
		$executor    = new ProspectiveExecutor();
		$database    = new SequencedSourceGuardDatabase(
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
		$lock        = new ProspectiveUpdaterLock();
		$sourceGuard = new RepositorySourceGuard( $database, $this->createStub( Database::class ) );
		$this->setReadyRelease();
		self::assertNotNull( $this->acquisition );
		$this->acquisition->discardResult = false;
		$facade                           = $this->facade( $plugins, $executor, 7, $lock, null, $sourceGuard );

		$result = $facade->install(
			'plugin',
			$this->repositoryRequest(),
			42,
			'v1.2.3',
			self::FINGERPRINT,
			'stable',
			'valid-nonce'
		);

		self::assertFalse( $result->successful() );
		self::assertSame( 'installation_cleanup_failed', $result->code() );
		self::assertSame( array(), $result->data() );
		self::assertSame( 0, $lock->acquireCalls );
		self::assertSame( 0, $lock->releaseCalls );
		self::assertSame( 0, $this->acquisition?->handoffCalls );
		self::assertSame( 1, $this->acquisition?->discardCalls );
		self::assertSame( 2, $database->reads );
		self::assertSame( 0, $executor->installCalls );
	}

	public function testUnavailableRelationshipBeforeLockAcquisitionStopsWithoutMutation(): void {
		$plugins     = new ProspectivePluginRepository();
		$executor    = new ProspectiveExecutor();
		$database    = new SequencedSourceGuardDatabase( array( array(), array( (object) array() ) ) );
		$lock        = new ProspectiveUpdaterLock();
		$sourceGuard = new RepositorySourceGuard( $database, $this->createStub( Database::class ) );
		$this->setReadyRelease();
		$facade = $this->facade( $plugins, $executor, 7, $lock, null, $sourceGuard );

		$result = $facade->install( 'plugin', $this->repositoryRequest(), 42, 'v1.2.3', self::FINGERPRINT, 'stable', 'valid-nonce' );

		self::assertSame( 'release_unavailable', $result->code() );
		self::assertSame( 0, $lock->acquireCalls );
		self::assertSame( 0, $executor->installCalls );
	}

	public function testUnavailableRelationshipAfterLockAcquisitionStopsWithoutMutation(): void {
		$plugins     = new ProspectivePluginRepository();
		$executor    = new ProspectiveExecutor();
		$database    = new SequencedSourceGuardDatabase( array( array(), array(), array( (object) array() ) ) );
		$lock        = new ProspectiveUpdaterLock();
		$sourceGuard = new RepositorySourceGuard( $database, $this->createStub( Database::class ) );
		$this->setReadyRelease();
		$facade = $this->facade( $plugins, $executor, 7, $lock, null, $sourceGuard );

		$result = $facade->install( 'plugin', $this->repositoryRequest(), 42, 'v1.2.3', self::FINGERPRINT, 'stable', 'valid-nonce' );

		self::assertSame( 'release_unavailable', $result->code() );
		self::assertSame( 1, $lock->acquireCalls );
		self::assertSame( 1, $lock->releaseCalls );
		self::assertSame( 0, $executor->installCalls );
	}

	public function testUnrelatedConcurrentActivationDoesNotBlockAdoption(): void {
		$plugins             = new ProspectivePluginRepository();
		$executor            = new ProspectiveExecutor();
		$executor->onInstall = static function (): void {
			$GLOBALS['ran_booster_prospective_options']['active_plugins'] = array( 'unrelated/unrelated.php' );
		};
		$facade              = $this->facade( $plugins, $executor );
		$this->setReadyRelease();

		$result = $facade->install(
			'plugin',
			$this->repositoryRequest(),
			42,
			'v1.2.3',
			self::FINGERPRINT,
			'stable',
			'valid-nonce'
		);

		self::assertTrue( $result->successful() );
		self::assertSame( 'installed', $result->code() );
		self::assertSame( 1, $plugins->adoptionCalls );
		self::assertFileDoesNotExist( (string) $this->artifactPath );
	}

	public function testTargetActivationChangeReportsInstalledButUnmanagedWithoutAdoption(): void {
		$plugins             = new ProspectivePluginRepository();
		$executor            = new ProspectiveExecutor();
		$executor->onInstall = static function (): void {
			$GLOBALS['ran_booster_prospective_options']['active_plugins'] = array( 'example/example.php' );
		};
		$facade              = $this->facade( $plugins, $executor );
		$this->setReadyRelease();

		$result = $facade->install(
			'plugin',
			$this->repositoryRequest(),
			42,
			'v1.2.3',
			self::FINGERPRINT,
			'stable',
			'valid-nonce'
		);

		self::assertFalse( $result->successful() );
		self::assertSame( 'installed_but_unmanaged', $result->code() );
		self::assertSame( 0, $plugins->adoptionCalls );
		self::assertFileDoesNotExist( (string) $this->artifactPath );
	}

	public function testWrongInstalledVersionIsReportedWithItsActualIdentity(): void {
		$plugins                   = new ProspectivePluginRepository();
		$plugins->installedVersion = '9.9.9';
		$executor                  = new ProspectiveExecutor();
		$facade                    = $this->facade( $plugins, $executor );
		$this->setReadyRelease();

		$result = $facade->install(
			'plugin',
			$this->repositoryRequest(),
			42,
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
		self::assertSame( 0, $plugins->adoptionCalls );
	}

	public function testLockAcquisitionExceptionReportsUpdaterCleanupFailure(): void {
		$plugins              = new ProspectivePluginRepository();
		$executor             = new ProspectiveExecutor();
		$lock                 = new ProspectiveUpdaterLock();
		$lock->throwOnAcquire = true;
		$facade               = $this->facade( $plugins, $executor, 7, $lock );
		$this->setReadyRelease();
		self::assertNotNull( $this->acquisition );
		$this->acquisition->discardResult = false;

		$result = $facade->install(
			'plugin',
			$this->repositoryRequest(),
			42,
			'v1.2.3',
			self::FINGERPRINT,
			'stable',
			'valid-nonce'
		);

		self::assertFalse( $result->successful() );
		self::assertSame( 'installation_cleanup_failed', $result->code() );
		self::assertSame( 1, $this->acquisition?->discardCalls );
		self::assertSame( 0, $lock->releaseCalls );
		self::assertFileExists( (string) $this->artifactPath );
	}

	public function testCoreFailureWithExactPackagePresentReportsInstalledButUnmanaged(): void {
		$plugins          = new ProspectivePluginRepository();
		$executor         = new ProspectiveExecutor();
		$executor->result = CorePackageExecutionResult::failed( CorePackageExecutionFailure::WORDPRESS_FAILED );
		$facade           = $this->facade( $plugins, $executor );
		$this->setReadyRelease();

		$result = $facade->install(
			'plugin',
			$this->repositoryRequest(),
			42,
			'v1.2.3',
			self::FINGERPRINT,
			'stable',
			'valid-nonce'
		);

		self::assertFalse( $result->successful() );
		self::assertSame( 'installed_but_unmanaged', $result->code() );
		self::assertSame( 0, $plugins->adoptionCalls );
	}

	public function testCoreFailureReturnsOrdinaryFailureOnlyWhenTargetIsProvenAbsent(): void {
		$plugins                 = new ProspectivePluginRepository();
		$executor                = new ProspectiveExecutor();
		$executor->markInstalled = false;
		$executor->result        = CorePackageExecutionResult::failed( CorePackageExecutionFailure::WORDPRESS_FAILED );
		$facade                  = $this->facade( $plugins, $executor );
		$this->setReadyRelease();

		$result = $facade->install(
			'plugin',
			$this->repositoryRequest(),
			42,
			'v1.2.3',
			self::FINGERPRINT,
			'stable',
			'valid-nonce'
		);

		self::assertFalse( $result->successful() );
		self::assertSame( 'wordpress_failed', $result->code() );
		self::assertSame( 0, $plugins->adoptionCalls );
	}

	public function testAbsentTargetIgnoresUnrelatedActivationSideEffect(): void {
		$plugins                 = new ProspectivePluginRepository();
		$executor                = new ProspectiveExecutor();
		$executor->markInstalled = false;
		$executor->result        = CorePackageExecutionResult::failed( CorePackageExecutionFailure::WORDPRESS_FAILED );
		$executor->onInstall     = static function (): void {
			$GLOBALS['ran_booster_prospective_options']['active_plugins'] = array( 'unrelated/unrelated.php' );
		};
		$facade                  = $this->facade( $plugins, $executor );
		$this->setReadyRelease();

		$result = $facade->install(
			'plugin',
			$this->repositoryRequest(),
			42,
			'v1.2.3',
			self::FINGERPRINT,
			'stable',
			'valid-nonce'
		);

		self::assertFalse( $result->successful() );
		self::assertSame( 'wordpress_failed', $result->code() );
		self::assertSame( array(), $result->data() );
		self::assertSame( 0, $plugins->adoptionCalls );
	}

	public function testUnreadableInstalledTargetReportsBoundedUncertainty(): void {
		$plugins                            = new ProspectivePluginRepository();
		$plugins->installedPackageAvailable = false;
		$executor                           = new ProspectiveExecutor();
		$facade                             = $this->facade( $plugins, $executor );
		$this->setReadyRelease();

		$result = $facade->install(
			'plugin',
			$this->repositoryRequest(),
			42,
			'v1.2.3',
			self::FINGERPRINT,
			'stable',
			'valid-nonce'
		);

		self::assertFalse( $result->successful() );
		self::assertSame( 'management_state_uncertain', $result->code() );
		self::assertSame( array( 'identifier' => 'example/example.php' ), $result->data() );
		self::assertSame( 0, $plugins->adoptionCalls );
	}

	public function testReleaseExceptionConvertsInstalledOutcomeToCleanupFailure(): void {
		$plugins              = new ProspectivePluginRepository();
		$executor             = new ProspectiveExecutor();
		$lock                 = new ProspectiveUpdaterLock();
		$lock->throwOnRelease = true;
		$facade               = $this->facade( $plugins, $executor, 7, $lock );
		$this->setReadyRelease();

		$result = $facade->install(
			'plugin',
			$this->repositoryRequest(),
			42,
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
		self::assertSame( 1, $lock->releaseCalls );
		self::assertFileDoesNotExist( (string) $this->artifactPath );
	}

	public function testFalseLockReleaseConvertsInstalledOutcomeToCleanupFailure(): void {
		$plugins             = new ProspectivePluginRepository();
		$executor            = new ProspectiveExecutor();
		$lock                = new ProspectiveUpdaterLock();
		$lock->releaseResult = false;
		$facade              = $this->facade( $plugins, $executor, 7, $lock );
		$this->setReadyRelease();

		$result = $facade->install(
			'plugin',
			$this->repositoryRequest(),
			42,
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
		self::assertFileDoesNotExist( (string) $this->artifactPath );
	}

	public function testPersistenceFailureAfterInstallReportsManagementStateUncertain(): void {
		$plugins                 = new ProspectivePluginRepository();
		$plugins->adoptionResult = PackageMutationResult::failed(
			PackageStorageOperation::INSERT,
			'ran_booster_storage_write_failed',
			'The release management record could not be saved.',
			true
		);
		$executor                = new ProspectiveExecutor();
		$facade                  = $this->facade( $plugins, $executor );
		$this->setReadyRelease();

		$result = $facade->install(
			'plugin',
			$this->repositoryRequest(),
			42,
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
		self::assertSame( 1, $executor->installCalls );
		self::assertSame( 1, $plugins->adoptionCalls );
		self::assertSame( PackageSource::RELEASE_ASSET, $plugins->adoptedPackage?->getSource() );
		self::assertSame( DeploymentPolicy::MANUAL, $plugins->adoptedPackage?->getDeploymentPolicy() );
		self::assertFileDoesNotExist( (string) $this->artifactPath );
	}

	private function setReadyRelease(): void {
		$this->artifactPath = tempnam( sys_get_temp_dir(), 'ran-booster-prospective-' );
		if ( false === $this->artifactPath ) {
			throw new RuntimeException( 'The test release artifact could not be created.' );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test-only temporary artifact.
		file_put_contents( $this->artifactPath, 'verified-release-archive' );
		$this->acquisition                          = new ProspectiveRepositoryReleaseArtifact(
			$this->artifactPath,
			'1.2.3',
			str_repeat( 'a', 40 ),
			'example',
			'example.php'
		);
		ProspectiveRepositoryProvider::$acquisition = $this->acquisition;
	}

	private function facade(
		ProspectivePluginRepository $plugins,
		ProspectiveExecutor $executor,
		int $userId = 7,
		?ProspectiveUpdaterLock $updaterLock = null,
		RepositoryProvider|iterable|null $provider = null,
		?RepositorySourceGuard $sourceGuard = null
	): NativeProspectiveReleaseFacade {
		$providers         = null === $provider
			? array( new ProspectiveRepositoryProvider() )
			: ( $provider instanceof RepositoryProvider ? array( $provider ) : $provider );
		$registry          = new ProviderRegistry( $providers );
		$resolver          = new PackageRepositoryRequestResolver( $registry );
		$executor->plugins = $plugins;
		$sourceGuard     ??= $this->sourceGuard();

		return new NativeProspectiveReleaseFacade(
			$resolver,
			$executor,
			$plugins,
			new ProspectiveThemeRepository(),
			$updaterLock ?? new ProspectiveUpdaterLock(),
			$registry,
			static fn ( string $type ): bool => 'plugin' === $type,
			static fn ( string $nonce, string $action ): bool => 'valid-nonce' === $nonce
					&& str_starts_with( $action, 'ran-booster-prospective-release-' ),
			static fn (): int => $userId,
			$sourceGuard
		);
	}

	private function sourceGuard(): RepositorySourceGuard {
		$this->sourceGuardDatabase ??= new ProspectiveSourceGuardDatabase();

		return new RepositorySourceGuard( $this->sourceGuardDatabase, $this->createStub( Database::class ) );
	}

	/** @return array<string, string> */
	private function repositoryRequest( string $branch = 'main' ): array {
		return array(
			'provider'      => 'gh',
			'repository'    => 'owner/example',
			'credential_id' => '',
			'branch'        => $branch,
		);
	}
}

final class ProspectiveRepositoryProvider implements RepositoryProvider, RepositoryReleaseCandidateListing, RepositoryReleaseInspector, RepositoryReleaseAcquirer, RepositoryReleaseMetadata, RepositoryReleaseNativeTargets {
	private const EXPECTED_FINGERPRINT = 'v1:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

	public static int $resolveCalls                                     = 0;
	public static int $listingCalls                                     = 0;
	public static int $inspectionCalls                                  = 0;
	public static int $acquisitionCalls                                 = 0;
	public static int $metadataCalls                                    = 0;
	public static RepositoryReleaseArtifact|Throwable|null $acquisition = null;

	/** @var array{package_type?: string, repository?: RepositoryReference, release_id?: string, tag?: string, channel?: string} */
	public static array $inspectionInput = array();

	/** @var array{package_type?: string, repository?: RepositoryReference, release_id?: string, tag?: string, fingerprint?: string, channel?: string} */
	public static array $acquisitionInput = array();

	public function __construct(
		private readonly string $code = 'gh',
		private readonly RepositoryReleaseCandidateList|Throwable|null $candidateList = null,
		private readonly RepositoryReleaseInspection|Throwable|null $inspection = null,
		private readonly ?Throwable $resolveFailure = null
	) {
	}

	public function getMetadata(): ProviderMetadata {
		return new ProviderMetadata(
			ProviderCode::parse( $this->code ),
			'Prospective provider',
			'https://example.com/',
			'Owner'
		);
	}

	public function getProviderDiagnostics(): ProviderDiagnostics {
		return new class() implements ProviderDiagnostics {
			public function diagnose( ProviderDiagnosticRequest $request ): array {
				unset( $request );

				return array();
			}
		};
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

	public function resolveRepository( RepositoryLookupRequest $request ): RepositoryDescriptor {
		++self::$resolveCalls;
		if ( null !== $this->resolveFailure ) {
			throw $this->resolveFailure;
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

	public function prepareArchive( ArchiveRequest $request ): PreparedArchive {
		unset( $request );

		throw new RuntimeException( 'Branch archive preparation is outside this test.' );
	}

	public function listReleaseCandidates(
		string $packageType,
		RepositoryReference $repository,
		string $channel
	): RepositoryReleaseCandidateList {
		++self::$listingCalls;
		if ( $this->candidateList instanceof Throwable ) {
			throw $this->candidateList;
		}
		if ( null !== $this->candidateList ) {
			return $this->candidateList;
		}

		$preflight = ReleaseCandidatePreflight::fromProspectiveTarget(
			array(
				'repository'           => $repository->locator,
				'providerRepositoryId' => $repository->providerRepositoryId,
				'channel'              => $channel,
				'accessToken'          => null,
				'packageType'          => $packageType,
			)
		);
		$releases  = $preflight->listCandidates();
		if ( $releases instanceof \WP_Error ) {
			return new RepositoryReleaseCandidateList( array() );
		}
		if ( ! is_array( $releases ) ) {
			throw new RuntimeException( 'Release candidate fixtures are unavailable.' );
		}

		$candidates = array();
		foreach ( $releases as $release ) {
			$candidates[] = new RepositoryReleaseCandidate(
				(string) $release->releaseId(),
				$release->tag(),
				$release->version(),
				$release->isPrerelease(),
				$release->publishedAt(),
				$release->expectedAssetNames()
			);
		}

		return new RepositoryReleaseCandidateList( $candidates );
	}

	public function inspectRelease(
		string $packageType,
		RepositoryReference $repository,
		string $providerReleaseId,
		string $tag,
		string $channel
	): RepositoryReleaseInspection {
		++self::$inspectionCalls;
		self::$inspectionInput = array(
			'package_type' => $packageType,
			'repository'   => $repository,
			'release_id'   => $providerReleaseId,
			'tag'          => $tag,
			'channel'      => $channel,
		);
		if ( $this->inspection instanceof Throwable ) {
			throw $this->inspection;
		}

		return $this->inspection ?? self::defaultInspection( $providerReleaseId, $tag );
	}

	public function acquireRelease(
		string $packageType,
		RepositoryReference $repository,
		string $providerReleaseId,
		string $tag,
		string $expectedFingerprint,
		string $channel
	): RepositoryReleaseArtifact {
		++self::$acquisitionCalls;
		self::$acquisitionInput = array(
			'package_type' => $packageType,
			'repository'   => $repository,
			'release_id'   => $providerReleaseId,
			'tag'          => $tag,
			'fingerprint'  => $expectedFingerprint,
			'channel'      => $channel,
		);
		if ( self::$acquisition instanceof Throwable ) {
			throw self::$acquisition;
		}
		if ( self::EXPECTED_FINGERPRINT !== $expectedFingerprint ) {
			throw RepositoryReleaseAcquisitionRejected::invalidRelease();
		}
		if ( ! self::$acquisition instanceof RepositoryReleaseArtifact ) {
			throw new RuntimeException( 'Release acquisition fixture is unavailable.' );
		}

		return self::$acquisition;
	}

	public function expectedUpdateUri( RepositoryReference $repository ): string {
		++self::$metadataCalls;

		return ( 'gh' === $this->code ? 'https://github.com/' : 'https://example.com/' ) . $repository->locator;
	}

	public function releaseDetailsUrl( RepositoryReference $repository, string $tag ): string {
		++self::$metadataCalls;

		return $this->expectedUpdateUriWithoutTracking( $repository ) . '/releases/tag/' . rawurlencode( $tag );
	}

	public static function defaultInspection( string $providerReleaseId = '42', string $tag = 'v1.2.3' ): RepositoryReleaseInspection {
		return new RepositoryReleaseInspection(
			$providerReleaseId,
			$tag,
			'1.2.3',
			str_repeat( 'a', 40 ),
			'example',
			'example.php',
			'v1:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'
		);
	}

	private function expectedUpdateUriWithoutTracking( RepositoryReference $repository ): string {
		return ( 'gh' === $this->code ? 'https://github.com/' : 'https://example.com/' ) . $repository->locator;
	}
}

final class ProspectiveListingOnlyProvider implements RepositoryProvider, RepositoryReleaseCandidateListing {
	private ProspectiveRepositoryProvider $provider;

	public function __construct( string $code ) {
		$this->provider = new ProspectiveRepositoryProvider( $code );
	}

	public function getMetadata(): ProviderMetadata {
		return $this->provider->getMetadata();
	}

	public function getProviderDiagnostics(): ProviderDiagnostics {
		return $this->provider->getProviderDiagnostics();
	}

	public function resolveRepository( RepositoryLookupRequest $request ): RepositoryDescriptor {
		return $this->provider->resolveRepository( $request );
	}

	public function prepareArchive( ArchiveRequest $request ): PreparedArchive {
		return $this->provider->prepareArchive( $request );
	}

	public function listReleaseCandidates(
		string $packageType,
		RepositoryReference $repository,
		string $channel
	): RepositoryReleaseCandidateList {
		return $this->provider->listReleaseCandidates( $packageType, $repository, $channel );
	}
}

final class ProspectiveProviderWithoutAcquisition implements RepositoryProvider, RepositoryReleaseCandidateListing, RepositoryReleaseInspector, RepositoryReleaseMetadata {
	private ProspectiveRepositoryProvider $provider;

	public function __construct() {
		$this->provider = new ProspectiveRepositoryProvider();
	}

	public function getMetadata(): ProviderMetadata {
		return $this->provider->getMetadata();
	}

	public function getProviderDiagnostics(): ProviderDiagnostics {
		return $this->provider->getProviderDiagnostics();
	}

	public function resolveRepository( RepositoryLookupRequest $request ): RepositoryDescriptor {
		return $this->provider->resolveRepository( $request );
	}

	public function prepareArchive( ArchiveRequest $request ): PreparedArchive {
		return $this->provider->prepareArchive( $request );
	}

	public function listReleaseCandidates(
		string $packageType,
		RepositoryReference $repository,
		string $channel
	): RepositoryReleaseCandidateList {
		return $this->provider->listReleaseCandidates( $packageType, $repository, $channel );
	}

	public function inspectRelease(
		string $packageType,
		RepositoryReference $repository,
		string $providerReleaseId,
		string $tag,
		string $channel
	): RepositoryReleaseInspection {
		return $this->provider->inspectRelease( $packageType, $repository, $providerReleaseId, $tag, $channel );
	}

	public function expectedUpdateUri( RepositoryReference $repository ): string {
		return $this->provider->expectedUpdateUri( $repository );
	}

	public function releaseDetailsUrl( RepositoryReference $repository, string $tag ): string {
		return $this->provider->releaseDetailsUrl( $repository, $tag );
	}
}

abstract class ProspectivePartialReleaseProvider implements RepositoryProvider {
	public int $resolveCalls = 0;

	public function getMetadata(): ProviderMetadata {
		return new ProviderMetadata(
			ProviderCode::parse( 'gh' ),
			'Partial release provider',
			'https://example.com/',
			'Owner'
		);
	}

	public function getProviderDiagnostics(): ProviderDiagnostics {
		return new class() implements ProviderDiagnostics {
			public function diagnose( ProviderDiagnosticRequest $request ): array {
				unset( $request );

				return array();
			}
		};
	}

	public function resolveRepository( RepositoryLookupRequest $request ): RepositoryDescriptor {
		++$this->resolveCalls;

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

	public function prepareArchive( ArchiveRequest $request ): PreparedArchive {
		unset( $request );

		throw new RuntimeException( 'Branch archive preparation is outside this test.' );
	}
}

final class ProspectiveAcquisitionOnlyProvider implements RepositoryProvider, RepositoryReleaseAcquirer {
	private ProspectiveRepositoryProvider $provider;

	public function __construct( string $code ) {
		$this->provider = new ProspectiveRepositoryProvider( $code );
	}

	public function getMetadata(): ProviderMetadata {
		return $this->provider->getMetadata();
	}

	public function getProviderDiagnostics(): ProviderDiagnostics {
		return $this->provider->getProviderDiagnostics();
	}

	public function resolveRepository( RepositoryLookupRequest $request ): RepositoryDescriptor {
		return $this->provider->resolveRepository( $request );
	}

	public function prepareArchive( ArchiveRequest $request ): PreparedArchive {
		return $this->provider->prepareArchive( $request );
	}

	public function acquireRelease(
		string $packageType,
		RepositoryReference $repository,
		string $providerReleaseId,
		string $tag,
		string $expectedFingerprint,
		string $channel
	): RepositoryReleaseArtifact {
		return $this->provider->acquireRelease( $packageType, $repository, $providerReleaseId, $tag, $expectedFingerprint, $channel );
	}
}

final class ProspectiveRepositoryProviderWithoutListing implements RepositoryProvider {

	public int $resolveCalls = 0;

	public function getMetadata(): ProviderMetadata {
		return new ProviderMetadata(
			ProviderCode::parse( 'gh' ),
			'GitHub without release listing',
			'https://github.com/',
			'Owner'
		);
	}

	public function getProviderDiagnostics(): ProviderDiagnostics {
		return new class() implements ProviderDiagnostics {
			public function diagnose( ProviderDiagnosticRequest $request ): array {
				unset( $request );

				return array();
			}
		};
	}

	public function resolveRepository( RepositoryLookupRequest $request ): RepositoryDescriptor {
		++$this->resolveCalls;

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

	public function prepareArchive( ArchiveRequest $request ): PreparedArchive {
		unset( $request );

		throw new RuntimeException( 'Branch archive preparation is outside this test.' );
	}
}

final class ProspectiveRepositoryReleaseArtifact implements RepositoryReleaseArtifact {
	public int $handoffCalls   = 0;
	public int $discardCalls   = 0;
	public bool $discardResult = true;
	private bool $handedOff    = false;
	private bool $discarded    = false;

	public function __construct(
		private string $path,
		private string $releaseVersion,
		private string $commit,
		private string $root,
		private string $metadataFile
	) {
	}

	public function discard(): bool {
		++$this->discardCalls;
		if ( $this->handedOff || $this->discarded ) {
			return true;
		}
		if ( ! $this->discardResult ) {
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

	public function handoffToCore(): PreparedArtifact {
		if ( $this->handedOff || $this->discarded ) {
			throw new RuntimeException( 'The release artifact is unavailable.' );
		}
		++$this->handoffCalls;
		$identity = PreparedArtifact::regularFileIdentity( $this->path );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_hash_file -- Test-only immutable artifact evidence.
		$digest = hash_file( 'sha256', $this->path );
		if ( null === $identity || ! is_string( $digest ) ) {
			throw new RuntimeException( 'The release artifact could not be prepared.' );
		}
		$this->handedOff = true;

		return new PreparedArtifact(
			$this->path,
			$this->commit,
			$this->releaseVersion,
			$digest,
			$identity['device'],
			$identity['inode'],
			$identity['size'],
			$identity['permissions'],
			$identity['links']
		);
	}

	public function version(): string {
		return $this->releaseVersion;
	}

	public function providerCommitId(): string {
		return $this->commit;
	}

	public function packageRoot(): string {
		return $this->root;
	}

	public function mainFile(): string {
		return $this->metadataFile;
	}

	public function identifier( string $packageType ): string {
		return match ( $packageType ) {
			'plugin' => $this->root . '/' . $this->metadataFile,
			'theme' => $this->root,
			default => throw new RuntimeException( 'The package type is invalid.' ),
		};
	}
}

final class ProspectiveExecutor extends CorePackageExecutor {

	public int $installCalls           = 0;
	public string $packageSlug         = '';
	public ?string $subdirectory       = null;
	public ?PreparedArtifact $artifact = null;
	public CorePackageExecutionResult $result;
	public ?\Closure $onInstall                  = null;
	public ?ProspectivePluginRepository $plugins = null;
	public bool $markInstalled                   = true;

	public function __construct() {
		$this->result = CorePackageExecutionResult::succeeded();
	}

	public function installPlugin(
		PreparedArtifact $artifact,
		string $packageSlug,
		?string $subdirectory
	): CorePackageExecutionResult {
		++$this->installCalls;
		$this->artifact     = $artifact;
		$this->packageSlug  = $packageSlug;
		$this->subdirectory = $subdirectory;
		if ( $this->markInstalled && null !== $this->plugins ) {
			$this->plugins->installed = true;
		}
		if ( null !== $this->onInstall ) {
			( $this->onInstall )();
		}

		return $this->result;
	}
}

final class ProspectivePluginRepository extends PluginRepository {

	public int $adoptionCalls                                 = 0;
	public int $adoptionUserId                                = 0;
	public ?Plugin $adoptedPackage                            = null;
	public ?ManagedReleaseConfiguration $adoptedConfiguration = null;
	public PackageMutationResult $adoptionResult;
	public bool $installed                 = false;
	public bool $managed                   = false;
	public bool $installedPackageAvailable = true;
	public string $installedVersion        = '1.2.3';

	public function __construct() {
		$this->adoptionResult = PackageMutationResult::changed( PackageStorageOperation::INSERT );
	}

	public function isInstalled( string $identifier ): bool {
		unset( $identifier );

		return $this->installed;
	}

	public function hasManagementRecord( mixed $identifier ): bool {
		unset( $identifier );

		return $this->managed;
	}

	public function installedPluginFromFile( string $file ): Plugin {
		if ( ! $this->installedPackageAvailable ) {
			throw new RuntimeException( 'The installed plugin is unavailable.' );
		}

		return new ProspectiveInstalledPlugin( $file, $this->installedVersion );
	}

	public function adoptRelease(
		Plugin $plugin,
		ManagedReleaseConfiguration $configuration,
		int $userId
	): PackageMutationResult {
		++$this->adoptionCalls;
		$this->adoptedPackage       = $plugin;
		$this->adoptedConfiguration = $configuration;
		$this->adoptionUserId       = $userId;

		return $this->adoptionResult;
	}
}

final class ProspectiveThemeRepository extends ThemeRepository {

	public function __construct() {
	}
}

final class ProspectiveUpdaterLock extends WordPressUpdaterLock {

	public int $acquireCalls    = 0;
	public int $releaseCalls    = 0;
	public bool $releaseResult  = true;
	public bool $throwOnAcquire = false;
	public bool $throwOnRelease = false;
	public ?\Closure $onAcquire = null;

	public function acquire(): string {
		++$this->acquireCalls;
		if ( $this->throwOnAcquire ) {
			throw new RuntimeException( 'The test lock could not be acquired.' );
		}
		if ( null !== $this->onAcquire ) {
			( $this->onAcquire )();
		}

		return 'test-lock-token';
	}

	public function release( string $token ): bool {
		unset( $token );
		++$this->releaseCalls;
		if ( $this->throwOnRelease ) {
			throw new RuntimeException( 'The test lock could not be released.' );
		}

		return $this->releaseResult;
	}
}

final class SequencedSourceGuardDatabase {
	/** @param list<list<object>> $rowsByRead */
	public function __construct( public array $rowsByRead ) {
	}

	public int $reads            = 0;
	public string $preparedQuery = '';

	public function prepare( string $query, mixed ...$arguments ): string {
		unset( $arguments );
		$this->preparedQuery = $query;

		return $query;
	}

	/** @return list<object> */
	public function get_results( string $query ): array {
		unset( $query );
		$read = $this->rowsByRead[ $this->reads ] ?? array();
		++$this->reads;

		return $read;
	}
}

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

final class ProspectiveInstalledPlugin extends Plugin {

	public function __construct( string $file, string $version ) {
		$this->file    = $file;
		$this->name    = 'Example';
		$this->version = $version;
	}
}

	// phpcs:enable Generic.Files.OneObjectStructurePerFile.MultipleFound
