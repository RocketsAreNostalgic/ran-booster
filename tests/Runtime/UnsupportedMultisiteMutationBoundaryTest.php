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
use RAN\WordPress\ManagedReleaseStore;
use RAN\WordPress\ManagedReleaseTargetRegistrar;
use RAN\WordPress\WordPressUpdaterLock;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class UnsupportedMultisiteMutationBoundaryTest extends TestCase {

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this exact lifecycle override name.
	protected function setUp(): void {
		require_once dirname( __DIR__ ) . '/Support/BootstrapRuntimeWordPressFunctions.php';
	}

	public function test_runtime_support_selects_the_unsupported_mode(): void {
		self::assertSame( RuntimeSupport::MULTISITE_UNSUPPORTED, RuntimeSupport::current() );
		self::assertFalse( RuntimeSupport::current()->allows_managed_operations() );
		$this->expectException( UnsupportedRuntimeException::class );
		RuntimeSupport::assert_managed_operations_allowed();
	}

	public function test_managed_release_registration_reads_no_packages_or_credentials(): void {
		$registrar = new ManagedReleaseTargetRegistrar(
			$this->blank( PluginRepository::class ),
			$this->blank( ThemeRepository::class ),
			$this->blank( ManagedReleaseStore::class ),
			$this->blank( WordPressUpdaterLock::class ),
			$this->blank( \RAN\RepositoryProvider\ProviderRegistry::class )
		);

		$registrar->register();

		self::assertNull( $registrar->target( 'plugin', 'example/example.php' ) );
		self::assertSame( '', $registrar->failure_code( 'plugin', 'example/example.php' ) );
	}

	public function test_core_executor_rejects_before_artifact_or_word_press_access(): void {
		$core_calls = 0;
		$executor   = new CorePackageExecutor(
			static function () use ( &$core_calls ): never {
				++$core_calls;
				throw new \RuntimeException( 'WordPress Core must stay inert.' );
			}
		);
		$artifact   = $this->blank( PreparedArtifact::class );
		$results    = array(
			$executor->install_plugin( $artifact, 'example', null ),
			$executor->install_theme( $artifact, 'example', null ),
			$executor->update_plugin( $artifact, 'example', null, 'example/example.php' ),
			$executor->update_theme( $artifact, 'example', null, 'example' ),
		);

		foreach ( $results as $result ) {
			self::assertFalse( $result->is_successful() );
			self::assertSame( CorePackageExecutionFailure::RUNTIME_UNSUPPORTED, $result->get_failure() );
		}
		self::assertSame( 0, $core_calls );
	}

	public function test_prospective_facade_rejects_listing_inspection_and_installation_before_authorization(): void {
		$facade  = new NativeProspectiveReleaseFacade(
			$this->blank( PackageRepositoryRequestResolver::class ),
			$this->blank( CorePackageExecutor::class ),
			$this->blank( PluginRepository::class ),
			$this->blank( ThemeRepository::class ),
			$this->blank( WordPressUpdaterLock::class ),
			$this->blank( \RAN\RepositoryProvider\ProviderRegistry::class ),
			static fn (): never => throw new \RuntimeException( 'Capabilities must not be checked.' ),
			static fn (): never => throw new \RuntimeException( 'Nonces must not be checked.' )
		);
		$results = array(
			$facade->list_candidates( 'plugin', array(), 'stable', 'nonce' ),
			$facade->inspect( 'plugin', array(), '1', 'v1.0.0', 'stable', 'nonce' ),
			$facade->install( 'plugin', array(), '1', 'v1.0.0', str_repeat( 'a', 64 ), 'stable', 'nonce' ),
		);

		foreach ( $results as $result ) {
			self::assertInstanceOf( ProspectiveReleaseResult::class, $result );
			self::assertFalse( $result->successful() );
			self::assertSame( UnsupportedRuntimeException::ERROR_CODE, $result->code() );
			self::assertSame( array(), $result->data() );
		}
	}

	public function test_release_tracking_mutations_reject_before_authorization_or_storage(): void {
		$facade = $this->release_tracking_facade();
		self::assertNull( $facade->preflight( 'plugin', 'example/example.php', 1, 'stable', 'nonce' ) );
		$results = array(
			$facade->enable( 'plugin', 'example/example.php', 1, 'stable', 'nonce' ),
			$facade->change_channel( 'plugin', 'example/example.php', 1, 'prerelease', 'nonce' ),
			$facade->refresh( 'plugin', 'example/example.php', 1, 'nonce' ),
			$facade->return_to_branch( 'plugin', 'example/example.php', 1, 'nonce' ),
		);

		foreach ( $results as $result ) {
			self::assertFalse( $result->successful() );
			self::assertSame( UnsupportedRuntimeException::ERROR_CODE, $result->code() );
			self::assertSame( 'Release tracking is unavailable on WordPress Multisite.', $result->message() );
		}
	}

	public function test_release_tracking_status_reads_are_unavailable_before_storage(): void {
		$facade = $this->release_tracking_facade();

		try {
			$facade->status( 'plugin', 'example/example.php' );
			self::fail( 'A retained release facade must not read status on unsupported Multisite.' );
		} catch ( UnsupportedRuntimeException ) {
			self::assertTrue( true );
		}

		$this->expectException( UnsupportedRuntimeException::class );
		$facade->statuses( 'plugin', array( 'example/example.php' ) );
	}

	public function test_package_operations_and_queued_work_reject_before_repositories_or_attempts(): void {
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

		$coordinator = $this->deployment_coordinator();
		try {
			$coordinator->queue_manual_updates( array() );
			self::fail( 'Queued mutations must be unavailable.' );
		} catch ( UnsupportedRuntimeException ) {
			self::assertTrue( true );
		}

		$this->expectException( UnsupportedRuntimeException::class );
		$coordinator->execute_claimed( $this->blank( DeploymentAttempt::class ) );
	}

	public function test_removal_and_managed_release_persistence_reject_before_storage(): void {
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

	public function test_transporter_entry_points_reject_before_authorization_archives_or_storage(): void {
		$controller = new PortabilityController(
			$this->blank( ManagedPackageBlueprintExporter::class ),
			$this->blank( BlueprintArchive::class ),
			$this->blank( \RAN\Portability\PortabilityApplicationService::class ),
			$this->blank( \RAN\Admin\ProviderSettingsPresenter::class )
		);

		foreach (
			array(
				static fn (): mixed => $controller->handle_export(),
				static fn (): mixed => $controller->handle_preview(),
				static fn (): mixed => $controller->handle_apply(),
				static fn (): mixed => $controller->preview_file( '/not-readable' ),
			) as $entry_point
		) {
			try {
				$entry_point();
				self::fail( 'Transporter must be unavailable.' );
			} catch ( UnsupportedRuntimeException ) {
				self::assertTrue( true );
			}
		}
	}

	public function test_lowest_storage_and_attempt_mutation_seams_reject_direct_calls(): void {
		$entry_points = array(
			static fn (): mixed => ( new PluginRepository() )->unlink( 'example/example.php' ),
			fn (): mixed => $this->blank( DeploymentAttemptRepository::class )->claim_next(),
			fn (): mixed => $this->blank( Database::class )->maybe_upgrade(),
		);

		foreach ( $entry_points as $entry_point ) {
			try {
				$entry_point();
				self::fail( 'The lowest mutation seam must be unavailable.' );
			} catch ( UnsupportedRuntimeException ) {
				self::assertTrue( true );
			}
		}
	}

	public function test_stale_worker_cannot_claim_or_transition_an_attempt(): void {
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
			$worker->run_once()
		);
	}

	public function test_webhook_processing_stays_unavailable_when_an_inert_route_is_registered_directly(): void {
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

		self::assertSame( 503, $response->get_status() );
		self::assertSame(
			array( 'message' => 'Webhook processing is unavailable on WordPress Multisite.' ),
			$response->get_data()
		);

		( new WebhookController( $processor ) )->register_routes();
		self::assertCount( 1, $GLOBALS['ran_booster_rest_routes'] );
		self::assertSame( 'ran-booster/v1', $GLOBALS['ran_booster_rest_routes'][0]['namespace'] );
	}

	private function release_tracking_facade(): NativeReleaseTrackingFacade {
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

	private function deployment_coordinator(): DeploymentCoordinator {
		return new DeploymentCoordinator(
			$this->blank( DeploymentAttemptRepository::class ),
			$this->blank( PluginRepository::class ),
			$this->blank( ThemeRepository::class ),
			$this->blank( \RAN\RepositoryProvider\ProviderRegistry::class ),
			$this->blank( WordPressWorkerWakeup::class ),
			'/tmp/ran-booster-multisite-quarantine-maintenance',
			$this->blank( WordPressUpdaterLock::class )
		);
	}

	/** @template T of object
	 *  @param class-string<T> $class_name
	 *  @return T
	 */
	private function blank( string $class_name ): object {
		return ( new \ReflectionClass( $class_name ) )->newInstanceWithoutConstructor();
	}
}
