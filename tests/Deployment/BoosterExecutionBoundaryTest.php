<?php

declare(strict_types=1);

namespace Tests\Deployment;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RAN\Booster;
use RAN\Deployment\DeploymentWorker;
use RAN\Internal\CoreContainer;
use RAN\Storage\Database;
use RAN\Storage\DatabaseCompatibilityFailure;
use RAN\Storage\DatabaseLifecycleFailure;
use RAN\Webhook\WebhookController;

final class BoosterExecutionBoundaryTest extends TestCase {

	public function test_cron_callback_upgrades_schema_before_running_worker(): void {
		$calls     = array();
		$container = new CoreContainer();
		$booster   = new Booster( $container );
		$container->bind( Database::class, new ExecutionBoundaryDatabase( $calls ) );
		$container->bind( DeploymentWorker::class, new ExecutionBoundaryWorker( $calls ) );

		$booster->run_deployment_worker();

		self::assertSame( array( 'schema', 'worker' ), $calls );
	}

	public function test_rest_callback_registers_routes_without_touching_schema(): void {
		$calls     = array();
		$container = new CoreContainer();
		$booster   = new Booster( $container );
		$container->bind( WebhookController::class, new ExecutionBoundaryWebhookController( $calls ) );

		$booster->register_webhook_routes();

		self::assertSame( array( 'routes' ), $calls );
	}

	/** @return array<string, array{DatabaseCompatibilityFailure|DatabaseLifecycleFailure}> */
	public static function database_safe_state_provider(): array {
		return array(
			'unsupported server' => array( new DatabaseCompatibilityFailure( 'unsupported_version' ) ),
			'blocked lifecycle'  => array( new DatabaseLifecycleFailure( 'schema_operation_failed' ) ),
		);
	}

	#[DataProvider( 'database_safe_state_provider' )]
	public function test_database_safe_state_stops_worker_without_leaking_the_failure(
		DatabaseCompatibilityFailure|DatabaseLifecycleFailure $failure
	): void {
		$calls     = array();
		$container = new CoreContainer();
		$booster   = new Booster( $container );
		$container->bind( Database::class, new BlockedExecutionBoundaryDatabase( $calls, $failure ) );
		$container->bind( DeploymentWorker::class, new ExecutionBoundaryWorker( $calls ) );

		$booster->run_deployment_worker();

		self::assertSame( array( 'schema' ), $calls );
	}
}

// phpcs:disable Generic.Files.OneObjectStructurePerFile -- Focused order spies.
final class ExecutionBoundaryDatabase extends Database {
	/** @param list<string> $calls */
	public function __construct( private array &$calls ) {}
	public function maybe_upgrade(): void {
		$this->calls[] = 'schema'; }
}

final class BlockedExecutionBoundaryDatabase extends Database {
	/** @param list<string> $calls */
	public function __construct(
		private array &$calls,
		private DatabaseCompatibilityFailure|DatabaseLifecycleFailure $failure
	) {
	}

	public function maybe_upgrade(): void {
		$this->calls[] = 'schema';
		throw $this->failure;
	}

	public function require_ready(): void {
		throw $this->failure;
	}
}

final class ExecutionBoundaryWorker {
	/** @param list<string> $calls */
	public function __construct( private array &$calls ) {}
	public function run_once(): array {
		$this->calls[] = 'worker';
		return array(); }
}

final class ExecutionBoundaryWebhookController {
	/** @param list<string> $calls */
	public function __construct( private array &$calls ) {}
	public function register_routes(): void {
		$this->calls[] = 'routes'; }
}
// phpcs:enable Generic.Files.OneObjectStructurePerFile
