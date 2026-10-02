<?php

declare(strict_types=1);

namespace Tests\Deployment;

require_once __DIR__ . '/DeploymentWorkerPhpFunctions.php';
require_once __DIR__ . '/WordPressWorkerWakeupCron.php';
require_once __DIR__ . '/WordPressWorkerWakeupWordPressFunctions.php';
require_once __DIR__ . '/AttemptRepositoryDatabase.php';
require_once dirname( __DIR__ ) . '/Portability/WpPusherCoexistenceWordPressFunctions.php';

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RAN\Deployment\DeploymentAttempt;
use RAN\Deployment\DeploymentAttemptRepository;
use RAN\Deployment\DeploymentCoordinator;
use RAN\Deployment\DeploymentOutcome;
use RAN\Deployment\DeploymentPolicy;
use RAN\Deployment\DeploymentRequest;
use RAN\Deployment\DeploymentState;
use RAN\Deployment\DeploymentWorker;
use RAN\Deployment\WordPressWorkerWakeup;
use RuntimeException;

final class DeploymentWorkerTest extends TestCase {

	private AttemptRepositoryDatabase $database;
	private DeploymentAttemptRepository $attempts;
	private WorkerCoordinator $coordinator;
	private int $random_byte = 1;

	protected function setUp(): void {
		$GLOBALS['ran_booster_worker_doing_cron'] = true;
		WordPressWorkerWakeupCron::reset();
		$this->database    = new AttemptRepositoryDatabase();
		$this->attempts    = new DeploymentAttemptRepository(
			$this->database,
			'wp_ran_booster_deployment_attempts',
			static fn (): DateTimeImmutable => new DateTimeImmutable( '2026-07-19 00:00:00 UTC' ),
			function ( int $length ): string {
				return str_repeat( chr( $this->random_byte++ ), $length );
			}
		);
		$this->coordinator = new WorkerCoordinator();
	}

	protected function tearDown(): void {
		unset(
			$GLOBALS['ran_booster_worker_doing_cron'],
			$GLOBALS['ran_booster_wp_pusher_active_plugins']
		);
		WordPressWorkerWakeupCron::reset();
	}

	public function test_non_cron_invocation_does_not_claim_work(): void {
		$GLOBALS['ran_booster_worker_doing_cron'] = false;
		$this->admit( 'first' );

		self::assertSame( 'unavailable', $this->worker()->run_once()['status'] );
		self::assertSame( 0, $this->coordinator->calls );
		self::assertSame( 'queued', $this->database->rows[0]['state'] );
	}

	public function test_active_wp_pusher_does_not_claim_queued_work(): void {
		$this->admit( 'first' );
		$GLOBALS['ran_booster_wp_pusher_active_plugins'] = array( 'wppusher/wppusher.php' );

		self::assertSame( 'unavailable', $this->worker()->run_once()['status'] );
		self::assertSame( 'queued', $this->database->rows[0]['state'] );
		self::assertSame( 0, $this->coordinator->calls );
	}

	public function test_one_cron_pass_claims_and_processes_only_the_first_attempt(): void {
		$first = $this->admit( 'first' );
		$this->admit( 'second' );

		$result = $this->worker()->run_once();

		self::assertSame( 'processed', $result['status'] );
		self::assertSame( $first->get_correlation_id(), $result['correlation_id'] );
		self::assertSame( 'scheduled', $result['runner_status'] );
		self::assertSame( array( $first->get_id() ), $this->coordinator->attempt_ids );
		self::assertSame( 'running', $this->database->rows[0]['state'] );
		self::assertSame( 'queued', $this->database->rows[1]['state'] );
		self::assertCount( 1, WordPressWorkerWakeupCron::$events );
	}

	public function test_cron_worker_selects_manual_and_webhook_attempts_in_fifo_order(): void {
		$this->attempts->admit_and_claim_manual( 'update', 'plugin', 'gh', 'R_manual', $this->request( 'manual' ), 'main', 'branch', 1 );
		$this->database->rows[0]['state'] = DeploymentState::QUEUED->value;
		$manual_correlation                = $this->database->rows[0]['correlation_id'];
		$this->admit( 'webhook' );

		$result = $this->worker()->run_once();

		self::assertSame( $manual_correlation, $result['correlation_id'] );
		self::assertSame( array( 1 ), $this->coordinator->attempt_ids );
		self::assertSame( 'manual', $this->database->rows[0]['source'] );
		self::assertSame( 'running', $this->database->rows[0]['state'] );
		self::assertSame( $manual_correlation, $this->database->rows[0]['correlation_id'] );
		self::assertSame( 'queued', $this->database->rows[1]['state'] );
	}

	public function test_empty_cron_pass_returns_without_scheduling(): void {
		self::assertSame(
			array(
				'status'        => 'empty',
				'runner_status' => 'not_required',
			),
			$this->worker()->run_once()
		);
		self::assertSame( array(), WordPressWorkerWakeupCron::$events );
	}

	public function test_a_previously_running_attempt_does_not_hide_the_next_queued_attempt(): void {
		$active                           = $this->admit( 'active' );
		$waiting                          = $this->admit( 'waiting' );
		$this->database->rows[0]['state'] = DeploymentState::RUNNING->value;

		$result = $this->worker()->run_once();

		self::assertSame( 'processed', $result['status'] );
		self::assertSame( $waiting->get_correlation_id(), $result['correlation_id'] );
		self::assertSame( array( $waiting->get_id() ), $this->coordinator->attempt_ids );
		self::assertSame( 'running', $this->database->rows[0]['state'] );
		self::assertSame( 'running', $this->database->rows[1]['state'] );
	}

	public function test_coordinator_throwable_leaves_the_running_attempt_protected(): void {
		$attempt                    = $this->admit( 'failure' );
		$this->coordinator->failure = new RuntimeException( 'sensitive execution failure' );

		$result = $this->worker()->run_once();

		self::assertSame( 'unavailable', $result['status'] );
		self::assertSame( DeploymentState::RUNNING, $this->attempts->find_exact( $attempt->get_id() )?->get_state() );
	}

	private function worker(): DeploymentWorker {
		return new DeploymentWorker( $this->attempts, $this->coordinator, new WordPressWorkerWakeup( $this->attempts ) );
	}

	private function admit( string $slug ): DeploymentAttempt {
		return $this->attempts->admit_webhook_batch(
			'gh',
			'delivery-' . $slug,
			hash( 'sha256', 'delivery-' . $slug ),
			array(
				array(
					'operation'               => 'update',
					'package_type'            => 'plugin',
					'provider_repository_id'  => 'R_' . $slug,
					'requested_ref'           => 'main',
					'package_source'          => 'branch',
					'package_source_revision' => 1,
					'request'                 => $this->request( $slug ),
				),
			)
		)[0];
	}

	private function request( string $slug ): DeploymentRequest {
		return new DeploymentRequest( 'org/' . $slug, null, false, 'main', $slug, null, DeploymentPolicy::AUTOMATIC, 1 );
	}
}

// phpcs:disable Generic.Files.OneObjectStructurePerFile -- Purpose-built worker spy.
final class WorkerCoordinator extends DeploymentCoordinator {

	public int $calls = 0;
	/** @var list<int> */
	public array $attempt_ids          = array();
	public ?RuntimeException $failure = null;

	public function __construct() {
	}

	public function execute_claimed( DeploymentAttempt $attempt ): DeploymentOutcome {
		++$this->calls;
		$this->attempt_ids[] = $attempt->get_id();
		if ( null !== $this->failure ) {
			throw $this->failure;
		}

		return DeploymentOutcome::from_code( DeploymentOutcome::CODE_DEPLOYED );
	}
}
// phpcs:enable Generic.Files.OneObjectStructurePerFile
