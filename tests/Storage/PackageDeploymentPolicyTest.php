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

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this exact lifecycle override name.
	protected function setUp(): void {
		global $wpdb;

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Focused WordPress database test double.
		$wpdb = new StorageTestWpdb();
	}

	public function test_packages_default_to_manual_and_use_the_shared_policy_enum(): void {
		$package = $this->package();

		self::assertSame( DeploymentPolicy::MANUAL, $package->get_deployment_policy() );

		foreach ( DeploymentPolicy::cases() as $policy ) {
			$package->set_deployment_policy( $policy );
			self::assertSame( $policy, $package->get_deployment_policy() );
		}

		self::assertNull( DeploymentPolicy::tryFrom( 'enabled' ) );
	}

	public function test_storage_persists_and_hydrates_one_deployment_policy_without_legacy_fields(): void {
		global $wpdb;

		$package = $this->package();
		$package->set_deployment_policy( DeploymentPolicy::AUTOMATIC );

		$result = $this->storage()->store_for_test( $package );

		self::assertTrue( $result->is_successful() );
		self::assertSame( DeploymentPolicy::AUTOMATIC->value, $wpdb->inserts[0][1]['deployment_policy'] );
		self::assertArrayNotHasKey( 'ptd', $wpdb->inserts[0][1] );
		self::assertArrayNotHasKey( 'status', $wpdb->inserts[0][1] );

		$hydrated = $this->storage()->find_for_test( 'example/example.php' );
		self::assertSame( DeploymentPolicy::AUTOMATIC, $hydrated->get_deployment_policy() );
	}

	public function test_package_model_rejects_unknown_policies(): void {
		$this->expectException( \InvalidArgumentException::class );
		new PackageModel( array( 'deployment_policy' => 'enabled' ) );
	}

	public function test_package_model_accepts_only_canonical_source_state(): void {
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

	public function test_bulk_policy_can_enable_native_automatic_updates_for_arelease_source(): void {
		global $wpdb;

		$row                    = $this->stored_row( 1, 'alpha/alpha.php', DeploymentPolicy::MANUAL );
		$row['source']          = PackageSource::RELEASE_ASSET->value;
		$row['source_revision'] = 4;
		$wpdb->rows             = array( $row );

		$result = $this->storage()->set_policies_for_test(
			array( $this->snapshot( $row ) ),
			DeploymentPolicy::AUTOMATIC
		);

		self::assertSame( 1, $result['changed'] );
		self::assertSame( DeploymentPolicy::AUTOMATIC->value, $wpdb->rows[0]['deployment_policy'] );
		self::assertSame( PackageSource::RELEASE_ASSET->value, $wpdb->rows[0]['source'] );
		self::assertSame( 4, $wpdb->rows[0]['source_revision'] );
	}

	public function test_bulk_policy_does_not_mutate_aquarantined_nested_release(): void {
		global $wpdb;

		$row                    = $this->stored_row( 1, 'alpha/alpha.php', DeploymentPolicy::MANUAL );
		$row['source']          = PackageSource::RELEASE_ASSET->value;
		$row['source_revision'] = 4;
		$row['subdirectory']    = 'packages/alpha';
		$wpdb->rows             = array( $row );

		$this->expectException( PackageStorageFailure::class );
		try {
			$this->storage()->set_policies_for_test( array( $this->snapshot( $row ) ), DeploymentPolicy::AUTOMATIC );
		} finally {
			self::assertSame( array(), $wpdb->updates );
			self::assertSame( DeploymentPolicy::MANUAL->value, $wpdb->rows[0]['deployment_policy'] );
		}
	}

	public function test_package_model_normalizes_package_privacy_values(): void {
		foreach ( array( false, 0, '0' ) as $value ) {
			self::assertSame( 0, ( new PackageModel( array( 'private' => $value ) ) )->private );
		}

		foreach ( array( true, 1, '1' ) as $value ) {
			self::assertSame( 1, ( new PackageModel( array( 'private' => $value ) ) )->private );
		}
	}

	public function test_package_model_rejects_ambiguous_package_privacy_values(): void {
		$this->expectException( \InvalidArgumentException::class );

		new PackageModel( array( 'private' => '' ) );
	}

	public function test_bulk_policy_writes_only_the_policy_and_counts_changed_rows(): void {
		global $wpdb;

		$wpdb->rows = array(
			$this->stored_row( 1, 'alpha/alpha.php', DeploymentPolicy::MANUAL ),
			$this->stored_row( 2, 'beta/beta.php', DeploymentPolicy::DISABLED ),
		);

		$result = $this->storage()->set_policies_for_test(
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

	public function test_bulk_policy_rolls_back_every_write_when_alater_update_fails(): void {
		global $wpdb;

		$wpdb->rows               = array(
			$this->stored_row( 1, 'alpha/alpha.php', DeploymentPolicy::MANUAL ),
			$this->stored_row( 2, 'beta/beta.php', DeploymentPolicy::MANUAL ),
		);
		$snapshots                = array( $this->snapshot( $wpdb->rows[0] ), $this->snapshot( $wpdb->rows[1] ) );
		$wpdb->fail_update_number = 2;

		try {
			$this->storage()->set_policies_for_test( $snapshots, DeploymentPolicy::AUTOMATIC );
			self::fail( 'A partial bulk policy change must not survive.' );
		} catch ( PackageStorageFailure ) {
			self::assertSame( array( 'manual', 'manual' ), array_column( $wpdb->rows, 'deployment_policy' ) );
			self::assertSame( 'ROLLBACK', $wpdb->queries[ array_key_last( $wpdb->queries ) ] );
		}
	}

	public function test_bulk_policy_rejects_astale_snapshot_before_writing(): void {
		global $wpdb;

		$wpdb->rows         = array( $this->stored_row( 1, 'alpha/alpha.php', DeploymentPolicy::MANUAL ) );
		$snapshot           = $this->snapshot( $wpdb->rows[0] );
		$snapshot['branch'] = 'stale-branch';

		$this->expectException( PackageStorageFailure::class );
		try {
			$this->storage()->set_policies_for_test( array( $snapshot ), DeploymentPolicy::DISABLED );
		} finally {
			self::assertSame( array(), $wpdb->updates );
			self::assertSame( 'manual', $wpdb->rows[0]['deployment_policy'] );
		}
	}

	private function storage(): AbstractPackageRepository {
		$lifecycle = new class() extends Database {
			public function require_ready(): void {
			}
		};

		return new class( $lifecycle ) extends AbstractPackageRepository {

			public function store_for_test( Package $package ): PackageMutationResult {
				return $this->store_package( $package );
			}

			public function find_for_test( string $identifier ): Package {
				return $this->managed_package( $identifier );
			}

			/**
			 * @param list<array<string, mixed>> $snapshots
			 * @return array{selected: int, changed: int, unchanged: int}
			 */
			public function set_policies_for_test( array $snapshots, DeploymentPolicy $policy ): array {
				return $this->set_deployment_policies( $snapshots, $policy );
			}

			protected function package_type(): int {
				return 1;
			}

			// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundInExtendedClass -- The fixture implementation of package_exists retains the production method contract; these inputs do not affect this controlled result.
			protected function package_exists( string $identifier ): bool {
				return true;
			}

			protected function package_from_installation( string $identifier ): Package {
				return new class( $identifier ) extends AbstractPackage {

					public function __construct( private readonly string $identifier ) {
					}

					public function get_identifier(): mixed {
						return $this->identifier;
					}
				};
			}

			protected function not_found_exception(): Throwable {
				return new RuntimeException( 'Package not found.' );
			}
		};
	}

	private function package(): Package {
		$package    = new class() extends AbstractPackage {

			public function get_identifier(): mixed {
				return 'example/example.php';
			}
		};
		$repository = new ManagedRepository( 'gh', 'owner/example', 'repository-id', 'main' );
		$package->set_repository( $repository );

		return $package;
	}

	/** @return array<string, mixed> */
	private function stored_row( int $id, string $identifier, DeploymentPolicy $policy ): array {
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
