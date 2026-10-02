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

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this exact lifecycle override name.
	protected function setUp(): void {
		global $ran_booster_storage_test_options, $wpdb;

		$ran_booster_storage_test_options = array();
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Focused WordPress database test double.
		$wpdb = new StorageTestWpdb();
	}

	public function test_empty_query_is_distinct_from_database_and_malformed_result_failures(): void {
		global $wpdb;

		$storage = $this->storage();
		self::assertSame( array(), $storage->all_for_test() );

		$wpdb->last_error = 'database details must not escape';

		try {
			$storage->all_for_test();
			self::fail( 'Expected a query failure.' );
		} catch ( PackageStorageFailure $failure ) {
			self::assertSame( 'ran_booster_storage_query_failed', $failure->get_diagnostic_id() );
			self::assertStringNotContainsString( 'database details', $failure->getMessage() );
		}

		$wpdb->last_error     = '';
		$wpdb->forced_results = array( 'not-a-row' );

		$this->expectException( PackageStorageFailure::class );
		$storage->all_for_test();
	}

	public function test_unsupported_database_blocks_package_reads_and_writes_before_table_access(): void {
		global $wpdb;

		$wpdb->server_info = '5.7.44';
		$storage           = $this->storage();

		try {
			$storage->all_for_test();
			self::fail( 'Unsupported package reads must fail closed.' );
		} catch ( PackageStorageFailure $failure ) {
			self::assertSame( 'ran_booster_storage_database_unsupported', $failure->get_diagnostic_id() );
		}

		foreach ( array(
			array( PackageStorageOperation::UPDATE, fn (): PackageMutationResult => $storage->edit_for_test( 'example/example.php', $this->edit_input() ) ),
			array( PackageStorageOperation::INSERT, fn (): PackageMutationResult => $storage->store_for_test( $this->package() ) ),
			array( PackageStorageOperation::INSERT, fn (): PackageMutationResult => $storage->adopt_for_test( $this->package() ) ),
		) as $case ) {
			[ $operation, $write ] = $case;
			try {
				$write();
				self::fail( 'Unsupported package writes must fail closed.' );
			} catch ( PackageStorageFailure $failure ) {
				self::assertSame( 'ran_booster_storage_database_unsupported', $failure->get_diagnostic_id() );
				self::assertSame( $operation, $failure->get_operation() );
			}
		}

		self::assertSame( array(), $wpdb->queries );
		self::assertSame( array(), $wpdb->updates );
		self::assertSame( array(), $wpdb->inserts );
		self::assertSame( array(), $wpdb->deletes );
	}

	public function test_cached_lifecycle_failure_blocks_package_reads_and_writes_before_table_access(): void {
		global $ran_booster_storage_test_options, $wpdb;

		$lifecycle = new Database( $wpdb );
		$ran_booster_storage_test_options[ Database::VERSION_OPTION ] = 'not-a-version';
		try {
			$lifecycle->require_ready();
			self::fail( 'Expected the lifecycle to enter its safe state.' );
		} catch ( DatabaseLifecycleFailure ) {
			$ran_booster_storage_test_options[ Database::VERSION_OPTION ] = Database::$booster_db_version;
		}

		$wpdb->read_failure = true;
		$storage            = $this->storage( true, $lifecycle );
		foreach ( array(
			static fn () => $storage->all_for_test(),
			fn () => $storage->edit_for_test( 'example/example.php', $this->edit_input() ),
			fn () => $storage->store_for_test( $this->package() ),
			fn () => $storage->adopt_for_test( $this->package() ),
		) as $operation ) {
			try {
				$operation();
				self::fail( 'Cached lifecycle failures must block package storage.' );
			} catch ( PackageStorageFailure $failure ) {
				self::assertSame( 'ran_booster_storage_database_unsupported', $failure->get_diagnostic_id() );
			}
		}

		self::assertSame( '', $wpdb->last_error );
		self::assertSame( array(), $wpdb->queries );
		self::assertSame( array(), $wpdb->updates );
		self::assertSame( array(), $wpdb->inserts );
	}

	public function test_management_presence_uses_the_unhydrated_management_row(): void {
		global $wpdb;

		$storage = $this->storage();
		self::assertFalse( $storage->has_management_record_for_test( 'example/example.php' ) );

		$wpdb->rows[] = array(
			'id'      => 12,
			'package' => 'example/example.php',
			'type'    => 1,
		);
		self::assertTrue( $storage->has_management_record_for_test( 'example/example.php' ) );

		$wpdb->last_error = 'database details must not escape';
		$this->expectException( PackageStorageFailure::class );
		$storage->has_management_record_for_test( 'example/example.php' );
	}

	public function test_insert_and_update_results_are_verified_against_authoritative_state(): void {
		global $wpdb;

		$storage = $this->storage();
		$package = $this->package();

		$wpdb->insert_result = false;
		self::assertSame( PackageMutationStatus::FAILED, $storage->store_for_test( $package )->get_status() );

		$wpdb->insert_result = null;
		self::assertSame( PackageMutationStatus::CHANGED, $storage->store_for_test( $package )->get_status() );

		$wpdb->update_result = 0;
		self::assertSame( PackageMutationStatus::CONFLICT, $storage->store_for_test( $package )->get_status() );

		$package->set_repository( new ManagedRepository( 'gh', 'owner/example', 'repository-id', 'next' ) );
		self::assertSame( PackageMutationStatus::CONFLICT, $storage->store_for_test( $package )->get_status() );

		$wpdb->update_result = 1;
		$wpdb->apply_writes  = false;
		$result              = $storage->store_for_test( $package );

		self::assertSame( PackageMutationStatus::CONFLICT, $result->get_status() );
		self::assertSame( PackageStorageOperation::UPDATE, $result->get_operation() );
		self::assertSame( 'ran_booster_storage_verification_conflict', $result->get_diagnostic_id() );

		$wpdb->rows          = array();
		$wpdb->update_result = 0;
		self::assertSame(
			PackageMutationStatus::CONFLICT,
			$storage->edit_for_test( 'missing/example.php', $this->edit_input() )->get_status()
		);
	}

	public function test_adoption_inserts_only_when_no_management_record_exists(): void {
		global $wpdb;

		$storage = $this->storage();
		$package = $this->package();

		$result = $storage->adopt_for_test( $package );
		self::assertSame( PackageMutationStatus::CHANGED, $result->get_status() );
		self::assertSame( PackageStorageOperation::INSERT, $result->get_operation() );
		self::assertCount( 1, $wpdb->inserts );

		$conflict = $storage->adopt_for_test( $package );
		self::assertSame( PackageMutationStatus::CONFLICT, $conflict->get_status() );
		self::assertSame( 'ran_booster_storage_adoption_conflict', $conflict->get_diagnostic_id() );
		self::assertCount( 1, $wpdb->inserts );
	}

	public function test_storage_rejects_an_empty_or_uninstalled_package_identity_before_any_write(): void {
		global $wpdb;

		foreach ( array(
			array( $this->storage(), $this->package( '' ) ),
			array( $this->storage( false ), $this->package() ),
		) as $case ) {
			[$storage, $package] = $case;
			$store               = $storage->store_for_test( $package );
			$adopt               = $storage->adopt_for_test( $package );
			$edit                = $storage->edit_for_test( (string) $package->get_identifier(), $this->edit_input() );

			self::assertSame( PackageMutationStatus::FAILED, $store->get_status() );
			self::assertSame( PackageMutationStatus::FAILED, $adopt->get_status() );
			self::assertSame( PackageMutationStatus::FAILED, $edit->get_status() );
			self::assertSame( 'ran_booster_storage_invalid_package_identity', $store->get_diagnostic_id() );
			self::assertSame( 'ran_booster_storage_invalid_package_identity', $adopt->get_diagnostic_id() );
			self::assertSame( 'ran_booster_storage_invalid_package_identity', $edit->get_diagnostic_id() );
		}

		self::assertSame( array(), $wpdb->inserts );
		self::assertSame( array(), $wpdb->updates );
	}

	public function test_adoption_treats_alate_management_row_as_aconflict_rather_than_overwriting_it(): void {
		global $wpdb;

		$storage               = $this->storage();
		$wpdb->insert_result   = false;
		$wpdb->insert_race_row = $this->stored_row();

		$result = $storage->adopt_for_test( $this->package() );

		self::assertSame( PackageMutationStatus::CONFLICT, $result->get_status() );
		self::assertSame( 'ran_booster_storage_adoption_conflict', $result->get_diagnostic_id() );
	}

	public function test_package_privacy_matches_mysql_tinyint_scalars_after_public_and_private_writes(): void {
		global $wpdb;

		$wpdb->coerce_private_as_mysql_tinyint = true;
		$storage                               = $this->storage();
		$package                               = $this->package();

		self::assertSame( PackageMutationStatus::CHANGED, $storage->store_for_test( $package )->get_status() );
		self::assertSame( 0, $wpdb->inserts[0][1]['private'] );
		self::assertSame( '0', $wpdb->rows[0]['private'] );

		$package->set_repository( new ManagedRepository( 'gh', 'owner/example', 'repository-id', 'next' ) );
		self::assertSame( PackageMutationStatus::CHANGED, $storage->store_for_test( $package )->get_status() );
		self::assertSame( 0, $wpdb->updates[0][1]['private'] );
		self::assertSame( '0', $wpdb->rows[0]['private'] );

		$package->set_repository( new ManagedRepository( 'gh', 'owner/example', 'repository-id', 'next', true, 'private-profile' ) );
		$package->set_source( PackageSource::BRANCH, 2 );
		self::assertSame( PackageMutationStatus::CHANGED, $storage->store_for_test( $package )->get_status() );
		self::assertSame( 1, $wpdb->updates[1][1]['private'] );
		self::assertSame( '1', $wpdb->rows[0]['private'] );
	}

	public function test_package_privacy_accepts_only_canonical_boolean_and_database_forms(): void {
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

	public function test_package_model_rejects_non_canonical_package_identities(): void {
		foreach ( array( '', ' example/example.php', 'example/example.php ', "example/\nexample.php", str_repeat( 'a', 256 ), 12 ) as $identifier ) {
			try {
				new PackageModel( array( 'package' => $identifier ) );
				self::fail( 'Expected an invalid package identity to be rejected.' );
			} catch ( InvalidArgumentException $exception ) {
				self::assertSame( 'The managed package identity is invalid.', $exception->getMessage() );
			}
		}
	}

	public function test_delete_failure_and_unverified_delete_cannot_report_success(): void {
		global $wpdb;

		$storage      = $this->storage();
		$wpdb->rows[] = array(
			'id'      => 12,
			'package' => 'example/example.php',
			'type'    => 1,
		);

		$wpdb->delete_result = false;
		self::assertSame( PackageMutationStatus::FAILED, $storage->unlink_for_test( 'example/example.php' )->get_status() );

		$wpdb->delete_result = 0;
		$wpdb->apply_writes  = false;
		self::assertSame( PackageMutationStatus::CONFLICT, $storage->unlink_for_test( 'example/example.php' )->get_status() );

		$wpdb->rows = array();
		self::assertSame( PackageMutationStatus::UNCHANGED, $storage->unlink_for_test( 'example/example.php' )->get_status() );
	}

	public function test_post_write_verification_failures_require_recovery_for_every_mutation(): void {
		global $wpdb;

		$storage = $this->storage();
		$package = $this->package();

		$wpdb->successful_reads_before_failure = 2;
		$insert_result                         = $storage->store_for_test( $package );
		$this->assert_ambiguous_write( $insert_result, PackageStorageOperation::INSERT );

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Isolated database double.
		$wpdb                                  = new StorageTestWpdb();
		$wpdb->rows[]                          = $this->stored_row();
		$wpdb->successful_reads_before_failure = 2;
		$package->set_repository( new ManagedRepository( 'gh', 'owner/example', 'repository-id', 'next' ) );
		$update_result = $storage->store_for_test( $package );
		$this->assert_ambiguous_write( $update_result, PackageStorageOperation::UPDATE );

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Isolated database double.
		$wpdb                                  = new StorageTestWpdb();
		$wpdb->rows[]                          = $this->stored_row();
		$wpdb->update_result                   = 0;
		$wpdb->successful_reads_before_failure = 2;
		$zero_update_result                    = $storage->store_for_test( $package );
		$this->assert_ambiguous_write( $zero_update_result, PackageStorageOperation::UPDATE );

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Isolated database double.
		$wpdb                                  = new StorageTestWpdb();
		$wpdb->rows[]                          = $this->stored_row();
		$wpdb->successful_reads_before_failure = 1;
		$edit_result                           = $storage->edit_for_test( 'example/example.php', $this->edit_input() );
		$this->assert_ambiguous_write( $edit_result, PackageStorageOperation::UPDATE );

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Isolated database double.
		$wpdb                                  = new StorageTestWpdb();
		$wpdb->rows[]                          = $this->stored_row();
		$wpdb->update_result                   = 0;
		$wpdb->successful_reads_before_failure = 1;
		$zero_edit_result                      = $storage->edit_for_test( 'example/example.php', $this->edit_input() );
		$this->assert_ambiguous_write( $zero_edit_result, PackageStorageOperation::UPDATE );

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Isolated database double.
		$wpdb                                  = new StorageTestWpdb();
		$wpdb->rows[]                          = $this->stored_row();
		$wpdb->successful_reads_before_failure = 0;
		$delete_result                         = $storage->unlink_for_test( 'example/example.php' );
		$this->assert_ambiguous_write( $delete_result, PackageStorageOperation::DELETE );

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Isolated database double.
		$wpdb                                  = new StorageTestWpdb();
		$wpdb->delete_result                   = 0;
		$wpdb->successful_reads_before_failure = 0;
		$zero_delete_result                    = $storage->unlink_for_test( 'example/example.php' );
		$this->assert_ambiguous_write( $zero_delete_result, PackageStorageOperation::DELETE );
	}

	public function test_missing_package_read_preserves_the_management_row(): void {
		global $wpdb;

		$storage      = $this->storage( false );
		$wpdb->rows[] = array(
			'id'      => 12,
			'package' => 'missing/example.php',
			'type'    => 1,
		);

		self::assertSame( array(), $storage->all_for_test() );
		self::assertCount( 1, $wpdb->rows );
		self::assertSame( array(), $wpdb->deletes );
	}

	public function test_package_read_can_filter_release_sources_before_hydration(): void {
		global $wpdb;

		$branch             = $this->stored_row();
		$release            = $this->stored_row();
		$release['id']      = 2;
		$release['package'] = 'release/release.php';
		$release['source']  = PackageSource::RELEASE_ASSET->value;
		$wpdb->rows         = array( $branch, $release );

		self::assertSame( array( 'release/release.php' ), array_keys( $this->storage()->all_for_test( PackageSource::RELEASE_ASSET ) ) );
	}

	private function storage( bool $package_exists = true, ?Database $database = null ): AbstractPackageRepository {
		return new class( $package_exists, $database ) extends AbstractPackageRepository {

			public function __construct( private readonly bool $exists, ?Database $database ) {
				parent::__construct( $database );
			}

			/** @return array<string, Package> */
			public function all_for_test( ?PackageSource $source = null ): array {
				return $this->all_packages( $source );
			}

			public function has_management_record_for_test( string $identifier ): bool {
				return $this->has_management_record( $identifier );
			}

			public function store_for_test( Package $package ): PackageMutationResult {
				return $this->store_package( $package );
			}

			public function adopt_for_test( Package $package ): PackageMutationResult {
				return $this->adopt_package( $package );
			}

			public function unlink_for_test( string $identifier ): PackageMutationResult {
				return $this->unlink( $identifier );
			}

			/** @param array<string, mixed> $input */
			public function edit_for_test( string $identifier, array $input ): PackageMutationResult {
				return $this->edit_package( $identifier, $input );
			}

			protected function package_type(): int {
				return 1;
			}

			protected function package_exists( string $identifier ): bool {
				return $this->exists;
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

	private function package( string $identifier = 'example/example.php' ): Package {
		$package    = new class( $identifier ) extends AbstractPackage {

			public function __construct( private readonly string $identifier ) {
			}

			public function get_identifier(): mixed {
				return $this->identifier;
			}
		};
		$repository = new ManagedRepository( 'gh', 'owner/example', 'repository-id', 'main' );
		$package->set_repository( $repository );
		$package->set_deployment_policy( DeploymentPolicy::MANUAL );
		$package->set_subdirectory( '' );

		return $package;
	}

	/** @return array<string, mixed> */
	private function edit_input(): array {
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

	private function assert_ambiguous_write( PackageMutationResult $result, PackageStorageOperation $operation ): void {
		self::assertSame( PackageMutationStatus::FAILED, $result->get_status() );
		self::assertSame( $operation, $result->get_operation() );
		self::assertSame( 'ran_booster_storage_verification_failed', $result->get_diagnostic_id() );
		self::assertTrue( $result->is_recovery_required() );
		self::assertStringContainsString( 'may have changed', $result->get_message() );
	}

	/** @return array<string, mixed> */
	private function stored_row(): array {
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
