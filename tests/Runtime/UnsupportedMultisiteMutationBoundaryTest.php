<?php

declare(strict_types=1);

namespace Tests\Runtime;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use RAN\AddOn\ReleaseTracking\NativeProspectiveReleaseFacade;
use RAN\AddOn\ReleaseTracking\NativeReleaseTrackingFacade;
use RAN\AddOn\ReleaseTracking\ProspectiveReleaseResult;
use RAN\AddOn\ReleaseTracking\ReleaseTrackingResult;
use RAN\Admin\PackageRepositoryRequestResolver;
use RAN\Admin\PortabilityController;
use RAN\Deployment\DeploymentAttempt;
use RAN\Deployment\DeploymentAttemptRepository;
use RAN\Deployment\DeploymentCoordinator;
use RAN\Deployment\DeploymentWorker;
use RAN\Deployment\PreparedArtifact;
use RAN\Deployment\WordPressWorkerWakeup;
use RAN\PackageOperation;
use RAN\PackageOperationService;
use RAN\PackageRemoval\PackageRemovalService;
use RAN\Portability\BlueprintArchive;
use RAN\Portability\BlueprintRepositoryVerifier;
use RAN\Portability\BlueprintReviewer;
use RAN\Portability\ManagedPackageBlueprintExporter;
use RAN\Runtime\RuntimeSupport;
use RAN\Runtime\UnsupportedRuntimeException;
use RAN\Secrets\SecretsFile;
use RAN\Storage\PluginRepository;
use RAN\Storage\Database;
use RAN\Storage\ThemeRepository;
use RAN\Webhook\SignedWebhookVerifier;
use RAN\Webhook\WebhookController;
use RAN\Webhook\WebhookProcessor;
use RAN\WordPress\CorePackageExecutionFailure;
use RAN\WordPress\CorePackageExecutor;
use RAN\WordPress\ManagedReleasePreflight;
use RAN\WordPress\ManagedReleaseStore;
use RAN\WordPress\ManagedReleaseTargetRegistrar;
use RAN\WordPress\WordPressUpdaterLock;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class UnsupportedMultisiteMutationBoundaryTest extends TestCase {

	protected function setUp(): void {
		require_once dirname( __DIR__ ) . '/Support/BootstrapRuntimeWordPressFunctions.php';
	}

	public function testRuntimeSupportSelectsTheUnsupportedMode(): void {
		self::assertSame( RuntimeSupport::MULTISITE_UNSUPPORTED, RuntimeSupport::current() );
		self::assertFalse( RuntimeSupport::current()->allowsManagedOperations() );
		$this->expectException( UnsupportedRuntimeException::class );
		RuntimeSupport::assertManagedOperationsAllowed();
	}

	public function testManagedReleaseRegistrationReadsNoPackagesOrCredentials(): void {
		$registrar = new ManagedReleaseTargetRegistrar(
			$this->blank( PluginRepository::class ),
			$this->blank( ThemeRepository::class ),
			$this->blank( SecretsFile::class ),
			$this->blank( ManagedReleaseStore::class ),
			$this->blank( WordPressUpdaterLock::class ),
			static fn (): never => throw new \RuntimeException( 'The updater factory must stay inert.' )
		);

		$registrar->register();

		self::assertNull( $registrar->facade( 'plugin', 'example/example.php' ) );
		self::assertSame( '', $registrar->failureCode( 'plugin', 'example/example.php' ) );
	}

	public function testCoreExecutorRejectsBeforeArtifactOrWordPressAccess(): void {
		$coreCalls = 0;
		$executor  = new CorePackageExecutor(
			static function () use ( &$coreCalls ): never {
				++$coreCalls;
				throw new \RuntimeException( 'WordPress Core must stay inert.' );
			}
		);
		$artifact  = $this->blank( PreparedArtifact::class );
		$results   = array(
			$executor->installPlugin( $artifact, 'example', null ),
			$executor->installTheme( $artifact, 'example', null ),
			$executor->updatePlugin( $artifact, 'example', null, 'example/example.php' ),
			$executor->updateTheme( $artifact, 'example', null, 'example' ),
		);

		foreach ( $results as $result ) {
			self::assertFalse( $result->isSuccessful() );
			self::assertSame( CorePackageExecutionFailure::RUNTIME_UNSUPPORTED, $result->getFailure() );
		}
		self::assertSame( 0, $coreCalls );
	}

	public function testProspectiveFacadeRejectsDiscoveryInspectionAndInstallationBeforeAuthorization(): void {
		$facade  = new NativeProspectiveReleaseFacade(
			$this->blank( PackageRepositoryRequestResolver::class ),
			$this->blank( ManagedReleasePreflight::class ),
			$this->blank( CorePackageExecutor::class ),
			$this->blank( PluginRepository::class ),
			$this->blank( ThemeRepository::class ),
			$this->blank( WordPressUpdaterLock::class ),
			static fn (): never => throw new \RuntimeException( 'Capabilities must not be checked.' ),
			static fn (): never => throw new \RuntimeException( 'Nonces must not be checked.' )
		);
		$results = array(
			$facade->listCandidates( 'plugin', array(), 'stable', 'nonce' ),
			$facade->discover( 'plugin', array(), 'stable', 'nonce' ),
			$facade->inspect( 'plugin', array(), 1, 'v1.0.0', 'stable', 'nonce' ),
			$facade->install( 'plugin', array(), 1, 'v1.0.0', str_repeat( 'a', 64 ), 'stable', 'nonce' ),
		);

		foreach ( $results as $result ) {
			self::assertInstanceOf( ProspectiveReleaseResult::class, $result );
			self::assertFalse( $result->successful() );
			self::assertSame( UnsupportedRuntimeException::ERROR_CODE, $result->code() );
			self::assertSame( array(), $result->data() );
		}
	}

	public function testReleaseTrackingMutationsRejectBeforeAuthorizationOrStorage(): void {
		$facade = $this->releaseTrackingFacade();
		self::assertNull( $facade->preflight( 'plugin', 'example/example.php', 1, 'stable', 'nonce' ) );
		$results = array(
			$facade->enable( 'plugin', 'example/example.php', 1, 'stable', 'nonce' ),
			$facade->changeChannel( 'plugin', 'example/example.php', 1, 'prerelease', 'nonce' ),
			$facade->refresh( 'plugin', 'example/example.php', 1, 'nonce' ),
			$facade->returnToBranch( 'plugin', 'example/example.php', 1, 'nonce' ),
		);

		foreach ( $results as $result ) {
			self::assertFalse( $result->successful() );
			self::assertSame( UnsupportedRuntimeException::ERROR_CODE, $result->code() );
			self::assertSame( 'Release tracking is unavailable on WordPress Multisite.', $result->message() );
		}
	}

	public function testReleaseTrackingStatusReadsAreUnavailableBeforeStorage(): void {
		$facade = $this->releaseTrackingFacade();

		try {
			$facade->status( 'plugin', 'example/example.php' );
			self::fail( 'A retained release facade must not read status on unsupported Multisite.' );
		} catch ( UnsupportedRuntimeException ) {
			self::assertTrue( true );
		}

		$this->expectException( UnsupportedRuntimeException::class );
		$facade->statuses( 'plugin', array( 'example/example.php' ) );
	}

	public function testPackageOperationsAndQueuedWorkRejectBeforeRepositoriesOrAttempts(): void {
		$service = new PackageOperationService(
			$this->blank( PluginRepository::class ),
			$this->blank( ThemeRepository::class ),
			$this->blank( DeploymentCoordinator::class ),
			$this->blank( PackageRemovalService::class ),
			$this->blank( WordPressUpdaterLock::class )
		);

		try {
			$service->execute( $this->blank( PackageOperation::class ) );
			self::fail( 'Package operations must be unavailable.' );
		} catch ( UnsupportedRuntimeException ) {
			self::assertTrue( true );
		}

		$coordinator = $this->deploymentCoordinator();
		try {
			$coordinator->queueManualUpdates( array() );
			self::fail( 'Queued mutations must be unavailable.' );
		} catch ( UnsupportedRuntimeException ) {
			self::assertTrue( true );
		}

		$this->expectException( UnsupportedRuntimeException::class );
		$coordinator->executeClaimed( $this->blank( DeploymentAttempt::class ) );
	}

	public function testRemovalAndManagedReleasePersistenceRejectBeforeStorage(): void {
		$removal = new PackageRemovalService(
			$this->blank( PluginRepository::class ),
			$this->blank( ThemeRepository::class ),
			$this->createStub( \RAN\PackageRemoval\PackageRemovalGateway::class ),
			null,
			$this->blank( WordPressUpdaterLock::class )
		);

		try {
			$removal->execute( $this->blank( PackageOperation::class ) );
			self::fail( 'Package removal must be unavailable.' );
		} catch ( UnsupportedRuntimeException ) {
			self::assertTrue( true );
		}

		$this->expectException( UnsupportedRuntimeException::class );
		$this->blank( ManagedReleaseStore::class )->transition(
			'plugin',
			'example/example.php',
			\RAN\PackageSource::BRANCH,
			1,
			\RAN\PackageSource::RELEASE_ASSET,
			$this->blank( \RAN\WordPress\ManagedReleaseConfiguration::class ),
			1
		);
	}

	public function testTransporterEntryPointsRejectBeforeAuthorizationArchivesOrStorage(): void {
		$controller = new PortabilityController(
			$this->blank( ManagedPackageBlueprintExporter::class ),
			$this->blank( BlueprintArchive::class ),
			$this->blank( \RAN\Portability\PortabilityApplicationService::class ),
			$this->blank( \RAN\Admin\ProviderSettingsPresenter::class )
		);

		foreach (
			array(
				static fn (): mixed => $controller->handleExport(),
				static fn (): mixed => $controller->handlePreview(),
				static fn (): mixed => $controller->handleApply(),
				static fn (): mixed => $controller->previewFile( '/not-readable' ),
			) as $entryPoint
		) {
			try {
				$entryPoint();
				self::fail( 'Transporter must be unavailable.' );
			} catch ( UnsupportedRuntimeException ) {
				self::assertTrue( true );
			}
		}
	}

	public function testLowestStorageAndAttemptMutationSeamsRejectDirectCalls(): void {
		$entryPoints = array(
			static fn (): mixed => ( new PluginRepository() )->unlink( 'example/example.php' ),
			fn (): mixed => $this->blank( DeploymentAttemptRepository::class )->claimNext(),
			fn (): mixed => $this->blank( Database::class )->maybeUpgrade(),
		);

		foreach ( $entryPoints as $entryPoint ) {
			try {
				$entryPoint();
				self::fail( 'The lowest mutation seam must be unavailable.' );
			} catch ( UnsupportedRuntimeException ) {
				self::assertTrue( true );
			}
		}
	}

	public function testStaleWorkerCannotClaimOrTransitionAnAttempt(): void {
		$worker = new DeploymentWorker(
			$this->blank( DeploymentAttemptRepository::class ),
			$this->blank( DeploymentCoordinator::class ),
			$this->blank( WordPressWorkerWakeup::class )
		);

		self::assertSame(
			array(
				'status'        => 'unavailable',
				'runner_status' => 'not_required',
			),
			$worker->runOnce()
		);
	}

	public function testWebhookProcessingStaysUnavailableWhenAnInertRouteIsRegisteredDirectly(): void {
		$processor = new WebhookProcessor(
			$this->blank( \RAN\RepositoryProvider\ProviderRegistry::class ),
			$this->blank( DeploymentCoordinator::class ),
			$this->blank( SignedWebhookVerifier::class )
		);
		$response  = $processor->handle(
			'gh',
			static fn (): array => array(
				'body'    => 'credential-canary',
				'headers' => array(),
			)
		);

		self::assertSame( 503, $response->getStatus() );
		self::assertSame(
			array( 'message' => 'Webhook processing is unavailable on WordPress Multisite.' ),
			$response->getData()
		);

		( new WebhookController( $processor ) )->registerRoutes();
		self::assertCount( 1, $GLOBALS['ran_booster_rest_routes'] );
		self::assertSame( 'ran-booster/v1', $GLOBALS['ran_booster_rest_routes'][0]['namespace'] );
	}

	private function releaseTrackingFacade(): NativeReleaseTrackingFacade {
		return new NativeReleaseTrackingFacade(
			$this->blank( PluginRepository::class ),
			$this->blank( ThemeRepository::class ),
			$this->blank( ManagedReleaseStore::class ),
			$this->blank( ManagedReleaseTargetRegistrar::class ),
			$this->blank( WordPressUpdaterLock::class ),
			$this->blank( \RAN\RepositoryProvider\ProviderRegistry::class ),
			static fn (): never => throw new \RuntimeException( 'Capabilities must not be checked.' ),
			static fn (): never => throw new \RuntimeException( 'Nonces must not be checked.' )
		);
	}

	private function deploymentCoordinator(): DeploymentCoordinator {
		return new DeploymentCoordinator(
			$this->blank( DeploymentAttemptRepository::class ),
			$this->blank( PluginRepository::class ),
			$this->blank( ThemeRepository::class ),
			$this->blank( \RAN\RepositoryProvider\ProviderRegistry::class ),
			$this->blank( \RAN\Deployment\DeploymentArchivePreflight::class ),
			$this->blank( CorePackageExecutor::class ),
			$this->blank( WordPressWorkerWakeup::class ),
			'/tmp/ran-booster-multisite-quarantine-maintenance',
			$this->blank( WordPressUpdaterLock::class )
		);
	}

	/** @template T of object
	 *  @param class-string<T> $class
	 *  @return T
	 */
	private function blank( string $class ): object {
		return ( new \ReflectionClass( $class ) )->newInstanceWithoutConstructor();
	}
}
