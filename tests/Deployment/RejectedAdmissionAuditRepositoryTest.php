<?php

declare(strict_types=1);

namespace Tests\Deployment;

// phpcs:disable Generic.Files.OneObjectStructurePerFile -- Focused database double remains with its repository contract tests.

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RAN\Deployment\RejectedAdmissionAuditRepository;
use RAN\Storage\Database;
use RuntimeException;

#[CoversClass( RejectedAdmissionAuditRepository::class )]
final class RejectedAdmissionAuditRepositoryTest extends TestCase {

	private RejectedAdmissionAuditDatabase $database;
	private DateTimeImmutable $now;
	private RejectedAdmissionAuditRepository $repository;

	protected function setUp(): void {
		$this->database   = new RejectedAdmissionAuditDatabase();
		$this->now        = new DateTimeImmutable( '2026-07-27 12:00:00 UTC' );
		$this->repository = $this->repository();
	}

	public function testRecordsOnlySafeRejectedAdmissionEvidence(): void {
		$event = $this->repository->recordBlockedByNeedsAttention( $this->attempt(), 17, 'reinstall' );

		self::assertSame(
			array(
				'id'             => 1,
				'event'          => 'blocked_by_needs_attention',
				'attempt_id'     => 29,
				'correlation_id' => 'adbb33edaf022124149eff3669289ebf',
				'package_type'   => 'plugin',
				'package_slug'   => 'ran-duplicate-detector',
				'actor_id'       => 17,
				'operation'      => 'reinstall',
				'occurred_at'    => '2026-07-27 12:00:00',
			),
			$event
		);
		self::assertSame( array_keys( $event ), array_keys( array_replace( $event, $this->database->rows[0] ) ) );
		self::assertStringContainsString( 'SET TRANSACTION ISOLATION LEVEL SERIALIZABLE', $this->database->queries[0] );
		self::assertSame( 'COMMIT', $this->database->queries[ array_key_last( $this->database->queries ) ] );
	}

	public function testCoalescesRepeatedActionsByTheSameActorAndOperationForTenSeconds(): void {
		$first     = $this->repository->recordBlockedByNeedsAttention( $this->attempt(), 17, 'reinstall' );
		$this->now = $this->now->modify( '+9 seconds' );
		$same      = $this->repository->recordBlockedByNeedsAttention( $this->attempt(), 17, 'reinstall' );
		$this->now = $this->now->modify( '+2 seconds' );
		$next      = $this->repository->recordBlockedByNeedsAttention( $this->attempt(), 17, 'reinstall' );

		self::assertSame( $first, $same );
		self::assertSame( 2, $next['id'] );
		self::assertCount( 2, $this->database->rows );
	}

	public function testKeepsDistinctActorsAndOperationsAsSeparateAuditEvents(): void {
		$first  = $this->repository->recordBlockedByNeedsAttention( $this->attempt(), 17, 'reinstall' );
		$actor  = $this->repository->recordBlockedByNeedsAttention( $this->attempt(), 18, 'reinstall' );
		$action = $this->repository->recordBlockedByNeedsAttention( $this->attempt(), 17, 'update' );

		self::assertSame( array( 1, 2, 3 ), array( $first['id'], $actor['id'], $action['id'] ) );
	}

	public function testRetentionDeletesOnlyTheOldestEventBeforeWritingTheNewOne(): void {
		$this->repository = $this->repository( 2 );
		$first            = $this->repository->recordBlockedByNeedsAttention( $this->attempt(), 1, 'reinstall' );
		$this->now        = $this->now->modify( '+301 seconds' );
		$second           = $this->repository->recordBlockedByNeedsAttention( $this->attempt(), 1, 'reinstall' );
		$this->now        = $this->now->modify( '+301 seconds' );
		$third            = $this->repository->recordBlockedByNeedsAttention( $this->attempt(), 1, 'reinstall' );

		self::assertSame( 1, $first['id'] );
		self::assertSame( 2, $second['id'] );
		self::assertSame( 3, $third['id'] );
		self::assertSame( array( 2, 3 ), array_column( $this->database->rows, 'id' ) );
	}

	public function testRecentReadsNewestFirstAndRejectsUnsafeRows(): void {
		$this->repository->recordBlockedByNeedsAttention( $this->attempt(), 1, 'reinstall' );
		$this->now = $this->now->modify( '+301 seconds' );
		$this->repository->recordBlockedByNeedsAttention( $this->attempt(), 1, 'reinstall' );

		self::assertSame( array( 2, 1 ), array_column( $this->repository->recent(), 'id' ) );

		$this->database->rows[0]['operation'] = 'not safe!';
		$this->expectException( RuntimeException::class );
		$this->repository->recent();
	}

	public function testRejectsUnsafeAttemptBeforeWriting(): void {
		$attempt          = $this->attempt();
		$attempt['state'] = 'running';

		$this->expectException( RuntimeException::class );
		try {
			$this->repository->recordBlockedByNeedsAttention( $attempt, 1, 'reinstall' );
		} finally {
			self::assertSame( array(), $this->database->rows );
		}
	}

	/** @return array<string, bool|int|string|null> */
	private function attempt(): array {
		return array(
			'id'             => 29,
			'correlation_id' => 'adbb33edaf022124149eff3669289ebf',
			'package_type'   => 'plugin',
			'package_slug'   => 'ran-duplicate-detector',
			'state'          => 'needs_attention',
		);
	}

	private function repository( int $maximumRows = RejectedAdmissionAuditRepository::DEFAULT_MAX_EVENT_ROWS ): RejectedAdmissionAuditRepository {
		$lifecycle = $this->createStub( Database::class );
		return new RejectedAdmissionAuditRepository(
			$this->database,
			'wp_ran_booster_rejected_admission_audit',
			fn (): DateTimeImmutable => $this->now,
			$lifecycle,
			$maximumRows
		);
	}
}

/** Focused in-memory wpdb double for rejected-admission audit persistence. */
final class RejectedAdmissionAuditDatabase {

	public string $last_error = '';
	public int $insert_id     = 0;
	/** @var list<array<string, int|string>> */
	public array $rows = array();
	/** @var list<string> */
	public array $queries = array();
	/** @var list<array<string, int|string>>|null */
	private ?array $snapshot = null;

	public function prepare( string $query, mixed ...$arguments ): string {
		foreach ( $arguments as $argument ) {
			$query = (string) preg_replace_callback(
				'/%[dis]/',
				static fn ( array $match ): string => match ( $match[0] ) {
					'%i' => '`' . str_replace( '`', '``', (string) $argument ) . '`',
					'%d' => (string) (int) $argument,
					default => "'" . addslashes( (string) $argument ) . "'",
				},
				$query,
				1
			);
		}

		return $query;
	}

	public function query( string $query ): int|false {
		$this->queries[] = $query;
		if ( 'SET TRANSACTION ISOLATION LEVEL SERIALIZABLE' === $query ) {
			return 0;
		}
		if ( 'START TRANSACTION' === $query ) {
			$this->snapshot = $this->rows;
			return 0;
		}
		if ( 'COMMIT' === $query ) {
			$this->snapshot = null;
			return 0;
		}
		if ( 'ROLLBACK' === $query ) {
			if ( null !== $this->snapshot ) {
				$this->rows = $this->snapshot;
			}
			$this->snapshot = null;
			return 0;
		}
		if ( preg_match( '/^DELETE FROM `[^`]+` WHERE id = (\d+)$/', $query, $matches ) === 1 ) {
			$id         = (int) $matches[1];
			$before     = count( $this->rows );
			$this->rows = array_values( array_filter( $this->rows, static fn ( array $row ): bool => (int) $row['id'] !== $id ) );
			return $before - count( $this->rows );
		}

		return 1;
	}

	/** @param array<string, int|string> $data */
	public function insert( string $table, array $data ): int|false {
		unset( $table );
		$this->insert_id = array_reduce( $this->rows, static fn ( int $largest, array $row ): int => max( $largest, (int) $row['id'] ), 0 ) + 1;
		$data['id']      = $this->insert_id;
		$this->rows[]    = $data;

		return 1;
	}

	/** @return array<string, int|string>|null */
	public function get_row( string $query, mixed $output = null ): ?array {
		unset( $output );
		$rows = $this->filtered( $query );

		return $rows[0] ?? null;
	}

	/** @return list<array<string, int|string>> */
	public function get_results( string $query, mixed $output = null ): array {
		unset( $output );
		return $this->filtered( $query );
	}

	/** @return list<array<string, int|string>> */
	private function filtered( string $query ): array {
		$rows = $this->rows;
		foreach ( array( 'event', 'operation', 'correlation_id', 'package_type', 'package_slug' ) as $field ) {
			if ( preg_match( "/$field = '([^']+)'/", $query, $matches ) === 1 ) {
				$rows = array_filter( $rows, static fn ( array $row ): bool => (string) $row[ $field ] === stripslashes( $matches[1] ) );
			}
		}
		foreach ( array( 'id', 'attempt_id', 'actor_id' ) as $field ) {
			if ( preg_match( "/\\b$field = (\\d+)/", $query, $matches ) === 1 ) {
				$rows = array_filter( $rows, static fn ( array $row ): bool => (int) $row[ $field ] === (int) $matches[1] );
			}
		}
		if ( preg_match( "/occurred_at >= '([^']+)'/", $query, $matches ) === 1 ) {
			$rows = array_filter( $rows, static fn ( array $row ): bool => (string) $row['occurred_at'] >= stripslashes( $matches[1] ) );
		}
		if ( str_contains( $query, 'ORDER BY occurred_at DESC, id DESC' ) ) {
			usort( $rows, static fn ( array $left, array $right ): int => array( $right['occurred_at'], $right['id'] ) <=> array( $left['occurred_at'], $left['id'] ) );
		} elseif ( str_contains( $query, 'ORDER BY occurred_at, id' ) ) {
			usort( $rows, static fn ( array $left, array $right ): int => array( $left['occurred_at'], $left['id'] ) <=> array( $right['occurred_at'], $right['id'] ) );
		}
		if ( preg_match( '/LIMIT (\d+)/', $query, $matches ) === 1 ) {
			$rows = array_slice( array_values( $rows ), 0, (int) $matches[1] );
		}

		return array_values( $rows );
	}
}
