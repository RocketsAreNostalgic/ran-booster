<?php

declare(strict_types=1);

namespace RAN\Tests\Deployment;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use RAN\Deployment\DeploymentAttempt;
use RAN\Deployment\DeploymentAttemptRepository;
use RAN\Deployment\DeploymentOutcome;
use RAN\Deployment\DeploymentPolicy;
use RAN\Deployment\DeploymentRequest;
use RAN\Deployment\DeploymentState;
use RAN\Deployment\DeploymentStorageFailure;
use RAN\RepositoryProvider\ProviderCode;
use RAN\Storage\Database;
use RAN\Storage\DatabaseLifecycleFailure;

require_once __DIR__ . '/AttemptRepositoryDatabase.php';

final class DeploymentAttemptRepositoryTest extends TestCase {

	private AttemptRepositoryDatabase $database;
	private DeploymentAttemptRepository $repository;
	private Database $database_lifecycle;
	private int $random_byte = 1;

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this exact lifecycle override name.
	protected function setUp(): void {
		$this->database                               = new AttemptRepositoryDatabase();
		$this->database_lifecycle                     = $this->createStub( Database::class );
		$GLOBALS['ran_booster_attempt_cache_deletes'] = array();
		$this->repository                             = new DeploymentAttemptRepository(
			$this->database,
			'wp_ran_booster_deployment_attempts',
			static fn (): DateTimeImmutable => new DateTimeImmutable( '2026-07-19 00:00:00 UTC' ),
			function ( int $length ): string {
				return str_repeat( chr( $this->random_byte++ ), $length );
			},
			$this->database_lifecycle
		);
	}

	public function test_read_only_connection_cannot_start_a_deployment_transaction(): void {
		$database   = new class( $this->database ) implements \RAN\Storage\SqlReadConnection {
			public string $last_error = '';
			public function __construct( private AttemptRepositoryDatabase $database ) {}
			public function prepare( string $query, mixed ...$arguments ): string {
				return $this->database->prepare( $query, ...$arguments );
			}
			/** @return list<object>|null */
			public function get_results( string $query ): ?array {
				return $this->database->get_results( $query );
			}
			public function query( string $query ): int|false {
				return $this->database->query( $query );
			}
		};
		$repository = new DeploymentAttemptRepository( $database, 'wp_attempts', database_lifecycle: $this->createStub( Database::class ) );
		try {
			$repository->claim_next();
			self::fail( 'A reader must not begin a deployment transaction.' );
		} catch ( DeploymentStorageFailure ) {
			self::assertSame( array(), $this->database->queries );
		}
	}

	public function test_undeclared_connection_is_rejected_after_lifecycle_readiness(): void {
		$lifecycle = $this->createMock( Database::class );
		$lifecycle->expects( self::once() )->method( 'require_ready' );
		$database   = new class( $this->database ) {
			public string $last_error = '';
			public function __construct( private AttemptRepositoryDatabase $database ) {}
			/** @param array<array-key, mixed> $arguments */
			public function __call( string $method, array $arguments ): mixed {
				return $this->database->{$method}( ...$arguments );
			}
		};
		$repository = new DeploymentAttemptRepository( $database, 'wp_attempts', database_lifecycle: $lifecycle );
		$this->expectException( DeploymentStorageFailure::class );
		$repository->find_exact( 1 );
	}

	public function test_manual_admission_and_claim_are_one_atomic_transaction(): void {
		$attempt = $this->manual( 'example' );

		self::assertSame( DeploymentState::RUNNING, $attempt->get_state() );
		self::assertSame( 'example', $attempt->get_request()->package_slug );
		self::assertCount( 1, $this->database->rows );
		self::assertSame( $attempt->get_request()->to_json(), $this->database->rows[0]['request_json'] );
		self::assertSame( 'SET TRANSACTION ISOLATION LEVEL SERIALIZABLE', $this->database->queries[0] );
		self::assertSame( 'START TRANSACTION', $this->database->queries[1] );
		$last_key = array_key_last( $this->database->queries );
		self::assertNotNull( $last_key );
		self::assertSame( 'COMMIT', $this->database->queries[ $last_key ] );
		self::assertStringContainsString( "SET state = 'running' WHERE id = 1 AND state = 'queued'", implode( "\n", $this->database->queries ) );
	}

	public function test_unresolved_package_attempt_read_tracks_the_removal_contention_boundary(): void {
		$attempt = $this->manual( 'example' );

		self::assertTrue( $this->repository->has_unresolved_package_attempt( 'plugin', 'example' ) );
		$this->repository->finish(
			$attempt->get_id(),
			DeploymentOutcome::from_code( DeploymentOutcome::CODE_DEPLOYED )
		);
		self::assertFalse( $this->repository->has_unresolved_package_attempt( 'plugin', 'example' ) );
	}

	public function test_attempt_row_limit_configuration_defaults_and_only_accepts_canonical_raised_integers(): void {
		self::assertSame(
			array(
				'valid'        => true,
				'maximum_rows' => 200,
				'source'       => 'default',
			),
			$this->repository->retention_configuration_status()
		);

		foreach ( array( 199, 100001, '250', 250.0, true ) as $invalid ) {
			self::assertSame(
				array(
					'valid'        => false,
					'maximum_rows' => 200,
					'source'       => 'configured',
				),
				$this->repository_with_maximum( $invalid )->retention_configuration_status()
			);
		}

		foreach ( array( 200, 250, 100000 ) as $valid ) {
			self::assertSame(
				array(
					'valid'        => true,
					'maximum_rows' => $valid,
					'source'       => 'configured',
				),
				$this->repository_with_maximum( $valid )->retention_configuration_status()
			);
		}
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_wp_config_constant_raises_the_attempt_row_limit(): void {
		define( 'RAN_BOOSTER_MAX_ATTEMPT_ROWS', 350 );

		self::assertSame(
			array(
				'valid'        => true,
				'maximum_rows' => 350,
				'source'       => 'configured',
			),
			$this->repository_with_maximum()->retention_configuration_status()
		);
	}

	public function test_single_admission_prunes_existing_overflow_and_the_oldest_tied_terminal_rows(): void {
		$this->seed_attempts( array_fill( 0, 205, DeploymentState::SUCCEEDED->value ) );

		$attempt = $this->manual( 'new-attempt' );

		self::assertSame( DeploymentState::RUNNING, $attempt->get_state() );
		self::assertCount( 200, $this->database->rows );
		$ids = array_column( $this->database->rows, 'id' );
		if ( array() === $ids ) {
			self::fail( 'Retained deployment rows must expose their IDs.' );
		}
		self::assertSame( 7, min( $ids ) );
		self::assertStringContainsString(
			"SELECT id FROM `wp_ran_booster_deployment_attempts` WHERE (state IN ('succeeded','failed') OR (state = 'needs_attention' AND resolved_at IS NOT NULL AND resolved_by IS NOT NULL)) ORDER BY created_at, id LIMIT 6 FOR UPDATE",
			implode( "\n", $this->database->queries )
		);
	}

	public function test_admission_at_one_hundred_ninety_nine_rows_fills_before_the_next_admission_prunes(): void {
		$this->seed_attempts( array_fill( 0, 199, DeploymentState::SUCCEEDED->value ) );

		$this->manual( 'fills-capacity' );

		self::assertCount( 200, $this->database->rows );
		self::assertStringNotContainsString( 'DELETE FROM', implode( "\n", $this->database->queries ) );
		$this->database->queries = array();

		$this->manual( 'requires-pruning' );

		self::assertCount( 200, $this->database->rows );
		self::assertStringContainsString( 'DELETE FROM', implode( "\n", $this->database->queries ) );
	}

	public function test_protected_rows_exhaust_capacity_without_mutation(): void {
		$this->seed_attempts( array_fill( 0, 200, DeploymentState::QUEUED->value ) );
		$before = $this->database->rows;

		try {
			$this->manual( 'new-attempt' );
			self::fail( 'Protected deployment work must exhaust storage safely.' );
		} catch ( DeploymentStorageFailure $failure ) {
			self::assertTrue( $failure->is_capacity_exhausted() );
			self::assertStringContainsString( 'Resolve queued, running, or needs-attention deployments', $failure->getMessage() );
		}

		self::assertSame( $before, $this->database->rows );
		$last_key = array_key_last( $this->database->queries );
		self::assertNotNull( $last_key );
		self::assertSame( 'ROLLBACK', $this->database->queries[ $last_key ] );
	}

	public function test_manual_batch_reserves_only_eligible_rows_and_never_prunes_protected_work(): void {
		$states    = array( DeploymentState::SUCCEEDED->value, DeploymentState::QUEUED->value );
		$states    = array_merge( $states, array_fill( 0, 198, DeploymentState::RUNNING->value ) );
		$overrides = array( 2 => 'busy' );
		$this->seed_attempts( $states, $overrides );

		$result = $this->repository->admit_manual_batch(
			array(
				$this->manual_target( 'busy' ),
				$this->manual_target( 'available' ),
			)
		);

		self::assertCount( 1, $result['busy'] );
		self::assertCount( 1, $result['admitted'] );
		self::assertCount( 200, $this->database->rows );
		self::assertNotContains( 1, array_column( $this->database->rows, 'id' ) );
		self::assertContains( 2, array_column( $this->database->rows, 'id' ) );
		self::assertSame( 'available', $result['admitted'][0]->get_request()->package_slug );
	}

	public function test_webhook_batch_and_zero_target_acknowledgement_reserve_their_exact_rows(): void {
		$this->seed_attempts( array_fill( 0, 200, DeploymentState::FAILED->value ) );

		$attempts = $this->repository->admit_webhook_batch(
			'gh',
			'delivery-batch',
			hash( 'sha256', 'delivery-batch' ),
			array( $this->target( 'alpha' ), $this->target( 'beta' ) )
		);

		self::assertCount( 2, $attempts );
		self::assertCount( 200, $this->database->rows );
		self::assertNotContains( 1, array_column( $this->database->rows, 'id' ) );
		self::assertNotContains( 2, array_column( $this->database->rows, 'id' ) );

		$this->repository->admit_webhook_batch( 'gh', 'delivery-empty', hash( 'sha256', 'delivery-empty' ), array() );

		self::assertCount( 200, $this->database->rows );
		$last_key = array_key_last( $this->database->rows );
		self::assertNotNull( $last_key );
		self::assertSame( 'delivery', $this->database->rows[ $last_key ]['package_type'] );
	}

	public function test_replay_returns_before_capacity_accounting(): void {
		$this->repository->admit_webhook_batch( 'gh', 'delivery-replay', hash( 'sha256', 'delivery-replay' ), array() );
		$this->seed_attempts( array_fill( 0, 199, DeploymentState::SUCCEEDED->value ), array(), 2 );
		$this->database->queries = array();

		$result = $this->repository->admit_webhook_batch(
			'gh',
			'delivery-replay',
			hash( 'sha256', 'delivery-replay' ),
			array( $this->target( 'newly-managed' ) )
		);

		self::assertSame( array(), $result );
		self::assertCount( 200, $this->database->rows );
		self::assertStringNotContainsString( 'COUNT(*) AS total', implode( "\n", $this->database->queries ) );
		self::assertStringNotContainsString( 'DELETE FROM', implode( "\n", $this->database->queries ) );
	}

	public function test_pruning_and_partial_batch_insert_roll_back_together(): void {
		$this->seed_attempts( array_fill( 0, 200, DeploymentState::SUCCEEDED->value ) );
		$before                             = $this->database->rows;
		$this->database->fail_insert_number = 2;

		try {
			$this->repository->admit_manual_batch(
				array(
					$this->manual_target( 'alpha' ),
					$this->manual_target( 'beta' ),
				)
			);
			self::fail( 'A partially inserted capacity reservation must roll back.' );
		} catch ( DeploymentStorageFailure ) {
			self::assertSame( $before, $this->database->rows );
			$last_key = array_key_last( $this->database->queries );
			self::assertNotNull( $last_key );
			self::assertSame( 'ROLLBACK', $this->database->queries[ $last_key ] );
		}
	}

	public function test_delete_and_commit_failures_restore_pruned_rows(): void {
		foreach ( array( 'delete', 'commit' ) as $failure ) {
			$this->database   = new AttemptRepositoryDatabase();
			$this->repository = $this->repository_with_maximum();
			$this->seed_attempts( array_fill( 0, 200, DeploymentState::SUCCEEDED->value ) );
			$before = $this->database->rows;

			$this->database->fail_query_contains = 'delete' === $failure ? 'DELETE FROM' : null;
			$this->database->fail_commit         = 'commit' === $failure;
			try {
				$this->manual( $failure . '-failure' );
				self::fail( 'Capacity pruning must roll back when its transaction fails.' );
			} catch ( DeploymentStorageFailure $storage_failure ) {
				self::assertSame( $before, $this->database->rows );
				$last_key = array_key_last( $this->database->queries );
				self::assertNotNull( $last_key );
				self::assertSame( 'ROLLBACK', $this->database->queries[ $last_key ] );
			}
		}
	}

	public function test_cursor_history_continues_across_more_than_one_hundred_retained_rows(): void {
		$this->repository = $this->repository_with_maximum( 250 );
		$this->seed_attempts( array_fill( 0, 210, DeploymentState::SUCCEEDED->value ) );

		$first    = $this->repository->recent_history( 100 );
		$last_key = array_key_last( $first );
		self::assertNotNull( $last_key );
		$second   = $this->repository->recent_history( 100, $first[ $last_key ]->get_id() );
		$last_key = array_key_last( $second );
		self::assertNotNull( $last_key );
		$third = $this->repository->recent_history( 100, $second[ $last_key ]->get_id() );

		self::assertCount( 100, $first );
		self::assertCount( 100, $second );
		self::assertCount( 10, $third );
		self::assertSame( 210, $first[0]->get_id() );
		$last_key = array_key_last( $third );
		self::assertNotNull( $last_key );
		self::assertSame( 1, $third[ $last_key ]->get_id() );
	}

	public function test_unsupported_database_blocks_attempt_reads_and_admissions_before_table_access(): void {
		$this->database->server_info = '5.7.44';
		$this->repository            = $this->repository_with_maximum( database_lifecycle: new Database( $this->database ) );

		try {
			$this->repository->recent_history();
			self::fail( 'Unsupported history reads must fail closed.' );
		} catch ( DeploymentStorageFailure $failure ) {
			self::assertTrue( $failure->is_database_unsupported() );
		}

		try {
			$this->manual( 'unsupported' );
			self::fail( 'Unsupported admissions must fail closed.' );
		} catch ( DeploymentStorageFailure $failure ) {
			self::assertTrue( $failure->is_database_unsupported() );
		}

		self::assertSame( array(), $this->database->queries );
		self::assertSame( array(), $this->database->rows );
	}

	public function test_lifecycle_safe_state_blocks_attempt_reads_and_admissions_before_table_access(): void {
		$lifecycle = $this->createStub( Database::class );
		$lifecycle->method( 'require_ready' )->willThrowException( new DatabaseLifecycleFailure( failure_reason: 'schema_operation_failed' ) );
		$this->database->fail_reads = true;
		$this->database->queries    = array();
		$this->repository           = $this->repository_with_maximum( database_lifecycle: $lifecycle );
		foreach ( array(
			fn () => $this->repository->recent_history(),
			fn () => $this->manual( 'blocked' ),
		) as $operation ) {
			try {
				$operation();
				self::fail( 'Cached lifecycle failures must block deployment-attempt storage.' );
			} catch ( DeploymentStorageFailure $failure ) {
				self::assertTrue( $failure->is_database_unsupported() );
			}
		}

		self::assertSame( '', $this->database->last_error );
		self::assertSame( array(), $this->database->queries );
		self::assertSame( array(), $this->database->rows );
	}

	public function test_manual_admission_rejects_an_existing_active_package_attempt(): void {
		$active = $this->manual( 'example' );

		try {
			$this->manual( 'example' );
			self::fail( 'A second manual mutation must not overlap an active attempt.' );
		} catch ( DeploymentStorageFailure $failure ) {
			self::assertSame( $active->get_correlation_id(), $failure->get_active_correlation_id() );
			self::assertCount( 1, $this->database->rows );
			$last_key = array_key_last( $this->database->queries );
			self::assertNotNull( $last_key );
			self::assertSame( 'ROLLBACK', $this->database->queries[ $last_key ] );
		}
	}

	public function test_insert_and_readback_failures_fail_closed(): void {
		$this->database->fail_insert = true;
		try {
			$this->manual( 'insert-failure' );
			self::fail( 'Insert failure must fail closed.' );
		} catch ( DeploymentStorageFailure ) {
			self::assertCount( 0, $this->database->rows );
		}

		$this->database->fail_insert          = false;
		$this->database->tamper_insert_column = 'request_json';
		$this->database->tamper_insert_value  = '{}';
		$this->expectException( DeploymentStorageFailure::class );
		$this->manual( 'readback-failure' );
	}

	public function test_manual_claim_or_commit_failure_rolls_back_the_admission(): void {
		foreach ( array( 'claim', 'commit' ) as $failure ) {
			$this->database->zero_query_contains = 'claim' === $failure ? "SET state = 'running'" : null;
			$this->database->fail_commit         = 'commit' === $failure;

			try {
				$this->manual( $failure . '-failure' );
				self::fail( 'Manual admission and claim must fail atomically.' );
			} catch ( DeploymentStorageFailure ) {
				self::assertSame( array(), $this->database->rows );
				$last_key = array_key_last( $this->database->queries );
				self::assertNotNull( $last_key );
				self::assertSame( 'ROLLBACK', $this->database->queries[ $last_key ] );
			}
			$this->database->queries = array();
		}
	}

	public function test_valid_but_different_insert_identity_fails_readback(): void {
		$this->database->tamper_insert_column = 'provider_repository_id';
		$this->database->tamper_insert_value  = 'R_different';

		try {
			$this->manual( 'example' );
			self::fail( 'A changed provider identity must fail readback.' );
		} catch ( DeploymentStorageFailure ) {
			self::assertSame( array(), $this->database->rows );
		}
	}

	public function test_valid_but_different_delivery_digest_fails_readback(): void {
		$this->database->tamper_insert_column = 'delivery_digest';
		$this->database->tamper_insert_value  = str_repeat( 'e', 64 );

		try {
			$this->repository->admit_webhook_batch( 'gh', 'delivery-1', str_repeat( 'd', 64 ), array( $this->target( 'example' ) ) );
			self::fail( 'A changed delivery digest must fail readback.' );
		} catch ( DeploymentStorageFailure ) {
			self::assertSame( array(), $this->database->rows );
		}
	}

	public function test_webhook_batch_is_sorted_and_committed_atomically(): void {
		$attempts = $this->repository->admit_webhook_batch(
			'gh',
			'delivery-1',
			str_repeat( 'd', 64 ),
			array( $this->target( 'zeta', 'theme' ), $this->target( 'alpha', 'plugin' ) )
		);

		self::assertSame( array( 'alpha', 'zeta' ), array_map( static fn ( DeploymentAttempt $attempt ): string => $attempt->get_request()->package_slug, $attempts ) );
		$last_key = array_key_last( $this->database->queries );
		self::assertNotNull( $last_key );
		self::assertSame( 'COMMIT', $this->database->queries[ $last_key ] );
		// The integration gate exercises this ordering with two real connections whose session default is READ COMMITTED.
		self::assertSame( 'SET TRANSACTION ISOLATION LEVEL SERIALIZABLE', $this->database->queries[0] );
		self::assertSame( 'START TRANSACTION', $this->database->queries[1] );
		self::assertStringContainsString( 'ORDER BY package_type, package_slug FOR UPDATE', implode( "\n", $this->database->queries ) );
	}

	public function test_manual_batch_is_sorted_and_committed_as_queued(): void {
		$result = $this->repository->admit_manual_batch(
			array(
				$this->manual_target( 'zeta', 'theme' ),
				$this->manual_target( 'alpha', 'plugin' ),
			)
		);

		self::assertSame( array( 'alpha', 'zeta' ), array_map( static fn ( DeploymentAttempt $attempt ): string => $attempt->get_request()->package_slug, $result['admitted'] ) );
		self::assertSame( array(), $result['busy'] );
		self::assertSame( array( 'manual', 'manual' ), array_column( $this->database->rows, 'source' ) );
		self::assertSame( array( 'queued', 'queued' ), array_column( $this->database->rows, 'state' ) );
		self::assertSame( 'SET TRANSACTION ISOLATION LEVEL SERIALIZABLE', $this->database->queries[0] );
		$last_key = array_key_last( $this->database->queries );
		self::assertNotNull( $last_key );
		self::assertSame( 'COMMIT', $this->database->queries[ $last_key ] );
	}

	public function test_manual_batch_reports_busy_and_admits_the_remaining_target(): void {
		$active                           = $this->manual( 'active' );
		$this->database->rows[0]['state'] = DeploymentState::QUEUED->value;

		$result = $this->repository->admit_manual_batch(
			array(
				$this->manual_target( 'active' ),
				$this->manual_target( 'available' ),
			)
		);

		self::assertCount( 1, $result['busy'] );
		self::assertSame( $active->get_correlation_id(), $result['busy'][0]['correlation_id'] );
		self::assertCount( 1, $result['admitted'] );
		self::assertSame( 'available', $result['admitted'][0]->get_request()->package_slug );
		self::assertCount( 2, $this->database->rows );
	}

	public function test_terminal_manual_history_does_not_block_a_new_batch_attempt(): void {
		$running = $this->manual( 'example' );
		$this->repository->finish( $running->get_id(), DeploymentOutcome::from_code( DeploymentOutcome::CODE_PREFLIGHT_FAILED ) );

		$result = $this->repository->admit_manual_batch( array( $this->manual_target( 'example' ) ) );

		self::assertCount( 1, $result['admitted'] );
		self::assertSame( array(), $result['busy'] );
	}

	public function test_needs_attention_history_blocks_a_new_batch_attempt(): void {
		$running = $this->manual( 'example' );
		$this->repository->finish( $running->get_id(), DeploymentOutcome::from_code( DeploymentOutcome::CODE_INTERRUPTED ) );
		$this->database->queries = array();

		$result = $this->repository->admit_manual_batch( array( $this->manual_target( 'example' ) ) );

		self::assertSame( array(), $result['admitted'] );
		self::assertCount( 1, $result['busy'] );
		self::assertSame( DeploymentState::NEEDS_ATTENTION->value, $result['busy'][0]['state'] );
		self::assertStringNotContainsString( 'COUNT(*) AS total', implode( "\n", $this->database->queries ) );
	}

	public function test_exact_batch_reads_only_requested_attempts_in_one_query(): void {
		$first  = $this->manual( 'first' );
		$second = $this->manual( 'second' );
		$third  = $this->manual( 'third' );
		$before = count( $this->database->queries );

		$found = $this->repository->find_exact_batch( array( $third->get_id(), $first->get_id() ) );

		self::assertSame( array( $first->get_id(), $third->get_id() ), array_keys( $found ) );
		self::assertArrayNotHasKey( $second->get_id(), $found );
		self::assertCount( 1, array_slice( $this->database->queries, $before ) );
	}

	public function test_manual_batch_rejects_duplicates_and_rolls_back_insert_failure(): void {
		try {
			$this->repository->admit_manual_batch( array( $this->manual_target( 'duplicate' ), $this->manual_target( 'duplicate' ) ) );
			self::fail( 'Duplicate manual targets must be rejected.' );
		} catch ( DeploymentStorageFailure ) {
			self::assertSame( array(), $this->database->queries );
		}

		$release_target                   = $this->manual_target( 'release-managed' );
		$release_target['package_source'] = 'release_asset';
		try {
			$this->repository->admit_manual_batch( array( $release_target ) );
			self::fail( 'The branch queue must reject release-managed targets.' );
		} catch ( DeploymentStorageFailure ) {
			// @phpstan-ignore staticMethod.alreadyNarrowedType (Repeat the assertion after external database filesystem or scheduler state changes; earlier narrowing must not replace the runtime check.)
			self::assertSame( array(), $this->database->queries );
		}

		$this->database->fail_insert_number = 2;
		try {
			$this->repository->admit_manual_batch( array( $this->manual_target( 'alpha' ), $this->manual_target( 'beta' ) ) );
			self::fail( 'A partial manual batch must not survive.' );
		} catch ( DeploymentStorageFailure ) {
			self::assertSame( array(), $this->database->rows );
			$last_key = array_key_last( $this->database->queries );
			self::assertNotNull( $last_key );
			self::assertSame( 'ROLLBACK', $this->database->queries[ $last_key ] );
		}
	}

	public function test_webhook_admission_fails_before_transaction_when_serializable_isolation_is_unavailable(): void {
		$this->database->fail_query_contains = 'SET TRANSACTION ISOLATION LEVEL SERIALIZABLE';

		try {
			$this->repository->admit_webhook_batch( 'gh', 'delivery-1', str_repeat( 'd', 64 ), array( $this->target( 'example' ) ) );
			self::fail( 'Webhook admission must not run without serializable isolation.' );
		} catch ( DeploymentStorageFailure ) {
			self::assertSame( array( 'SET TRANSACTION ISOLATION LEVEL SERIALIZABLE' ), $this->database->queries );
			self::assertSame( array(), $this->database->rows );
		}
	}

	public function test_webhook_replay_returns_existing_rows_without_duplicates(): void {
		$targets = array( $this->target( 'example' ) );
		$first   = $this->repository->admit_webhook_batch( 'gh', 'delivery-1', str_repeat( 'd', 64 ), $targets );
		$replay  = $this->repository->admit_webhook_batch( 'gh', 'delivery-1', str_repeat( 'd', 64 ), $targets );

		self::assertSame( $first[0]->get_id(), $replay[0]->get_id() );
		self::assertCount( 1, $this->database->rows );
	}

	public function test_webhook_replay_cannot_add_a_newly_matching_target(): void {
		$first  = $this->repository->admit_webhook_batch( 'gh', 'delivery-1', str_repeat( 'd', 64 ), array( $this->target( 'original' ) ) );
		$replay = $this->repository->admit_webhook_batch(
			'gh',
			'delivery-1',
			str_repeat( 'd', 64 ),
			array( $this->target( 'original' ), $this->target( 'newly-managed' ) )
		);

		self::assertCount( 1, $replay );
		self::assertSame( $first[0]->get_id(), $replay[0]->get_id() );
		self::assertSame( 'original', $replay[0]->get_request()->package_slug );
		self::assertCount( 1, $this->database->rows );
	}

	public function test_different_delivery_digest_rolls_back_without_adding_rows(): void {
		$this->repository->admit_webhook_batch( 'gh', 'delivery-1', str_repeat( 'd', 64 ), array( $this->target( 'existing' ) ) );

		try {
			$this->repository->admit_webhook_batch( 'gh', 'delivery-1', str_repeat( 'e', 64 ), array( $this->target( 'new' ) ) );
			self::fail( 'Digest reuse must fail.' );
		} catch ( DeploymentStorageFailure $failure ) {
			self::assertStringContainsString( 'different authenticated content', $failure->getMessage() );
			self::assertCount( 1, $this->database->rows );
			$last_key = array_key_last( $this->database->queries );
			self::assertNotNull( $last_key );
			self::assertSame( 'ROLLBACK', $this->database->queries[ $last_key ] );
		}
	}

	public function test_second_target_insert_failure_rolls_back_the_whole_batch(): void {
		$this->database->fail_insert_number = 2;

		try {
			$this->repository->admit_webhook_batch(
				'gh',
				'delivery-1',
				str_repeat( 'd', 64 ),
				array( $this->target( 'alpha' ), $this->target( 'beta' ) )
			);
			self::fail( 'Partial batch must not survive.' );
		} catch ( DeploymentStorageFailure ) {
			self::assertCount( 0, $this->database->rows );
		}
	}

	public function test_commit_failure_rolls_back_and_is_reported_distinctly(): void {
		$this->database->fail_commit = true;

		try {
			$this->repository->admit_webhook_batch( 'gh', 'delivery-1', str_repeat( 'd', 64 ), array( $this->target( 'example' ) ) );
			self::fail( 'Commit failure must fail closed.' );
		} catch ( DeploymentStorageFailure $failure ) {
			self::assertSame( 'RAN Booster could not commit deployment state.', $failure->getMessage() );
			self::assertCount( 0, $this->database->rows );
		}
	}

	public function test_zero_target_webhook_stores_a_durable_acknowledgement_and_freezes_the_empty_set(): void {
		self::assertSame( array(), $this->repository->admit_webhook_batch( 'gh', 'delivery-1', str_repeat( 'd', 64 ), array() ) );
		self::assertCount( 1, $this->database->rows );
		self::assertSame( 'delivery', $this->database->rows[0]['package_type'] );
		self::assertSame( 'succeeded', $this->database->rows[0]['state'] );

		self::assertSame(
			array(),
			$this->repository->admit_webhook_batch( 'gh', 'delivery-1', str_repeat( 'd', 64 ), array( $this->target( 'newly-managed' ) ) )
		);
		self::assertCount( 1, $this->database->rows );
		self::assertSame( array(), $this->repository->recent_history() );
	}

	public function test_latest_authenticated_delivery_evidence_is_provider_scoped_and_distinguishes_matches(): void {
		self::assertNull( $this->repository->latest_authenticated_delivery( ProviderCode::parse( 'gh' ) ) );

		$this->repository->admit_webhook_batch( 'gh', 'delivery-empty', str_repeat( 'd', 64 ), array() );
		$this->repository->admit_webhook_batch( 'bb', 'delivery-bitbucket', str_repeat( 'b', 64 ), array( $this->target( 'bitbucket' ) ) );

		$github_evidence    = $this->repository->latest_authenticated_delivery( ProviderCode::parse( 'gh' ) );
		$bitbucket_evidence = $this->repository->latest_authenticated_delivery( ProviderCode::parse( 'bb' ) );

		// @phpstan-ignore staticMethod.impossibleType (Repeat the assertion after external database filesystem or scheduler state changes; earlier narrowing must not replace the runtime check.)
		self::assertNotNull( $github_evidence );
		self::assertTrue( $github_evidence->provider->equals( ProviderCode::parse( 'gh' ) ) );
		self::assertSame( '2026-07-19 00:00:00', $github_evidence->received_at );
		self::assertFalse( $github_evidence->matched_managed_package );
		self::assertNotNull( $bitbucket_evidence );
		self::assertTrue( $bitbucket_evidence->matched_managed_package );

		$this->repository->admit_webhook_batch( 'gh', 'delivery-matched', str_repeat( 'e', 64 ), array( $this->target( 'github' ) ) );

		self::assertTrue( $this->repository->latest_authenticated_delivery( ProviderCode::parse( 'gh' ) )?->matched_managed_package );
	}

	public function test_claim_transition_is_one_atomic_transaction(): void {
		$queued                  = $this->webhook( 'first' );
		$this->database->queries = array();
		$running                 = $this->repository->claim_next();

		self::assertNotNull( $running );
		self::assertSame( $queued->get_id(), $running->get_id() );
		self::assertSame( DeploymentState::RUNNING, $running->get_state() );
		self::assertSame( 'START TRANSACTION', $this->database->queries[0] );
		$last_key = array_key_last( $this->database->queries );
		self::assertNotNull( $last_key );
		self::assertSame( 'COMMIT', $this->database->queries[ $last_key ] );
		self::assertStringNotContainsString( 'wp_options', implode( "\n", $this->database->queries ) );
	}

	public function test_running_attempt_does_not_hide_the_next_queued_webhook(): void {
		$active  = $this->manual( 'active' );
		$waiting = $this->webhook( 'waiting' );

		$second = $this->repository->claim_next();

		self::assertNotNull( $second );
		self::assertSame( $waiting->get_id(), $second->get_id() );
		self::assertSame( DeploymentState::RUNNING, $active->get_state() );
		self::assertSame( DeploymentState::RUNNING, $second->get_state() );
	}

	public function test_claim_transition_failure_rolls_back_its_state_change(): void {
		$this->webhook( 'example' );
		$this->database->zero_query_contains = 'UPDATE `wp_ran_booster_deployment_attempts`';

		try {
			$this->repository->claim_next();
			self::fail( 'A failed transition must roll back.' );
		} catch ( DeploymentStorageFailure ) {
			self::assertSame( 'queued', $this->database->rows[0]['state'] );
			$last_key = array_key_last( $this->database->queries );
			self::assertNotNull( $last_key );
			self::assertSame( 'ROLLBACK', $this->database->queries[ $last_key ] );
		}
	}

	public function test_resolved_ref_fence_and_terminal_outcome_are_written_and_read_back(): void {
		$running  = $this->manual( 'example' );
		$resolved = $this->repository->record_resolved_ref( $running->get_id(), str_repeat( 'a', 40 ) );
		$fenced   = $this->repository->mark_mutation_started( $running->get_id() );
		$finished = $this->repository->finish( $running->get_id(), DeploymentOutcome::from_code( DeploymentOutcome::CODE_DEPLOYED ) );

		self::assertSame( str_repeat( 'a', 40 ), $resolved->safe_data()['resolved_ref'] );
		self::assertNotNull( $fenced->safe_data()['mutation_started_at'] );
		self::assertSame( DeploymentState::SUCCEEDED, $finished->get_state() );
		self::assertSame( 'deployed', $finished->get_outcome()?->get_code() );
	}

	public function test_valid_but_different_terminal_timestamp_fails_readback(): void {
		$running                              = $this->manual( 'example' );
		$this->database->tamper_update_column = 'finished_at';
		$this->database->tamper_update_value  = '2026-07-19 00:00:01';

		$this->expectException( DeploymentStorageFailure::class );
		$this->repository->finish( $running->get_id(), DeploymentOutcome::from_code( DeploymentOutcome::CODE_DEPLOYED ) );
	}

	public function test_write_failures_and_empty_query_are_not_conflated(): void {
		self::assertSame( array(), $this->repository->recent_history() );
		$this->database->fail_reads = true;

		$this->expectException( DeploymentStorageFailure::class );
		$this->repository->recent_history();
	}

	public function test_pre_fence_reconciliation_fails_the_running_attempt(): void {
		$running = $this->manual( 'example' );
		$result  = $this->repository->reconcile_confirmed_stopped( $running->get_id() );

		self::assertSame( DeploymentState::FAILED, $result->get_state() );
		self::assertSame( 'worker_stopped', $result->get_outcome()?->get_code() );
	}

	public function test_post_fence_reconciliation_requires_attention(): void {
		$running = $this->manual( 'example' );
		$this->repository->mark_mutation_started( $running->get_id() );
		$result = $this->repository->reconcile_confirmed_stopped( $running->get_id() );

		self::assertSame( DeploymentState::NEEDS_ATTENTION, $result->get_state() );
		self::assertSame( 'interrupted', $result->get_outcome()?->get_code() );
	}

	public function test_exact_operator_resolution_preserves_outcome_and_allows_retry(): void {
		$running = $this->manual( 'example' );
		$this->repository->mark_mutation_started( $running->get_id() );
		$attention = $this->repository->reconcile_confirmed_stopped( $running->get_id() );

		try {
			$this->manual( 'example' );
			self::fail( 'An unresolved needs-attention attempt must block admission.' );
		} catch ( DeploymentStorageFailure $failure ) {
			self::assertSame( $attention->safe_data()['id'], $failure->get_active_attempt()['id'] ?? null );
			self::assertSame( 'needs_attention', $failure->get_active_attempt()['state'] ?? null );
		}

		$resolved = $this->repository->resolve_needs_attention(
			$attention->get_id(),
			$attention->get_correlation_id(),
			7
		);
		$retry    = $this->manual( 'example' );

		self::assertSame( DeploymentState::NEEDS_ATTENTION, $resolved->get_state() );
		self::assertSame( 'interrupted', $resolved->get_outcome()?->get_code() );
		self::assertSame( '2026-07-19 00:00:00', $resolved->safe_data()['resolved_at'] );
		self::assertSame( 7, $resolved->safe_data()['resolved_by'] );
		self::assertFalse( $resolved->requires_operator_resolution() );
		self::assertSame( 0, $this->repository->operational_snapshot()['needs_attention'] );
		self::assertSame( DeploymentState::RUNNING, $retry->get_state() );
	}

	public function test_operator_resolution_requires_the_exact_correlation_reference(): void {
		$running = $this->manual( 'example' );
		$this->repository->mark_mutation_started( $running->get_id() );
		$attention = $this->repository->reconcile_confirmed_stopped( $running->get_id() );

		try {
			$this->repository->resolve_needs_attention( $attention->get_id(), str_repeat( 'f', 32 ), 7 );
			self::fail( 'A mismatched support reference must not resolve the attempt.' );
		} catch ( DeploymentStorageFailure ) {
			$stored = $this->repository->find_exact( $attention->get_id() );
			self::assertTrue( $stored?->requires_operator_resolution() );
			self::assertNull( $stored->safe_data()['resolved_at'] );
		}
	}

	public function test_resolved_needs_attention_rows_are_eligible_for_pruning(): void {
		$this->seed_attempts( array( DeploymentState::NEEDS_ATTENTION->value ) );
		$this->database->rows[0]['resolved_at'] = '2026-07-18 00:02:00';
		$this->database->rows[0]['resolved_by'] = 7;
		$this->seed_attempts( array_fill( 0, 199, DeploymentState::QUEUED->value ), array(), 2 );

		$this->manual( 'new-attempt' );

		self::assertCount( 200, $this->database->rows );
		self::assertNotContains( 1, array_column( $this->database->rows, 'id' ) );
	}

	public function test_reconciliation_hydrates_and_rejects_an_invalid_mutation_fence(): void {
		$running                                        = $this->manual( 'example' );
		$this->database->rows[0]['mutation_started_at'] = 'not-a-date';

		$this->expectException( DeploymentStorageFailure::class );
		$this->repository->reconcile_confirmed_stopped( $running->get_id() );
	}

	public function test_reconciliation_rolls_back_a_tampered_terminal_timestamp(): void {
		$running                              = $this->manual( 'example' );
		$this->database->tamper_update_column = 'finished_at';
		$this->database->tamper_update_value  = '2026-07-19 00:00:01';

		try {
			$this->repository->reconcile_confirmed_stopped( $running->get_id() );
			self::fail( 'A changed reconciliation result must fail readback.' );
		} catch ( DeploymentStorageFailure ) {
			self::assertSame( DeploymentState::RUNNING, $this->repository->find_exact( $running->get_id() )?->get_state() );
		}
	}

	public function test_history_is_bounded_newest_first_and_does_not_expose_secrets_or_raw_json(): void {
		$this->manual( 'first' );
		$this->manual( 'second' );
		$this->manual( 'third' );
		$history = $this->repository->recent_history( 2 );

		self::assertSame( array( 'third', 'second' ), array_map( static fn ( DeploymentAttempt $attempt ): string => $attempt->get_request()->package_slug, $history ) );
		foreach ( $history as $attempt ) {
			self::assertArrayNotHasKey( 'request_json', $attempt->safe_data() );
			self::assertArrayNotHasKey( 'delivery_digest', $attempt->safe_data() );
		}
	}

	public function test_package_activity_summary_reads_latest_and_last_success_in_one_bounded_query(): void {
		$successful = $this->manual( 'example' );
		$this->repository->record_resolved_ref( $successful->get_id(), str_repeat( 'a', 40 ) );
		$successful = $this->repository->finish( $successful->get_id(), DeploymentOutcome::from_code( DeploymentOutcome::CODE_DEPLOYED ) );
		$failed     = $this->manual( 'example' );
		$failed     = $this->repository->finish( $failed->get_id(), DeploymentOutcome::from_code( DeploymentOutcome::CODE_PREFLIGHT_FAILED ) );

		$this->database->queries = array();
		$summary                 = $this->repository->package_activity_summary( 'plugin', 'example' );

		self::assertSame( $failed->get_id(), $summary['latest']?->get_id() );
		self::assertSame( $successful->get_id(), $summary['last_successful']?->get_id() );
		self::assertCount( 1, $this->database->queries );
		self::assertStringContainsString( 'ORDER BY attempts.id DESC LIMIT 2', $this->database->queries[0] );
	}

	public function test_package_activity_summary_fails_closed_when_its_single_read_fails(): void {
		$this->database->fail_reads = true;

		$this->expectException( DeploymentStorageFailure::class );
		$this->repository->package_activity_summary( 'theme', 'example' );
	}

	private function repository_with_maximum(
		mixed $maximum_rows = null,
		?Database $database_lifecycle = null
	): DeploymentAttemptRepository {
		return new DeploymentAttemptRepository(
			$this->database,
			'wp_ran_booster_deployment_attempts',
			static fn (): DateTimeImmutable => new DateTimeImmutable( '2026-07-19 00:00:00 UTC' ),
			function ( int $length ): string {
				return str_repeat( chr( $this->random_byte++ ), $length );
			},
			$database_lifecycle ?? $this->database_lifecycle,
			$maximum_rows
		);
	}

	/**
	 * @param list<string> $states
	 * @param array<int, string> $package_slugs
	 */
	private function seed_attempts( array $states, array $package_slugs = array(), int $first_id = 1 ): void {
		foreach ( $states as $offset => $state ) {
			$id      = $first_id + $offset;
			$slug    = $package_slugs[ $id ] ?? 'seed-' . $id;
			$outcome = match ( $state ) {
				DeploymentState::SUCCEEDED->value       => DeploymentOutcome::CODE_NO_CHANGE,
				DeploymentState::FAILED->value          => DeploymentOutcome::CODE_PREFLIGHT_FAILED,
				DeploymentState::NEEDS_ATTENTION->value => DeploymentOutcome::CODE_INTERRUPTED,
				default                                 => null,
			};

			$this->database->rows[] = array(
				'id'                      => $id,
				'correlation_id'          => str_pad( dechex( $id ), 32, '0', STR_PAD_LEFT ),
				'source'                  => 'manual',
				'operation'               => 'update',
				'package_type'            => 'plugin',
				'package_slug'            => $slug,
				'package_source'          => 'branch',
				'package_source_revision' => 1,
				'provider'                => 'gh',
				'provider_repository_id'  => 'R_' . $slug,
				'requested_ref'           => 'main',
				'resolved_ref'            => null,
				'delivery_id'             => null,
				'delivery_digest'         => null,
				'state'                   => $state,
				'mutation_started_at'     => null,
				'outcome_code'            => $outcome,
				'request_json'            => $this->request( $slug )->to_json(),
				'created_at'              => '2026-07-18 00:00:00',
				'finished_at'             => null === $outcome ? null : '2026-07-18 00:01:00',
				'resolved_at'             => null,
				'resolved_by'             => null,
			);
		}
	}

	private function manual( string $slug ): DeploymentAttempt {
		return $this->repository->admit_and_claim_manual( 'install', 'plugin', 'gh', 'R_' . $slug, $this->request( $slug ), 'main', 'branch', 0 );
	}

	private function webhook( string $slug ): DeploymentAttempt {
		return $this->repository->admit_webhook_batch(
			'gh',
			'delivery-' . $slug,
			hash( 'sha256', 'delivery-' . $slug ),
			array( $this->target( $slug ) )
		)[0];
	}

	/** @return array{operation: string, package_type: string, provider_repository_id: string, requested_ref: string, package_source: string, package_source_revision: int, request: DeploymentRequest} */
	private function target( string $slug, string $type = 'plugin' ): array {
		return array(
			'operation'               => 'update',
			'package_type'            => $type,
			'provider_repository_id'  => 'R_' . $slug,
			'requested_ref'           => str_repeat( 'a', 40 ),
			'package_source'          => 'branch',
			'package_source_revision' => 1,
			'request'                 => $this->request( $slug ),
		);
	}

	/** @return array{package_type: string, provider: string, provider_repository_id: string, requested_ref: string, package_source: string, package_source_revision: int, request: DeploymentRequest} */
	private function manual_target( string $slug, string $type = 'plugin' ): array {
		return array(
			'package_type'            => $type,
			'provider'                => 'gh',
			'provider_repository_id'  => 'R_' . $slug,
			'requested_ref'           => 'main',
			'package_source'          => 'branch',
			'package_source_revision' => 1,
			'request'                 => $this->request( $slug ),
		);
	}

	private function request( string $slug ): DeploymentRequest {
		return new DeploymentRequest( 'org/' . $slug, 'profile_1', true, 'main', $slug, null, DeploymentPolicy::AUTOMATIC, 1 );
	}
}
