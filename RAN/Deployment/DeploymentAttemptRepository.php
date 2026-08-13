<?php

declare(strict_types=1);

namespace RAN\Deployment;

use DateTimeImmutable;
use DateTimeInterface;
use RAN\Logging\BoosterLogger;
use RAN\RepositoryProvider\AuthenticatedWebhookDeliveryEvidence;
use RAN\RepositoryProvider\ProviderCode;
use RAN\Runtime\RuntimeSupport;
use RAN\Storage\Database;
use RAN\Storage\DatabaseCompatibilityFailure;
use RAN\Storage\DatabaseLifecycleFailure;
use Throwable;

/**
 * Checked persistence for the one-row-per-mutation deployment queue.
 *
 * The database transaction owns admission and claiming. WordPress's updater
 * lock guards the later filesystem-mutation boundary.
 */
final class DeploymentAttemptRepository {

	public const DEFAULT_MAX_ATTEMPT_ROWS = 200;
	public const MAX_ATTEMPT_ROWS         = 100000;

	private const MAX_HISTORY         = 100;
	private const MAX_MANUAL_TARGETS  = 20;
	private const MAX_WEBHOOK_TARGETS = 64;
	private const DELIVERY_ACK_TYPE   = 'delivery';

	/** @var callable(): DateTimeImmutable */
	private $clock;
	/** @var callable(int): string */
	private $randomBytes;
	private Database $databaseLifecycle;
	/** @var array{valid: bool, maximum_rows: int, source: 'configured'|'default'}|null */
	private ?array $retentionConfiguration = null;

	public function __construct(
		private ?object $database = null,
		private ?string $tableName = null,
		?callable $clock = null,
		?callable $randomBytes = null,
		?Database $databaseLifecycle = null,
		private mixed $configuredMaxAttemptRows = null
	) {
		if ( null === $this->database ) {
			global $wpdb;
			$this->database = $wpdb;
		}
		if ( null === $this->tableName ) {
			$this->tableName = \RAN\Storage\Database::attemptTableName();
		}
		$this->clock             = $clock ?? static fn (): DateTimeImmutable => new DateTimeImmutable( 'now', wp_timezone() );
		$this->randomBytes       = $randomBytes ?? static fn ( int $length ): string => random_bytes( $length );
		$this->databaseLifecycle = $databaseLifecycle ?? new Database( $this->database );
	}

	public function admitAndClaimManual(
		string $operation,
		string $packageType,
		string $provider,
		string $providerRepositoryId,
		DeploymentRequest $request,
		string $requestedRef,
		string $packageSource,
		int $packageSourceRevision
	): DeploymentAttempt {
		RuntimeSupport::assertManagedOperationsAllowed();

		$this->assertOperation( $operation );
		$this->assertPackageType( $packageType );
		$this->assertProvider( $provider );
		$this->assertSafeText( $providerRepositoryId, 191 );
		$this->assertSafeText( $requestedRef, 255 );
		$this->assertPackageSource( $packageSource, $packageSourceRevision );

		return $this->transaction(
			function () use ( $operation, $packageType, $provider, $providerRepositoryId, $request, $requestedRef, $packageSource, $packageSourceRevision ): DeploymentAttempt {
				$active = $this->activePackageAttempt( $packageType, $request->packageSlug );
				if ( null !== $active ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The failure stores a validated, whitelisted attempt projection; it does not render output.
					throw DeploymentStorageFailure::contention( $active->safeData() );
				}

				$this->reserveCapacity( 1 );
				$queued = $this->insertAndRead(
					$this->rowData(
						'manual',
						$operation,
						$packageType,
						$provider,
						$providerRepositoryId,
						$request,
						$requestedRef,
						$packageSource,
						$packageSourceRevision,
						null,
						null
					)
				);
				$query  = $this->updateQuery( $queued->getId(), DeploymentState::QUEUED, array( 'state' => DeploymentState::RUNNING->value ) );
				if ( 1 !== $this->database->query( $query ) ) {
					throw DeploymentStorageFailure::unavailable();
				}
				$running = $this->requireExact( $queued->getId() );
				if ( DeploymentState::RUNNING !== $running->getState() ) {
					throw DeploymentStorageFailure::inconsistent();
				}
				BoosterLogger::log( 'attempt queued and claimed (manual)', $running->logContext() + array( 'transition' => 'queued->running' ) );

				return $running;
			},
			true
		);
	}

	/**
	 * Admit one administrator submission as independently journalled queued updates.
	 *
	 * @param list<array{package_type: string, provider: string, provider_repository_id: string, requested_ref: string, package_source: string, package_source_revision: int, request: DeploymentRequest}> $targets
	 * @return array{admitted: list<DeploymentAttempt>, busy: list<array<string, bool|int|string|null>>}
	 */
	public function admitManualBatch( array $targets ): array {
		RuntimeSupport::assertManagedOperationsAllowed();

		if ( array() === $targets || count( $targets ) > self::MAX_MANUAL_TARGETS ) {
			throw DeploymentStorageFailure::invalidRecord();
		}

		$normalized = array();
		foreach ( $targets as $target ) {
			if ( ! isset( $target['package_type'], $target['provider'], $target['provider_repository_id'], $target['requested_ref'], $target['package_source'], $target['package_source_revision'], $target['request'] )
				|| ! is_string( $target['package_type'] )
				|| ! is_string( $target['provider'] )
				|| ! is_string( $target['provider_repository_id'] )
				|| ! is_string( $target['requested_ref'] )
				|| ! is_string( $target['package_source'] )
				|| ! is_int( $target['package_source_revision'] )
				|| ! $target['request'] instanceof DeploymentRequest ) {
				throw DeploymentStorageFailure::invalidRecord();
			}
			$this->assertPackageType( $target['package_type'] );
			$this->assertProvider( $target['provider'] );
			$this->assertSafeText( $target['provider_repository_id'], 191 );
			$this->assertSafeText( $target['requested_ref'], 255 );
			$this->assertPackageSource( $target['package_source'], $target['package_source_revision'] );
			$key = $target['package_type'] . "\0" . $target['request']->packageSlug;
			if ( isset( $normalized[ $key ] ) ) {
				throw DeploymentStorageFailure::invalidRecord();
			}
			$normalized[ $key ] = $target;
		}
		ksort( $normalized, SORT_STRING );

		$result = $this->transaction(
			function () use ( $normalized ): array {
				$admitted = array();
				$busy     = array();

				foreach ( $normalized as $target ) {
					$request = $target['request'];
					$active  = $this->activePackageAttempt( $target['package_type'], $request->packageSlug );
					if ( null !== $active ) {
						$busy[] = $active->safeData();
						continue;
					}

					$admitted[] = $target;
				}

				$this->reserveCapacity( count( $admitted ) );
				$attempts = array();
				foreach ( $admitted as $target ) {
					$request    = $target['request'];
					$attempts[] = $this->insertAndRead(
						$this->rowData(
							'manual',
							'update',
							$target['package_type'],
							$target['provider'],
							$target['provider_repository_id'],
							$request,
							$target['requested_ref'],
							$target['package_source'],
							$target['package_source_revision'],
							null,
							null
						)
					);
				}

				return array(
					'admitted' => $attempts,
					'busy'     => $busy,
				);
			},
			true
		);

		foreach ( $result['admitted'] as $attempt ) {
			BoosterLogger::log( 'attempt queued (manual batch)', $attempt->logContext() + array( 'transition' => 'new->queued' ) );
		}

		return $result;
	}

	/**
	 * @param list<array{operation: string, package_type: string, provider_repository_id: string, requested_ref: string, package_source: string, package_source_revision: int, request: DeploymentRequest}> $targets
	 * @return list<DeploymentAttempt>
	 */
	public function admitWebhookBatch( string $provider, string $deliveryId, string $deliveryDigest, array $targets ): array {
		RuntimeSupport::assertManagedOperationsAllowed();

		$this->assertProvider( $provider );
		$this->assertSafeText( $deliveryId, 191 );
		$this->assertHex( $deliveryDigest, 64 );
		if ( count( $targets ) > self::MAX_WEBHOOK_TARGETS ) {
			throw DeploymentStorageFailure::invalidRecord();
		}
		$normalized = array();
		foreach ( $targets as $target ) {
			if ( ! isset( $target['operation'], $target['package_type'], $target['provider_repository_id'], $target['requested_ref'], $target['package_source'], $target['package_source_revision'], $target['request'] )
				|| ! is_string( $target['operation'] )
				|| ! is_string( $target['package_type'] )
				|| ! is_string( $target['provider_repository_id'] )
				|| ! is_string( $target['requested_ref'] )
				|| ! is_string( $target['package_source'] )
				|| ! is_int( $target['package_source_revision'] )
				|| ! $target['request'] instanceof DeploymentRequest ) {
				throw DeploymentStorageFailure::invalidRecord();
			}
			$this->assertOperation( $target['operation'] );
			$this->assertPackageType( $target['package_type'] );
			$this->assertSafeText( $target['provider_repository_id'], 191 );
			$this->assertSafeText( $target['requested_ref'], 255 );
			$this->assertPackageSource( $target['package_source'], $target['package_source_revision'] );
			$key = $target['package_type'] . "\0" . $target['request']->packageSlug;
			if ( isset( $normalized[ $key ] ) ) {
				throw DeploymentStorageFailure::invalidRecord();
			}
			$normalized[ $key ] = $target;
		}
		ksort( $normalized, SORT_STRING );

		return $this->transaction(
			function () use ( $provider, $deliveryId, $deliveryDigest, $normalized ): array {
				$query    = $this->prepare(
					'SELECT * FROM %i WHERE provider = %s AND delivery_id = %s ORDER BY package_type, package_slug FOR UPDATE',
					$this->tableName,
					$provider,
					$deliveryId
				);
				$rows     = $this->readRows( $query );
				$existing = array();
				foreach ( $rows as $row ) {
					if ( ! hash_equals( $deliveryDigest, (string) ( $row->delivery_digest ?? '' ) ) ) {
						throw DeploymentStorageFailure::deliveryConflict();
					}
					if ( self::DELIVERY_ACK_TYPE === ( $row->package_type ?? null ) ) {
						$this->assertDeliveryAcknowledgement( $row, $provider, $deliveryId, $deliveryDigest );
						continue;
					}
					$attempt          = DeploymentAttempt::fromDatabase( $row );
					$data             = $attempt->safeData();
					$key              = $data['package_type'] . "\0" . $data['package_slug'];
					$existing[ $key ] = $attempt;
				}
				if ( array() !== $rows ) {
					return array_values( $existing );
				}
				if ( array() === $normalized ) {
					$this->reserveCapacity( 1 );
					$this->insertDeliveryAcknowledgement( $provider, $deliveryId, $deliveryDigest );

					return array();
				}

				$this->reserveCapacity( count( $normalized ) );
				$attempts = array();
				foreach ( $normalized as $target ) {
					$attempts[] = $this->insertAndRead(
						$this->rowData(
							'webhook',
							$target['operation'],
							$target['package_type'],
							$provider,
							$target['provider_repository_id'],
							$target['request'],
							$target['requested_ref'],
							$target['package_source'],
							$target['package_source_revision'],
							$deliveryId,
							$deliveryDigest
						)
					);
				}
				foreach ( $attempts as $attempt ) {
					BoosterLogger::log( 'attempt queued (webhook)', $attempt->logContext() + array( 'transition' => 'new->queued' ) );
				}

				return $attempts;
			},
			true
		);
	}

	public function claimNext(): ?DeploymentAttempt {
		RuntimeSupport::assertManagedOperationsAllowed();

		return $this->transaction(
			function (): ?DeploymentAttempt {
				$query = $this->prepare(
					"SELECT * FROM %i WHERE state = 'queued' ORDER BY created_at, id LIMIT 1 FOR UPDATE",
					$this->tableName
				);
				$rows  = $this->readRows( $query );

				return $this->claimLockedRow( $rows );
			}
		);
	}

	public function recordResolvedRef( int $attemptId, string $resolvedRef ): DeploymentAttempt {
		RuntimeSupport::assertManagedOperationsAllowed();

		$this->assertSafeText( $resolvedRef, 191 );
		return $this->runningWrite( $attemptId, array( 'resolved_ref' => $resolvedRef ) );
	}

	public function markMutationStarted( int $attemptId, ?DateTimeInterface $at = null ): DeploymentAttempt {
		RuntimeSupport::assertManagedOperationsAllowed();

		return $this->runningWrite( $attemptId, array( 'mutation_started_at' => $this->timeString( $at ?? $this->now() ) ) );
	}

	public function finish( int $attemptId, DeploymentOutcome $outcome, ?DateTimeInterface $at = null ): DeploymentAttempt {
		RuntimeSupport::assertManagedOperationsAllowed();

		$id    = $this->positiveId( $attemptId );
		$data  = array(
			'state'        => $outcome->getState()->value,
			'outcome_code' => $outcome->getCode(),
			'finished_at'  => $this->timeString( $at ?? $this->now() ),
		);
		$query = $this->updateQuery( $id, DeploymentState::RUNNING, $data );
		if ( 1 !== $this->database->query( $query ) ) {
			throw DeploymentStorageFailure::unavailable();
		}
		$attempt = $this->requireExact( $id );
		$this->assertAttemptData( $attempt, $data );
		BoosterLogger::log(
			'attempt finished',
			$attempt->logContext() + array(
				'transition'   => 'running->' . $outcome->getState()->value,
				'outcome_code' => $outcome->getCode(),
			)
		);

		return $attempt;
	}

	public function findExact( int $attemptId ): ?DeploymentAttempt {
		$query = $this->prepare( 'SELECT * FROM %i WHERE id = %d LIMIT 2', $this->tableName, $this->positiveId( $attemptId ) );
		$rows  = $this->readRows( $query );
		if ( count( $rows ) > 1 ) {
			throw DeploymentStorageFailure::inconsistent();
		}

		return isset( $rows[0] ) ? DeploymentAttempt::fromDatabase( $rows[0] ) : null;
	}

	/**
	 * Read an exact, bounded set of attempts in one query.
	 *
	 * @param list<int> $attemptIds
	 * @return array<int, DeploymentAttempt>
	 */
	public function findExactBatch( array $attemptIds ): array {
		if ( array() === $attemptIds || count( $attemptIds ) > self::MAX_MANUAL_TARGETS ) {
			throw DeploymentStorageFailure::invalidRecord();
		}

		$ids = array();
		foreach ( $attemptIds as $attemptId ) {
			$id = $this->positiveId( $attemptId );
			if ( isset( $ids[ $id ] ) ) {
				throw DeploymentStorageFailure::invalidRecord();
			}
			$ids[ $id ] = $id;
		}

		$query = $this->prepare(
			'SELECT * FROM %i WHERE id IN (' . implode( ', ', array_fill( 0, count( $ids ), '%d' ) ) . ') ORDER BY id',
			$this->tableName,
			...array_values( $ids )
		);
		$found = array();
		foreach ( $this->readRows( $query ) as $row ) {
			$attempt = DeploymentAttempt::fromDatabase( $row );
			$id      = $attempt->getId();
			if ( ! isset( $ids[ $id ] ) || isset( $found[ $id ] ) ) {
				throw DeploymentStorageFailure::inconsistent();
			}
			$found[ $id ] = $attempt;
		}

		return $found;
	}

	public function earliestQueuedAt(): ?DateTimeImmutable {
		$query = $this->prepare( "SELECT created_at FROM %i WHERE state = 'queued' ORDER BY created_at, id LIMIT 1", $this->tableName );
		$rows  = $this->readRows( $query );
		if ( array() === $rows ) {
			return null;
		}
		$value = $rows[0]->created_at ?? null;
		if ( ! is_string( $value ) ) {
			throw DeploymentStorageFailure::inconsistent();
		}

		return new DateTimeImmutable( $value, wp_timezone() );
	}

	/** @return array{queued: int, running: int, needs_attention: int} */
	public function operationalSnapshot(): array {
		$query  = $this->prepare(
			"SELECT state, COUNT(*) AS total FROM %i WHERE state IN ('queued','running') OR (state = 'needs_attention' AND resolved_at IS NULL AND resolved_by IS NULL) GROUP BY state",
			$this->tableName
		);
		$result = array(
			'queued'          => 0,
			'running'         => 0,
			'needs_attention' => 0,
		);
		foreach ( $this->readRows( $query ) as $row ) {
			$state = is_string( $row->state ?? null ) ? $row->state : '';
			if ( array_key_exists( $state, $result ) && is_numeric( $row->total ?? null ) ) {
				$result[ $state ] = (int) $row->total;
			}
		}

		return $result;
	}

	public function hasUnresolvedPackageAttempt( string $packageType, string $packageSlug ): bool {
		$this->assertPackageType( $packageType );
		$this->assertSafeText( $packageSlug, 191 );
		$rows = $this->readRows(
			$this->prepare(
				"SELECT id FROM %i WHERE package_type = %s AND package_slug = %s AND (state IN ('queued','running') OR (state = 'needs_attention' AND resolved_at IS NULL AND resolved_by IS NULL)) LIMIT 2",
				$this->tableName,
				$packageType,
				$packageSlug
			)
		);
		if ( count( $rows ) > 1 ) {
			throw DeploymentStorageFailure::inconsistent();
		}

		return array() !== $rows;
	}

	public function latestAuthenticatedDelivery( ProviderCode $provider ): ?AuthenticatedWebhookDeliveryEvidence {
		$rows = $this->readRows(
			$this->prepare(
				"SELECT provider, source, package_type, delivery_id, delivery_digest, created_at
				FROM %i
				WHERE provider = %s AND source = 'webhook'
				ORDER BY id DESC
				LIMIT 1",
				$this->tableName,
				$provider->value
			)
		);
		if ( array() === $rows ) {
			return null;
		}
		if ( 1 !== count( $rows ) ) {
			throw DeploymentStorageFailure::inconsistent();
		}

		$row         = $rows[0];
		$rowProvider = is_string( $row->provider ?? null ) ? $row->provider : '';
		$source      = is_string( $row->source ?? null ) ? $row->source : '';
		$packageType = is_string( $row->package_type ?? null ) ? $row->package_type : '';
		$deliveryId  = is_string( $row->delivery_id ?? null ) ? $row->delivery_id : '';
		$digest      = is_string( $row->delivery_digest ?? null ) ? $row->delivery_digest : '';
		$createdAt   = is_string( $row->created_at ?? null ) ? $row->created_at : '';
		if ( ! hash_equals( $provider->value, $rowProvider )
			|| 'webhook' !== $source
			|| ! in_array( $packageType, array( 'plugin', 'theme', self::DELIVERY_ACK_TYPE ), true )
		) {
			throw DeploymentStorageFailure::inconsistent();
		}
		$this->assertSafeText( $deliveryId, 191 );
		$this->assertHex( $digest, 64 );

		try {
			return new AuthenticatedWebhookDeliveryEvidence(
				$provider,
				$createdAt,
				self::DELIVERY_ACK_TYPE !== $packageType
			);
		} catch ( \InvalidArgumentException ) {
			throw DeploymentStorageFailure::inconsistent();
		}
	}

	/** @return array{valid: bool, maximum_rows: int, source: 'configured'|'default'} */
	public function retentionConfigurationStatus(): array {
		if ( null !== $this->retentionConfiguration ) {
			return $this->retentionConfiguration;
		}

		$value  = $this->configuredMaxAttemptRows;
		$source = 'configured';
		if ( null === $value && ! defined( 'RAN_BOOSTER_MAX_ATTEMPT_ROWS' ) ) {
			$source = 'default';
			$value  = self::DEFAULT_MAX_ATTEMPT_ROWS;
		} elseif ( null === $value ) {
			$value = constant( 'RAN_BOOSTER_MAX_ATTEMPT_ROWS' );
		}
		$valid = is_int( $value )
			&& $value >= self::DEFAULT_MAX_ATTEMPT_ROWS
			&& $value <= self::MAX_ATTEMPT_ROWS;

		$this->retentionConfiguration = array(
			'valid'        => $valid,
			'maximum_rows' => $valid ? $value : self::DEFAULT_MAX_ATTEMPT_ROWS,
			'source'       => $source,
		);

		return $this->retentionConfiguration;
	}

	/** @return list<DeploymentAttempt> */
	public function recentHistory( int $limit = 25, ?int $beforeId = null ): array {
		$limit = $this->historyLimit( $limit );
		$query = $this->prepare(
			'SELECT * FROM %i WHERE package_type IN (%s, %s)',
			$this->tableName,
			'plugin',
			'theme'
		);
		if ( null !== $beforeId ) {
			$query .= $this->prepare( ' AND id < %d', $this->positiveId( $beforeId ) );
		}
		$query .= $this->prepare( ' ORDER BY id DESC LIMIT %d', $limit );

		return array_map( array( DeploymentAttempt::class, 'fromDatabase' ), $this->readRows( $query ) );
	}

	/**
	 * Read the newest attempt and newest successful attempt for one package.
	 *
	 * The two scalar subqueries keep this to one bounded database read even when
	 * the most recent activity is newer than the last successful deployment.
	 *
	 * @return array{latest: DeploymentAttempt|null, last_successful: DeploymentAttempt|null}
	 */
	public function packageActivitySummary( string $packageType, string $packageSlug ): array {
		$this->assertPackageType( $packageType );
		$this->assertPackageSlug( $packageSlug );
		$query    = $this->prepare(
			'SELECT * FROM %i AS attempts
			WHERE attempts.id = (
				SELECT MAX(latest.id) FROM %i AS latest WHERE latest.package_type = %s AND latest.package_slug = %s
			) OR attempts.id = (
				SELECT MAX(success.id) FROM %i AS success WHERE success.package_type = %s AND success.package_slug = %s AND success.state = %s
			)
			ORDER BY attempts.id DESC LIMIT 2',
			$this->tableName,
			$this->tableName,
			$packageType,
			$packageSlug,
			$this->tableName,
			$packageType,
			$packageSlug,
			DeploymentState::SUCCEEDED->value
		);
		$attempts = array_map( array( DeploymentAttempt::class, 'fromDatabase' ), $this->readRows( $query ) );
		$success  = null;
		foreach ( $attempts as $attempt ) {
			if ( DeploymentState::SUCCEEDED === $attempt->getState() ) {
				$success = $attempt;
				break;
			}
		}

		return array(
			'latest'          => $attempts[0] ?? null,
			'last_successful' => $success,
		);
	}

	/**
	 * Reconcile only after an administrator confirms the worker stopped.
	 */
	public function reconcileConfirmedStopped( int $attemptId, ?DateTimeInterface $at = null ): DeploymentAttempt {
		RuntimeSupport::assertManagedOperationsAllowed();

		$id = $this->positiveId( $attemptId );

		return $this->transaction(
			function () use ( $id, $at ): DeploymentAttempt {
				$row    = $this->lockedAttemptRow( $id );
				$stored = DeploymentAttempt::fromDatabase( $row );
				$safe   = $stored->safeData();
				if ( DeploymentState::RUNNING !== $stored->getState() ) {
					throw DeploymentStorageFailure::inconsistent();
				}
				$outcome = null === $safe['mutation_started_at']
					? DeploymentOutcome::fromCode( DeploymentOutcome::CODE_WORKER_STOPPED )
					: DeploymentOutcome::fromCode( DeploymentOutcome::CODE_INTERRUPTED );
				$data    = array(
					'state'        => $outcome->getState()->value,
					'outcome_code' => $outcome->getCode(),
					'finished_at'  => $this->timeString( $at ?? $this->now() ),
				);
				if ( 1 !== $this->database->query( $this->updateQuery( $id, DeploymentState::RUNNING, $data ) ) ) {
					throw DeploymentStorageFailure::unavailable();
				}
				$attempt = $this->requireExact( $id );
				$this->assertAttemptData( $attempt, $data );
				BoosterLogger::log(
					'attempt reconciled as stopped',
					$attempt->logContext() + array(
						'transition'   => 'running->' . $outcome->getState()->value,
						'outcome_code' => $outcome->getCode(),
					)
				);
				return $attempt;
			}
		);
	}

	public function resolveNeedsAttention(
		int $attemptId,
		string $correlationId,
		int $resolvedBy,
		?DateTimeInterface $at = null
	): DeploymentAttempt {
		RuntimeSupport::assertManagedOperationsAllowed();

		$id = $this->positiveId( $attemptId );
		$this->assertHex( $correlationId, 32 );
		$userId = $this->positiveId( $resolvedBy );

		return $this->transaction(
			function () use ( $id, $correlationId, $userId, $at ): DeploymentAttempt {
				$row    = $this->lockedAttemptRow( $id, $correlationId );
				$stored = DeploymentAttempt::fromDatabase( $row );
				if ( ! $stored->requiresOperatorResolution() ) {
					throw DeploymentStorageFailure::inconsistent();
				}
				$data = array(
					'resolved_at' => $this->timeString( $at ?? $this->now() ),
					'resolved_by' => (string) $userId,
				);
				if ( 1 !== $this->database->query( $this->updateQuery( $id, DeploymentState::NEEDS_ATTENTION, $data ) ) ) {
					throw DeploymentStorageFailure::unavailable();
				}
				$attempt = $this->requireExact( $id );
				$this->assertAttemptData( $attempt, $data );
				if ( ! hash_equals( $correlationId, $attempt->getCorrelationId() ) || $attempt->requiresOperatorResolution() ) {
					throw DeploymentStorageFailure::inconsistent();
				}
				BoosterLogger::log(
					'attempt operator resolution recorded',
					$attempt->logContext() + array(
						'resolved_by' => $userId,
						'transition'  => 'needs_attention->resolved',
					)
				);

				return $attempt;
			}
		);
	}

	/** @param list<object> $rows */
	private function claimLockedRow( array $rows ): ?DeploymentAttempt {
		if ( array() === $rows ) {
			return null;
		}
		if ( count( $rows ) !== 1 ) {
			throw DeploymentStorageFailure::inconsistent();
		}
		$queued = DeploymentAttempt::fromDatabase( $rows[0] );
		$query  = $this->updateQuery( $queued->getId(), DeploymentState::QUEUED, array( 'state' => DeploymentState::RUNNING->value ) );
		if ( 1 !== $this->database->query( $query ) ) {
			throw DeploymentStorageFailure::unavailable();
		}
		$running = $this->requireExact( $queued->getId() );
		if ( DeploymentState::RUNNING !== $running->getState() ) {
			throw DeploymentStorageFailure::inconsistent();
		}
		BoosterLogger::log( 'attempt claimed from queue', $running->logContext() + array( 'transition' => 'queued->running' ) );

		return $running;
	}

	/** @param array<string, string|null> $data */
	private function runningWrite( int $attemptId, array $data ): DeploymentAttempt {
		$id = $this->positiveId( $attemptId );
		if ( 1 !== $this->database->query( $this->updateQuery( $id, DeploymentState::RUNNING, $data ) ) ) {
			throw DeploymentStorageFailure::unavailable();
		}
		$attempt = $this->requireExact( $id );
		$safe    = $attempt->safeData();
		foreach ( $data as $key => $value ) {
			if ( (string) ( $safe[ $key ] ?? '' ) !== (string) $value ) {
				throw DeploymentStorageFailure::inconsistent();
			}
		}

		return $attempt;
	}

	/** @param array<string, string|null> $data */
	private function updateQuery( int $id, DeploymentState $currentState, array $data ): string {
		$assignments = array();
		$arguments   = array( $this->tableName );
		foreach ( $data as $column => $value ) {
			if ( preg_match( '/^[a-z_]+$/D', $column ) !== 1 ) {
				throw DeploymentStorageFailure::invalidRecord();
			}
			$assignments[] = null === $value ? "$column = NULL" : "$column = %s";
			if ( null !== $value ) {
				$arguments[] = $value;
			}
		}
		$arguments[] = $id;
		$arguments[] = $currentState->value;

		return $this->prepare(
			'UPDATE %i SET ' . implode( ', ', $assignments ) . ' WHERE id = %d AND state = %s',
			...$arguments
		);
	}

	/** @return array<string, int|string|null> */
	private function rowData(
		string $source,
		string $operation,
		string $packageType,
		string $provider,
		string $providerRepositoryId,
		DeploymentRequest $request,
		string $requestedRef,
		string $packageSource,
		int $packageSourceRevision,
		?string $deliveryId,
		?string $deliveryDigest
	): array {
		return array(
			'correlation_id'          => bin2hex( ( $this->randomBytes )( 16 ) ),
			'source'                  => $source,
			'operation'               => $operation,
			'package_type'            => $packageType,
			'package_slug'            => $request->packageSlug,
			'package_source'          => $packageSource,
			'package_source_revision' => $packageSourceRevision,
			'provider'                => $provider,
			'provider_repository_id'  => $providerRepositoryId,
			'requested_ref'           => $requestedRef,
			'resolved_ref'            => null,
			'delivery_id'             => $deliveryId,
			'delivery_digest'         => $deliveryDigest,
			'state'                   => DeploymentState::QUEUED->value,
			'mutation_started_at'     => null,
			'outcome_code'            => null,
			'request_json'            => $request->toJson(),
			'created_at'              => $this->timeString( $this->now() ),
			'finished_at'             => null,
			'resolved_at'             => null,
			'resolved_by'             => null,
		);
	}

	/** @param array<string, int|string|null> $data */
	private function insertAndRead( array $data ): DeploymentAttempt {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- This is the deployment persistence boundary.
		if ( 1 !== $this->database->insert( $this->tableName, $data ) ) {
			throw DeploymentStorageFailure::unavailable();
		}
		$query = $this->prepare( 'SELECT * FROM %i WHERE correlation_id = %s LIMIT 2', $this->tableName, $data['correlation_id'] );
		$rows  = $this->readRows( $query );
		if ( count( $rows ) !== 1 ) {
			throw DeploymentStorageFailure::inconsistent();
		}
		$attempt = DeploymentAttempt::fromDatabase( $rows[0] );
		$this->assertRowData( $rows[0], $data );

		return $attempt;
	}

	private function insertDeliveryAcknowledgement( string $provider, string $deliveryId, string $deliveryDigest ): void {
		$created = $this->timeString( $this->now() );
		$data    = array(
			'correlation_id'          => bin2hex( ( $this->randomBytes )( 16 ) ),
			'source'                  => 'webhook',
			'operation'               => 'update',
			'package_type'            => self::DELIVERY_ACK_TYPE,
			'package_slug'            => $this->deliveryAcknowledgementSlug( $provider, $deliveryId ),
			'package_source'          => 'branch',
			'package_source_revision' => 0,
			'provider'                => $provider,
			'provider_repository_id'  => self::DELIVERY_ACK_TYPE,
			'requested_ref'           => self::DELIVERY_ACK_TYPE,
			'resolved_ref'            => null,
			'delivery_id'             => $deliveryId,
			'delivery_digest'         => $deliveryDigest,
			'state'                   => DeploymentState::SUCCEEDED->value,
			'mutation_started_at'     => null,
			'outcome_code'            => DeploymentOutcome::CODE_NO_CHANGE,
			'request_json'            => '{}',
			'created_at'              => $created,
			'finished_at'             => $created,
			'resolved_at'             => null,
			'resolved_by'             => null,
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- This is the durable zero-target delivery acknowledgement.
		if ( 1 !== $this->database->insert( $this->tableName, $data ) ) {
			throw DeploymentStorageFailure::unavailable();
		}
		$query = $this->prepare( 'SELECT * FROM %i WHERE correlation_id = %s LIMIT 2', $this->tableName, $data['correlation_id'] );
		$rows  = $this->readRows( $query );
		if ( count( $rows ) !== 1 ) {
			throw DeploymentStorageFailure::inconsistent();
		}
		$this->assertRowData( $rows[0], $data );
	}

	private function assertDeliveryAcknowledgement( object $row, string $provider, string $deliveryId, string $deliveryDigest ): void {
		$this->assertRowData(
			$row,
			array(
				'source'                  => 'webhook',
				'operation'               => 'update',
				'package_type'            => self::DELIVERY_ACK_TYPE,
				'package_slug'            => $this->deliveryAcknowledgementSlug( $provider, $deliveryId ),
				'package_source'          => 'branch',
				'package_source_revision' => 0,
				'provider'                => $provider,
				'provider_repository_id'  => self::DELIVERY_ACK_TYPE,
				'requested_ref'           => self::DELIVERY_ACK_TYPE,
				'resolved_ref'            => null,
				'delivery_id'             => $deliveryId,
				'delivery_digest'         => $deliveryDigest,
				'state'                   => DeploymentState::SUCCEEDED->value,
				'mutation_started_at'     => null,
				'outcome_code'            => DeploymentOutcome::CODE_NO_CHANGE,
				'request_json'            => '{}',
				'resolved_at'             => null,
				'resolved_by'             => null,
			)
		);
	}

	private function deliveryAcknowledgementSlug( string $provider, string $deliveryId ): string {
		return 'delivery-' . substr( hash( 'sha256', $provider . "\0" . $deliveryId ), 0, 32 );
	}

	private function requireExact( int $id ): DeploymentAttempt {
		$attempt = $this->findExact( $id );
		if ( null === $attempt ) {
			throw DeploymentStorageFailure::inconsistent();
		}

		return $attempt;
	}

	private function lockedAttemptRow( int $id, ?string $correlationId = null ): object {
		$query = null === $correlationId
			? $this->prepare( 'SELECT * FROM %i WHERE id = %d LIMIT 2 FOR UPDATE', $this->tableName, $id )
			: $this->prepare( 'SELECT * FROM %i WHERE id = %d AND correlation_id = %s LIMIT 2 FOR UPDATE', $this->tableName, $id, $correlationId );
		$rows  = $this->readRows( $query );
		if ( count( $rows ) !== 1 ) {
			throw DeploymentStorageFailure::inconsistent();
		}

		return $rows[0];
	}

	private function activePackageAttempt( string $packageType, string $packageSlug ): ?DeploymentAttempt {
		$query = $this->prepare(
			"SELECT * FROM %i WHERE package_type = %s AND package_slug = %s AND (state IN ('queued','running') OR (state = 'needs_attention' AND resolved_at IS NULL AND resolved_by IS NULL)) ORDER BY created_at DESC, id DESC LIMIT 1 FOR UPDATE",
			$this->tableName,
			$packageType,
			$packageSlug
		);
		$rows  = $this->readRows( $query );
		if ( count( $rows ) > 1 ) {
			throw DeploymentStorageFailure::inconsistent();
		}

		return isset( $rows[0] ) ? DeploymentAttempt::fromDatabase( $rows[0] ) : null;
	}

	private function reserveCapacity( int $incomingRows ): void {
		if ( 0 === $incomingRows ) {
			return;
		}
		if ( $incomingRows < 0 || $incomingRows > self::MAX_WEBHOOK_TARGETS ) {
			throw DeploymentStorageFailure::invalidRecord();
		}

		$countRows = $this->readRows(
			$this->prepare(
				'SELECT COUNT(*) AS total FROM %i FOR UPDATE',
				$this->tableName
			)
		);
		if ( count( $countRows ) !== 1 || ! is_numeric( $countRows[0]->total ?? null ) ) {
			throw DeploymentStorageFailure::inconsistent();
		}
		$currentRows = (int) $countRows[0]->total;
		if ( $currentRows < 0 ) {
			throw DeploymentStorageFailure::inconsistent();
		}

		$maximumRows = $this->retentionConfigurationStatus()['maximum_rows'];
		$pruneRows   = max( 0, $currentRows + $incomingRows - $maximumRows );
		if ( 0 === $pruneRows ) {
			return;
		}

		$candidates = $this->readRows(
			$this->prepare(
				"SELECT id FROM %i WHERE (state IN ('succeeded','failed') OR (state = 'needs_attention' AND resolved_at IS NOT NULL AND resolved_by IS NOT NULL)) ORDER BY created_at, id LIMIT %d FOR UPDATE",
				$this->tableName,
				$pruneRows
			)
		);
		if ( count( $candidates ) !== $pruneRows ) {
			throw DeploymentStorageFailure::capacityExhausted();
		}

		$ids = array();
		foreach ( $candidates as $candidate ) {
			$id = is_numeric( $candidate->id ?? null ) ? (int) $candidate->id : 0;
			if ( $id < 1 || isset( $ids[ $id ] ) ) {
				throw DeploymentStorageFailure::inconsistent();
			}
			$ids[ $id ] = $id;
		}
		$query = $this->prepare(
			'DELETE FROM %i WHERE id IN (' . implode( ', ', array_fill( 0, count( $ids ), '%d' ) ) . ") AND (state IN ('succeeded','failed') OR (state = 'needs_attention' AND resolved_at IS NOT NULL AND resolved_by IS NOT NULL))",
			$this->tableName,
			...array_values( $ids )
		);
		if ( count( $ids ) !== $this->database->query( $query ) ) {
			throw DeploymentStorageFailure::unavailable();
		}
	}

	private function transaction( callable $operation, bool $serializable = false ): mixed {
		$this->requireStorageSupport();
		if ( $serializable && false === $this->database->query( 'SET TRANSACTION ISOLATION LEVEL SERIALIZABLE' ) ) {
			throw DeploymentStorageFailure::unavailable();
		}
		if ( false === $this->database->query( 'START TRANSACTION' ) ) {
			throw DeploymentStorageFailure::unavailable();
		}
		try {
			$result = $operation();
			if ( false === $this->database->query( 'COMMIT' ) ) {
				throw DeploymentStorageFailure::transactionCommitFailed();
			}

			return $result;
		} catch ( Throwable $exception ) {
			$this->database->query( 'ROLLBACK' );
			throw $exception;
		}
	}

	/** @param array<string, int|string|null> $expected */
	private function assertRowData( object $row, array $expected ): void {
		foreach ( $expected as $column => $value ) {
			if ( ! property_exists( $row, $column ) || ! $this->sameStoredValue( $row->{$column}, $value ) ) {
				throw DeploymentStorageFailure::inconsistent();
			}
		}
	}

	/** @param array<string, string|null> $expected */
	private function assertAttemptData( DeploymentAttempt $attempt, array $expected ): void {
		$stored = $attempt->safeData();
		foreach ( $expected as $column => $value ) {
			if ( ! array_key_exists( $column, $stored ) || ! $this->sameStoredValue( $stored[ $column ], $value ) ) {
				throw DeploymentStorageFailure::inconsistent();
			}
		}
	}

	private function sameStoredValue( mixed $stored, mixed $expected ): bool {
		return null === $expected ? null === $stored : is_scalar( $stored ) && hash_equals( (string) $expected, (string) $stored );
	}

	/** @return list<object> */
	private function readRows( string $query ): array {
		$this->database->last_error = '';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Durable state cannot use object caching.
		$rows = $this->database->get_results( $query );
		if ( ! is_array( $rows ) || '' !== (string) $this->database->last_error ) {
			throw DeploymentStorageFailure::unavailable();
		}

		if ( count( $rows ) !== count( array_filter( $rows, 'is_object' ) ) ) {
			throw DeploymentStorageFailure::inconsistent();
		}

		return array_values( $rows );
	}

	private function prepare( string $query, mixed ...$arguments ): string {
		$this->requireStorageSupport();

		return $this->database->prepare( $query, ...$arguments );
	}

	private function requireStorageSupport(): void {
		try {
			$this->databaseLifecycle->requireReady();
		} catch ( DatabaseCompatibilityFailure | DatabaseLifecycleFailure ) {
			throw DeploymentStorageFailure::unsupportedDatabase();
		}
	}

	private function now(): DateTimeImmutable {
		$now = ( $this->clock )();

		return DateTimeImmutable::createFromInterface( $now );
	}

	private function timeString( DateTimeInterface $time ): string {
		return $time->format( 'Y-m-d H:i:s' );
	}

	private function positiveId( int $id ): int {
		if ( $id < 1 ) {
			throw DeploymentStorageFailure::invalidRecord();
		}

		return $id;
	}

	private function historyLimit( int $limit ): int {
		if ( $limit < 1 || $limit > self::MAX_HISTORY ) {
			throw DeploymentStorageFailure::invalidRecord();
		}

		return $limit;
	}

	private function assertOperation( string $operation ): void {
		if ( ! in_array( $operation, array( 'install', 'update' ), true ) ) {
			throw DeploymentStorageFailure::invalidRecord();
		}
	}

	private function assertPackageType( string $packageType ): void {
		if ( ! in_array( $packageType, array( 'plugin', 'theme' ), true ) ) {
			throw DeploymentStorageFailure::invalidRecord();
		}
	}

	private function assertPackageSource(
		string $packageSource,
		int $packageSourceRevision
	): void {
		if ( 'branch' !== $packageSource || $packageSourceRevision < 0 ) {
			throw DeploymentStorageFailure::invalidRecord();
		}
	}

	private function assertPackageSlug( string $packageSlug ): void {
		if ( preg_match( '/^[a-z0-9][a-z0-9._-]{0,190}$/D', $packageSlug ) !== 1 ) {
			throw DeploymentStorageFailure::invalidRecord();
		}
	}

	private function assertProvider( string $provider ): void {
		if ( preg_match( '/^[a-z][a-z0-9-]{0,31}$/D', $provider ) !== 1 ) {
			throw DeploymentStorageFailure::invalidRecord();
		}
	}

	private function assertHex( string $value, int $length ): void {
		if ( preg_match( sprintf( '/^[a-f0-9]{%d}$/D', $length ), $value ) !== 1 ) {
			throw DeploymentStorageFailure::invalidRecord();
		}
	}

	private function assertSafeText( string $value, int $limit ): void {
		if ( '' === $value || strlen( $value ) > $limit || preg_match( '//u', $value ) !== 1
			|| preg_match( '/[[:cntrl:]]/', $value ) === 1
			|| preg_match( '/(?:https?:\/\/|[A-Za-z][A-Za-z0-9+.-]*:\/\/)[^\s]*@/i', $value ) === 1
			|| preg_match( '/\b(?:authorization|bearer|token|secret|password|signature)\b\s*[:=]/i', $value ) === 1 ) {
			throw DeploymentStorageFailure::invalidRecord();
		}
	}
}
