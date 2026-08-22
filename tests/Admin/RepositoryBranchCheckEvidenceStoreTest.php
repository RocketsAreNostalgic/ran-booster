<?php

declare(strict_types=1);

namespace Tests\Admin;

use PHPUnit\Framework\TestCase;
use RAN\AbstractPackage;
use RAN\Admin\RepositoryBranchCheckEvidenceStore;
use RAN\ManagedRepository;
use RAN\PackageSource;

final class RepositoryBranchCheckEvidenceStoreTest extends TestCase {

	public function testVerifiedEvidenceIsReturnedOnlyForTheExactCurrentTargetAndProfileGeneration(): void {
		$store   = new InMemoryRepositoryBranchCheckEvidenceStore();
		$package = new BranchEvidencePackage( new ManagedRepository( 'gh', 'owner/example', '42', 'main' ) );

		$store->record( 'plugin', $package, 'profile-a', 'verified' );

		self::assertSame( 'verified', $store->find( 'plugin', $package, 'profile-a' )['outcome'] );
		self::assertNull( $store->find( 'plugin', $package, 'profile-b' ) );

		$store->bumpProfileGeneration( 'gh', 'profile-a' );
		self::assertNull( $store->find( 'plugin', $package, 'profile-a' ) );
	}

	public function testFreshFailureClearsEarlierVerifiedEvidence(): void {
		$store   = new InMemoryRepositoryBranchCheckEvidenceStore();
		$package = new BranchEvidencePackage( new ManagedRepository( 'gh', 'owner/example', '42', 'main' ) );

		$store->record( 'plugin', $package, 'profile-a', 'verified' );
		$store->record( 'plugin', $package, 'profile-a', 'unable_to_check' );

		self::assertNull( $store->find( 'plugin', $package, 'profile-a' ) );
		self::assertSame( array(), $store->records['records'] );
	}

	public function testChangedSourceRevisionAndProviderGenerationInvalidateEvidence(): void {
		$store   = new InMemoryRepositoryBranchCheckEvidenceStore();
		$package = new BranchEvidencePackage( new ManagedRepository( 'gh', 'owner/example', '42', 'main' ) );
		$store->record( 'plugin', $package, 'profile-a', 'verified' );
		$package->setSource( PackageSource::BRANCH, 2 );
		self::assertNull( $store->find( 'plugin', $package, 'profile-a' ) );

		$store->record( 'plugin', $package, 'profile-a', 'verified' );
		$store->bumpProviderGeneration( 'gh' );
		self::assertNull( $store->find( 'plugin', $package, 'profile-a' ) );
	}

	public function testAnonymousAccessDoesNotCollideWithAProfileNamedAnonymous(): void {
		$store   = new InMemoryRepositoryBranchCheckEvidenceStore();
		$package = new BranchEvidencePackage( new ManagedRepository( 'gh', 'owner/example', '42', 'main' ) );

		$store->record( 'plugin', $package, null, 'verified' );

		self::assertNotNull( $store->find( 'plugin', $package, null ) );
		self::assertNull( $store->find( 'plugin', $package, 'anonymous' ) );
	}

	public function testTheStoreCapsDistinctPackageRecordsAtTwoHundred(): void {
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

	public function testMalformedStoredRecordIsIgnoredWithoutWarnings(): void {
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
	public array $records = array();

	protected function readOption(): array {
		return $this->records;
	}

	protected function writeOption( array $records ): bool {
		$this->records = $records;
		return true;
	}
}

final class BranchEvidencePackage extends AbstractPackage {

	public function __construct( ManagedRepository $repository, private string $identifier = 'example/example.php' ) {
		$this->repository = $repository;
	}

	public function getIdentifier(): mixed {
		return $this->identifier;
	}

	protected function runtimeSlug(): string {
		return 'example';
	}
}
