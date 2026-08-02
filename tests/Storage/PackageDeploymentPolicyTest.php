<?php

declare(strict_types=1);

namespace Tests\Storage;

use RAN\AbstractPackage;
use RAN\Deployment\DeploymentPolicy;
use RAN\ManagedRepository;
use RAN\Package;
use RAN\PackageSource;
use RAN\Storage\AbstractPackageRepository;
use RAN\Storage\Database;
use RAN\Storage\PackageModel;
use RAN\Storage\PackageMutationResult;
use RAN\Storage\PackageStorageFailure;
use RuntimeException;
use Tests\RANBoosterTestCase;
use Throwable;

require_once __DIR__ . '/StorageTestEnvironment.php';

final class PackageDeploymentPolicyTest extends RANBoosterTestCase {

	protected function setUp(): void {
		global $wpdb;

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Focused WordPress database test double.
		$wpdb = new StorageTestWpdb();
	}

	public function testPackagesDefaultToManualAndUseTheSharedPolicyEnum(): void {
		$package = $this->package();

		self::assertSame( DeploymentPolicy::MANUAL, $package->getDeploymentPolicy() );

		foreach ( DeploymentPolicy::cases() as $policy ) {
			$package->setDeploymentPolicy( $policy );
			self::assertSame( $policy, $package->getDeploymentPolicy() );
		}

		self::assertNull( DeploymentPolicy::tryFrom( 'enabled' ) );
	}

	public function testStoragePersistsAndHydratesOneDeploymentPolicyWithoutLegacyFields(): void {
		global $wpdb;

		$package = $this->package();
		$package->setDeploymentPolicy( DeploymentPolicy::AUTOMATIC );

		$result = $this->storage()->storeForTest( $package );

		self::assertTrue( $result->isSuccessful() );
		self::assertSame( DeploymentPolicy::AUTOMATIC->value, $wpdb->inserts[0][1]['deployment_policy'] );
		self::assertArrayNotHasKey( 'ptd', $wpdb->inserts[0][1] );
		self::assertArrayNotHasKey( 'status', $wpdb->inserts[0][1] );

		$hydrated = $this->storage()->findForTest( 'example/example.php' );
		self::assertSame( DeploymentPolicy::AUTOMATIC, $hydrated->getDeploymentPolicy() );
	}

	public function testPackageModelRejectsUnknownPolicies(): void {
		$this->expectException( \InvalidArgumentException::class );
		new PackageModel( array( 'deployment_policy' => 'enabled' ) );
	}

	public function testPackageModelAcceptsOnlyCanonicalSourceState(): void {
		self::assertSame( 'branch', ( new PackageModel( array() ) )->source );
		self::assertSame( 1, ( new PackageModel( array() ) )->source_revision );
		self::assertSame(
			'release_asset',
			( new PackageModel( array( 'source' => PackageSource::RELEASE_ASSET->value ) ) )->source
		);
		self::assertSame( 12, ( new PackageModel( array( 'source_revision' => '12' ) ) )->source_revision );

		foreach ( array( '', 'tag', 1, null ) as $source ) {
			try {
				new PackageModel( array( 'source' => $source ) );
				self::fail( 'Expected an invalid package source to be rejected.' );
			} catch ( \InvalidArgumentException ) {
				self::addToAssertionCount( 1 );
			}
		}
		foreach ( array( 0, -1, '01', '1.0', true, PHP_INT_MAX . '0' ) as $revision ) {
			try {
				new PackageModel( array( 'source_revision' => $revision ) );
				self::fail( 'Expected an invalid package source revision to be rejected.' );
			} catch ( \InvalidArgumentException ) {
				self::addToAssertionCount( 1 );
			}
		}
	}

	public function testBulkPolicyCanEnableNativeAutomaticUpdatesForAReleaseSource(): void {
		global $wpdb;

		$row                    = $this->storedRow( 1, 'alpha/alpha.php', DeploymentPolicy::MANUAL );
		$row['source']          = PackageSource::RELEASE_ASSET->value;
		$row['source_revision'] = 4;
		$wpdb->rows             = array( $row );

		$result = $this->storage()->setPoliciesForTest(
			array( $this->snapshot( $row ) ),
			DeploymentPolicy::AUTOMATIC
		);

		self::assertSame( 1, $result['changed'] );
		self::assertSame( DeploymentPolicy::AUTOMATIC->value, $wpdb->rows[0]['deployment_policy'] );
		self::assertSame( PackageSource::RELEASE_ASSET->value, $wpdb->rows[0]['source'] );
		self::assertSame( 4, $wpdb->rows[0]['source_revision'] );
	}

	public function testPackageModelNormalizesPackagePrivacyValues(): void {
		foreach ( array( false, 0, '0' ) as $value ) {
			self::assertSame( 0, ( new PackageModel( array( 'private' => $value ) ) )->private );
		}

		foreach ( array( true, 1, '1' ) as $value ) {
			self::assertSame( 1, ( new PackageModel( array( 'private' => $value ) ) )->private );
		}
	}

	public function testPackageModelRejectsAmbiguousPackagePrivacyValues(): void {
		$this->expectException( \InvalidArgumentException::class );

		new PackageModel( array( 'private' => '' ) );
	}

	public function testBulkPolicyWritesOnlyThePolicyAndCountsChangedRows(): void {
		global $wpdb;

		$wpdb->rows = array(
			$this->storedRow( 1, 'alpha/alpha.php', DeploymentPolicy::MANUAL ),
			$this->storedRow( 2, 'beta/beta.php', DeploymentPolicy::DISABLED ),
		);

		$result = $this->storage()->setPoliciesForTest(
			array(
				$this->snapshot( $wpdb->rows[0] ),
				$this->snapshot( $wpdb->rows[1] ),
			),
			DeploymentPolicy::DISABLED
		);

		self::assertSame(
			array(
				'selected'  => 2,
				'changed'   => 1,
				'unchanged' => 1,
			),
			$result
		);
		self::assertCount( 1, $wpdb->updates );
		self::assertSame( array( 'deployment_policy' => 'disabled' ), $wpdb->updates[0][1] );
		self::assertSame( array( 'disabled', 'disabled' ), array_column( $wpdb->rows, 'deployment_policy' ) );
		self::assertSame( 'COMMIT', $wpdb->queries[ array_key_last( $wpdb->queries ) ] );
	}

	public function testBulkPolicyRollsBackEveryWriteWhenALaterUpdateFails(): void {
		global $wpdb;

		$wpdb->rows             = array(
			$this->storedRow( 1, 'alpha/alpha.php', DeploymentPolicy::MANUAL ),
			$this->storedRow( 2, 'beta/beta.php', DeploymentPolicy::MANUAL ),
		);
		$snapshots              = array( $this->snapshot( $wpdb->rows[0] ), $this->snapshot( $wpdb->rows[1] ) );
		$wpdb->failUpdateNumber = 2;

		try {
			$this->storage()->setPoliciesForTest( $snapshots, DeploymentPolicy::AUTOMATIC );
			self::fail( 'A partial bulk policy change must not survive.' );
		} catch ( PackageStorageFailure ) {
			self::assertSame( array( 'manual', 'manual' ), array_column( $wpdb->rows, 'deployment_policy' ) );
			self::assertSame( 'ROLLBACK', $wpdb->queries[ array_key_last( $wpdb->queries ) ] );
		}
	}

	public function testBulkPolicyRejectsAStaleSnapshotBeforeWriting(): void {
		global $wpdb;

		$wpdb->rows         = array( $this->storedRow( 1, 'alpha/alpha.php', DeploymentPolicy::MANUAL ) );
		$snapshot           = $this->snapshot( $wpdb->rows[0] );
		$snapshot['branch'] = 'stale-branch';

		$this->expectException( PackageStorageFailure::class );
		try {
			$this->storage()->setPoliciesForTest( array( $snapshot ), DeploymentPolicy::DISABLED );
		} finally {
			self::assertSame( array(), $wpdb->updates );
			self::assertSame( 'manual', $wpdb->rows[0]['deployment_policy'] );
		}
	}

	private function storage(): AbstractPackageRepository {
		$lifecycle = new class() extends Database {
			public function requireReady(): void {
			}
		};

		return new class( $lifecycle ) extends AbstractPackageRepository {

			public function storeForTest( Package $package ): PackageMutationResult {
				return $this->storePackage( $package );
			}

			public function findForTest( string $identifier ): Package {
				return $this->managedPackage( $identifier );
			}

			/**
			 * @param list<array<string, mixed>> $snapshots
			 * @return array{selected: int, changed: int, unchanged: int}
			 */
			public function setPoliciesForTest( array $snapshots, DeploymentPolicy $policy ): array {
				return $this->setDeploymentPolicies( $snapshots, $policy );
			}

			protected function packageType(): int {
				return 1;
			}

			protected function packageExists( string $identifier ): bool {
				return true;
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

	private function package(): Package {
		$package    = new class() extends AbstractPackage {

			public function getIdentifier(): mixed {
				return 'example/example.php';
			}
		};
		$repository = new ManagedRepository( 'gh', 'owner/example', 'repository-id', 'main' );
		$package->setRepository( $repository );

		return $package;
	}

	/** @return array<string, mixed> */
	private function storedRow( int $id, string $identifier, DeploymentPolicy $policy ): array {
		return array(
			'id'                     => $id,
			'package'                => $identifier,
			'repository'             => 'owner/' . dirname( $identifier ),
			'branch'                 => 'main',
			'type'                   => 1,
			'deployment_policy'      => $policy->value,
			'source'                 => 'branch',
			'source_revision'        => 1,
			'provider'               => 'gh',
			'provider_repository_id' => 'R_' . $id,
			'private'                => 0,
			'credential_id'          => null,
			'subdirectory'           => null,
		);
	}

	/** @param array<string, mixed> $row */
	private function snapshot( array $row ): array {
		unset( $row['id'], $row['type'] );

		return $row;
	}
}
