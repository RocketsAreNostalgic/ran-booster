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
	private $random_bytes;
	private Database $database_lifecycle;
	/** @var array{valid: bool, maximum_rows: int, source: 'configured'|'default'}|null */
	private ?array $retention_configuration = null;

	public function __construct(
		private ?object $database = null,
		private ?string $table_name = null,
		?callable $clock = null,
		?callable $random_bytes = null,
		?Database $database_lifecycle = null,
		private mixed $configured_max_attempt_rows = null
	) {
		if ( null === $this->database ) {
			global $wpdb;
			$this->database = $wpdb;
		}
		if ( null === $this->table_name ) {
			$this->table_name = \RAN\Storage\Database::attempt_table_name();
		}
		$this->clock              = $clock ?? static fn (): DateTimeImmutable => new DateTimeImmutable( 'now', wp_timezone() );
		$this->random_bytes       = $random_bytes ?? static fn ( int $length ): string => random_bytes( $length );
		$this->database_lifecycle = $database_lifecycle ?? new Database( $this->database );
	}
	public function admit_and_claim_manual(
		string $operation,
		string $package_type,
		string $provider,
		string $provider_repository_id,
		DeploymentRequest $request,
		string $requested_ref,
		string $package_source,
		int $package_source_revision
	): DeploymentAttempt {
		RuntimeSupport::assert_managed_operations_allowed();

		$this->assert_operation( $operation );
		$this->assert_package_type( $package_type );
		$this->assert_provider( $provider );
		$this->assert_safe_text( $provider_repository_id, 191 );
		$this->assert_safe_text( $requested_ref, 255 );
		$this->assert_package_source( $package_source, $package_source_revision );

		return $this->transaction(
			function () use ( $operation, $package_type, $provider, $provider_repository_id, $request, $requested_ref, $package_source, $package_source_revision ): DeploymentAttempt {
				$active = $this->active_package_attempt( $package_type, $request->package_slug );
				if ( null !== $active ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The failure stores a validated, whitelisted attempt projection; it does not render output.
					throw DeploymentStorageFailure::contention( $active->safe_data() );
				}

				$this->reserve_capacity( 1 );
				$queued = $this->insert_and_read(
					$this->row_data(
						'manual',
						$operation,
						$package_type,
						$provider,
						$provider_repository_id,
						$request,
						$requested_ref,
						$package_source,
						$package_source_revision,
						null,
						null
					)
				);
				$query  = $this->update_query( $queued->get_id(), DeploymentState::QUEUED, array( 'state' => DeploymentState::RUNNING->value ) );
				if ( 1 !== $this->database->query( $query ) ) {
					throw DeploymentStorageFailure::unavailable();
				}
				$running = $this->require_exact( $queued->get_id() );
				if ( DeploymentState::RUNNING !== $running->get_state() ) {
					throw DeploymentStorageFailure::inconsistent();
				}
				BoosterLogger::log( 'attempt queued and claimed (manual)', $running->log_context() + array( 'transition' => 'queued->running' ) );

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
	public function admit_manual_batch( array $targets ): array {
		RuntimeSupport::assert_managed_operations_allowed();

		if ( array() === $targets || count( $targets ) > self::MAX_MANUAL_TARGETS ) {
			throw DeploymentStorageFailure::invalid_record();
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
				throw DeploymentStorageFailure::invalid_record();
			}
			$this->assert_package_type( $target['package_type'] );
			$this->assert_provider( $target['provider'] );
			$this->assert_safe_text( $target['provider_repository_id'], 191 );
			$this->assert_safe_text( $target['requested_ref'], 255 );
			$this->assert_package_source( $target['package_source'], $target['package_source_revision'] );
			$key = $target['package_type'] . "\0" . $target['request']->package_slug;
			if ( isset( $normalized[ $key ] ) ) {
				throw DeploymentStorageFailure::invalid_record();
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
					$active  = $this->active_package_attempt( $target['package_type'], $request->package_slug );
					if ( null !== $active ) {
						$busy[] = $active->safe_data();
						continue;
					}

					$admitted[] = $target;
				}

				$this->reserve_capacity( count( $admitted ) );
				$attempts = array();
				foreach ( $admitted as $target ) {
					$request    = $target['request'];
					$attempts[] = $this->insert_and_read(
						$this->row_data(
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
			BoosterLogger::log( 'attempt queued (manual batch)', $attempt->log_context() + array( 'transition' => 'new->queued' ) );
		}

		return $result;
	}

	/**
	 * @param list<array{operation: string, package_type: string, provider_repository_id: string, requested_ref: string, package_source: string, package_source_revision: int, request: DeploymentRequest}> $targets
	 * @return list<DeploymentAttempt>
	 */
	public function admit_webhook_batch( string $provider, string $delivery_id, string $delivery_digest, array $targets ): array {
		RuntimeSupport::assert_managed_operations_allowed();

		$this->assert_provider( $provider );
		$this->assert_safe_text( $delivery_id, 191 );
		$this->assert_hex( $delivery_digest, 64 );
		if ( count( $targets ) > self::MAX_WEBHOOK_TARGETS ) {
			throw DeploymentStorageFailure::invalid_record();
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
				throw DeploymentStorageFailure::invalid_record();
			}
			$this->assert_operation( $target['operation'] );
			$this->assert_package_type( $target['package_type'] );
			$this->assert_safe_text( $target['provider_repository_id'], 191 );
			$this->assert_safe_text( $target['requested_ref'], 255 );
			$this->assert_package_source( $target['package_source'], $target['package_source_revision'] );
			$key = $target['package_type'] . "\0" . $target['request']->package_slug;
			if ( isset( $normalized[ $key ] ) ) {
				throw DeploymentStorageFailure::invalid_record();
			}
			$normalized[ $key ] = $target;
		}
		ksort( $normalized, SORT_STRING );

		return $this->transaction(
			function () use ( $provider, $delivery_id, $delivery_digest, $normalized ): array {
				$query    = $this->prepare(
					'SELECT * FROM %i WHERE provider = %s AND delivery_id = %s ORDER BY package_type, package_slug FOR UPDATE',
					$this->table_name,
					$provider,
					$delivery_id
				);
				$rows     = $this->read_rows( $query );
				$existing = array();
				foreach ( $rows as $row ) {
					if ( ! hash_equals( $delivery_digest, (string) ( $row->delivery_digest ?? '' ) ) ) {
						throw DeploymentStorageFailure::delivery_conflict();
					}
					if ( self::DELIVERY_ACK_TYPE === ( $row->package_type ?? null ) ) {
						$this->assert_delivery_acknowledgement( $row, $provider, $delivery_id, $delivery_digest );
						continue;
					}
					$attempt          = DeploymentAttempt::from_database( $row );
					$data             = $attempt->safe_data();
					$key              = $data['package_type'] . "\0" . $data['package_slug'];
					$existing[ $key ] = $attempt;
				}
				if ( array() !== $rows ) {
					return array_values( $existing );
				}
				if ( array() === $normalized ) {
					$this->reserve_capacity( 1 );
					$this->insert_delivery_acknowledgement( $provider, $delivery_id, $delivery_digest );

					return array();
				}

				$this->reserve_capacity( count( $normalized ) );
				$attempts = array();
				foreach ( $normalized as $target ) {
					$attempts[] = $this->insert_and_read(
						$this->row_data(
							'webhook',
							$target['operation'],
							$target['package_type'],
							$provider,
							$target['provider_repository_id'],
							$target['request'],
							$target['requested_ref'],
							$target['package_source'],
							$target['package_source_revision'],
							$delivery_id,
							$delivery_digest
						)
					);
				}
				foreach ( $attempts as $attempt ) {
					BoosterLogger::log( 'attempt queued (webhook)', $attempt->log_context() + array( 'transition' => 'new->queued' ) );
				}

				return $attempts;
			},
			true
		);
	}
	public function claim_next(): ?DeploymentAttempt {
		RuntimeSupport::assert_managed_operations_allowed();

		return $this->transaction(
			function (): ?DeploymentAttempt {
				$query = $this->prepare(
					"SELECT * FROM %i WHERE state = 'queued' ORDER BY created_at, id LIMIT 1 FOR UPDATE",
					$this->table_name
				);
				$rows  = $this->read_rows( $query );

				return $this->claim_locked_row( $rows );
			}
		);
	}
	public function record_resolved_ref( int $attempt_id, string $resolved_ref ): DeploymentAttempt {
		RuntimeSupport::assert_managed_operations_allowed();
		$this->assert_safe_text( $resolved_ref, 191 );
		return $this->running_write( $attempt_id, array( 'resolved_ref' => $resolved_ref ) );
	}
	public function mark_mutation_started( int $attempt_id, ?DateTimeInterface $at = null ): DeploymentAttempt {
		RuntimeSupport::assert_managed_operations_allowed();
		return $this->running_write( $attempt_id, array( 'mutation_started_at' => $this->time_string( $at ?? $this->now() ) ) );
	}
	public function finish( int $attempt_id, DeploymentOutcome $outcome, ?DateTimeInterface $at = null ): DeploymentAttempt {
		RuntimeSupport::assert_managed_operations_allowed();
		$id    = $this->positive_id( $attempt_id );
		$data  = array(
			'state'        => $outcome->get_state()->value,
			'outcome_code' => $outcome->get_code(),
			'finished_at'  => $this->time_string( $at ?? $this->now() ),
		);
		$query = $this->update_query( $id, DeploymentState::RUNNING, $data );
		if ( 1 !== $this->database->query( $query ) ) {
			throw DeploymentStorageFailure::unavailable();
		}
		$attempt = $this->require_exact( $id );
		$this->assert_attempt_data( $attempt, $data );
		BoosterLogger::log(
			'attempt finished',
			$attempt->log_context() + array(
				'transition'   => 'running->' . $outcome->get_state()->value,
				'outcome_code' => $outcome->get_code(),
			)
		);

		return $attempt;
	}
	public function find_exact( int $attempt_id ): ?DeploymentAttempt {
		$query = $this->prepare( 'SELECT * FROM %i WHERE id = %d LIMIT 2', $this->table_name, $this->positive_id( $attempt_id ) );
		$rows  = $this->read_rows( $query );
		if ( count( $rows ) > 1 ) {
			throw DeploymentStorageFailure::inconsistent();
		}

		return isset( $rows[0] ) ? DeploymentAttempt::from_database( $rows[0] ) : null;
	}

	/**
	 * Read an exact, bounded set of attempts in one query.
	 *
	 * @param list<int> $attempt_ids
	 * @return array<int, DeploymentAttempt>
	 */
	public function find_exact_batch( array $attempt_ids ): array {
		if ( array() === $attempt_ids || count( $attempt_ids ) > self::MAX_MANUAL_TARGETS ) {
			throw DeploymentStorageFailure::invalid_record();
		}

		$ids = array();
		foreach ( $attempt_ids as $attempt_id ) {
			$id = $this->positive_id( $attempt_id );
			if ( isset( $ids[ $id ] ) ) {
				throw DeploymentStorageFailure::invalid_record();
			}
			$ids[ $id ] = $id;
		}

		$query = $this->prepare(
			'SELECT * FROM %i WHERE id IN (' . implode( ', ', array_fill( 0, count( $ids ), '%d' ) ) . ') ORDER BY id',
			$this->table_name,
			...array_values( $ids )
		);
		$found = array();
		foreach ( $this->read_rows( $query ) as $row ) {
			$attempt = DeploymentAttempt::from_database( $row );
			$id      = $attempt->get_id();
			if ( ! isset( $ids[ $id ] ) || isset( $found[ $id ] ) ) {
				throw DeploymentStorageFailure::inconsistent();
			}
			$found[ $id ] = $attempt;
		}

		return $found;
	}
	public function earliest_queued_at(): ?DateTimeImmutable {
		$query = $this->prepare( "SELECT created_at FROM %i WHERE state = 'queued' ORDER BY created_at, id LIMIT 1", $this->table_name );
		$rows  = $this->read_rows( $query );
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
	public function operational_snapshot(): array {
		$query  = $this->prepare(
			"SELECT state, COUNT(*) AS total FROM %i WHERE state IN ('queued','running') OR (state = 'needs_attention' AND resolved_at IS NULL AND resolved_by IS NULL) GROUP BY state",
			$this->table_name
		);
		$result = array(
			'queued'          => 0,
			'running'         => 0,
			'needs_attention' => 0,
		);
		foreach ( $this->read_rows( $query ) as $row ) {
			$state = is_string( $row->state ?? null ) ? $row->state : '';
			if ( array_key_exists( $state, $result ) && is_numeric( $row->total ?? null ) ) {
				$result[ $state ] = (int) $row->total;
			}
		}

		return $result;
	}
	public function has_unresolved_package_attempt( string $package_type, string $package_slug ): bool {
		$this->assert_package_type( $package_type );
		$this->assert_safe_text( $package_slug, 191 );
		$rows = $this->read_rows(
			$this->prepare(
				"SELECT id FROM %i WHERE package_type = %s AND package_slug = %s AND (state IN ('queued','running') OR (state = 'needs_attention' AND resolved_at IS NULL AND resolved_by IS NULL)) LIMIT 2",
				$this->table_name,
				$package_type,
				$package_slug
			)
		);
		if ( count( $rows ) > 1 ) {
			throw DeploymentStorageFailure::inconsistent();
		}

		return array() !== $rows;
	}
	public function latest_authenticated_delivery( ProviderCode $provider ): ?AuthenticatedWebhookDeliveryEvidence {
		$rows = $this->read_rows(
			$this->prepare(
				"SELECT provider, source, package_type, delivery_id, delivery_digest, created_at
				FROM %i
				WHERE provider = %s AND source = 'webhook'
				ORDER BY id DESC
				LIMIT 1",
				$this->table_name,
				$provider->value
			)
		);
		if ( array() === $rows ) {
			return null;
		}
		if ( 1 !== count( $rows ) ) {
			throw DeploymentStorageFailure::inconsistent();
		}

		$row          = $rows[0];
		$row_provider = is_string( $row->provider ?? null ) ? $row->provider : '';
		$source       = is_string( $row->source ?? null ) ? $row->source : '';
		$package_type = is_string( $row->package_type ?? null ) ? $row->package_type : '';
		$delivery_id  = is_string( $row->delivery_id ?? null ) ? $row->delivery_id : '';
		$digest       = is_string( $row->delivery_digest ?? null ) ? $row->delivery_digest : '';
		$created_at   = is_string( $row->created_at ?? null ) ? $row->created_at : '';
		if ( ! hash_equals( $provider->value, $row_provider )
			|| 'webhook' !== $source
			|| ! in_array( $package_type, array( 'plugin', 'theme', self::DELIVERY_ACK_TYPE ), true )
		) {
			throw DeploymentStorageFailure::inconsistent();
		}
		$this->assert_safe_text( $delivery_id, 191 );
		$this->assert_hex( $digest, 64 );

		try {
			return new AuthenticatedWebhookDeliveryEvidence(
				$provider,
				$created_at,
				self::DELIVERY_ACK_TYPE !== $package_type
			);
		} catch ( \InvalidArgumentException ) {
			throw DeploymentStorageFailure::inconsistent();
		}
	}

	/** @return array{valid: bool, maximum_rows: int, source: 'configured'|'default'} */
	public function retention_configuration_status(): array {
		if ( null !== $this->retention_configuration ) {
			return $this->retention_configuration;
		}
		$value  = $this->configured_max_attempt_rows;
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

		$this->retention_configuration = array(
			'valid'        => $valid,
			'maximum_rows' => $valid ? $value : self::DEFAULT_MAX_ATTEMPT_ROWS,
			'source'       => $source,
		);

		return $this->retention_configuration;
	}

	/** @return list<DeploymentAttempt> */
	public function recent_history( int $limit = 25, ?int $before_id = null ): array {
		$limit = $this->history_limit( $limit );
		$query = $this->prepare(
			'SELECT * FROM %i WHERE package_type IN (%s, %s)',
			$this->table_name,
			'plugin',
			'theme'
		);
		if ( null !== $before_id ) {
			$query .= $this->prepare( ' AND id < %d', $this->positive_id( $before_id ) );
		}
		$query .= $this->prepare( ' ORDER BY id DESC LIMIT %d', $limit );

		return array_map( array( DeploymentAttempt::class, 'from_database' ), $this->read_rows( $query ) );
	}

	/**
	 * Read the newest attempt and newest successful attempt for one package.
	 *
	 * The two scalar subqueries keep this to one bounded database read even when
	 * the most recent activity is newer than the last successful deployment.
	 *
	 * @return array{latest: DeploymentAttempt|null, last_successful: DeploymentAttempt|null}
	 */
	public function package_activity_summary( string $package_type, string $package_slug ): array {
		$this->assert_package_type( $package_type );
		$this->assert_package_slug( $package_slug );
		$query    = $this->prepare(
			'SELECT * FROM %i AS attempts
			WHERE attempts.id = (
				SELECT MAX(latest.id) FROM %i AS latest WHERE latest.package_type = %s AND latest.package_slug = %s
			) OR attempts.id = (
				SELECT MAX(success.id) FROM %i AS success WHERE success.package_type = %s AND success.package_slug = %s AND success.state = %s
			)
			ORDER BY attempts.id DESC LIMIT 2',
			$this->table_name,
			$this->table_name,
			$package_type,
			$package_slug,
			$this->table_name,
			$package_type,
			$package_slug,
			DeploymentState::SUCCEEDED->value
		);
		$attempts = array_map( array( DeploymentAttempt::class, 'from_database' ), $this->read_rows( $query ) );
		$success  = null;
		foreach ( $attempts as $attempt ) {
			if ( DeploymentState::SUCCEEDED === $attempt->get_state() ) {
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
	public function reconcile_confirmed_stopped( int $attempt_id, ?DateTimeInterface $at = null ): DeploymentAttempt {
		RuntimeSupport::assert_managed_operations_allowed();
		$id = $this->positive_id( $attempt_id );

		return $this->transaction(
			function () use ( $id, $at ): DeploymentAttempt {
				$row    = $this->locked_attempt_row( $id );
				$stored = DeploymentAttempt::from_database( $row );
				$safe   = $stored->safe_data();
				if ( DeploymentState::RUNNING !== $stored->get_state() ) {
					throw DeploymentStorageFailure::inconsistent();
				}
				$outcome = null === $safe['mutation_started_at']
					? DeploymentOutcome::from_code( DeploymentOutcome::CODE_WORKER_STOPPED )
					: DeploymentOutcome::from_code( DeploymentOutcome::CODE_INTERRUPTED );
				$data    = array(
					'state'        => $outcome->get_state()->value,
					'outcome_code' => $outcome->get_code(),
					'finished_at'  => $this->time_string( $at ?? $this->now() ),
				);
				if ( 1 !== $this->database->query( $this->update_query( $id, DeploymentState::RUNNING, $data ) ) ) {
					throw DeploymentStorageFailure::unavailable();
				}
				$attempt = $this->require_exact( $id );
				$this->assert_attempt_data( $attempt, $data );
				BoosterLogger::log(
					'attempt reconciled as stopped',
					$attempt->log_context() + array(
						'transition'   => 'running->' . $outcome->get_state()->value,
						'outcome_code' => $outcome->get_code(),
					)
				);
				return $attempt;
			}
		);
	}
	public function resolve_needs_attention(
		int $attempt_id,
		string $correlation_id,
		int $resolved_by,
		?DateTimeInterface $at = null
	): DeploymentAttempt {
		RuntimeSupport::assert_managed_operations_allowed();
		$id = $this->positive_id( $attempt_id );
		$this->assert_hex( $correlation_id, 32 );
		$user_id = $this->positive_id( $resolved_by );

		return $this->transaction(
			function () use ( $id, $correlation_id, $user_id, $at ): DeploymentAttempt {
				$row    = $this->locked_attempt_row( $id, $correlation_id );
				$stored = DeploymentAttempt::from_database( $row );
				if ( ! $stored->requires_operator_resolution() ) {
					throw DeploymentStorageFailure::inconsistent();
				}
				$data = array(
					'resolved_at' => $this->time_string( $at ?? $this->now() ),
					'resolved_by' => (string) $user_id,
				);
				if ( 1 !== $this->database->query( $this->update_query( $id, DeploymentState::NEEDS_ATTENTION, $data ) ) ) {
					throw DeploymentStorageFailure::unavailable();
				}
				$attempt = $this->require_exact( $id );
				$this->assert_attempt_data( $attempt, $data );
				if ( ! hash_equals( $correlation_id, $attempt->get_correlation_id() ) || $attempt->requires_operator_resolution() ) {
					throw DeploymentStorageFailure::inconsistent();
				}
				BoosterLogger::log(
					'attempt operator resolution recorded',
					$attempt->log_context() + array(
						'resolved_by' => $user_id,
						'transition'  => 'needs_attention->resolved',
					)
				);

				return $attempt;
			}
		);
	}

	/** @param list<object> $rows */
	private function claim_locked_row( array $rows ): ?DeploymentAttempt {
		if ( array() === $rows ) {
			return null;
		}
		if ( count( $rows ) !== 1 ) {
			throw DeploymentStorageFailure::inconsistent();
		}
		$queued = DeploymentAttempt::from_database( $rows[0] );
		$query  = $this->update_query( $queued->get_id(), DeploymentState::QUEUED, array( 'state' => DeploymentState::RUNNING->value ) );
		if ( 1 !== $this->database->query( $query ) ) {
			throw DeploymentStorageFailure::unavailable();
		}
		$running = $this->require_exact( $queued->get_id() );
		if ( DeploymentState::RUNNING !== $running->get_state() ) {
			throw DeploymentStorageFailure::inconsistent();
		}
		BoosterLogger::log( 'attempt claimed from queue', $running->log_context() + array( 'transition' => 'queued->running' ) );

		return $running;
	}

	/** @param array<string, string|null> $data */
	private function running_write( int $attempt_id, array $data ): DeploymentAttempt {
		$id = $this->positive_id( $attempt_id );
		if ( 1 !== $this->database->query( $this->update_query( $id, DeploymentState::RUNNING, $data ) ) ) {
			throw DeploymentStorageFailure::unavailable();
		}
		$attempt = $this->require_exact( $id );
		$safe    = $attempt->safe_data();
		foreach ( $data as $key => $value ) {
			if ( (string) ( $safe[ $key ] ?? '' ) !== (string) $value ) {
				throw DeploymentStorageFailure::inconsistent();
			}
		}

		return $attempt;
	}

	/** @param array<string, string|null> $data */
	private function update_query( int $id, DeploymentState $current_state, array $data ): string {
		$assignments = array();
		$arguments   = array( $this->table_name );
		foreach ( $data as $column => $value ) {
			if ( preg_match( '/^[a-z_]+$/D', $column ) !== 1 ) {
				throw DeploymentStorageFailure::invalid_record();
			}
			$assignments[] = null === $value ? "$column = NULL" : "$column = %s";
			if ( null !== $value ) {
				$arguments[] = $value;
			}
		}
		$arguments[] = $id;
		$arguments[] = $current_state->value;

		return $this->prepare(
			'UPDATE %i SET ' . implode( ', ', $assignments ) . ' WHERE id = %d AND state = %s',
			...$arguments
		);
	}

	/** @return array<string, int|string|null> */
	private function row_data(
		string $source,
		string $operation,
		string $package_type,
		string $provider,
		string $provider_repository_id,
		DeploymentRequest $request,
		string $requested_ref,
		string $package_source,
		int $package_source_revision,
		?string $delivery_id,
		?string $delivery_digest
	): array {
		return array(
			'correlation_id'          => bin2hex( ( $this->random_bytes )( 16 ) ),
			'source'                  => $source,
			'operation'               => $operation,
			'package_type'            => $package_type,
			'package_slug'            => $request->package_slug,
			'package_source'          => $package_source,
			'package_source_revision' => $package_source_revision,
			'provider'                => $provider,
			'provider_repository_id'  => $provider_repository_id,
			'requested_ref'           => $requested_ref,
			'resolved_ref'            => null,
			'delivery_id'             => $delivery_id,
			'delivery_digest'         => $delivery_digest,
			'state'                   => DeploymentState::QUEUED->value,
			'mutation_started_at'     => null,
			'outcome_code'            => null,
			'request_json'            => $request->to_json(),
			'created_at'              => $this->time_string( $this->now() ),
			'finished_at'             => null,
			'resolved_at'             => null,
			'resolved_by'             => null,
		);
	}

	/** @param array<string, int|string|null> $data */
	private function insert_and_read( array $data ): DeploymentAttempt {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- This is the deployment persistence boundary. Retain the promoted constructor or external DTO property contract.
		if ( 1 !== $this->database->insert( $this->table_name, $data ) ) {
			throw DeploymentStorageFailure::unavailable();
		}
		$query = $this->prepare( 'SELECT * FROM %i WHERE correlation_id = %s LIMIT 2', $this->table_name, $data['correlation_id'] );
		$rows  = $this->read_rows( $query );
		if ( count( $rows ) !== 1 ) {
			throw DeploymentStorageFailure::inconsistent();
		}
		$attempt = DeploymentAttempt::from_database( $rows[0] );
		$this->assert_row_data( $rows[0], $data );

		return $attempt;
	}

	private function insert_delivery_acknowledgement( string $provider, string $delivery_id, string $delivery_digest ): void {
		$created = $this->time_string( $this->now() );
		$data    = array(
			'correlation_id'          => bin2hex( ( $this->random_bytes )( 16 ) ),
			'source'                  => 'webhook',
			'operation'               => 'update',
			'package_type'            => self::DELIVERY_ACK_TYPE,
			'package_slug'            => $this->delivery_acknowledgement_slug( $provider, $delivery_id ),
			'package_source'          => 'branch',
			'package_source_revision' => 0,
			'provider'                => $provider,
			'provider_repository_id'  => self::DELIVERY_ACK_TYPE,
			'requested_ref'           => self::DELIVERY_ACK_TYPE,
			'resolved_ref'            => null,
			'delivery_id'             => $delivery_id,
			'delivery_digest'         => $delivery_digest,
			'state'                   => DeploymentState::SUCCEEDED->value,
			'mutation_started_at'     => null,
			'outcome_code'            => DeploymentOutcome::CODE_NO_CHANGE,
			'request_json'            => '{}',
			'created_at'              => $created,
			'finished_at'             => $created,
			'resolved_at'             => null,
			'resolved_by'             => null,
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- This is the durable zero-target delivery acknowledgement. Retain the promoted constructor or external DTO property contract.
		if ( 1 !== $this->database->insert( $this->table_name, $data ) ) {
			throw DeploymentStorageFailure::unavailable();
		}
		$query = $this->prepare( 'SELECT * FROM %i WHERE correlation_id = %s LIMIT 2', $this->table_name, $data['correlation_id'] );
		$rows  = $this->read_rows( $query );
		if ( count( $rows ) !== 1 ) {
			throw DeploymentStorageFailure::inconsistent();
		}
		$this->assert_row_data( $rows[0], $data );
	}

	private function assert_delivery_acknowledgement( object $row, string $provider, string $delivery_id, string $delivery_digest ): void {
		$this->assert_row_data(
			$row,
			array(
				'source'                  => 'webhook',
				'operation'               => 'update',
				'package_type'            => self::DELIVERY_ACK_TYPE,
				'package_slug'            => $this->delivery_acknowledgement_slug( $provider, $delivery_id ),
				'package_source'          => 'branch',
				'package_source_revision' => 0,
				'provider'                => $provider,
				'provider_repository_id'  => self::DELIVERY_ACK_TYPE,
				'requested_ref'           => self::DELIVERY_ACK_TYPE,
				'resolved_ref'            => null,
				'delivery_id'             => $delivery_id,
				'delivery_digest'         => $delivery_digest,
				'state'                   => DeploymentState::SUCCEEDED->value,
				'mutation_started_at'     => null,
				'outcome_code'            => DeploymentOutcome::CODE_NO_CHANGE,
				'request_json'            => '{}',
				'resolved_at'             => null,
				'resolved_by'             => null,
			)
		);
	}

	private function delivery_acknowledgement_slug( string $provider, string $delivery_id ): string {
		return 'delivery-' . substr( hash( 'sha256', $provider . "\0" . $delivery_id ), 0, 32 );
	}

	private function require_exact( int $id ): DeploymentAttempt {
		$attempt = $this->find_exact( $id );
		if ( null === $attempt ) {
			throw DeploymentStorageFailure::inconsistent();
		}

		return $attempt;
	}

	private function locked_attempt_row( int $id, ?string $correlation_id = null ): object {
		$query = null === $correlation_id
			? $this->prepare( 'SELECT * FROM %i WHERE id = %d LIMIT 2 FOR UPDATE', $this->table_name, $id )
			: $this->prepare( 'SELECT * FROM %i WHERE id = %d AND correlation_id = %s LIMIT 2 FOR UPDATE', $this->table_name, $id, $correlation_id );
		$rows  = $this->read_rows( $query );
		if ( count( $rows ) !== 1 ) {
			throw DeploymentStorageFailure::inconsistent();
		}

		return $rows[0];
	}

	private function active_package_attempt( string $package_type, string $package_slug ): ?DeploymentAttempt {
		$query = $this->prepare(
			"SELECT * FROM %i WHERE package_type = %s AND package_slug = %s AND (state IN ('queued','running') OR (state = 'needs_attention' AND resolved_at IS NULL AND resolved_by IS NULL)) ORDER BY created_at DESC, id DESC LIMIT 1 FOR UPDATE",
			$this->table_name,
			$package_type,
			$package_slug
		);
		$rows  = $this->read_rows( $query );
		if ( count( $rows ) > 1 ) {
			throw DeploymentStorageFailure::inconsistent();
		}

		return isset( $rows[0] ) ? DeploymentAttempt::from_database( $rows[0] ) : null;
	}

	private function reserve_capacity( int $incoming_rows ): void {
		if ( 0 === $incoming_rows ) {
			return;
		}
		if ( $incoming_rows < 0 || $incoming_rows > self::MAX_WEBHOOK_TARGETS ) {
			throw DeploymentStorageFailure::invalid_record();
		}

		$count_rows = $this->read_rows(
			$this->prepare(
				'SELECT COUNT(*) AS total FROM %i FOR UPDATE',
				$this->table_name
			)
		);
		if ( count( $count_rows ) !== 1 || ! is_numeric( $count_rows[0]->total ?? null ) ) {
			throw DeploymentStorageFailure::inconsistent();
		}
		$current_rows = (int) $count_rows[0]->total;
		if ( $current_rows < 0 ) {
			throw DeploymentStorageFailure::inconsistent();
		}

		$maximum_rows = $this->retention_configuration_status()['maximum_rows'];
		$prune_rows   = max( 0, $current_rows + $incoming_rows - $maximum_rows );
		if ( 0 === $prune_rows ) {
			return;
		}

		$candidates = $this->read_rows(
			$this->prepare(
				"SELECT id FROM %i WHERE (state IN ('succeeded','failed') OR (state = 'needs_attention' AND resolved_at IS NOT NULL AND resolved_by IS NOT NULL)) ORDER BY created_at, id LIMIT %d FOR UPDATE",
				$this->table_name,
				$prune_rows
			)
		);
		if ( count( $candidates ) !== $prune_rows ) {
			throw DeploymentStorageFailure::capacity_exhausted();
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
			$this->table_name,
			...array_values( $ids )
		);
		if ( count( $ids ) !== $this->database->query( $query ) ) {
			throw DeploymentStorageFailure::unavailable();
		}
	}

	private function transaction( callable $operation, bool $serializable = false ): mixed {
		$this->require_storage_support();
		if ( $serializable && false === $this->database->query( 'SET TRANSACTION ISOLATION LEVEL SERIALIZABLE' ) ) {
			throw DeploymentStorageFailure::unavailable();
		}
		if ( false === $this->database->query( 'START TRANSACTION' ) ) {
			throw DeploymentStorageFailure::unavailable();
		}
		try {
			$result = $operation();
			if ( false === $this->database->query( 'COMMIT' ) ) {
				throw DeploymentStorageFailure::transaction_commit_failed();
			}

			return $result;
		} catch ( Throwable $exception ) {
			$this->database->query( 'ROLLBACK' );
			throw $exception;
		}
	}

	/** @param array<string, int|string|null> $expected */
	private function assert_row_data( object $row, array $expected ): void {
		foreach ( $expected as $column => $value ) {
			if ( ! property_exists( $row, $column ) || ! $this->same_stored_value( $row->{$column}, $value ) ) {
				throw DeploymentStorageFailure::inconsistent();
			}
		}
	}

	/** @param array<string, string|null> $expected */
	private function assert_attempt_data( DeploymentAttempt $attempt, array $expected ): void {
		$stored = $attempt->safe_data();
		foreach ( $expected as $column => $value ) {
			if ( ! array_key_exists( $column, $stored ) || ! $this->same_stored_value( $stored[ $column ], $value ) ) {
				throw DeploymentStorageFailure::inconsistent();
			}
		}
	}

	private function same_stored_value( mixed $stored, mixed $expected ): bool {
		return null === $expected ? null === $stored : is_scalar( $stored ) && hash_equals( (string) $expected, (string) $stored );
	}

	/** @return list<object> */
	private function read_rows( string $query ): array {
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
		$this->require_storage_support();

		return $this->database->prepare( $query, ...$arguments );
	}

	private function require_storage_support(): void {
		try {
			$this->database_lifecycle->require_ready();
		} catch ( DatabaseCompatibilityFailure | DatabaseLifecycleFailure ) {
			throw DeploymentStorageFailure::unsupported_database();
		}
	}

	private function now(): DateTimeImmutable {
		$now = ( $this->clock )();

		return DateTimeImmutable::createFromInterface( $now );
	}

	private function time_string( DateTimeInterface $time ): string {
		return $time->format( 'Y-m-d H:i:s' );
	}

	private function positive_id( int $id ): int {
		if ( $id < 1 ) {
			throw DeploymentStorageFailure::invalid_record();
		}

		return $id;
	}

	private function history_limit( int $limit ): int {
		if ( $limit < 1 || $limit > self::MAX_HISTORY ) {
			throw DeploymentStorageFailure::invalid_record();
		}

		return $limit;
	}

	private function assert_operation( string $operation ): void {
		if ( ! in_array( $operation, array( 'install', 'update' ), true ) ) {
			throw DeploymentStorageFailure::invalid_record();
		}
	}

	private function assert_package_type( string $package_type ): void {
		if ( ! in_array( $package_type, array( 'plugin', 'theme' ), true ) ) {
			throw DeploymentStorageFailure::invalid_record();
		}
	}

	private function assert_package_source(
		string $package_source,
		int $package_source_revision
	): void {
		if ( 'branch' !== $package_source || $package_source_revision < 0 ) {
			throw DeploymentStorageFailure::invalid_record();
		}
	}

	private function assert_package_slug( string $package_slug ): void {
		if ( preg_match( '/^[a-z0-9][a-z0-9._-]{0,190}$/D', $package_slug ) !== 1 ) {
			throw DeploymentStorageFailure::invalid_record();
		}
	}

	private function assert_provider( string $provider ): void {
		if ( preg_match( '/^[a-z][a-z0-9-]{0,31}$/D', $provider ) !== 1 ) {
			throw DeploymentStorageFailure::invalid_record();
		}
	}

	private function assert_hex( string $value, int $length ): void {
		if ( preg_match( sprintf( '/^[a-f0-9]{%d}$/D', $length ), $value ) !== 1 ) {
			throw DeploymentStorageFailure::invalid_record();
		}
	}

	private function assert_safe_text( string $value, int $limit ): void {
		if ( '' === $value || strlen( $value ) > $limit || preg_match( '//u', $value ) !== 1
			|| preg_match( '/[[:cntrl:]]/', $value ) === 1
			|| preg_match( '/(?:https?:\/\/|[A-Za-z][A-Za-z0-9+.-]*:\/\/)[^\s]*@/i', $value ) === 1
			|| preg_match( '/\b(?:authorization|bearer|token|secret|password|signature)\b\s*[:=]/i', $value ) === 1 ) {
			throw DeploymentStorageFailure::invalid_record();
		}
	}
}
