<?php

declare(strict_types=1);

namespace RAN\Deployment;

use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use RAN\Storage\Database;
use RAN\Storage\DatabaseCompatibilityFailure;
use RAN\Storage\DatabaseLifecycleFailure;
use RuntimeException;
use Throwable;

/**
 * Durable, rate-limited evidence for manual actions rejected before deployment.
 *
 * These rows are deliberately separate from deployment attempts: no package
 * mutation was admitted, so creating a synthetic attempt would misstate the
 * deployment journal.
 */
final class RejectedAdmissionAuditRepository {

	public const EVENT_BLOCKED_BY_NEEDS_ATTENTION = 'blocked_by_needs_attention';
	public const DEFAULT_MAX_EVENT_ROWS           = 200;
	public const DEDUPLICATION_WINDOW_SECONDS     = 10;

	/** @var callable(): DateTimeImmutable */
	private $clock;
	private Database $databaseLifecycle;

	public function __construct(
		private ?object $database = null,
		private ?string $tableName = null,
		?callable $clock = null,
		?Database $databaseLifecycle = null,
		private int $maximumRows = self::DEFAULT_MAX_EVENT_ROWS
	) {
		if ( null === $this->database ) {
			global $wpdb;
			$this->database = $wpdb;
		}
		if ( null === $this->tableName ) {
			$this->tableName = Database::rejectedAdmissionAuditTableName();
		}
		$this->clock             = $clock ?? static fn (): DateTimeImmutable => new DateTimeImmutable( 'now', wp_timezone() );
		$this->databaseLifecycle = $databaseLifecycle ?? new Database( $this->database );
		if ( $this->maximumRows < 1 || $this->maximumRows > 100000 ) {
			throw new RuntimeException( 'The rejected-admission audit retention is invalid.' );
		}
	}

	/**
	 * Record one administrator action blocked by an unresolved deployment.
	 *
	 * @param array<string, bool|int|string|null> $attempt Validated active-attempt projection.
	 * @return array{id: int, event: 'blocked_by_needs_attention', attempt_id: int, correlation_id: string, package_type: 'plugin'|'theme', package_slug: string, actor_id: int, operation: string, occurred_at: string}
	 */
	public function recordBlockedByNeedsAttention( array $attempt, int $actorId, string $operation ): array {
		$attemptData = $this->validatedAttempt( $attempt );
		if ( $actorId < 1 || '' === $operation || strlen( $operation ) > 32 || preg_match( '/^[a-z][a-z0-9_-]*$/D', $operation ) !== 1 ) {
			throw new RuntimeException( 'The rejected-admission audit input is invalid.' );
		}

		try {
			$this->databaseLifecycle->requireReady();

			return $this->transaction(
				function () use ( $attemptData, $actorId, $operation ): array {
					$occurredAt = ( $this->clock )();
					if ( ! $occurredAt instanceof DateTimeImmutable ) {
						throw new RuntimeException( 'The rejected-admission audit clock is invalid.' );
					}
					$cutoff   = $occurredAt->sub( new DateInterval( 'PT' . self::DEDUPLICATION_WINDOW_SECONDS . 'S' ) );
					$existing = $this->mostRecentMatching( $attemptData['id'], $actorId, $operation, $cutoff );
					if ( null !== $existing ) {
						return $existing;
					}

					$this->trimForInsert();
					$stored = $this->database->insert(
						$this->tableName,
						array(
							'event'          => self::EVENT_BLOCKED_BY_NEEDS_ATTENTION,
							'attempt_id'     => $attemptData['id'],
							'correlation_id' => $attemptData['correlation_id'],
							'package_type'   => $attemptData['package_type'],
							'package_slug'   => $attemptData['package_slug'],
							'actor_id'       => $actorId,
							'operation'      => $operation,
							'occurred_at'    => $this->formatTime( $occurredAt ),
						)
					);
					if ( 1 !== $stored || ! isset( $this->database->insert_id ) || ! is_numeric( $this->database->insert_id ) ) {
						throw new RuntimeException( 'The rejected-admission audit could not be stored.' );
					}

					return $this->requireExact( (int) $this->database->insert_id );
				}
			);
		} catch ( DatabaseCompatibilityFailure | DatabaseLifecycleFailure $failure ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The prior failure is retained for programmatic diagnostics only.
			throw new RuntimeException( 'The rejected-admission audit storage is unavailable.', 0, $failure );
		}
	}

	/**
	 * @return list<array{id: int, event: 'blocked_by_needs_attention', attempt_id: int, correlation_id: string, package_type: 'plugin'|'theme', package_slug: string, actor_id: int, operation: string, occurred_at: string}>
	 */
	public function recent( int $limit = 100 ): array {
		if ( $limit < 1 || $limit > self::DEFAULT_MAX_EVENT_ROWS ) {
			throw new RuntimeException( 'The rejected-admission audit limit is invalid.' );
		}
		try {
			$this->databaseLifecycle->requireReady();
			$query = $this->prepare(
				'SELECT * FROM %i ORDER BY occurred_at DESC, id DESC LIMIT %d',
				$this->tableName,
				$limit
			);
			$rows  = $this->database->get_results( $query, 'ARRAY_A' );
			if ( ! is_array( $rows ) || '' !== trim( (string) ( $this->database->last_error ?? '' ) ) ) {
				throw new RuntimeException( 'The rejected-admission audit could not be read.' );
			}

			return array_map( fn ( mixed $row ): array => $this->fromRow( $row ), $rows );
		} catch ( DatabaseCompatibilityFailure | DatabaseLifecycleFailure $failure ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The prior failure is retained for programmatic diagnostics only.
			throw new RuntimeException( 'The rejected-admission audit storage is unavailable.', 0, $failure );
		}
	}

	/**
	 * @param array<string, bool|int|string|null> $attempt
	 * @return array{id: int, correlation_id: string, package_type: 'plugin'|'theme', package_slug: string}
	 */
	private function validatedAttempt( array $attempt ): array {
		$id            = $attempt['id'] ?? null;
		$correlationId = $attempt['correlation_id'] ?? null;
		$packageType   = $attempt['package_type'] ?? null;
		$packageSlug   = $attempt['package_slug'] ?? null;
		$state         = $attempt['state'] ?? null;
		if ( ! is_int( $id ) || $id < 1
			|| ! is_string( $correlationId ) || preg_match( '/^[a-f0-9]{32}$/D', $correlationId ) !== 1
			|| ! is_string( $packageType ) || ! in_array( $packageType, array( 'plugin', 'theme' ), true )
			|| ! is_string( $packageSlug ) || '' === $packageSlug || strlen( $packageSlug ) > 191
			|| 'needs_attention' !== $state ) {
			throw new RuntimeException( 'The rejected-admission audit attempt is invalid.' );
		}

		return array(
			'id'             => $id,
			'correlation_id' => $correlationId,
			'package_type'   => $packageType,
			'package_slug'   => $packageSlug,
		);
	}

	/**
	 * @param array{id: int, correlation_id: string, package_type: 'plugin'|'theme', package_slug: string} $attempt
	 * @return array{id: int, event: 'blocked_by_needs_attention', attempt_id: int, correlation_id: string, package_type: 'plugin'|'theme', package_slug: string, actor_id: int, operation: string, occurred_at: string}|null
	 */
	private function mostRecentMatching( int $attemptId, int $actorId, string $operation, DateTimeInterface $cutoff ): ?array {
		$query = $this->prepare(
			'SELECT * FROM %i WHERE event = %s AND attempt_id = %d AND actor_id = %d AND operation = %s AND occurred_at >= %s ORDER BY occurred_at DESC, id DESC LIMIT 1 FOR UPDATE',
			$this->tableName,
			self::EVENT_BLOCKED_BY_NEEDS_ATTENTION,
			$attemptId,
			$actorId,
			$operation,
			$this->formatTime( $cutoff )
		);
		$row   = $this->database->get_row( $query, 'ARRAY_A' );
		if ( '' !== trim( (string) ( $this->database->last_error ?? '' ) ) ) {
			throw new RuntimeException( 'The rejected-admission audit could not be read.' );
		}

		return null === $row ? null : $this->fromRow( $row );
	}

	private function trimForInsert(): void {
		$rows = $this->database->get_results(
			$this->prepare(
				'SELECT id FROM %i ORDER BY occurred_at, id LIMIT %d FOR UPDATE',
				$this->tableName,
				$this->maximumRows
			),
			'ARRAY_A'
		);
		if ( ! is_array( $rows ) || '' !== trim( (string) ( $this->database->last_error ?? '' ) ) ) {
			throw new RuntimeException( 'The rejected-admission audit could not be retained.' );
		}
		if ( count( $rows ) < $this->maximumRows ) {
			return;
		}
		$ids    = array_map(
			static function ( mixed $row ): int {
				$id = is_array( $row ) ? $row['id'] ?? null : null;
				if ( ! is_numeric( $id ) || (int) $id < 1 ) {
					throw new RuntimeException( 'The rejected-admission audit row is invalid.' );
				}

				return (int) $id;
			},
			$rows
		);
		$oldest = $ids[0] ?? null;
		if ( ! is_int( $oldest ) || 1 !== $this->database->query( $this->prepare( 'DELETE FROM %i WHERE id = %d', $this->tableName, $oldest ) ) ) {
			throw new RuntimeException( 'The rejected-admission audit could not be retained.' );
		}
	}

	/** @return array{id: int, event: 'blocked_by_needs_attention', attempt_id: int, correlation_id: string, package_type: 'plugin'|'theme', package_slug: string, actor_id: int, operation: string, occurred_at: string} */
	private function requireExact( int $id ): array {
		$row = $this->database->get_row( $this->prepare( 'SELECT * FROM %i WHERE id = %d', $this->tableName, $id ), 'ARRAY_A' );
		if ( null === $row || '' !== trim( (string) ( $this->database->last_error ?? '' ) ) ) {
			throw new RuntimeException( 'The rejected-admission audit could not be verified.' );
		}

		return $this->fromRow( $row );
	}

	/** @return array{id: int, event: 'blocked_by_needs_attention', attempt_id: int, correlation_id: string, package_type: 'plugin'|'theme', package_slug: string, actor_id: int, operation: string, occurred_at: string} */
	private function fromRow( mixed $row ): array {
		if ( ! is_array( $row )
			|| ! is_numeric( $row['id'] ?? null ) || (int) $row['id'] < 1
			|| self::EVENT_BLOCKED_BY_NEEDS_ATTENTION !== ( $row['event'] ?? null )
			|| ! is_numeric( $row['attempt_id'] ?? null ) || (int) $row['attempt_id'] < 1
			|| ! is_string( $row['correlation_id'] ?? null ) || preg_match( '/^[a-f0-9]{32}$/D', $row['correlation_id'] ) !== 1
			|| ! is_string( $row['package_type'] ?? null ) || ! in_array( $row['package_type'], array( 'plugin', 'theme' ), true )
			|| ! is_string( $row['package_slug'] ?? null ) || '' === $row['package_slug'] || strlen( $row['package_slug'] ) > 191
			|| ! is_numeric( $row['actor_id'] ?? null ) || (int) $row['actor_id'] < 1
			|| ! is_string( $row['operation'] ?? null ) || '' === $row['operation'] || strlen( $row['operation'] ) > 32 || preg_match( '/^[a-z][a-z0-9_-]*$/D', $row['operation'] ) !== 1
			|| ! is_string( $row['occurred_at'] ?? null ) || false === strtotime( $row['occurred_at'] ) ) {
			throw new RuntimeException( 'The rejected-admission audit row is invalid.' );
		}

		return array(
			'id'             => (int) $row['id'],
			'event'          => self::EVENT_BLOCKED_BY_NEEDS_ATTENTION,
			'attempt_id'     => (int) $row['attempt_id'],
			'correlation_id' => $row['correlation_id'],
			'package_type'   => $row['package_type'],
			'package_slug'   => $row['package_slug'],
			'actor_id'       => (int) $row['actor_id'],
			'operation'      => $row['operation'],
			'occurred_at'    => $row['occurred_at'],
		);
	}

	/** @template T @param callable(): T $operation @return T */
	private function transaction( callable $operation ): mixed {
		try {
			if ( false === $this->database->query( 'SET TRANSACTION ISOLATION LEVEL SERIALIZABLE' )
				|| false === $this->database->query( 'START TRANSACTION' ) ) {
				throw new RuntimeException( 'The rejected-admission audit transaction is unavailable.' );
			}
			$result = $operation();
			if ( false === $this->database->query( 'COMMIT' ) ) {
				throw new RuntimeException( 'The rejected-admission audit could not be committed.' );
			}

			return $result;
		} catch ( Throwable $failure ) {
			try {
				$this->database->query( 'ROLLBACK' );
			} catch ( Throwable $rollbackFailure ) {
				unset( $rollbackFailure ); // The original safe failure remains authoritative.
			}
			throw $failure;
		}
	}

	private function prepare( string $query, mixed ...$arguments ): string {
		if ( ! method_exists( $this->database, 'prepare' ) ) {
			throw new RuntimeException( 'The rejected-admission audit database is unavailable.' );
		}
		$prepared = $this->database->prepare( $query, ...$arguments );
		if ( ! is_string( $prepared ) || '' === $prepared ) {
			throw new RuntimeException( 'The rejected-admission audit query is invalid.' );
		}

		return $prepared;
	}

	private function formatTime( DateTimeInterface $time ): string {
		return $time->format( 'Y-m-d H:i:s' );
	}
}
