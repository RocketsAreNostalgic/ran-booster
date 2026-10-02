<?php

declare(strict_types=1);

namespace Tests\Admin;

use PHPUnit\Framework\TestCase;
use RAN\AbstractPackage;
use RAN\Admin\RepositoryBranchCheckEvidenceStore;
use RAN\ManagedRepository;
use RAN\PackageSource;

final class RepositoryBranchCheckEvidenceStoreTest extends TestCase {

	public function test_verified_evidence_is_returned_only_for_the_exact_current_target_and_profile_generation(): void {
		$store   = new InMemoryRepositoryBranchCheckEvidenceStore();
		$package = new BranchEvidencePackage( new ManagedRepository( 'gh', 'owner/example', '42', 'main' ) );

		$store->record( 'plugin', $package, 'profile-a', 'verified' );

		self::assertSame( 'verified', $store->find( 'plugin', $package, 'profile-a' )['outcome'] );
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

		self::assertNull( $store->find( 'plugin', $package, 'profile-a' ) );

		$fresh = $store->profile_fingerprint_for( $package, 'profile-a' );
		$store->record( 'plugin', $package, 'profile-a', 'verified', $fresh );

		self::assertSame( 'verified', $store->find( 'plugin', $package, 'profile-a' )['outcome'] );
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
		$locked           = false;
		$queued           = false;
		$store            = null;
		$acquire          = static function () use ( &$locked, &$queued ): bool {
			if ( $locked ) {
				$queued = true;
				return false;
			}
			$locked = true;

			return true;
		};
		$release          = static function () use ( &$locked, &$queued, &$store ): bool {
			$locked = false;
			if ( $queued && $store instanceof InMemoryRepositoryBranchCheckEvidenceStore ) {
				$queued = false;
				$store->bump_profile_generation( 'gh', 'profile-a' );
			}

			return true;
		};
		$store            = new InMemoryRepositoryBranchCheckEvidenceStore( $acquire, $release );
		$package          = new BranchEvidencePackage( new ManagedRepository( 'gh', 'owner/example', '42', 'main' ) );
		$profile          = $store->profile_fingerprint_for( $package, 'profile-a' );
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

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- Local fixtures keep the cache contract self-contained.
final class InMemoryRepositoryBranchCheckEvidenceStore extends RepositoryBranchCheckEvidenceStore {

	/** @var array<string, mixed> */
	public array $records       = array();
	public ?\Closure $after_read = null;
	private \Closure $acquire_mutation_lock_callback;
	private \Closure $release_mutation_lock_callback;

	/** @param callable(): bool|null $acquire_mutation_lock @param callable(): bool|null $release_mutation_lock */
	public function __construct( ?callable $acquire_mutation_lock = null, ?callable $release_mutation_lock = null ) {
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

final class BranchEvidencePackage extends AbstractPackage {

	public function __construct( ManagedRepository $repository, private string $identifier = 'example/example.php' ) {
		$this->repository = $repository;
	}

	public function get_identifier(): mixed {
		return $this->identifier;
	}

	protected function runtime_slug(): string {
		return 'example';
	}
}
