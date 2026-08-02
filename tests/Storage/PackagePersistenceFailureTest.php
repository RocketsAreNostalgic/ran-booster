<?php

declare(strict_types=1);

namespace Tests\Storage;

use InvalidArgumentException;
use RAN\AbstractPackage;
use RAN\Deployment\DeploymentPolicy;
use RAN\ManagedRepository;
use RAN\Package;
use RAN\PackageSource;
use RAN\Storage\AbstractPackageRepository;
use RAN\Storage\Database;
use RAN\Storage\DatabaseLifecycleFailure;
use RAN\Storage\PackageMutationResult;
use RAN\Storage\PackageMutationStatus;
use RAN\Storage\PackageModel;
use RAN\Storage\PackageStorageFailure;
use RAN\Storage\PackageStorageOperation;
use RuntimeException;
use Tests\RANBoosterTestCase;
use Throwable;

require_once __DIR__ . '/StorageTestEnvironment.php';

final class PackagePersistenceFailureTest extends RANBoosterTestCase {

	protected function setUp(): void {
		global $ran_booster_storage_test_options, $wpdb;

		$ran_booster_storage_test_options = array();
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Focused WordPress database test double.
		$wpdb = new StorageTestWpdb();
	}

	public function testEmptyQueryIsDistinctFromDatabaseAndMalformedResultFailures(): void {
		global $wpdb;

		$storage = $this->storage();
		self::assertSame( array(), $storage->allForTest() );

		$wpdb->last_error = 'database details must not escape';

		try {
			$storage->allForTest();
			self::fail( 'Expected a query failure.' );
		} catch ( PackageStorageFailure $failure ) {
			self::assertSame( 'ran_booster_storage_query_failed', $failure->getDiagnosticId() );
			self::assertStringNotContainsString( 'database details', $failure->getMessage() );
		}

		$wpdb->last_error    = '';
		$wpdb->forcedResults = array( 'not-a-row' );

		$this->expectException( PackageStorageFailure::class );
		$storage->allForTest();
	}

	public function testUnsupportedDatabaseBlocksPackageReadsAndWritesBeforeTableAccess(): void {
		global $wpdb;

		$wpdb->serverInfo = '5.7.44';
		$storage          = $this->storage();

		try {
			$storage->allForTest();
			self::fail( 'Unsupported package reads must fail closed.' );
		} catch ( PackageStorageFailure $failure ) {
			self::assertSame( 'ran_booster_storage_database_unsupported', $failure->getDiagnosticId() );
		}

		try {
			$storage->editForTest( 'example/example.php', $this->editInput() );
			self::fail( 'Unsupported package writes must fail closed.' );
		} catch ( PackageStorageFailure $failure ) {
			self::assertSame( 'ran_booster_storage_database_unsupported', $failure->getDiagnosticId() );
		}

		self::assertSame( array(), $wpdb->queries );
		self::assertSame( array(), $wpdb->updates );
		self::assertSame( array(), $wpdb->inserts );
		self::assertSame( array(), $wpdb->deletes );
	}

	public function testCachedLifecycleFailureBlocksPackageReadsAndWritesBeforeTableAccess(): void {
		global $ran_booster_storage_test_options, $wpdb;

		$lifecycle = new Database( $wpdb );
		$ran_booster_storage_test_options[ Database::VERSION_OPTION ] = 'not-a-version';
		try {
			$lifecycle->requireReady();
			self::fail( 'Expected the lifecycle to enter its safe state.' );
		} catch ( DatabaseLifecycleFailure ) {
			$ran_booster_storage_test_options[ Database::VERSION_OPTION ] = Database::$booster_db_version;
		}

		$wpdb->readFailure = true;
		$storage           = $this->storage( true, $lifecycle );
		foreach ( array(
			static fn () => $storage->allForTest(),
			fn () => $storage->editForTest( 'example/example.php', $this->editInput() ),
		) as $operation ) {
			try {
				$operation();
				self::fail( 'Cached lifecycle failures must block package storage.' );
			} catch ( PackageStorageFailure $failure ) {
				self::assertSame( 'ran_booster_storage_database_unsupported', $failure->getDiagnosticId() );
			}
		}

		self::assertSame( '', $wpdb->last_error );
		self::assertSame( array(), $wpdb->queries );
		self::assertSame( array(), $wpdb->updates );
		self::assertSame( array(), $wpdb->inserts );
	}

	public function testManagementPresenceUsesTheUnhydratedManagementRow(): void {
		global $wpdb;

		$storage = $this->storage();
		self::assertFalse( $storage->hasManagementRecordForTest( 'example/example.php' ) );

		$wpdb->rows[] = array(
			'id'      => 12,
			'package' => 'example/example.php',
			'type'    => 1,
		);
		self::assertTrue( $storage->hasManagementRecordForTest( 'example/example.php' ) );

		$wpdb->last_error = 'database details must not escape';
		$this->expectException( PackageStorageFailure::class );
		$storage->hasManagementRecordForTest( 'example/example.php' );
	}

	public function testInsertAndUpdateResultsAreVerifiedAgainstAuthoritativeState(): void {
		global $wpdb;

		$storage = $this->storage();
		$package = $this->package();

		$wpdb->insertResult = false;
		self::assertSame( PackageMutationStatus::FAILED, $storage->storeForTest( $package )->getStatus() );

		$wpdb->insertResult = null;
		self::assertSame( PackageMutationStatus::CHANGED, $storage->storeForTest( $package )->getStatus() );

		$wpdb->updateResult = 0;
		self::assertSame( PackageMutationStatus::CONFLICT, $storage->storeForTest( $package )->getStatus() );

		$package->setRepository( new ManagedRepository( 'gh', 'owner/example', 'repository-id', 'next' ) );
		self::assertSame( PackageMutationStatus::CONFLICT, $storage->storeForTest( $package )->getStatus() );

		$wpdb->updateResult = 1;
		$wpdb->applyWrites  = false;
		$result             = $storage->storeForTest( $package );

		self::assertSame( PackageMutationStatus::CONFLICT, $result->getStatus() );
		self::assertSame( PackageStorageOperation::UPDATE, $result->getOperation() );
		self::assertSame( 'ran_booster_storage_verification_conflict', $result->getDiagnosticId() );

		$wpdb->rows         = array();
		$wpdb->updateResult = 0;
		self::assertSame(
			PackageMutationStatus::CONFLICT,
			$storage->editForTest( 'missing/example.php', $this->editInput() )->getStatus()
		);
	}

	public function testAdoptionInsertsOnlyWhenNoManagementRecordExists(): void {
		global $wpdb;

		$storage = $this->storage();
		$package = $this->package();

		$result = $storage->adoptForTest( $package );
		self::assertSame( PackageMutationStatus::CHANGED, $result->getStatus() );
		self::assertSame( PackageStorageOperation::INSERT, $result->getOperation() );
		self::assertCount( 1, $wpdb->inserts );

		$conflict = $storage->adoptForTest( $package );
		self::assertSame( PackageMutationStatus::CONFLICT, $conflict->getStatus() );
		self::assertSame( 'ran_booster_storage_adoption_conflict', $conflict->getDiagnosticId() );
		self::assertCount( 1, $wpdb->inserts );
	}

	public function testStorageRejectsAnEmptyOrUninstalledPackageIdentityBeforeAnyWrite(): void {
		global $wpdb;

		foreach ( array(
			array( $this->storage(), $this->package( '' ) ),
			array( $this->storage( false ), $this->package() ),
		) as $case ) {
			[$storage, $package] = $case;
			$store               = $storage->storeForTest( $package );
			$adopt               = $storage->adoptForTest( $package );
			$edit                = $storage->editForTest( (string) $package->getIdentifier(), $this->editInput() );

			self::assertSame( PackageMutationStatus::FAILED, $store->getStatus() );
			self::assertSame( PackageMutationStatus::FAILED, $adopt->getStatus() );
			self::assertSame( PackageMutationStatus::FAILED, $edit->getStatus() );
			self::assertSame( 'ran_booster_storage_invalid_package_identity', $store->getDiagnosticId() );
			self::assertSame( 'ran_booster_storage_invalid_package_identity', $adopt->getDiagnosticId() );
			self::assertSame( 'ran_booster_storage_invalid_package_identity', $edit->getDiagnosticId() );
		}

		self::assertSame( array(), $wpdb->inserts );
		self::assertSame( array(), $wpdb->updates );
	}

	public function testAdoptionTreatsALateManagementRowAsAConflictRatherThanOverwritingIt(): void {
		global $wpdb;

		$storage             = $this->storage();
		$wpdb->insertResult  = false;
		$wpdb->insertRaceRow = $this->storedRow();

		$result = $storage->adoptForTest( $this->package() );

		self::assertSame( PackageMutationStatus::CONFLICT, $result->getStatus() );
		self::assertSame( 'ran_booster_storage_adoption_conflict', $result->getDiagnosticId() );
	}

	public function testPackagePrivacyMatchesMysqlTinyintScalarsAfterPublicAndPrivateWrites(): void {
		global $wpdb;

		$wpdb->coercePrivateAsMysqlTinyint = true;
		$storage                           = $this->storage();
		$package                           = $this->package();

		self::assertSame( PackageMutationStatus::CHANGED, $storage->storeForTest( $package )->getStatus() );
		self::assertSame( 0, $wpdb->inserts[0][1]['private'] );
		self::assertSame( '0', $wpdb->rows[0]['private'] );

		$package->setRepository( new ManagedRepository( 'gh', 'owner/example', 'repository-id', 'next' ) );
		self::assertSame( PackageMutationStatus::CHANGED, $storage->storeForTest( $package )->getStatus() );
		self::assertSame( 0, $wpdb->updates[0][1]['private'] );
		self::assertSame( '0', $wpdb->rows[0]['private'] );

		$package->setRepository( new ManagedRepository( 'gh', 'owner/example', 'repository-id', 'next', true, 'private-profile' ) );
		$package->setSource( PackageSource::BRANCH, 2 );
		self::assertSame( PackageMutationStatus::CHANGED, $storage->storeForTest( $package )->getStatus() );
		self::assertSame( 1, $wpdb->updates[1][1]['private'] );
		self::assertSame( '1', $wpdb->rows[0]['private'] );
	}

	public function testPackagePrivacyAcceptsOnlyCanonicalBooleanAndDatabaseForms(): void {
		foreach ( array( false, 0, '0', ' 0 ' ) as $value ) {
			self::assertSame( 0, ( new PackageModel( array( 'private' => $value ) ) )->private );
		}

		foreach ( array( true, 1, '1', ' 1 ' ) as $value ) {
			self::assertSame( 1, ( new PackageModel( array( 'private' => $value ) ) )->private );
		}

		foreach ( array( null, '', 2, 'false', array() ) as $value ) {
			try {
				new PackageModel( array( 'private' => $value ) );
				self::fail( 'Expected invalid repository privacy input to be rejected.' );
			} catch ( InvalidArgumentException $exception ) {
				self::assertSame( 'The repository privacy setting is invalid.', $exception->getMessage() );
			}
		}
	}

	public function testPackageModelRejectsNonCanonicalPackageIdentities(): void {
		foreach ( array( '', ' example/example.php', 'example/example.php ', "example/\nexample.php", str_repeat( 'a', 256 ), 12 ) as $identifier ) {
			try {
				new PackageModel( array( 'package' => $identifier ) );
				self::fail( 'Expected an invalid package identity to be rejected.' );
			} catch ( InvalidArgumentException $exception ) {
				self::assertSame( 'The managed package identity is invalid.', $exception->getMessage() );
			}
		}
	}

	public function testDeleteFailureAndUnverifiedDeleteCannotReportSuccess(): void {
		global $wpdb;

		$storage      = $this->storage();
		$wpdb->rows[] = array(
			'id'      => 12,
			'package' => 'example/example.php',
			'type'    => 1,
		);

		$wpdb->deleteResult = false;
		self::assertSame( PackageMutationStatus::FAILED, $storage->unlinkForTest( 'example/example.php' )->getStatus() );

		$wpdb->deleteResult = 0;
		$wpdb->applyWrites  = false;
		self::assertSame( PackageMutationStatus::CONFLICT, $storage->unlinkForTest( 'example/example.php' )->getStatus() );

		$wpdb->rows = array();
		self::assertSame( PackageMutationStatus::UNCHANGED, $storage->unlinkForTest( 'example/example.php' )->getStatus() );
	}

	public function testPostWriteVerificationFailuresRequireRecoveryForEveryMutation(): void {
		global $wpdb;

		$storage = $this->storage();
		$package = $this->package();

		$wpdb->successfulReadsBeforeFailure = 1;
		$insertResult                       = $storage->storeForTest( $package );
		$this->assertAmbiguousWrite( $insertResult, PackageStorageOperation::INSERT );

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Isolated database double.
		$wpdb                               = new StorageTestWpdb();
		$wpdb->rows[]                       = $this->storedRow();
		$wpdb->successfulReadsBeforeFailure = 1;
		$package->setRepository( new ManagedRepository( 'gh', 'owner/example', 'repository-id', 'next' ) );
		$updateResult = $storage->storeForTest( $package );
		$this->assertAmbiguousWrite( $updateResult, PackageStorageOperation::UPDATE );

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Isolated database double.
		$wpdb                               = new StorageTestWpdb();
		$wpdb->rows[]                       = $this->storedRow();
		$wpdb->updateResult                 = 0;
		$wpdb->successfulReadsBeforeFailure = 1;
		$zeroUpdateResult                   = $storage->storeForTest( $package );
		$this->assertAmbiguousWrite( $zeroUpdateResult, PackageStorageOperation::UPDATE );

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Isolated database double.
		$wpdb                               = new StorageTestWpdb();
		$wpdb->rows[]                       = $this->storedRow();
		$wpdb->successfulReadsBeforeFailure = 0;
		$editResult                         = $storage->editForTest( 'example/example.php', $this->editInput() );
		$this->assertAmbiguousWrite( $editResult, PackageStorageOperation::UPDATE );

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Isolated database double.
		$wpdb                               = new StorageTestWpdb();
		$wpdb->rows[]                       = $this->storedRow();
		$wpdb->updateResult                 = 0;
		$wpdb->successfulReadsBeforeFailure = 0;
		$zeroEditResult                     = $storage->editForTest( 'example/example.php', $this->editInput() );
		$this->assertAmbiguousWrite( $zeroEditResult, PackageStorageOperation::UPDATE );

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Isolated database double.
		$wpdb                               = new StorageTestWpdb();
		$wpdb->rows[]                       = $this->storedRow();
		$wpdb->successfulReadsBeforeFailure = 0;
		$deleteResult                       = $storage->unlinkForTest( 'example/example.php' );
		$this->assertAmbiguousWrite( $deleteResult, PackageStorageOperation::DELETE );

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Isolated database double.
		$wpdb                               = new StorageTestWpdb();
		$wpdb->deleteResult                 = 0;
		$wpdb->successfulReadsBeforeFailure = 0;
		$zeroDeleteResult                   = $storage->unlinkForTest( 'example/example.php' );
		$this->assertAmbiguousWrite( $zeroDeleteResult, PackageStorageOperation::DELETE );
	}

	public function testMissingPackageReadPreservesTheManagementRow(): void {
		global $wpdb;

		$storage      = $this->storage( false );
		$wpdb->rows[] = array(
			'id'      => 12,
			'package' => 'missing/example.php',
			'type'    => 1,
		);

		self::assertSame( array(), $storage->allForTest() );
		self::assertCount( 1, $wpdb->rows );
		self::assertSame( array(), $wpdb->deletes );
	}

	public function testPackageReadCanFilterReleaseSourcesBeforeHydration(): void {
		global $wpdb;

		$branch             = $this->storedRow();
		$release            = $this->storedRow();
		$release['id']      = 2;
		$release['package'] = 'release/release.php';
		$release['source']  = PackageSource::RELEASE_ASSET->value;
		$wpdb->rows         = array( $branch, $release );

		self::assertSame( array( 'release/release.php' ), array_keys( $this->storage()->allForTest( PackageSource::RELEASE_ASSET ) ) );
	}

	private function storage( bool $packageExists = true, ?Database $database = null ): AbstractPackageRepository {
		return new class( $packageExists, $database ) extends AbstractPackageRepository {

			public function __construct( private readonly bool $exists, ?Database $database ) {
				parent::__construct( $database );
			}

			/** @return array<string, Package> */
			public function allForTest( ?PackageSource $source = null ): array {
				return $this->allPackages( $source );
			}

			public function hasManagementRecordForTest( string $identifier ): bool {
				return $this->hasManagementRecord( $identifier );
			}

			public function storeForTest( Package $package ): PackageMutationResult {
				return $this->storePackage( $package );
			}

			public function adoptForTest( Package $package ): PackageMutationResult {
				return $this->adoptPackage( $package );
			}

			public function unlinkForTest( string $identifier ): PackageMutationResult {
				return $this->unlink( $identifier );
			}

			/** @param array<string, mixed> $input */
			public function editForTest( string $identifier, array $input ): PackageMutationResult {
				return $this->editPackage( $identifier, $input );
			}

			protected function packageType(): int {
				return 1;
			}

			protected function packageExists( string $identifier ): bool {
				return $this->exists;
			}

			protected function packageFromInstallation( string $identifier ): Package {
				return new class( $identifier ) extends AbstractPackage {

					public function __construct( private readonly string $identifier ) {
					}

					public function getIdentifier(): mixed {
						return $this->identifier;
					}
				};
			}

			protected function notFoundException(): Throwable {
				return new RuntimeException( 'Package not found.' );
			}
		};
	}

	private function package( string $identifier = 'example/example.php' ): Package {
		$package    = new class( $identifier ) extends AbstractPackage {

			public function __construct( private readonly string $identifier ) {
			}

			public function getIdentifier(): mixed {
				return $this->identifier;
			}
		};
		$repository = new ManagedRepository( 'gh', 'owner/example', 'repository-id', 'main' );
		$package->setRepository( $repository );
		$package->setDeploymentPolicy( DeploymentPolicy::MANUAL );
		$package->setSubdirectory( '' );

		return $package;
	}

	/** @return array<string, mixed> */
	private function editInput(): array {
		$repository = new ManagedRepository( 'gh', 'owner/example', 'repository-id', 'main' );

		return array(
			'repository'               => $repository,
			'branch'                   => 'main',
			'deployment_policy'        => DeploymentPolicy::MANUAL->value,
			'subdirectory'             => '',
			'private'                  => '0',
			'credential_id'            => '',
			'expected_source'          => 'branch',
			'expected_source_revision' => 1,
		);
	}

	private function assertAmbiguousWrite( PackageMutationResult $result, PackageStorageOperation $operation ): void {
		self::assertSame( PackageMutationStatus::FAILED, $result->getStatus() );
		self::assertSame( $operation, $result->getOperation() );
		self::assertSame( 'ran_booster_storage_verification_failed', $result->getDiagnosticId() );
		self::assertTrue( $result->isRecoveryRequired() );
		self::assertStringContainsString( 'may have changed', $result->getMessage() );
	}

	/** @return array<string, mixed> */
	private function storedRow(): array {
		return array(
			'id'                     => 1,
			'package'                => 'example/example.php',
			'repository'             => 'owner/example',
			'branch'                 => 'main',
			'type'                   => 1,
			'provider'               => 'gh',
			'provider_repository_id' => 'repository-id',
			'private'                => 0,
			'deployment_policy'      => DeploymentPolicy::MANUAL->value,
			'subdirectory'           => '',
			'credential_id'          => '',
			'source'                 => 'branch',
			'source_revision'        => 1,
		);
	}
}
