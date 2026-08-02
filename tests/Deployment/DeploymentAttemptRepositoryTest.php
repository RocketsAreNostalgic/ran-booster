<?php

declare(strict_types=1);

namespace Tests\Deployment;

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
	private Database $databaseLifecycle;
	private int $randomByte = 1;

	protected function setUp(): void {
		$this->database                               = new AttemptRepositoryDatabase();
		$this->databaseLifecycle                      = $this->createStub( Database::class );
		$GLOBALS['ran_booster_attempt_cache_deletes'] = array();
		$this->repository                             = new DeploymentAttemptRepository(
			$this->database,
			'wp_ran_booster_deployment_attempts',
			static fn (): DateTimeImmutable => new DateTimeImmutable( '2026-07-19 00:00:00 UTC' ),
			function ( int $length ): string {
				return str_repeat( chr( $this->randomByte++ ), $length );
			},
			$this->databaseLifecycle
		);
	}

	public function testManualAdmissionAndClaimAreOneAtomicTransaction(): void {
		$attempt = $this->manual( 'example' );

		self::assertSame( DeploymentState::RUNNING, $attempt->getState() );
		self::assertSame( 'example', $attempt->getRequest()->packageSlug );
		self::assertCount( 1, $this->database->rows );
		self::assertSame( $attempt->getRequest()->toJson(), $this->database->rows[0]['request_json'] );
		self::assertSame( 'SET TRANSACTION ISOLATION LEVEL SERIALIZABLE', $this->database->queries[0] );
		self::assertSame( 'START TRANSACTION', $this->database->queries[1] );
		self::assertSame( 'COMMIT', $this->database->queries[ array_key_last( $this->database->queries ) ] );
		self::assertStringContainsString( "SET state = 'running' WHERE id = 1 AND state = 'queued'", implode( "\n", $this->database->queries ) );
	}

	public function testUnresolvedPackageAttemptReadTracksTheRemovalContentionBoundary(): void {
		$attempt = $this->manual( 'example' );

		self::assertTrue( $this->repository->hasUnresolvedPackageAttempt( 'plugin', 'example' ) );
		$this->repository->finish(
			$attempt->getId(),
			DeploymentOutcome::fromCode( DeploymentOutcome::CODE_DEPLOYED )
		);
		self::assertFalse( $this->repository->hasUnresolvedPackageAttempt( 'plugin', 'example' ) );
	}

	public function testAttemptRowLimitConfigurationDefaultsAndOnlyAcceptsCanonicalRaisedIntegers(): void {
		self::assertSame(
			array(
				'valid'        => true,
				'maximum_rows' => 200,
				'source'       => 'default',
			),
			$this->repository->retentionConfigurationStatus()
		);

		foreach ( array( 199, 100001, '250', 250.0, true ) as $invalid ) {
			self::assertSame(
				array(
					'valid'        => false,
					'maximum_rows' => 200,
					'source'       => 'configured',
				),
				$this->repositoryWithMaximum( $invalid )->retentionConfigurationStatus()
			);
		}

		foreach ( array( 200, 250, 100000 ) as $valid ) {
			self::assertSame(
				array(
					'valid'        => true,
					'maximum_rows' => $valid,
					'source'       => 'configured',
				),
				$this->repositoryWithMaximum( $valid )->retentionConfigurationStatus()
			);
		}
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function testWpConfigConstantRaisesTheAttemptRowLimit(): void {
		define( 'RAN_BOOSTER_MAX_ATTEMPT_ROWS', 350 );

		self::assertSame(
			array(
				'valid'        => true,
				'maximum_rows' => 350,
				'source'       => 'configured',
			),
			$this->repositoryWithMaximum()->retentionConfigurationStatus()
		);
	}

	public function testSingleAdmissionPrunesExistingOverflowAndTheOldestTiedTerminalRows(): void {
		$this->seedAttempts( array_fill( 0, 205, DeploymentState::SUCCEEDED->value ) );

		$attempt = $this->manual( 'new-attempt' );

		self::assertSame( DeploymentState::RUNNING, $attempt->getState() );
		self::assertCount( 200, $this->database->rows );
		self::assertSame( 7, min( array_column( $this->database->rows, 'id' ) ) );
		self::assertStringContainsString(
			"SELECT id FROM `wp_ran_booster_deployment_attempts` WHERE (state IN ('succeeded','failed') OR (state = 'needs_attention' AND resolved_at IS NOT NULL AND resolved_by IS NOT NULL)) ORDER BY created_at, id LIMIT 6 FOR UPDATE",
			implode( "\n", $this->database->queries )
		);
	}

	public function testAdmissionAtOneHundredNinetyNineRowsFillsBeforeTheNextAdmissionPrunes(): void {
		$this->seedAttempts( array_fill( 0, 199, DeploymentState::SUCCEEDED->value ) );

		$this->manual( 'fills-capacity' );

		self::assertCount( 200, $this->database->rows );
		self::assertStringNotContainsString( 'DELETE FROM', implode( "\n", $this->database->queries ) );
		$this->database->queries = array();

		$this->manual( 'requires-pruning' );

		self::assertCount( 200, $this->database->rows );
		self::assertStringContainsString( 'DELETE FROM', implode( "\n", $this->database->queries ) );
	}

	public function testProtectedRowsExhaustCapacityWithoutMutation(): void {
		$this->seedAttempts( array_fill( 0, 200, DeploymentState::QUEUED->value ) );
		$before = $this->database->rows;

		try {
			$this->manual( 'new-attempt' );
			self::fail( 'Protected deployment work must exhaust storage safely.' );
		} catch ( DeploymentStorageFailure $failure ) {
			self::assertTrue( $failure->isCapacityExhausted() );
			self::assertStringContainsString( 'Resolve queued, running, or needs-attention deployments', $failure->getMessage() );
		}

		self::assertSame( $before, $this->database->rows );
		self::assertSame( 'ROLLBACK', $this->database->queries[ array_key_last( $this->database->queries ) ] );
	}

	public function testManualBatchReservesOnlyEligibleRowsAndNeverPrunesProtectedWork(): void {
		$states    = array( DeploymentState::SUCCEEDED->value, DeploymentState::QUEUED->value );
		$states    = array_merge( $states, array_fill( 0, 198, DeploymentState::RUNNING->value ) );
		$overrides = array( 2 => 'busy' );
		$this->seedAttempts( $states, $overrides );

		$result = $this->repository->admitManualBatch(
			array(
				$this->manualTarget( 'busy' ),
				$this->manualTarget( 'available' ),
			)
		);

		self::assertCount( 1, $result['busy'] );
		self::assertCount( 1, $result['admitted'] );
		self::assertCount( 200, $this->database->rows );
		self::assertNotContains( 1, array_column( $this->database->rows, 'id' ) );
		self::assertContains( 2, array_column( $this->database->rows, 'id' ) );
		self::assertSame( 'available', $result['admitted'][0]->getRequest()->packageSlug );
	}

	public function testWebhookBatchAndZeroTargetAcknowledgementReserveTheirExactRows(): void {
		$this->seedAttempts( array_fill( 0, 200, DeploymentState::FAILED->value ) );

		$attempts = $this->repository->admitWebhookBatch(
			'gh',
			'delivery-batch',
			hash( 'sha256', 'delivery-batch' ),
			array( $this->target( 'alpha' ), $this->target( 'beta' ) )
		);

		self::assertCount( 2, $attempts );
		self::assertCount( 200, $this->database->rows );
		self::assertNotContains( 1, array_column( $this->database->rows, 'id' ) );
		self::assertNotContains( 2, array_column( $this->database->rows, 'id' ) );

		$this->repository->admitWebhookBatch( 'gh', 'delivery-empty', hash( 'sha256', 'delivery-empty' ), array() );

		self::assertCount( 200, $this->database->rows );
		self::assertSame( 'delivery', $this->database->rows[ array_key_last( $this->database->rows ) ]['package_type'] );
	}

	public function testReplayReturnsBeforeCapacityAccounting(): void {
		$this->repository->admitWebhookBatch( 'gh', 'delivery-replay', hash( 'sha256', 'delivery-replay' ), array() );
		$this->seedAttempts( array_fill( 0, 199, DeploymentState::SUCCEEDED->value ), array(), 2 );
		$this->database->queries = array();

		$result = $this->repository->admitWebhookBatch(
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

	public function testPruningAndPartialBatchInsertRollBackTogether(): void {
		$this->seedAttempts( array_fill( 0, 200, DeploymentState::SUCCEEDED->value ) );
		$before                           = $this->database->rows;
		$this->database->failInsertNumber = 2;

		try {
			$this->repository->admitManualBatch(
				array(
					$this->manualTarget( 'alpha' ),
					$this->manualTarget( 'beta' ),
				)
			);
			self::fail( 'A partially inserted capacity reservation must roll back.' );
		} catch ( DeploymentStorageFailure ) {
			self::assertSame( $before, $this->database->rows );
			self::assertSame( 'ROLLBACK', $this->database->queries[ array_key_last( $this->database->queries ) ] );
		}
	}

	public function testDeleteAndCommitFailuresRestorePrunedRows(): void {
		foreach ( array( 'delete', 'commit' ) as $failure ) {
			$this->database   = new AttemptRepositoryDatabase();
			$this->repository = $this->repositoryWithMaximum();
			$this->seedAttempts( array_fill( 0, 200, DeploymentState::SUCCEEDED->value ) );
			$before = $this->database->rows;

			$this->database->failQueryContains = 'delete' === $failure ? 'DELETE FROM' : null;
			$this->database->failCommit        = 'commit' === $failure;
			try {
				$this->manual( $failure . '-failure' );
				self::fail( 'Capacity pruning must roll back when its transaction fails.' );
			} catch ( DeploymentStorageFailure $storageFailure ) {
				self::assertSame( $before, $this->database->rows );
				self::assertSame( 'ROLLBACK', $this->database->queries[ array_key_last( $this->database->queries ) ] );
			}
		}
	}

	public function testCursorHistoryContinuesAcrossMoreThanOneHundredRetainedRows(): void {
		$this->repository = $this->repositoryWithMaximum( 250 );
		$this->seedAttempts( array_fill( 0, 210, DeploymentState::SUCCEEDED->value ) );

		$first  = $this->repository->recentHistory( 100 );
		$second = $this->repository->recentHistory( 100, $first[ array_key_last( $first ) ]->getId() );
		$third  = $this->repository->recentHistory( 100, $second[ array_key_last( $second ) ]->getId() );

		self::assertCount( 100, $first );
		self::assertCount( 100, $second );
		self::assertCount( 10, $third );
		self::assertSame( 210, $first[0]->getId() );
		self::assertSame( 1, $third[ array_key_last( $third ) ]->getId() );
	}

	public function testUnsupportedDatabaseBlocksAttemptReadsAndAdmissionsBeforeTableAccess(): void {
		$this->database->serverInfo = '5.7.44';
		$this->repository           = $this->repositoryWithMaximum( databaseLifecycle: new Database( $this->database ) );

		try {
			$this->repository->recentHistory();
			self::fail( 'Unsupported history reads must fail closed.' );
		} catch ( DeploymentStorageFailure $failure ) {
			self::assertTrue( $failure->isDatabaseUnsupported() );
		}

		try {
			$this->manual( 'unsupported' );
			self::fail( 'Unsupported admissions must fail closed.' );
		} catch ( DeploymentStorageFailure $failure ) {
			self::assertTrue( $failure->isDatabaseUnsupported() );
		}

		self::assertSame( array(), $this->database->queries );
		self::assertSame( array(), $this->database->rows );
	}

	public function testLifecycleSafeStateBlocksAttemptReadsAndAdmissionsBeforeTableAccess(): void {
		$lifecycle = $this->createStub( Database::class );
		$lifecycle->method( 'requireReady' )->willThrowException( new DatabaseLifecycleFailure( 'schema_operation_failed' ) );
		$this->database->failReads = true;
		$this->database->queries   = array();
		$this->repository          = $this->repositoryWithMaximum( databaseLifecycle: $lifecycle );
		foreach ( array(
			fn () => $this->repository->recentHistory(),
			fn () => $this->manual( 'blocked' ),
		) as $operation ) {
			try {
				$operation();
				self::fail( 'Cached lifecycle failures must block deployment-attempt storage.' );
			} catch ( DeploymentStorageFailure $failure ) {
				self::assertTrue( $failure->isDatabaseUnsupported() );
			}
		}

		self::assertSame( '', $this->database->last_error );
		self::assertSame( array(), $this->database->queries );
		self::assertSame( array(), $this->database->rows );
	}

	public function testManualAdmissionRejectsAnExistingActivePackageAttempt(): void {
		$active = $this->manual( 'example' );

		try {
			$this->manual( 'example' );
			self::fail( 'A second manual mutation must not overlap an active attempt.' );
		} catch ( DeploymentStorageFailure $failure ) {
			self::assertSame( $active->getCorrelationId(), $failure->getActiveCorrelationId() );
			self::assertCount( 1, $this->database->rows );
			self::assertSame( 'ROLLBACK', $this->database->queries[ array_key_last( $this->database->queries ) ] );
		}
	}

	public function testInsertAndReadbackFailuresFailClosed(): void {
		$this->database->failInsert = true;
		try {
			$this->manual( 'insert-failure' );
			self::fail( 'Insert failure must fail closed.' );
		} catch ( DeploymentStorageFailure ) {
			self::assertCount( 0, $this->database->rows );
		}

		$this->database->failInsert         = false;
		$this->database->tamperInsertColumn = 'request_json';
		$this->database->tamperInsertValue  = '{}';
		$this->expectException( DeploymentStorageFailure::class );
		$this->manual( 'readback-failure' );
	}

	public function testManualClaimOrCommitFailureRollsBackTheAdmission(): void {
		foreach ( array( 'claim', 'commit' ) as $failure ) {
			$this->database->zeroQueryContains = 'claim' === $failure ? "SET state = 'running'" : null;
			$this->database->failCommit        = 'commit' === $failure;

			try {
				$this->manual( $failure . '-failure' );
				self::fail( 'Manual admission and claim must fail atomically.' );
			} catch ( DeploymentStorageFailure ) {
				self::assertSame( array(), $this->database->rows );
				self::assertSame( 'ROLLBACK', $this->database->queries[ array_key_last( $this->database->queries ) ] );
			}
			$this->database->queries = array();
		}
	}

	public function testValidButDifferentInsertIdentityFailsReadback(): void {
		$this->database->tamperInsertColumn = 'provider_repository_id';
		$this->database->tamperInsertValue  = 'R_different';

		try {
			$this->manual( 'example' );
			self::fail( 'A changed provider identity must fail readback.' );
		} catch ( DeploymentStorageFailure ) {
			self::assertSame( array(), $this->database->rows );
		}
	}

	public function testValidButDifferentDeliveryDigestFailsReadback(): void {
		$this->database->tamperInsertColumn = 'delivery_digest';
		$this->database->tamperInsertValue  = str_repeat( 'e', 64 );

		try {
			$this->repository->admitWebhookBatch( 'gh', 'delivery-1', str_repeat( 'd', 64 ), array( $this->target( 'example' ) ) );
			self::fail( 'A changed delivery digest must fail readback.' );
		} catch ( DeploymentStorageFailure ) {
			self::assertSame( array(), $this->database->rows );
		}
	}

	public function testWebhookBatchIsSortedAndCommittedAtomically(): void {
		$attempts = $this->repository->admitWebhookBatch(
			'gh',
			'delivery-1',
			str_repeat( 'd', 64 ),
			array( $this->target( 'zeta', 'theme' ), $this->target( 'alpha', 'plugin' ) )
		);

		self::assertSame( array( 'alpha', 'zeta' ), array_map( static fn ( DeploymentAttempt $attempt ): string => $attempt->getRequest()->packageSlug, $attempts ) );
		self::assertSame( 'COMMIT', $this->database->queries[ array_key_last( $this->database->queries ) ] );
		// The integration gate exercises this ordering with two real connections whose session default is READ COMMITTED.
		self::assertSame( 'SET TRANSACTION ISOLATION LEVEL SERIALIZABLE', $this->database->queries[0] );
		self::assertSame( 'START TRANSACTION', $this->database->queries[1] );
		self::assertStringContainsString( 'ORDER BY package_type, package_slug FOR UPDATE', implode( "\n", $this->database->queries ) );
	}

	public function testManualBatchIsSortedAndCommittedAsQueued(): void {
		$result = $this->repository->admitManualBatch(
			array(
				$this->manualTarget( 'zeta', 'theme' ),
				$this->manualTarget( 'alpha', 'plugin' ),
			)
		);

		self::assertSame( array( 'alpha', 'zeta' ), array_map( static fn ( DeploymentAttempt $attempt ): string => $attempt->getRequest()->packageSlug, $result['admitted'] ) );
		self::assertSame( array(), $result['busy'] );
		self::assertSame( array( 'manual', 'manual' ), array_column( $this->database->rows, 'source' ) );
		self::assertSame( array( 'queued', 'queued' ), array_column( $this->database->rows, 'state' ) );
		self::assertSame( 'SET TRANSACTION ISOLATION LEVEL SERIALIZABLE', $this->database->queries[0] );
		self::assertSame( 'COMMIT', $this->database->queries[ array_key_last( $this->database->queries ) ] );
	}

	public function testManualBatchReportsBusyAndAdmitsTheRemainingTarget(): void {
		$active                           = $this->manual( 'active' );
		$this->database->rows[0]['state'] = DeploymentState::QUEUED->value;

		$result = $this->repository->admitManualBatch(
			array(
				$this->manualTarget( 'active' ),
				$this->manualTarget( 'available' ),
			)
		);

		self::assertCount( 1, $result['busy'] );
		self::assertSame( $active->getCorrelationId(), $result['busy'][0]['correlation_id'] );
		self::assertCount( 1, $result['admitted'] );
		self::assertSame( 'available', $result['admitted'][0]->getRequest()->packageSlug );
		self::assertCount( 2, $this->database->rows );
	}

	public function testTerminalManualHistoryDoesNotBlockANewBatchAttempt(): void {
		$running = $this->manual( 'example' );
		$this->repository->finish( $running->getId(), DeploymentOutcome::fromCode( DeploymentOutcome::CODE_PREFLIGHT_FAILED ) );

		$result = $this->repository->admitManualBatch( array( $this->manualTarget( 'example' ) ) );

		self::assertCount( 1, $result['admitted'] );
		self::assertSame( array(), $result['busy'] );
	}

	public function testNeedsAttentionHistoryBlocksANewBatchAttempt(): void {
		$running = $this->manual( 'example' );
		$this->repository->finish( $running->getId(), DeploymentOutcome::fromCode( DeploymentOutcome::CODE_INTERRUPTED ) );
		$this->database->queries = array();

		$result = $this->repository->admitManualBatch( array( $this->manualTarget( 'example' ) ) );

		self::assertSame( array(), $result['admitted'] );
		self::assertCount( 1, $result['busy'] );
		self::assertSame( DeploymentState::NEEDS_ATTENTION->value, $result['busy'][0]['state'] );
		self::assertStringNotContainsString( 'COUNT(*) AS total', implode( "\n", $this->database->queries ) );
	}

	public function testExactBatchReadsOnlyRequestedAttemptsInOneQuery(): void {
		$first  = $this->manual( 'first' );
		$second = $this->manual( 'second' );
		$third  = $this->manual( 'third' );
		$before = count( $this->database->queries );

		$found = $this->repository->findExactBatch( array( $third->getId(), $first->getId() ) );

		self::assertSame( array( $first->getId(), $third->getId() ), array_keys( $found ) );
		self::assertArrayNotHasKey( $second->getId(), $found );
		self::assertCount( 1, array_slice( $this->database->queries, $before ) );
	}

	public function testManualBatchRejectsDuplicatesAndRollsBackInsertFailure(): void {
		try {
			$this->repository->admitManualBatch( array( $this->manualTarget( 'duplicate' ), $this->manualTarget( 'duplicate' ) ) );
			self::fail( 'Duplicate manual targets must be rejected.' );
		} catch ( DeploymentStorageFailure ) {
			self::assertSame( array(), $this->database->queries );
		}

		$releaseTarget                   = $this->manualTarget( 'release-managed' );
		$releaseTarget['package_source'] = 'release_asset';
		try {
			$this->repository->admitManualBatch( array( $releaseTarget ) );
			self::fail( 'The branch queue must reject release-managed targets.' );
		} catch ( DeploymentStorageFailure ) {
			self::assertSame( array(), $this->database->queries );
		}

		$this->database->failInsertNumber = 2;
		try {
			$this->repository->admitManualBatch( array( $this->manualTarget( 'alpha' ), $this->manualTarget( 'beta' ) ) );
			self::fail( 'A partial manual batch must not survive.' );
		} catch ( DeploymentStorageFailure ) {
			self::assertSame( array(), $this->database->rows );
			self::assertSame( 'ROLLBACK', $this->database->queries[ array_key_last( $this->database->queries ) ] );
		}
	}

	public function testWebhookAdmissionFailsBeforeTransactionWhenSerializableIsolationIsUnavailable(): void {
		$this->database->failQueryContains = 'SET TRANSACTION ISOLATION LEVEL SERIALIZABLE';

		try {
			$this->repository->admitWebhookBatch( 'gh', 'delivery-1', str_repeat( 'd', 64 ), array( $this->target( 'example' ) ) );
			self::fail( 'Webhook admission must not run without serializable isolation.' );
		} catch ( DeploymentStorageFailure ) {
			self::assertSame( array( 'SET TRANSACTION ISOLATION LEVEL SERIALIZABLE' ), $this->database->queries );
			self::assertSame( array(), $this->database->rows );
		}
	}

	public function testWebhookReplayReturnsExistingRowsWithoutDuplicates(): void {
		$targets = array( $this->target( 'example' ) );
		$first   = $this->repository->admitWebhookBatch( 'gh', 'delivery-1', str_repeat( 'd', 64 ), $targets );
		$replay  = $this->repository->admitWebhookBatch( 'gh', 'delivery-1', str_repeat( 'd', 64 ), $targets );

		self::assertSame( $first[0]->getId(), $replay[0]->getId() );
		self::assertCount( 1, $this->database->rows );
	}

	public function testWebhookReplayCannotAddANewlyMatchingTarget(): void {
		$first  = $this->repository->admitWebhookBatch( 'gh', 'delivery-1', str_repeat( 'd', 64 ), array( $this->target( 'original' ) ) );
		$replay = $this->repository->admitWebhookBatch(
			'gh',
			'delivery-1',
			str_repeat( 'd', 64 ),
			array( $this->target( 'original' ), $this->target( 'newly-managed' ) )
		);

		self::assertCount( 1, $replay );
		self::assertSame( $first[0]->getId(), $replay[0]->getId() );
		self::assertSame( 'original', $replay[0]->getRequest()->packageSlug );
		self::assertCount( 1, $this->database->rows );
	}

	public function testDifferentDeliveryDigestRollsBackWithoutAddingRows(): void {
		$this->repository->admitWebhookBatch( 'gh', 'delivery-1', str_repeat( 'd', 64 ), array( $this->target( 'existing' ) ) );

		try {
			$this->repository->admitWebhookBatch( 'gh', 'delivery-1', str_repeat( 'e', 64 ), array( $this->target( 'new' ) ) );
			self::fail( 'Digest reuse must fail.' );
		} catch ( DeploymentStorageFailure $failure ) {
			self::assertStringContainsString( 'different authenticated content', $failure->getMessage() );
			self::assertCount( 1, $this->database->rows );
			self::assertSame( 'ROLLBACK', $this->database->queries[ array_key_last( $this->database->queries ) ] );
		}
	}

	public function testSecondTargetInsertFailureRollsBackTheWholeBatch(): void {
		$this->database->failInsertNumber = 2;

		try {
			$this->repository->admitWebhookBatch(
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

	public function testCommitFailureRollsBackAndIsReportedDistinctly(): void {
		$this->database->failCommit = true;

		try {
			$this->repository->admitWebhookBatch( 'gh', 'delivery-1', str_repeat( 'd', 64 ), array( $this->target( 'example' ) ) );
			self::fail( 'Commit failure must fail closed.' );
		} catch ( DeploymentStorageFailure $failure ) {
			self::assertSame( 'RAN Booster could not commit deployment state.', $failure->getMessage() );
			self::assertCount( 0, $this->database->rows );
		}
	}

	public function testZeroTargetWebhookStoresADurableAcknowledgementAndFreezesTheEmptySet(): void {
		self::assertSame( array(), $this->repository->admitWebhookBatch( 'gh', 'delivery-1', str_repeat( 'd', 64 ), array() ) );
		self::assertCount( 1, $this->database->rows );
		self::assertSame( 'delivery', $this->database->rows[0]['package_type'] );
		self::assertSame( 'succeeded', $this->database->rows[0]['state'] );

		self::assertSame(
			array(),
			$this->repository->admitWebhookBatch( 'gh', 'delivery-1', str_repeat( 'd', 64 ), array( $this->target( 'newly-managed' ) ) )
		);
		self::assertCount( 1, $this->database->rows );
		self::assertSame( array(), $this->repository->recentHistory() );
	}

	public function testLatestAuthenticatedDeliveryEvidenceIsProviderScopedAndDistinguishesMatches(): void {
		self::assertNull( $this->repository->latestAuthenticatedDelivery( ProviderCode::parse( 'gh' ) ) );

		$this->repository->admitWebhookBatch( 'gh', 'delivery-empty', str_repeat( 'd', 64 ), array() );
		$this->repository->admitWebhookBatch( 'bb', 'delivery-bitbucket', str_repeat( 'b', 64 ), array( $this->target( 'bitbucket' ) ) );

		$githubEvidence    = $this->repository->latestAuthenticatedDelivery( ProviderCode::parse( 'gh' ) );
		$bitbucketEvidence = $this->repository->latestAuthenticatedDelivery( ProviderCode::parse( 'bb' ) );

		self::assertNotNull( $githubEvidence );
		self::assertTrue( $githubEvidence->provider->equals( ProviderCode::parse( 'gh' ) ) );
		self::assertSame( '2026-07-19 00:00:00', $githubEvidence->receivedAt );
		self::assertFalse( $githubEvidence->matchedManagedPackage );
		self::assertNotNull( $bitbucketEvidence );
		self::assertTrue( $bitbucketEvidence->matchedManagedPackage );

		$this->repository->admitWebhookBatch( 'gh', 'delivery-matched', str_repeat( 'e', 64 ), array( $this->target( 'github' ) ) );

		self::assertTrue( $this->repository->latestAuthenticatedDelivery( ProviderCode::parse( 'gh' ) )?->matchedManagedPackage );
	}

	public function testClaimTransitionIsOneAtomicTransaction(): void {
		$queued                  = $this->webhook( 'first' );
		$this->database->queries = array();
		$running                 = $this->repository->claimNext();

		self::assertNotNull( $running );
		self::assertSame( $queued->getId(), $running->getId() );
		self::assertSame( DeploymentState::RUNNING, $running->getState() );
		self::assertSame( 'START TRANSACTION', $this->database->queries[0] );
		self::assertSame( 'COMMIT', $this->database->queries[ array_key_last( $this->database->queries ) ] );
		self::assertStringNotContainsString( 'wp_options', implode( "\n", $this->database->queries ) );
	}

	public function testRunningAttemptDoesNotHideTheNextQueuedWebhook(): void {
		$active  = $this->manual( 'active' );
		$waiting = $this->webhook( 'waiting' );

		$second = $this->repository->claimNext();

		self::assertNotNull( $second );
		self::assertSame( $waiting->getId(), $second->getId() );
		self::assertSame( DeploymentState::RUNNING, $active->getState() );
		self::assertSame( DeploymentState::RUNNING, $second->getState() );
	}

	public function testClaimTransitionFailureRollsBackItsStateChange(): void {
		$this->webhook( 'example' );
		$this->database->zeroQueryContains = 'UPDATE `wp_ran_booster_deployment_attempts`';

		try {
			$this->repository->claimNext();
			self::fail( 'A failed transition must roll back.' );
		} catch ( DeploymentStorageFailure ) {
			self::assertSame( 'queued', $this->database->rows[0]['state'] );
			self::assertSame( 'ROLLBACK', $this->database->queries[ array_key_last( $this->database->queries ) ] );
		}
	}

	public function testResolvedRefFenceAndTerminalOutcomeAreWrittenAndReadBack(): void {
		$running  = $this->manual( 'example' );
		$resolved = $this->repository->recordResolvedRef( $running->getId(), str_repeat( 'a', 40 ) );
		$fenced   = $this->repository->markMutationStarted( $running->getId() );
		$finished = $this->repository->finish( $running->getId(), DeploymentOutcome::fromCode( DeploymentOutcome::CODE_DEPLOYED ) );

		self::assertSame( str_repeat( 'a', 40 ), $resolved->safeData()['resolved_ref'] );
		self::assertNotNull( $fenced->safeData()['mutation_started_at'] );
		self::assertSame( DeploymentState::SUCCEEDED, $finished->getState() );
		self::assertSame( 'deployed', $finished->getOutcome()?->getCode() );
	}

	public function testValidButDifferentTerminalTimestampFailsReadback(): void {
		$running                            = $this->manual( 'example' );
		$this->database->tamperUpdateColumn = 'finished_at';
		$this->database->tamperUpdateValue  = '2026-07-19 00:00:01';

		$this->expectException( DeploymentStorageFailure::class );
		$this->repository->finish( $running->getId(), DeploymentOutcome::fromCode( DeploymentOutcome::CODE_DEPLOYED ) );
	}

	public function testWriteFailuresAndEmptyQueryAreNotConflated(): void {
		self::assertSame( array(), $this->repository->recentHistory() );
		$this->database->failReads = true;

		$this->expectException( DeploymentStorageFailure::class );
		$this->repository->recentHistory();
	}

	public function testPreFenceReconciliationFailsTheRunningAttempt(): void {
		$running = $this->manual( 'example' );
		$result  = $this->repository->reconcileConfirmedStopped( $running->getId() );

		self::assertSame( DeploymentState::FAILED, $result->getState() );
		self::assertSame( 'worker_stopped', $result->getOutcome()?->getCode() );
	}

	public function testPostFenceReconciliationRequiresAttention(): void {
		$running = $this->manual( 'example' );
		$this->repository->markMutationStarted( $running->getId() );
		$result = $this->repository->reconcileConfirmedStopped( $running->getId() );

		self::assertSame( DeploymentState::NEEDS_ATTENTION, $result->getState() );
		self::assertSame( 'interrupted', $result->getOutcome()?->getCode() );
	}

	public function testExactOperatorResolutionPreservesOutcomeAndAllowsRetry(): void {
		$running = $this->manual( 'example' );
		$this->repository->markMutationStarted( $running->getId() );
		$attention = $this->repository->reconcileConfirmedStopped( $running->getId() );

		try {
			$this->manual( 'example' );
			self::fail( 'An unresolved needs-attention attempt must block admission.' );
		} catch ( DeploymentStorageFailure $failure ) {
			self::assertSame( $attention->safeData()['id'], $failure->getActiveAttempt()['id'] ?? null );
			self::assertSame( 'needs_attention', $failure->getActiveAttempt()['state'] ?? null );
		}

		$resolved = $this->repository->resolveNeedsAttention(
			$attention->getId(),
			$attention->getCorrelationId(),
			7
		);
		$retry    = $this->manual( 'example' );

		self::assertSame( DeploymentState::NEEDS_ATTENTION, $resolved->getState() );
		self::assertSame( 'interrupted', $resolved->getOutcome()?->getCode() );
		self::assertSame( '2026-07-19 00:00:00', $resolved->safeData()['resolved_at'] );
		self::assertSame( 7, $resolved->safeData()['resolved_by'] );
		self::assertFalse( $resolved->requiresOperatorResolution() );
		self::assertSame( 0, $this->repository->operationalSnapshot()['needs_attention'] );
		self::assertSame( DeploymentState::RUNNING, $retry->getState() );
	}

	public function testOperatorResolutionRequiresTheExactCorrelationReference(): void {
		$running = $this->manual( 'example' );
		$this->repository->markMutationStarted( $running->getId() );
		$attention = $this->repository->reconcileConfirmedStopped( $running->getId() );

		try {
			$this->repository->resolveNeedsAttention( $attention->getId(), str_repeat( 'f', 32 ), 7 );
			self::fail( 'A mismatched support reference must not resolve the attempt.' );
		} catch ( DeploymentStorageFailure ) {
			$stored = $this->repository->findExact( $attention->getId() );
			self::assertTrue( $stored?->requiresOperatorResolution() );
			self::assertNull( $stored?->safeData()['resolved_at'] );
		}
	}

	public function testResolvedNeedsAttentionRowsAreEligibleForPruning(): void {
		$this->seedAttempts( array( DeploymentState::NEEDS_ATTENTION->value ) );
		$this->database->rows[0]['resolved_at'] = '2026-07-18 00:02:00';
		$this->database->rows[0]['resolved_by'] = 7;
		$this->seedAttempts( array_fill( 0, 199, DeploymentState::QUEUED->value ), array(), 2 );

		$this->manual( 'new-attempt' );

		self::assertCount( 200, $this->database->rows );
		self::assertNotContains( 1, array_column( $this->database->rows, 'id' ) );
	}

	public function testReconciliationHydratesAndRejectsAnInvalidMutationFence(): void {
		$running                                        = $this->manual( 'example' );
		$this->database->rows[0]['mutation_started_at'] = 'not-a-date';

		$this->expectException( DeploymentStorageFailure::class );
		$this->repository->reconcileConfirmedStopped( $running->getId() );
	}

	public function testReconciliationRollsBackATamperedTerminalTimestamp(): void {
		$running                            = $this->manual( 'example' );
		$this->database->tamperUpdateColumn = 'finished_at';
		$this->database->tamperUpdateValue  = '2026-07-19 00:00:01';

		try {
			$this->repository->reconcileConfirmedStopped( $running->getId() );
			self::fail( 'A changed reconciliation result must fail readback.' );
		} catch ( DeploymentStorageFailure ) {
			self::assertSame( DeploymentState::RUNNING, $this->repository->findExact( $running->getId() )?->getState() );
		}
	}

	public function testHistoryIsBoundedNewestFirstAndDoesNotExposeSecretsOrRawJson(): void {
		$this->manual( 'first' );
		$this->manual( 'second' );
		$this->manual( 'third' );
		$history = $this->repository->recentHistory( 2 );

		self::assertSame( array( 'third', 'second' ), array_map( static fn ( DeploymentAttempt $attempt ): string => $attempt->getRequest()->packageSlug, $history ) );
		foreach ( $history as $attempt ) {
			self::assertArrayNotHasKey( 'request_json', $attempt->safeData() );
			self::assertArrayNotHasKey( 'delivery_digest', $attempt->safeData() );
		}
	}

	public function testPackageActivitySummaryReadsLatestAndLastSuccessInOneBoundedQuery(): void {
		$successful = $this->manual( 'example' );
		$this->repository->recordResolvedRef( $successful->getId(), str_repeat( 'a', 40 ) );
		$successful = $this->repository->finish( $successful->getId(), DeploymentOutcome::fromCode( DeploymentOutcome::CODE_DEPLOYED ) );
		$failed     = $this->manual( 'example' );
		$failed     = $this->repository->finish( $failed->getId(), DeploymentOutcome::fromCode( DeploymentOutcome::CODE_PREFLIGHT_FAILED ) );

		$this->database->queries = array();
		$summary                 = $this->repository->packageActivitySummary( 'plugin', 'example' );

		self::assertSame( $failed->getId(), $summary['latest']?->getId() );
		self::assertSame( $successful->getId(), $summary['last_successful']?->getId() );
		self::assertCount( 1, $this->database->queries );
		self::assertStringContainsString( 'ORDER BY attempts.id DESC LIMIT 2', $this->database->queries[0] );
	}

	public function testPackageActivitySummaryFailsClosedWhenItsSingleReadFails(): void {
		$this->database->failReads = true;

		$this->expectException( DeploymentStorageFailure::class );
		$this->repository->packageActivitySummary( 'theme', 'example' );
	}

	private function repositoryWithMaximum(
		mixed $maximumRows = null,
		?Database $databaseLifecycle = null
	): DeploymentAttemptRepository {
		return new DeploymentAttemptRepository(
			$this->database,
			'wp_ran_booster_deployment_attempts',
			static fn (): DateTimeImmutable => new DateTimeImmutable( '2026-07-19 00:00:00 UTC' ),
			function ( int $length ): string {
				return str_repeat( chr( $this->randomByte++ ), $length );
			},
			$databaseLifecycle ?? $this->databaseLifecycle,
			$maximumRows
		);
	}

	/**
	 * @param list<string> $states
	 * @param array<int, string> $packageSlugs
	 */
	private function seedAttempts( array $states, array $packageSlugs = array(), int $firstId = 1 ): void {
		foreach ( $states as $offset => $state ) {
			$id      = $firstId + $offset;
			$slug    = $packageSlugs[ $id ] ?? 'seed-' . $id;
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
				'request_json'            => $this->request( $slug )->toJson(),
				'created_at'              => '2026-07-18 00:00:00',
				'finished_at'             => null === $outcome ? null : '2026-07-18 00:01:00',
				'resolved_at'             => null,
				'resolved_by'             => null,
			);
		}
	}

	private function manual( string $slug ): DeploymentAttempt {
		return $this->repository->admitAndClaimManual( 'install', 'plugin', 'gh', 'R_' . $slug, $this->request( $slug ), 'main', 'branch', 0 );
	}

	private function webhook( string $slug ): DeploymentAttempt {
		return $this->repository->admitWebhookBatch(
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
	private function manualTarget( string $slug, string $type = 'plugin' ): array {
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
