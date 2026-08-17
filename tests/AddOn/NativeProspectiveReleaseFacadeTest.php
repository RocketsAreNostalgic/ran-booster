<?php

declare(strict_types=1);

namespace Tests\AddOn;

	require_once __DIR__ . '/../Support/WPError.php';
	require_once __DIR__ . '/../Support/RepositoryAdminWordPressFunctions.php';
	require_once __DIR__ . '/../Support/PackageOperationGlobalWordPressFunctions.php';
	require_once __DIR__ . '/../Deployment/PackageMutationGuardWordPressFunctions.php';
	require_once __DIR__ . '/../Portability/WpPusherCoexistenceWordPressFunctions.php';
	require_once __DIR__ . '/../Support/ProspectiveReleaseFacadeWordPressFunctions.php';
	require_once __DIR__ . '/../Support/ProspectiveReleaseArtifactWordPressFunctions.php';
	require_once __DIR__ . '/../Support/ProspectiveReleaseUpdaterFixtures.php';

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
	use RAN\RepositoryProvider\RepositoryReleaseCandidate;
	use RAN\RepositoryProvider\RepositoryReleaseCandidateList;
	use RAN\RepositoryProvider\RepositoryReleaseCandidateListing;
	use RAN\Secrets\SecretsFile;
	use RAN\Storage\PackageMutationResult;
	use RAN\Storage\PackageStorageOperation;
	use RAN\Storage\PluginRepository;
	use RAN\Storage\ThemeRepository;
	use RAN\WordPress\CorePackageExecutionResult;
	use RAN\WordPress\CorePackageExecutionFailure;
	use RAN\WordPress\CorePackageExecutor;
	use RAN\WordPress\ManagedReleaseConfiguration;
	use RAN\WordPress\ManagedReleasePreflight;
	use RAN\WordPress\ProspectiveReleaseArtifact;
	use RAN\WordPress\WordPressUpdaterLock;
	use RAN\WPGitHubReleaseUpdater\V1\WordPress\ProspectiveAcquisitionFixture;
	use RAN\WPGitHubReleaseUpdater\V1\WordPress\ProspectiveCandidateFixture;
	use RAN\WPGitHubReleaseUpdater\V1\WordPress\ProspectiveDiscoveryFixture;
	use RAN\WPGitHubReleaseUpdater\V1\WordPress\ProspectiveInspectionFixture;
	use RAN\WPGitHubReleaseUpdater\V1\WordPress\ReleaseCandidatePreflight;
	use RuntimeException;

	// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- Focused collaborators stay with the facade contract tests.

final class NativeProspectiveReleaseFacadeTest extends TestCase {

	private const FINGERPRINT = 'v1:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

	private ?string $artifactPath                       = null;
	private ?ProspectiveAcquisitionFixture $acquisition = null;

	protected function setUp(): void {
		ReleaseCandidatePreflight::reset();
		ProspectiveRepositoryProvider::$resolveCalls = 0;

		$GLOBALS['ran_booster_prospective_options']              = array();
		$GLOBALS['ran_booster_package_mutation_guard_multisite'] = false;
		$GLOBALS['ran_booster_package_mutation_guard_file_mods'] = true;
		$GLOBALS['ran_booster_package_mutation_guard_contexts']  = array();
		$GLOBALS['ran_booster_prospective_chmod_allowed']        = true;
	}

	protected function tearDown(): void {
		if ( null !== $this->artifactPath && file_exists( $this->artifactPath ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test-only temporary artifact cleanup.
			unlink( $this->artifactPath );
		}
		$this->artifactPath = null;
		$this->acquisition  = null;
		unset( $GLOBALS['ran_booster_prospective_options'] );
		unset( $GLOBALS['ran_booster_prospective_chmod_allowed'] );
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

	public function testUnsupportedProviderFailsBeforeRepositoryResolutionOrPreflight(): void {
		$plugins    = new ProspectivePluginRepository();
		$executor   = new ProspectiveExecutor();
		$facade     = $this->facade( $plugins, $executor );
		$repository = $this->repositoryRequest();

		$repository['provider'] = 'bb';

		$results = array(
			$facade->listCandidates( 'plugin', $repository, 'stable', 'valid-nonce' ),
			$facade->discover( 'plugin', $repository, 'stable', 'valid-nonce' ),
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

	public function testDiscoveryMapsOnlyPublishedReleaseEvidence(): void {
		ReleaseCandidatePreflight::$discovery = new ProspectiveDiscoveryFixture( 42, 'v1.2.3', '1.2.3' );
		$plugins                              = new ProspectivePluginRepository();
		$executor                             = new ProspectiveExecutor();
		$facade                               = $this->facade( $plugins, $executor );

		$result = $facade->discover(
			'plugin',
			$this->repositoryRequest( 'feature/release' ),
			'stable',
			'valid-nonce'
		);

		self::assertTrue( $result->successful() );
		self::assertSame( 'release_available', $result->code() );
		self::assertSame(
			array(
				'release_id' => 42,
				'tag'        => 'v1.2.3',
				'version'    => '1.2.3',
				'channel'    => 'stable',
			),
			$result->data()
		);
		self::assertSame( 1, ReleaseCandidatePreflight::$discoverCalls );
		self::assertSame( 'stable', ReleaseCandidatePreflight::$target['channel'] );
		self::assertSame( 'plugin', ReleaseCandidatePreflight::$target['packageType'] );
		self::assertArrayNotHasKey( 'assetPrefix', ReleaseCandidatePreflight::$target );
		self::assertArrayNotHasKey( 'manifestPublicKey', ReleaseCandidatePreflight::$target );
		self::assertSame( '123456789', ReleaseCandidatePreflight::$target['providerRepositoryId'] );
		self::assertSame( 0, $executor->installCalls );
		self::assertSame( 0, $plugins->adoptionCalls );
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
		self::assertSame( 0, ReleaseCandidatePreflight::$inspectCalls );
		self::assertSame( 0, ReleaseCandidatePreflight::$acquireCalls );
		self::assertSame( 0, $executor->installCalls );
		self::assertSame( 0, $plugins->adoptionCalls );
	}

	public function testInvalidChannelFailsBeforeAnyProspectiveReleaseWork(): void {
		ReleaseCandidatePreflight::$discovery = new ProspectiveDiscoveryFixture( 42, 'v1.2.3', '1.2.3' );
		$facade                               = $this->facade(
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
			$facade->discover(
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
		ReleaseCandidatePreflight::$acquired = new \WP_Error(
			'github_updater_artifact_continuity_failed',
			'The selected published release changed.'
		);
		$plugins                             = new ProspectivePluginRepository();
		$executor                            = new ProspectiveExecutor();
		$facade                              = $this->facade( $plugins, $executor );

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
		self::assertSame( 1, ReleaseCandidatePreflight::$acquireCalls );
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
		self::assertSame( 'prerelease', ReleaseCandidatePreflight::$target['channel'] );
		self::assertSame( 1, $this->acquisition?->handoffCalls );
		self::assertSame( 0, $this->acquisition?->discardCalls );
		self::assertSame( 1, $lock->acquireCalls );
		self::assertSame( 1, $lock->releaseCalls );
		self::assertFileDoesNotExist( (string) $this->artifactPath );
	}

	public function testInspectReturnsTheFingerprintRequiredForInstallContinuity(): void {
		ReleaseCandidatePreflight::$inspection = new ProspectiveInspectionFixture();
		$plugins                               = new ProspectivePluginRepository();
		$facade                                = $this->facade( $plugins, new ProspectiveExecutor() );

		$result = $facade->inspect(
			'plugin',
			$this->repositoryRequest(),
			42,
			'v1.2.3',
			'stable',
			'valid-nonce'
		);

		self::assertTrue( $result->successful() );
		self::assertSame( self::FINGERPRINT, $result->data()['fingerprint'] );
		self::assertSame( 'stable', $result->data()['channel'] );
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

	public function testClaimedArtifactConversionUsesUpdaterAttestationWithoutCoreChmod(): void {
		$release = $this->claimedReleaseArtifact();
		$GLOBALS['ran_booster_prospective_chmod_allowed'] = false;

		$prepared = $release->handoffToCore();

		self::assertInstanceOf( PreparedArtifact::class, $prepared );
		self::assertSame( 1, $this->acquisition?->handoffCalls );
		$prepared->cleanup();
		self::assertFileDoesNotExist( (string) $this->artifactPath );
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
		$inspection         = new ProspectiveInspectionFixture();
		$this->artifactPath = tempnam( sys_get_temp_dir(), 'ran-booster-prospective-' );
		if ( false === $this->artifactPath ) {
			throw new RuntimeException( 'The test release artifact could not be created.' );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test-only temporary artifact.
		file_put_contents( $this->artifactPath, 'verified-release-archive' );
		ReleaseCandidatePreflight::$inspection = $inspection;
		$this->acquisition                     = new ProspectiveAcquisitionFixture(
			$this->artifactPath,
			$inspection
		);
		ReleaseCandidatePreflight::$acquired   = $this->acquisition;
	}

	private function claimedReleaseArtifact(): ProspectiveReleaseArtifact {
		$this->setReadyRelease();
		if ( null === $this->acquisition ) {
			throw new RuntimeException( 'The test acquisition is unavailable.' );
		}

		return new ProspectiveReleaseArtifact(
			$this->acquisition,
			42,
			'v1.2.3',
			'1.2.3',
			str_repeat( 'a', 40 ),
			'https://github.com/owner/example/releases/tag/v1.2.3',
			'example',
			'example.php'
		);
	}

	private function facade(
		ProspectivePluginRepository $plugins,
		ProspectiveExecutor $executor,
		int $userId = 7,
		?ProspectiveUpdaterLock $updaterLock = null,
		?RepositoryProvider $provider = null
	): NativeProspectiveReleaseFacade {
		$provider          = $provider ?? new ProspectiveRepositoryProvider();
		$registry          = new ProviderRegistry( array( $provider ) );
		$resolver          = new PackageRepositoryRequestResolver( $registry );
		$secrets           = new SecretsFile( sys_get_temp_dir() . '/ran-booster-prospective-secrets.php', array() );
		$executor->plugins = $plugins;

		return new NativeProspectiveReleaseFacade(
			$resolver,
			new ManagedReleasePreflight( $secrets ),
			$executor,
			$plugins,
			new ProspectiveThemeRepository(),
			$updaterLock ?? new ProspectiveUpdaterLock(),
			$registry,
			static fn ( string $type ): bool => 'plugin' === $type,
			static fn ( string $nonce, string $action ): bool => 'valid-nonce' === $nonce
					&& str_starts_with( $action, 'ran-booster-prospective-release-' ),
			static fn (): int => $userId
		);
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

final class ProspectiveRepositoryProvider implements RepositoryProvider, RepositoryReleaseCandidateListing {

	public static int $resolveCalls = 0;

	public function getMetadata(): ProviderMetadata {
		return new ProviderMetadata(
			ProviderCode::parse( 'gh' ),
			'GitHub',
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
		++self::$resolveCalls;

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

	public function listReleaseCandidates(
		string $packageType,
		RepositoryReference $repository,
		string $channel
	): RepositoryReleaseCandidateList {
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

final class ProspectiveInstalledPlugin extends Plugin {

	public function __construct( string $file, string $version ) {
		$this->file    = $file;
		$this->name    = 'Example';
		$this->version = $version;
	}
}

	// phpcs:enable Generic.Files.OneObjectStructurePerFile.MultipleFound
