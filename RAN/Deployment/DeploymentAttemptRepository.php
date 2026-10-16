<?php

declare(strict_types=1);

namespace RAN\Deployment;

use DateTimeImmutable;
use DateTimeInterface;
use RAN\Activity\ActivityRequest;
use RAN\Activity\ActivityStore;
use RAN\AdoptionIdentity;
use RAN\Logging\BoosterLogger;
use RAN\RepositoryProvider\AuthenticatedWebhookDeliveryEvidence;
use RAN\RepositoryProvider\ProviderCode;
use RAN\RepositoryProvider\GitHubProviderSelection;
use RAN\Runtime\RuntimeSupport;
use RAN\Storage\Database;
use RAN\Storage\SqlReadConnection;
use RAN\Storage\DeploymentWriteConnection;
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

	public const DEFAULT_MAX_ATTEMPT_ROWS = 2000;
	public const MAX_ATTEMPT_ROWS         = 100000;

	private const MAX_HISTORY         = 100;
	private const MAX_MANUAL_TARGETS  = 20;
	private const MAX_WEBHOOK_TARGETS = 64;
	private const DELIVERY_ACK_TYPE   = 'delivery';

	/** @var callable(): DateTimeImmutable */
	private $clock;
	/** @var callable(positive-int): string */
	private $random_bytes;
	private Database $database_lifecycle;
	private string $table_name;
	/** @var array{valid: bool, maximum_rows: int, source: 'configured'|'default'}|null */
	private ?array $retention_configuration = null;

	public function __construct(
		private ?object $database = null,
		?string $table_name = null,
		?callable $clock = null,
		?callable $random_bytes = null,
		?Database $database_lifecycle = null,
		private mixed $configured_max_attempt_rows = null,
		private ?ActivityStore $activity = null,
		private ?GitHubProviderSelection $selection = null
	) {
		if ( null === $this->database ) {
			global $wpdb;
			$this->database = $wpdb;
		}
		$this->table_name         = $table_name ?? Database::attempt_table_name();
		$this->clock              = $clock ?? static fn (): DateTimeImmutable => new DateTimeImmutable( 'now', wp_timezone() );
		$this->random_bytes       = $random_bytes ?? random_bytes( ... );
		$this->database_lifecycle = $database_lifecycle ?? new Database( $this->database );
	}
	/** Caller already owns the same selection row transaction, before attempt locks. */
	public function assert_selection_transition_idle( GitHubProviderSelection $selection ): void {
		if ( null === $this->database || ! $selection->uses_connection( $this->database ) ) {
			throw DeploymentStorageFailure::unavailable();
		}
		$rows = $this->read_rows(
			$this->prepare(
				"SELECT id FROM %i WHERE provider = 'gh' AND (state = 'running' OR (state = 'needs_attention' AND (resolved_at IS NULL OR resolved_by IS NULL))) LIMIT 1 FOR UPDATE",
				$this->table_name
			)
		);
		if ( array() !== $rows ) {
			throw new \RuntimeException( 'GitHub deployment recovery or completion is required before switching.' );
		}
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
				$request = $this->bind_selection( $provider, $package_type, $request );
				$this->lock_adoption( $operation, $package_type, $provider, $provider_repository_id, $request, $package_source, $package_source_revision );
				$active = $this->active_package_attempt( $package_type, $request->package_slug );
				if ( null !== $active ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The failure stores a validated, whitelisted attempt projection; it does not render output.
					throw DeploymentStorageFailure::contention( $active->safe_data() );
				}

				$this->reserve_capacity( 1 );
				$data          = $this->row_data(
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
				);
				$data['state'] = DeploymentState::RUNNING->value;
				$running       = $this->insert_and_read( $data );
				$this->record_activity_start( $running, 'deployment.running' );
				BoosterLogger::log( 'attempt admitted (manual)', $running->log_context() + array( 'transition' => 'new->running' ) );

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
		uasort( $normalized, static fn ( array $a, array $b ): int => array( $a['package_type'], $a['request']->installed_identifier ) <=> array( $b['package_type'], $b['request']->installed_identifier ) );

		$result = $this->transaction(
			function () use ( $normalized ): array {
				foreach ( $normalized as &$target ) {
					$target['request'] = $this->bind_selection( $target['provider'], $target['package_type'], $target['request'] );
				}
				unset( $target );
				foreach ( $normalized as $target ) {
					$this->lock_adoption( 'update', $target['package_type'], $target['provider'], $target['provider_repository_id'], $target['request'], $target['package_source'], $target['package_source_revision'] );
				}
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
					$this->record_activity_start( $attempts[ array_key_last( $attempts ) ], 'deployment.queued' );
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
				|| ! $target['request'] instanceof DeploymentRequest
				|| null !== $target['request']->selected_commit ) {
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
		uasort( $normalized, static fn ( array $a, array $b ): int => array( $a['package_type'], $a['request']->installed_identifier ) <=> array( $b['package_type'], $b['request']->installed_identifier ) );

		return $this->transaction(
			function () use ( $provider, $delivery_id, $delivery_digest, $normalized ): array {
				if ( 'gh' === $provider && array() === $normalized ) {
					$this->selection_generation();
				}
				foreach ( $normalized as &$target ) {
					$target['request'] = $this->bind_selection( $provider, $target['package_type'], $target['request'] );
				}
				unset( $target );
				foreach ( $normalized as $target ) {
					$this->lock_adoption( $target['operation'], $target['package_type'], $provider, $target['provider_repository_id'], $target['request'], $target['package_source'], $target['package_source_revision'] );
				}
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
					$this->record_activity_start( $attempts[ array_key_last( $attempts ) ], 'deployment.queued' );
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
		$this->require_storage_support();
		$this->write_connection();
		$hints = $this->read_rows( $this->prepare( "SELECT * FROM %i WHERE state = 'queued' ORDER BY created_at, id", $this->table_name ) );
		foreach ( $hints as $hint ) {
			$queued  = DeploymentAttempt::from_database( $hint );
			$claimed = $this->transaction(
				function () use ( $queued ): ?DeploymentAttempt {
					if ( DeploymentOutcome::CODE_PROVIDER_UNAVAILABLE === $this->provider_refusal( $queued ) ) {
						return null;
					}
					$data = $queued->safe_data();
					try {
						$this->lock_adoption( (string) $data['operation'], (string) $data['package_type'], (string) $data['provider'], (string) $data['provider_repository_id'], $queued->get_request(), (string) $data['package_source'], (int) $data['package_source_revision'] );
					} catch ( DeploymentStorageFailure $failure ) {
						// Stale package snapshots must become truthful failures, not starve the queue.
						if ( $failure->getMessage() !== DeploymentStorageFailure::inconsistent()->getMessage() ) {
							throw $failure;
						}
					}
					$row     = $this->locked_attempt_row( $queued->get_id() );
					$current = DeploymentAttempt::from_database( $row );
					if ( DeploymentState::QUEUED !== $current->get_state() ) {
						return null;
					}
					if ( $current->get_request()->to_json() !== $queued->get_request()->to_json() || $current->safe_data() !== $data ) {
						throw DeploymentStorageFailure::inconsistent();
					}
					return $this->claim_locked_row( array( $row ) );
				}
			);
			if ( null !== $claimed ) {
				return $claimed;
			}
		}
		return null;
	}

	/** Current guard also establishes selection-before-adoption-before-attempt lock order. */
	public function provider_refusal( DeploymentAttempt $attempt ): ?string {
		$data = $attempt->safe_data();
		if ( 'gh' !== $data['provider'] ) {
			return null;
		}
		if ( null === $this->selection || null === $this->database || ! $this->selection->uses_connection( $this->database ) ) {
			return DeploymentOutcome::CODE_PROVIDER_UNAVAILABLE;
		}
		$request = $attempt->get_request();
		return $this->selection->deployment_refusal( $request->provider_selection_generation, (string) $data['package_type'], $request->installed_identifier ?? $request->package_slug );
	}

	private function selection_generation(): int {
		if ( null === $this->selection || null === $this->database || ! $this->selection->uses_connection( $this->database ) ) {
			throw DeploymentStorageFailure::unavailable();
		}
		$expectation = $this->selection->expectation();
		if ( null === $expectation ) {
			throw DeploymentStorageFailure::unavailable();
		}
		return $expectation['selection_generation'];
	}

	private function bind_selection( string $provider, string $type, DeploymentRequest $request ): DeploymentRequest {
		if ( 'gh' !== $provider ) {
			return $request;
		}
		$generation = $this->selection_generation();
		if ( null === $this->selection || null !== $this->selection->deployment_refusal( $generation, $type, $request->installed_identifier ?? $request->package_slug ) ) {
			throw DeploymentStorageFailure::unavailable();
		}
		return new DeploymentRequest( $request->repository, $request->credential_id, $request->is_private, $request->configured_branch, $request->package_slug, $request->subdirectory, $request->deployment_policy, $request->initiating_user_id, $request->maximum_artifact_bytes, $request->installed_identifier, $request->adoption_token, $generation, $request->selected_commit );
	}
	public function record_resolved_ref( int $attempt_id, string $resolved_ref ): DeploymentAttempt {
		RuntimeSupport::assert_managed_operations_allowed();
		$this->assert_safe_text( $resolved_ref, 191 );
		return $this->running_write( $attempt_id, array( 'resolved_ref' => $resolved_ref ) );
	}
	/** @param callable():bool|null $owns_lock Exact updater ownership rechecked after adoption reads. */
	public function mark_mutation_started( int $attempt_id, ?DateTimeInterface $at = null, ?callable $owns_lock = null ): DeploymentAttempt|DeploymentOutcome {
		RuntimeSupport::assert_managed_operations_allowed();
		return $this->transaction(
			function () use ( $attempt_id, $at, $owns_lock ): DeploymentAttempt|DeploymentOutcome {
				$attempt = $this->require_exact( $attempt_id );
				$refusal = $this->provider_refusal( $attempt );
				if ( null !== $refusal ) {
					return DeploymentOutcome::from_code( $refusal );
				}
				$data = $attempt->safe_data();
				$this->lock_adoption( (string) $data['operation'], (string) $data['package_type'], (string) $data['provider'], (string) $data['provider_repository_id'], $attempt->get_request(), (string) $data['package_source'], (int) $data['package_source_revision'] );
				$locked  = DeploymentAttempt::from_database( $this->locked_attempt_row( $attempt_id ) );
				$refusal = $this->provider_refusal( $locked );
				if ( null !== $refusal ) {
					return DeploymentOutcome::from_code( $refusal );
				}
				if ( $locked->get_request()->to_json() !== $attempt->get_request()->to_json() || DeploymentState::RUNNING !== $locked->get_state() ) {
					throw DeploymentStorageFailure::inconsistent();
				}
				if ( null !== $owns_lock && true !== $owns_lock() ) {
					return DeploymentOutcome::from_code( DeploymentOutcome::CODE_LOCK_UNAVAILABLE );
				}
				$write = array( 'mutation_started_at' => $this->time_string( $at ?? $this->now() ) );
				if ( 1 !== $this->write_connection()->query( $this->update_query( $attempt_id, DeploymentState::RUNNING, $write ) ) ) {
					throw DeploymentStorageFailure::unavailable();
				}
				$mutating = $this->require_exact( $attempt_id );
				$this->assert_attempt_data( $mutating, $write );
				$this->require_activity_progress( $mutating, 'deployment.mutating' );
				return $mutating;
			}
		);
	}
	public function finish( int $attempt_id, DeploymentOutcome $outcome, ?DateTimeInterface $at = null ): DeploymentAttempt {
		RuntimeSupport::assert_managed_operations_allowed();
		$id = $this->positive_id( $attempt_id );
		return $this->transaction(
			function () use ( $id, $outcome, $at ): DeploymentAttempt {
				$data = array(
					'state'        => $outcome->get_state()->value,
					'outcome_code' => $outcome->get_code(),
					'finished_at'  => $this->time_string( $at ?? $this->now() ),
				);
				if ( 1 !== $this->write_connection()->query( $this->update_query( $id, DeploymentState::RUNNING, $data ) ) ) {
					throw DeploymentStorageFailure::unavailable();
				}
				$attempt = $this->require_exact( $id );
				$this->assert_attempt_data( $attempt, $data );
				$this->record_activity_outcome( $attempt, $outcome );
				BoosterLogger::log(
					'attempt finished',
					$attempt->log_context() + array(
						'transition'   => 'running->' . $outcome->get_state()->value,
						'outcome_code' => $outcome->get_code(),
					)
				);
				return $attempt;
			}
		);
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
		$policy                        = ActivityStore::retention_policy( $this->configured_max_attempt_rows );
		$this->retention_configuration = array(
			'valid'        => $policy['valid'],
			'maximum_rows' => $policy['maximum_rows'],
			'source'       => $policy['source'],
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
	public function package_activity_summary( string $package_type, string $package_slug, ?string $adoption_token = null ): array {
		if ( null === $adoption_token ) {
			return array(
				'latest'          => null,
				'last_successful' => null,
			);
		}
		AdoptionIdentity::parse( $adoption_token );
		$fragment = '"adoption_token":"' . $adoption_token . '"';
		$this->assert_package_type( $package_type );
		$this->assert_package_slug( $package_slug );
		$query    = $this->prepare(
			'SELECT * FROM %i AS attempts
			WHERE attempts.id = (
				SELECT MAX(latest.id) FROM %i AS latest WHERE latest.package_type = %s AND latest.package_slug = %s AND LOCATE(BINARY %s, BINARY latest.request_json) > 0
			) OR attempts.id = (
				SELECT MAX(success.id) FROM %i AS success WHERE success.package_type = %s AND success.package_slug = %s AND success.state = %s AND LOCATE(BINARY %s, BINARY success.request_json) > 0
			)
			ORDER BY attempts.id DESC LIMIT 2',
			$this->table_name,
			$this->table_name,
			$package_type,
			$package_slug,
			$fragment,
			$this->table_name,
			$package_type,
			$package_slug,
			DeploymentState::SUCCEEDED->value,
			$fragment
		);
		$attempts = array_map( array( DeploymentAttempt::class, 'from_database' ), $this->read_rows( $query ) );
		$success  = null;
		foreach ( $attempts as $attempt ) {
			if ( $attempt->get_request()->adoption_token !== $adoption_token ) {
				throw DeploymentStorageFailure::inconsistent();
			}
			if ( null === $success && DeploymentState::SUCCEEDED === $attempt->get_state() ) {
				$success = $attempt;
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
				if ( 1 !== $this->write_connection()->query( $this->update_query( $id, DeploymentState::RUNNING, $data ) ) ) {
					throw DeploymentStorageFailure::unavailable();
				}
				$attempt = $this->require_exact( $id );
				$this->assert_attempt_data( $attempt, $data );
				$this->record_activity_outcome( $attempt, $outcome );
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
				if ( 1 !== $this->write_connection()->query( $this->update_query( $id, DeploymentState::NEEDS_ATTENTION, $data ) ) ) {
					throw DeploymentStorageFailure::unavailable();
				}
				$attempt = $this->require_exact( $id );
				$this->assert_attempt_data( $attempt, $data );
				if ( ! hash_equals( $correlation_id, $attempt->get_correlation_id() ) || $attempt->requires_operator_resolution() ) {
					throw DeploymentStorageFailure::inconsistent();
				}
				$this->require_activity_progress( $attempt, 'deployment.operator_reviewed' );
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
		if ( 1 !== $this->write_connection()->query( $query ) ) {
			throw DeploymentStorageFailure::unavailable();
		}
		$running = $this->require_exact( $queued->get_id() );
		if ( DeploymentState::RUNNING !== $running->get_state() ) {
			throw DeploymentStorageFailure::inconsistent();
		}
		$this->require_activity_progress( $running, 'deployment.running' );
		BoosterLogger::log( 'attempt claimed from queue', $running->log_context() + array( 'transition' => 'queued->running' ) );

		return $running;
	}

	/** @param array<string, string|null> $data */
	private function running_write( int $attempt_id, array $data ): DeploymentAttempt {
		$id = $this->positive_id( $attempt_id );
		return $this->transaction(
			function () use ( $id, $data ): DeploymentAttempt {
				if ( 1 !== $this->write_connection()->query( $this->update_query( $id, DeploymentState::RUNNING, $data ) ) ) {
					throw DeploymentStorageFailure::unavailable();
				}
				$attempt = $this->require_exact( $id );
				$safe    = $attempt->safe_data();
				foreach ( $data as $key => $value ) {
					if ( (string) ( $safe[ $key ] ?? '' ) !== (string) $value ) {
						throw DeploymentStorageFailure::inconsistent();
					}
				}
				$this->require_activity_progress( $attempt, isset( $data['resolved_ref'] ) ? 'deployment.reference_resolved' : 'deployment.mutating' );
				return $attempt;
			}
		);
	}

	private function record_activity_start( DeploymentAttempt $attempt, string $stage ): void {
		if ( null === $this->activity ) {
			return;
		}
		$data    = $attempt->safe_data();
		$request = $attempt->get_request();
		$repo    = new \RAN\RepositoryProvider\RepositoryReference( $request->repository, $data['provider_repository_id'], $request->is_private, $request->credential_id );
		$target  = $request->installed_identifier ?? $request->package_slug;
		$capture = array(
			'subject_type'     => $data['package_type'],
			'subject_identity' => $target,
			'adoption_token'   => $request->adoption_token,
			'context'          => '',
			'purpose'          => null,
			'provenance'       => 'core',
			'actor'            => $request->initiating_user_id ?? 0,
		);
		$id      = $this->activity->begin( $data['provider'], new ActivityRequest( $data['operation'], $repo, $data['package_type'], $target ), $capture, $data['correlation_id'] );
		if ( null === $id || ! $this->activity->progress_by_correlation( $data['correlation_id'], $stage, '', $this->activity_facts( $attempt ) ) ) {
			throw DeploymentStorageFailure::unavailable();
		}
	}

	private function require_activity_progress( DeploymentAttempt $attempt, string $stage ): void {
		if ( null !== $this->activity ) {
			$correlation = $attempt->get_correlation_id();
			if ( ! $this->activity->progress_by_correlation( $correlation, $stage, '', $this->activity_facts( $attempt ) ) ) {
				throw DeploymentStorageFailure::unavailable();
			}
		}
	}

	private function record_activity_outcome( DeploymentAttempt $attempt, DeploymentOutcome $outcome ): void {
		if ( null === $this->activity ) {
			return;
		}
		if ( DeploymentState::NEEDS_ATTENTION === $outcome->get_state() ) {
			$this->require_activity_progress( $attempt, 'deployment.needs_attention' );
			return;
		}
		$status = DeploymentState::SUCCEEDED === $outcome->get_state() ? 'succeeded' : 'failed';
		if ( ! $this->activity->complete_by_correlation( $attempt->get_correlation_id(), $status, $outcome->get_code(), $outcome->get_code(), facts: $this->activity_facts( $attempt ) ) ) {
			throw DeploymentStorageFailure::unavailable();
		}
	}

	/** @return array{deployment_attempt_id:int, deployment_package_slug:string, deployment_source:'manual'|'webhook', deployment_state:string, deployment_requested_ref:string, deployment_resolved_ref:?string, deployment_mutation_started_at:?string, deployment_finished_at:?string, deployment_outcome:?string, deployment_resolved_at:?string, deployment_resolved_by:?int} */
	private function activity_facts( DeploymentAttempt $attempt ): array {
		$data = $attempt->safe_data();
		return array(
			'deployment_attempt_id'          => $data['id'],
			'deployment_package_slug'        => $data['package_slug'],
			'deployment_source'              => $data['source'],
			'deployment_state'               => $data['state'],
			'deployment_requested_ref'       => $data['requested_ref'],
			'deployment_resolved_ref'        => $data['resolved_ref'],
			'deployment_mutation_started_at' => $data['mutation_started_at'],
			'deployment_finished_at'         => $data['finished_at'],
			'deployment_outcome'             => $data['outcome_code'],
			'deployment_resolved_at'         => $data['resolved_at'],
			'deployment_resolved_by'         => $data['resolved_by'],
		);
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
		if ( null !== $request->selected_commit && ( $requested_ref !== $request->selected_commit || 'webhook' === $source ) ) {
			throw DeploymentStorageFailure::invalid_record();
		}

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
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- This is the deployment persistence boundary. Retain the promoted constructor or external DTO property contract.
		if ( 1 !== $this->write_connection()->insert( $this->table_name, $data ) ) {
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
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- This is the durable zero-target delivery acknowledgement. Retain the promoted constructor or external DTO property contract.
		if ( 1 !== $this->write_connection()->insert( $this->table_name, $data ) ) {
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

	/** Called inside the management-row transaction; unknown legacy targets remain a hard stop. */
	public function has_unresolved_target_attempt( string $package_type, string $identifier ): bool {
		$this->assert_package_type( $package_type );
		$this->assert_safe_text( $identifier, 255 );
		$rows = $this->read_rows(
			$this->prepare(
				"SELECT * FROM %i WHERE package_type = %s AND (state IN ('queued','running') OR (state = 'needs_attention' AND resolved_at IS NULL AND resolved_by IS NULL)) LIMIT 65 FOR UPDATE",
				$this->table_name,
				$package_type
			)
		);
		if ( count( $rows ) > 64 ) {
			throw DeploymentStorageFailure::inconsistent();
		}
		$blocked = false;
		foreach ( $rows as $row ) {
			$request = DeploymentAttempt::from_database( $row )->get_request();
			$blocked = $blocked || null === $request->installed_identifier || $identifier === $request->installed_identifier;
		}
		return $blocked;
	}

	/** Lock management before attempt rows, closing capture-before-admission races. */
	private function lock_adoption( string $operation, string $type, string $provider, string $repository_id, DeploymentRequest $request, string $source, int $revision ): void {
		if ( 'install' === $operation ) {
			if ( null !== $request->adoption_token || null !== $request->installed_identifier ) {
				throw DeploymentStorageFailure::invalid_record();
			}
			$rows = $this->read_rows( $this->prepare( 'SELECT * FROM %i WHERE package_type = %d FOR UPDATE', ran_booster_table_name(), 'plugin' === $type ? 1 : 2 ) );
			foreach ( $rows as $row ) {
				if ( ! is_string( $row->package ?? null ) || explode( '/', $row->package )[0] === $request->package_slug ) {
					throw DeploymentStorageFailure::inconsistent();
				}
			}
			return; // Execution still checks physical destination absence under the updater lock.
		}
		if ( null === $request->adoption_token || null === $request->installed_identifier
			|| ! AdoptionIdentity::matches( $request->adoption_token, $provider, $repository_id ) ) {
			throw DeploymentStorageFailure::inconsistent();
		}
		$rows     = $this->read_rows(
			$this->prepare(
				'SELECT * FROM %i WHERE package_type = %d AND BINARY package = BINARY %s LIMIT 2 FOR UPDATE',
				ran_booster_table_name(),
				'plugin' === $type ? 1 : 2,
				$request->installed_identifier
			)
		);
		$expected = array(
			'package'                => $request->installed_identifier,
			'adoption_token'         => $request->adoption_token,
			'provider'               => $provider,
			'provider_repository_id' => $repository_id,
			'repository'             => $request->repository,
			'branch'                 => $request->configured_branch,
			'private'                => (int) $request->is_private,
			'credential_id'          => $request->credential_id,
			'subdirectory'           => $request->subdirectory,
			'deployment_policy'      => $request->deployment_policy->value,
			'source'                 => $source,
			'source_revision'        => $revision,
		);
		if ( 1 !== count( $rows ) ) {
			throw DeploymentStorageFailure::inconsistent();
		}
		$this->assert_row_data( $rows[0], $expected );
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
		$policy = ActivityStore::retention_policy( $this->configured_max_attempt_rows );
		$aged   = $this->write_connection()->query(
			$this->prepare(
				"DELETE FROM %i WHERE (state IN ('succeeded','failed') OR (state = 'needs_attention' AND resolved_at IS NOT NULL AND resolved_by IS NOT NULL)) AND created_at < %s ORDER BY created_at, id LIMIT 100",
				$this->table_name,
				$this->time_string( $this->now()->sub( new \DateInterval( 'P' . $policy['maximum_days'] . 'D' ) ) )
			)
		);
		if ( ! is_int( $aged ) || $aged < 0 || $aged > 100 ) {
			throw DeploymentStorageFailure::unavailable();
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
		if ( count( $ids ) !== $this->write_connection()->query( $query ) ) {
			throw DeploymentStorageFailure::unavailable();
		}
	}

	/**
	 * @template TResult
	 * @param callable(): TResult $operation
	 * @return TResult
	 */
	private function transaction( callable $operation, bool $serializable = false ): mixed {
		$this->require_storage_support();
		if ( $serializable && false === $this->write_connection()->query( 'SET TRANSACTION ISOLATION LEVEL SERIALIZABLE' ) ) {
			throw DeploymentStorageFailure::unavailable();
		}
		if ( false === $this->write_connection()->query( 'START TRANSACTION' ) ) {
			throw DeploymentStorageFailure::unavailable();
		}
		try {
			$result = $operation();
			if ( false === $this->write_connection()->query( 'COMMIT' ) ) {
				throw DeploymentStorageFailure::transaction_commit_failed();
			}

			return $result;
		} catch ( Throwable $exception ) {
			$this->write_connection()->query( 'ROLLBACK' );
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
		$database   = $this->read_connection();
		$read_error = static fn (): string => '';
		if ( property_exists( $database, 'last_error' ) ) {
			$read_error           = static fn (): string => (string) $database->last_error;
			$database->last_error = '';
		} elseif ( method_exists( $database, '__get' ) || method_exists( $database, '__set' ) ) {
			if ( ! is_callable( array( $database, '__get' ) ) || ! is_callable( array( $database, '__set' ) ) ) {
				throw DeploymentStorageFailure::unavailable();
			}
			$read_error = static fn (): string => (string) $database->__get( 'last_error' );
			$database->__set( 'last_error', '' );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Durable state cannot use object caching.
		$rows = $database->get_results( $query );
		if ( ! is_array( $rows ) || '' !== $read_error() ) {
			throw DeploymentStorageFailure::unavailable();
		}

		if ( count( $rows ) !== count( array_filter( $rows, 'is_object' ) ) ) {
			throw DeploymentStorageFailure::inconsistent();
		}

		return array_values( $rows );
	}

	private function prepare( string $query, mixed ...$arguments ): string {
		$this->require_storage_support();

		return $this->read_connection()->prepare( $query, ...$arguments );
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

	private function read_connection(): \wpdb|SqlReadConnection {
		$database = $this->database;
		if ( ! $database instanceof \wpdb && ! $database instanceof SqlReadConnection ) {
			throw DeploymentStorageFailure::unavailable();
		}

		return $database;
	}

	private function write_connection(): \wpdb|DeploymentWriteConnection {
		$database = $this->database;
		if ( ! $database instanceof \wpdb && ! $database instanceof DeploymentWriteConnection ) {
			throw DeploymentStorageFailure::unavailable();
		}

		return $database;
	}
}
