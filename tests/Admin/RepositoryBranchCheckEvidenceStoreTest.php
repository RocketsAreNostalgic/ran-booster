<?php

declare(strict_types=1);

namespace RAN\Tests\Admin;

use PHPUnit\Framework\TestCase;
use RAN\AbstractPackage;
use RAN\Admin\RepositoryBranchCheckEvidenceStore;
use RAN\ManagedRepository;
use RAN\PackageSource;

require_once __DIR__ . '/../Activity/ActivityWordPressFunctions.php';

final class RepositoryBranchCheckEvidenceStoreTest extends TestCase {

	public function test_native_mutation_lock_preserves_structural_connections_and_sql_failures(): void {
		$original = $GLOBALS['wpdb'] ?? null;
		try {
			$database = new class() {
				public string $options    = 'wp_options';
				public string $last_error = '';
				/** @var list<array{string, string}> */
				public array $queries      = array();
				public mixed $result       = '1';
				public bool $release_fails = false;
				/** @return array{string, string} */
				public function prepare( string $query, string $name ): array {
					return array( $query, $name );
				}
				/** @param array{string, string} $query */
				public function get_var( array $query ): mixed {
					$this->queries[] = $query;
					return $this->release_fails && str_contains( $query[0], 'RELEASE_LOCK' ) ? null : $this->result;
				}
			};
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Install or restore the isolated test-owned database connection for the real advisory lock.
			$GLOBALS['wpdb'] = $database;
			$store           = new NativeBranchEvidenceOptionStore();
			$store->bump_provider_generation( 'gh' );
			self::assertSame( 1, $store->records['generation'] );
			self::assertSame( 'SELECT GET_LOCK(%s, 5)', $database->queries[0][0] );
			self::assertSame( 'SELECT RELEASE_LOCK(%s)', $database->queries[1][0] );
			self::assertSame( $database->queries[0][1], $database->queries[1][1] );
			foreach ( array( null, '0', '1' ) as $result ) {
				$database->result     = $result;
				$database->last_error = '1' === $result ? 'SQL failure' : '';
				try {
					$store->bump_provider_generation( 'gh' );
					self::fail( 'A failed SQL acquisition must not mutate evidence.' );
				} catch ( \RuntimeException $failure ) {
					self::assertSame( 'Booster could not coordinate repository branch check evidence.', $failure->getMessage() );
				}
				self::assertSame( 1, $store->records['generation'] );
				self::assertFalse( $store->release_for_test() );
			}
			$database->result        = '1';
			$database->last_error    = '';
			$database->release_fails = true;
			try {
				$store->bump_provider_generation( 'gh' );
				self::fail( 'Failed release must not claim successful coordination.' );
			} catch ( \RuntimeException $failure ) {
				self::assertSame( 'Booster could not release the repository branch check evidence lock.', $failure->getMessage() );
			}
			self::assertSame( 2, $store->records['generation'] );
		} finally {
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Install or restore the isolated test-owned database connection for the real advisory lock.
			$GLOBALS['wpdb'] = $original;
		}
	}

	public function test_unusable_database_never_claims_acquisition_or_release(): void {
		$original = $GLOBALS['wpdb'] ?? null;
		try {
			foreach ( array( null, new \stdClass(), new class() { public function prepare(): void {} } ) as $database ) {
				// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Install or restore the isolated test-owned database connection for the real advisory lock.
				$GLOBALS['wpdb'] = $database;
				$store           = new NativeBranchEvidenceOptionStore();
				self::assertFalse( $store->release_for_test() );
				try {
					$store->bump_profile_generation( 'gh', 'profile-a' );
					self::fail( 'Missing host locking cannot report successful mutation.' );
				} catch ( \RuntimeException $failure ) {
					self::assertSame( 'Booster could not coordinate repository branch check evidence.', $failure->getMessage() );
				}
				self::assertSame( array(), $store->records );
			}
		} finally {
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Install or restore the isolated test-owned database connection for the real advisory lock.
			$GLOBALS['wpdb'] = $original;
		}
	}

	public function test_failed_write_uses_readback_and_always_releases_the_lock(): void {
		$released = 0;
		$store    = new class( $released ) extends RepositoryBranchCheckEvidenceStore {
			/** @var array<string, mixed> */
			public array $records      = array();
			public bool $write_matches = false;
			public function __construct( public int &$released ) {}
			protected function read_option(): array {
				return $this->records;
			}
			protected function write_option( array $records ): bool {
				if ( $this->write_matches ) {
					$this->records = $records;
				}
				return false;
			}
			protected function acquire_mutation_lock(): bool {
				return true;
			}
			protected function release_mutation_lock(): bool {
				++$this->released;
				return true;
			}
		};
		try {
			$store->bump_provider_generation( 'gh' );
			self::fail( 'Different readback must reject a failed write.' );
		} catch ( \RuntimeException $failure ) {
			self::assertSame( 'Booster could not save repository branch check evidence.', $failure->getMessage() );
		}
		self::assertSame( 1, $released );
		$store->write_matches = true;
		$store->bump_provider_generation( 'gh' );
		self::assertSame( 1, $store->records['generation'] );
		self::assertSame( 2, $released );
	}

	public function test_verified_evidence_is_returned_only_for_the_exact_current_target_and_profile_generation(): void {
		$store   = new InMemoryRepositoryBranchCheckEvidenceStore();
		$package = new BranchEvidencePackage( new ManagedRepository( 'gh', 'owner/example', '42', 'main' ) );

		$store->record( 'plugin', $package, 'profile-a', 'verified' );

		$evidence = $store->find( 'plugin', $package, 'profile-a' );
		self::assertNotNull( $evidence );
		self::assertSame( 'verified', $evidence['outcome'] );
		self::assertNull( $store->find( 'plugin', $package, 'profile-b' ) );

		$store->bump_profile_generation( provider: 'gh', profile_id: 'profile-a' );
		self::assertNull( $store->find( 'plugin', $package, 'profile-a' ) );
	}

	public function test_fresh_failure_clears_earlier_verified_evidence(): void {
		$store   = new InMemoryRepositoryBranchCheckEvidenceStore();
		$package = new BranchEvidencePackage( new ManagedRepository( 'gh', 'owner/example', '42', 'main' ) );

		$store->record( 'plugin', $package, 'profile-a', 'verified' );
		$store->record( 'plugin', $package, 'profile-a', 'unable_to_check' );

		self::assertNull( $store->find( 'plugin', $package, 'profile-a' ) );
		self::assertSame( array(), $store->records['records'] );
	}

	public function test_clear_invalidates_an_in_flight_check_before_it_can_record_stale_evidence(): void {
		$store   = new InMemoryRepositoryBranchCheckEvidenceStore();
		$package = new BranchEvidencePackage( new ManagedRepository( 'gh', 'owner/example', '42', 'main' ) );
		$stale   = $store->profile_fingerprint_for( $package, 'profile-a' );

		$store->clear( 'plugin', $package );
		$store->record( 'plugin', $package, 'profile-a', 'verified', $stale );

		$package = new BranchEvidencePackage( new ManagedRepository( 'gh', 'owner/example', '42', 'main' ), token: 'gh:42:22222222-2222-4222-8222-222222222222' );
		self::assertNull( $store->find( 'plugin', $package, 'profile-a' ) );

		$fresh = $store->profile_fingerprint_for( $package, 'profile-a' );
		$store->record( 'plugin', $package, 'profile-a', 'verified', $fresh );

		$evidence = $store->find( 'plugin', $package, 'profile-a' );
		self::assertNotNull( $evidence );
		self::assertSame( 'verified', $evidence['outcome'] );
	}

	public function test_changed_source_revision_and_provider_generation_invalidate_evidence(): void {
		$store   = new InMemoryRepositoryBranchCheckEvidenceStore();
		$package = new BranchEvidencePackage( new ManagedRepository( 'gh', 'owner/example', '42', 'main' ) );
		$store->record( 'plugin', $package, 'profile-a', 'verified' );
		$package->set_source( PackageSource::BRANCH, 2 );
		self::assertNull( $store->find( 'plugin', $package, 'profile-a' ) );

		$store->record( 'plugin', $package, 'profile-a', 'verified' );
		$store->bump_provider_generation( 'gh' );
		self::assertNull( $store->find( 'plugin', $package, 'profile-a' ) );
	}

	public function test_evidence_written_after_profile_mutation_retains_the_original_profile_generation(): void {
		$store   = new InMemoryRepositoryBranchCheckEvidenceStore();
		$package = new BranchEvidencePackage( new ManagedRepository( 'gh', 'owner/example', '42', 'main' ) );
		$profile = $store->profile_fingerprint_for( $package, 'profile-a' );

		$store->bump_profile_generation( 'gh', 'profile-a' );
		$store->record( 'plugin', $package, 'profile-a', 'verified', $profile );

		self::assertNull( $store->find( 'plugin', $package, 'profile-a' ) );
	}

	public function test_anonymous_access_does_not_collide_with_aprofile_named_anonymous(): void {
		$store   = new InMemoryRepositoryBranchCheckEvidenceStore();
		$package = new BranchEvidencePackage( new ManagedRepository( 'gh', 'owner/example', '42', 'main' ) );

		$store->record( 'plugin', $package, null, 'verified' );

		self::assertNotNull( $store->find( 'plugin', $package, null ) );
		self::assertNull( $store->find( 'plugin', $package, 'anonymous' ) );
	}

	public function test_confirmed_missing_path_replaces_positive_observation_across_later_uncertain_checks(): void {
		$store    = new InMemoryRepositoryBranchCheckEvidenceStore();
		$package  = new BranchEvidencePackage( new ManagedRepository( 'gh', 'owner/example', '42', 'main' ) );
		$activity = new \RAN\Activity\ActivityStore( $store->database, 'test_activity' );
		$capture  = $store->activity_context( 'plugin', $package, 'branch.check' );
		self::assertNotNull( $capture['adoption_token'] );
		foreach ( array( 'verified', 'subdirectory_unavailable', 'unable_to_check', 'subdirectory_unverified', 'provider_unavailable' ) as $outcome ) {
			$store->record( 'plugin', $package, 'profile-a', $outcome );
			$summary = $activity->summary( 'plugin', $capture['subject_identity'], $capture['adoption_token'], $capture['context'], 'branch.check' );
			self::assertTrue( $summary['available'] );
			self::assertSame( $outcome, $summary['latest']['reason'] ?? null );
			self::assertSame( 'verified' === $outcome ? 'verified' : 'subdirectory_unavailable', $summary['observation']['reason'] ?? null );
			self::assertSame( 'verified' === $outcome ? 'succeeded' : 'rejected', $summary['observation']['status'] ?? null );
			self::assertSame( 'verified' === $outcome, null !== $store->find( 'plugin', $package, 'profile-a' ) );
		}
	}

	public function test_optional_history_failures_never_restore_an_earlier_verified_badge(): void {
		foreach ( array( 'fail_insert', 'fail_complete' ) as $failure ) {
			$store   = new InMemoryRepositoryBranchCheckEvidenceStore();
			$package = new BranchEvidencePackage( new ManagedRepository( 'gh', 'owner/example', '42', 'main' ) );
			$store->record( 'plugin', $package, 'profile-a', 'verified' );
			self::assertNotNull( $store->find( 'plugin', $package, 'profile-a' ) );
			$store->database->$failure = true;
			$store->begin_check( 'plugin', $package, 'profile-a' );
			self::assertNull( $store->find( 'plugin', $package, 'profile-a' ) );
			$store->record( 'plugin', $package, 'profile-a', 'verified' );
			self::assertNull( $store->find( 'plugin', $package, 'profile-a' ) );
			self::assertSame( array(), $store->records['records'] );
		}
	}

	public function test_check_start_freezes_context_before_credential_changes_and_retains_no_parallel_observation(): void {
		$store   = new InMemoryRepositoryBranchCheckEvidenceStore();
		$package = new BranchEvidencePackage( new ManagedRepository( 'gh', 'owner/example', '42', 'main' ) );
		$store->begin_check( 'plugin', $package, 'profile-a' );
		$store->bump_profile_generation( 'gh', 'profile-a' );
		$store->record( 'plugin', $package, 'profile-a', 'verified' );
		self::assertNull( $store->find( 'plugin', $package, 'profile-a' ) );
		self::assertCount( 1, $store->database->records );
		self::assertSame( 'verified', $store->database->records[1]['reason'] );
		$records = $store->records['records'];
		self::assertIsArray( $records );
		self::assertSame( array( 'id', 'profile' ), array_keys( array_values( $records )[0] ) );
	}

	public function test_the_store_caps_distinct_package_records_at_two_hundred(): void {
		$store = new InMemoryRepositoryBranchCheckEvidenceStore();
		for ( $index = 0; $index < 201; ++$index ) {
			$store->record(
				'plugin',
				new BranchEvidencePackage( new ManagedRepository( 'gh', 'owner/example', '42', 'main' ), 'example/' . $index . '.php' ),
				'profile-a',
				'verified'
			);
		}

		self::assertCount( 200, $store->records['records'] );
		self::assertNull( $store->find( 'plugin', new BranchEvidencePackage( new ManagedRepository( 'gh', 'owner/example', '42', 'main' ), 'example/0.php' ), 'profile-a' ) );
	}

	public function test_credential_and_provider_mutations_use_one_bounded_generation_value(): void {
		$store = new InMemoryRepositoryBranchCheckEvidenceStore();
		$store->bump_profile_generation( 'gh', 'profile-a' );
		$store->bump_provider_generation( 'gh' );

		self::assertSame( 2, $store->records['generation'] );
		self::assertArrayNotHasKey( 'profile_generations', $store->records );
		self::assertArrayNotHasKey( 'provider_generations', $store->records );
	}

	public function test_mutation_lock_prevents_astale_record_from_undoing_aqueued_generation_bump(): void {
		$locked            = false;
		$queued            = false;
		$store             = null;
		$acquire           = static function () use ( &$locked, &$queued ): bool {
			if ( $locked ) {
				$queued = true;
				return false;
			}
			$locked = true;

			return true;
		};
		$release           = static function () use ( &$locked, &$queued, &$store ): bool {
			$locked = false;
			// @phpstan-ignore instanceof.alwaysFalse, booleanAnd.alwaysFalse (This by-reference callback executes after the store is assigned below.)
			if ( $queued && $store instanceof InMemoryRepositoryBranchCheckEvidenceStore ) {
				$queued = false;
				$store->bump_profile_generation( 'gh', 'profile-a' );
			}

			return true;
		};
		$store             = new InMemoryRepositoryBranchCheckEvidenceStore( $acquire, $release );
		$package           = new BranchEvidencePackage( new ManagedRepository( 'gh', 'owner/example', '42', 'main' ) );
		$profile           = $store->profile_fingerprint_for( $package, 'profile-a' );
		$store->after_read = static function () use ( $store ): void {
			try {
				$store->bump_profile_generation( 'gh', 'profile-a' );
			} catch ( \RuntimeException $failure ) {
				self::assertSame( 'Booster could not coordinate repository branch check evidence.', $failure->getMessage() );
			}
		};

		$store->record( 'plugin', $package, 'profile-a', 'verified', $profile );

		self::assertFalse( $locked );
		self::assertFalse( $queued );
		self::assertSame( 1, $store->records['generation'] );
		self::assertNull( $store->find( 'plugin', $package, 'profile-a' ) );
	}

	public function test_malformed_stored_record_is_ignored_without_warnings(): void {
		$store          = new InMemoryRepositoryBranchCheckEvidenceStore();
		$package        = new BranchEvidencePackage( new ManagedRepository( 'gh', 'owner/example', '42', 'main' ) );
		$store->records = array(
			'records' => array(
				hash( 'sha256', "plugin\\0example/example.php" ) => array(
					'outcome'    => 'verified',
					'checked_at' => '2026-08-22T00:00:00Z',
					'target'     => array(),
					'profile'    => array(),
				),
			),
		);

		self::assertNull( $store->find( 'plugin', $package, 'profile-a' ) );
	}
}


// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- Local fixtures keep the cache contract self-contained.
final class InMemoryRepositoryBranchCheckEvidenceStore extends RepositoryBranchCheckEvidenceStore {

	/** @var array<string, mixed> */
	public array $records = array();
	public \RAN\Tests\Support\BranchActivityConnection $database;
	public ?\Closure $after_read = null;
	private \Closure $acquire_mutation_lock_callback;
	private \Closure $release_mutation_lock_callback;

	/** @param callable(): bool|null $acquire_mutation_lock @param callable(): bool|null $release_mutation_lock */
	public function __construct( ?callable $acquire_mutation_lock = null, ?callable $release_mutation_lock = null ) {
		$GLOBALS['ran_booster_activity_options'] = array( \RAN\Activity\ActivityStore::VERSION_OPTION => '1' );
		$this->database                          = new \RAN\Tests\Support\BranchActivityConnection();
		parent::__construct( new \RAN\Activity\ActivityStore( $this->database, 'test_activity' ) );
		$this->acquire_mutation_lock_callback = \Closure::fromCallable( $acquire_mutation_lock ?? static fn (): bool => true );
		$this->release_mutation_lock_callback = \Closure::fromCallable( $release_mutation_lock ?? static fn (): bool => true );
	}

	protected function read_option(): array {
		$records = $this->records;
		if ( null !== $this->after_read ) {
			$after_read       = $this->after_read;
			$this->after_read = null;
			$after_read();
		}

		return $records;
	}

	protected function write_option( array $records ): bool {
		$this->records = $records;
		return true;
	}

	protected function acquire_mutation_lock(): bool {
		return ( $this->acquire_mutation_lock_callback )();
	}

	protected function release_mutation_lock(): bool {
		return ( $this->release_mutation_lock_callback )();
	}
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- Local fixtures keep the cache contract self-contained.
final class BranchEvidencePackage extends AbstractPackage {

	public function __construct( ?ManagedRepository $repository = null, private string $identifier = 'example/example.php', string $token = 'gh:42:11111111-1111-4111-8111-111111111111' ) {
		TestCase::assertNotNull( $repository );
		$this->repository = $repository;
		$this->set_adoption_token( $token );
	}

	public function get_identifier(): mixed {
		return $this->identifier;
	}

	protected function runtime_slug(): string {
		return 'example';
	}
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- Explicit option seam retains the real structural database lock methods.
final class NativeBranchEvidenceOptionStore extends RepositoryBranchCheckEvidenceStore {
	/** @var array<string, mixed> */
	public array $records = array();
	protected function read_option(): array {
		return $this->records;
	}
	protected function write_option( array $records ): bool {
		$this->records = $records;
		return true;
	}
	public function release_for_test(): bool {
		return $this->release_mutation_lock();
	}
}
