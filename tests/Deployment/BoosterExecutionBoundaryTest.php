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

	public function testCronCallbackUpgradesSchemaBeforeRunningWorker(): void {
		$calls     = array();
		$container = new CoreContainer();
		$booster   = new Booster( $container );
		$container->bind( Database::class, new ExecutionBoundaryDatabase( $calls ) );
		$container->bind( DeploymentWorker::class, new ExecutionBoundaryWorker( $calls ) );

		$booster->runDeploymentWorker();

		self::assertSame( array( 'schema', 'worker' ), $calls );
	}

	public function testRestCallbackRegistersRoutesWithoutTouchingSchema(): void {
		$calls     = array();
		$container = new CoreContainer();
		$booster   = new Booster( $container );
		$container->bind( WebhookController::class, new ExecutionBoundaryWebhookController( $calls ) );

		$booster->registerWebhookRoutes();

		self::assertSame( array( 'routes' ), $calls );
	}

	/** @return array<string, array{DatabaseCompatibilityFailure|DatabaseLifecycleFailure}> */
	public static function databaseSafeStateProvider(): array {
		return array(
			'unsupported server' => array( new DatabaseCompatibilityFailure( 'unsupported_version' ) ),
			'blocked lifecycle'  => array( new DatabaseLifecycleFailure( 'schema_operation_failed' ) ),
		);
	}

	#[DataProvider( 'databaseSafeStateProvider' )]
	public function testDatabaseSafeStateStopsWorkerWithoutLeakingTheFailure(
		DatabaseCompatibilityFailure|DatabaseLifecycleFailure $failure
	): void {
		$calls     = array();
		$container = new CoreContainer();
		$booster   = new Booster( $container );
		$container->bind( Database::class, new BlockedExecutionBoundaryDatabase( $calls, $failure ) );
		$container->bind( DeploymentWorker::class, new ExecutionBoundaryWorker( $calls ) );

		$booster->runDeploymentWorker();

		self::assertSame( array( 'schema' ), $calls );
	}
}

// phpcs:disable Generic.Files.OneObjectStructurePerFile -- Focused order spies.
final class ExecutionBoundaryDatabase extends Database {
	/** @param list<string> $calls */
	public function __construct( private array &$calls ) {}
	public function maybeUpgrade(): void {
		$this->calls[] = 'schema'; }
}

final class BlockedExecutionBoundaryDatabase extends Database {
	/** @param list<string> $calls */
	public function __construct(
		private array &$calls,
		private DatabaseCompatibilityFailure|DatabaseLifecycleFailure $failure
	) {
	}

	public function maybeUpgrade(): void {
		$this->calls[] = 'schema';
		throw $this->failure;
	}

	public function requireReady(): void {
		throw $this->failure;
	}
}

final class ExecutionBoundaryWorker {
	/** @param list<string> $calls */
	public function __construct( private array &$calls ) {}
	public function runOnce(): array {
		$this->calls[] = 'worker';
		return array(); }
}

final class ExecutionBoundaryWebhookController {
	/** @param list<string> $calls */
	public function __construct( private array &$calls ) {}
	public function registerRoutes(): void {
		$this->calls[] = 'routes'; }
}
// phpcs:enable Generic.Files.OneObjectStructurePerFile
