<?php

declare(strict_types=1);

namespace RAN\Tests\Storage;

use InvalidArgumentException;
use RAN\AbstractPackage;
use RAN\Deployment\DeploymentPolicy;
use RAN\ManagedRepository;
use RAN\Package;
use RAN\PackageSource;
use RAN\Storage\AbstractPackageRepository;
use RAN\Storage\Database;
use RAN\BoosterGitHubProvider\V1\GitHubProvider;
use RAN\RepositoryProvider\GitHubReplacementDeclaration;
use RAN\Storage\PluginRepository;
use RAN\Storage\ThemeRepository;
use RAN\Storage\DatabaseLifecycleFailure;
use RAN\Storage\PackageMutationResult;
use RAN\Storage\PackageMutationStatus;
use RAN\Storage\PackageModel;
use RAN\Storage\PackageStorageFailure;
use RAN\Storage\PackageStorageOperation;
use RAN\WordPress\ManagedReleaseConfiguration;
use RuntimeException;
use RAN\Tests\RANBoosterTestCase;
use Throwable;

require_once __DIR__ . '/StorageTestEnvironment.php';
require_once dirname( __DIR__ ) . '/WordPress/disposable-management-cleanup.php';

final class PackagePersistenceFailureTest extends RANBoosterTestCase {

	private function install_fixture(): void {
		$GLOBALS['ran_booster_storage_test_options'][ Database::VERSION_OPTION ] = false;
		$declaration = new GitHubReplacementDeclaration( array_merge( array( 'plugin_basename' => 'ran-booster/ran-booster.php' ), GitHubProvider::compatibility_contract() ) );
		( new Database( bundled_declaration: $declaration ) )->install();
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this exact lifecycle override name.
	protected function setUp(): void {
		global $ran_booster_storage_test_options, $wpdb;

		$ran_booster_storage_test_options = array();
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Focused WordPress database test double.
		$wpdb = new StorageTestWpdb();
	}

	public function test_direct_fixture_cleanup_preserves_exact_record_and_failures(): void {
		foreach ( array( 1, 2 ) as $type ) {
			foreach ( array( 'current', 'absent', 'read-failure', 'false-write', 'zero-write', 'readback-failure', 'replacement' ) as $state ) {
				$GLOBALS['ran_booster_storage_test_options'] = array();
				// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Focused WordPress database test double.
				$GLOBALS['wpdb'] = new StorageTestWpdb();
				$wpdb            = $GLOBALS['wpdb'];
				$this->install_fixture();
				$id         = 1 === $type ? 'fixture/fixture.php' : 'fixture';
				$row        = array(
					'id'                     => 1,
					'package'                => $id,
					'package_type'           => $type,
					'provider'               => 'gh',
					'provider_repository_id' => 'repo',
					'adoption_token'         => 'gh:repo:12345678-1234-4234-8234-123456789abc',
					'source'                 => 'branch',
					'source_revision'        => 1,
				);
				$unrelated  = array_replace(
					$row,
					array(
						'id'           => 2,
						'package'      => 'unrelated',
						'package_type' => 2,
					)
				);
				$wpdb->rows = 'absent' === $state ? array( $unrelated ) : array( $row, $unrelated );
				$lifecycle  = new class() extends Database {
					public function require_ready(): void {
					}

					public function require_recovery_ready(): void {
					}
				};
				$repository = 1 === $type ? new PluginRepository( $lifecycle ) : new ThemeRepository( $lifecycle );
				if ( 'read-failure' === $state ) {
					$wpdb->read_failure = true;
				}
				if ( 'false-write' === $state ) {
					$wpdb->query_failure_contains = 'DELETE FROM';
				}
				if ( 'zero-write' === $state ) {
					$wpdb->delete_result = 0;
				}
				if ( 'readback-failure' === $state ) {
					$wpdb->successful_reads_before_failure = 2;
				}
				if ( 'replacement' === $state ) {
					$repository = new class( $lifecycle, $type ) extends PluginRepository {
						public function __construct( Database $db, private int $type ) {
							parent::__construct( $db );
						}
						protected function package_type(): int {
							return $this->type;
						}
						public function recovery_record( string $identifier ): array {
							$record                                     = parent::recovery_record( $identifier );
							$GLOBALS['wpdb']->rows[0]['adoption_token'] = 'gh:repo:22345678-1234-4234-8234-123456789abc';
							return $record;
						}
					};
				}
				$caught = false;
				try {
					ran_booster_cleanup_fixture_management( $repository, $id );
				} catch ( RuntimeException ) {
					$caught = true;
				}
				self::assertSame( ! in_array( $state, array( 'current', 'absent' ), true ), $caught );
				self::assertContains( $unrelated, $wpdb->rows );
				if ( in_array( $state, array( 'current', 'absent', 'readback-failure' ), true ) ) {
					self::assertSame( array( $unrelated ), $wpdb->rows );
				} else {
					self::assertCount( 2, $wpdb->rows );
				}
				if ( 'replacement' === $state ) {
					self::assertSame( 'gh:repo:22345678-1234-4234-8234-123456789abc', $wpdb->rows[0]['adoption_token'] );
				}
			}
		}
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

	public function test_inventory_rejects_invalid_privacy_after_a_valid_installed_row(): void {
		global $wpdb;

		$valid      = $this->stored_row();
		$invalid    = array_replace(
			$valid,
			array(
				'package' => 'other/other.php',
				'private' => 2,
			)
		);
		$wpdb->rows = array( $valid, $invalid );
		$this->expectException( PackageStorageFailure::class );
		try {
			$this->storage()->all_for_test();
		} finally {
			self::assertSame( array(), $wpdb->updates );
			self::assertSame( array(), $wpdb->deletes );
		}
	}

	public function test_inventory_rejects_a_non_object_after_a_valid_row(): void {
		global $wpdb;

		$wpdb->forced_results = array( (object) $this->stored_row(), 'not-a-row' );
		try {
			$this->storage()->all_for_test();
			self::fail( 'A valid first row must not hide a malformed later row.' );
		} catch ( PackageStorageFailure $failure ) {
			self::assertSame( 'ran_booster_storage_query_failed', $failure->get_diagnostic_id() );
		}
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
			'id'           => 12,
			'package'      => 'example/example.php',
			'package_type' => 1,
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

	public function test_guarded_delete_failure_and_unverified_delete_cannot_report_success(): void {
		global $wpdb;
		$this->install_fixture();
		$storage    = $this->storage();
		$wpdb->rows = array( $this->stored_row() );
		$snapshot   = $storage->recovery_record( 'example/example.php' );
		foreach ( array( false, 0 ) as $delete_result ) {
			$wpdb->delete_result = $delete_result;
			$result              = $storage->recover_unlink( 'example/example.php', $snapshot['adoption_token'], $snapshot['digest'], static function (): void {} );
			self::assertSame( PackageMutationStatus::FAILED, $result->get_status() );
			self::assertCount( 1, $wpdb->rows );
			self::assertSame( 'ROLLBACK', end( $wpdb->queries ) );
		}
		$wpdb->delete_result = 1;
		$wpdb->apply_writes  = false;
		$result              = $storage->recover_unlink( 'example/example.php', $snapshot['adoption_token'], $snapshot['digest'], static function (): void {} );
		self::assertSame( PackageMutationStatus::FAILED, $result->get_status() );
		self::assertTrue( $result->is_recovery_required() );
		self::assertCount( 1, $wpdb->rows );
		$wpdb->rows = array();
		try {
			$storage->recover_unlink(
				'example/example.php',
				$snapshot['adoption_token'],
				$snapshot['digest'],
				static function (): void {
					self::fail( 'Absent management must not reach the guard.' );
				}
			);
			self::fail( 'Absent management is not a confirmed deletion.' );
		} catch ( PackageStorageFailure $failure ) {
			self::assertSame( 'ran_booster_storage_duplicate_package', $failure->get_diagnostic_id() );
		}
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
		$package->set_adoption_token( 'gh:repository-id:12345678-1234-4234-8234-123456789abc' );
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
		$wpdb->successful_reads_before_failure = 2;
		$edit_result                           = $storage->edit_for_test( 'example/example.php', $this->edit_input() );
		$this->assert_ambiguous_write( $edit_result, PackageStorageOperation::UPDATE );

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Isolated database double.
		$wpdb                                  = new StorageTestWpdb();
		$wpdb->rows[]                          = $this->stored_row();
		$wpdb->update_result                   = 0;
		$wpdb->successful_reads_before_failure = 2;
		$zero_edit_result                      = $storage->edit_for_test( 'example/example.php', $this->edit_input() );
		$this->assert_ambiguous_write( $zero_edit_result, PackageStorageOperation::UPDATE );

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Isolated database double.
		$wpdb         = new StorageTestWpdb();
		$wpdb->rows[] = $this->stored_row();
		$this->install_fixture();
		$storage       = $this->storage();
		$snapshot      = $storage->recovery_record( 'example/example.php' );
		$delete_result = $storage->recover_unlink(
			'example/example.php',
			$snapshot['adoption_token'],
			$snapshot['digest'],
			static function () use ( $wpdb ): void {
				$wpdb->successful_reads_before_failure = 0;
			}
		);
		$this->assert_ambiguous_write( $delete_result, PackageStorageOperation::DELETE );

		// Zero affected rows is now rejected before readback, rather than an ambiguous idempotent raw delete.
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Reset the focused WordPress database test double.
		$wpdb         = new StorageTestWpdb();
		$wpdb->rows[] = $this->stored_row();
		$this->install_fixture();
		$storage             = $this->storage();
		$snapshot            = $storage->recovery_record( 'example/example.php' );
		$wpdb->delete_result = 0;
		$zero_delete_result  = $storage->recover_unlink( 'example/example.php', $snapshot['adoption_token'], $snapshot['digest'], static function (): void {} );
		self::assertSame( PackageMutationStatus::FAILED, $zero_delete_result->get_status() );
		self::assertFalse( $zero_delete_result->is_recovery_required() );
		self::assertCount( 1, $wpdb->rows );
		self::assertSame( 'ROLLBACK', end( $wpdb->queries ) );
	}

	public function test_missing_package_read_preserves_the_management_row(): void {
		global $wpdb;

		$storage      = $this->storage( false );
		$wpdb->rows[] = array(
			'id'           => 12,
			'package'      => 'missing/example.php',
			'package_type' => 1,
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

	public function test_confirmed_github_management_clears_a_true_hide_request_on_every_admission_path(): void {
		global $wpdb;

		foreach ( array( 'store', 'adopt', 'release_adopt' ) as $path ) {
			$wpdb->rows = array();
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- The wpdb double stores the byte-for-byte option document used by this persistence boundary.
			$wpdb->general_settings_value = serialize(
				array(
					'hide_github'  => true,
					'history_days' => 11,
				)
			);
			$package                      = $this->package();
			$result                       = match ( $path ) {
				'store' => $this->storage()->store_for_test( $package ),
				'adopt' => $this->storage()->adopt_for_test( $package ),
				'release_adopt' => $this->storage()->adopt_release_for_test(
					$this->release_package(),
					new ManagedReleaseConfiguration( 'example', 'example.php' )
				),
			};

			self::assertTrue( $result->is_successful(), $path );
			self::assertSame(
				array(
					'hide_github'  => false,
					'history_days' => 11,
				),
				// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- The assertion decodes only this fixture-owned option document.
				unserialize( (string) $wpdb->general_settings_value ),
				$path
			);
		}
	}

	public function test_confirmed_github_management_preserves_absent_and_false_preferences_without_seeding(): void {
		global $wpdb;

		foreach ( array(
			null,
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- The fixture explicitly covers an existing serialized option document.
			serialize(
				array(
					'hide_github'  => false,
					'history_days' => 11,
				)
			),
		) as $saved ) {
			$wpdb->rows                   = array();
			$wpdb->general_settings_value = $saved;
			$result                       = $this->storage()->adopt_for_test( $this->package() );

			self::assertTrue( $result->is_successful() );
			self::assertSame( $saved, $wpdb->general_settings_value );
		}
	}

	public function test_failed_hide_preference_clear_rolls_back_the_confirmed_github_management_record(): void {
		global $wpdb;

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- The wpdb double needs a serialized precondition for the rollback assertion.
		$wpdb->general_settings_value      = serialize( array( 'hide_github' => true ) );
		$wpdb->fail_general_settings_write = true;
		try {
			$this->storage()->adopt_for_test( $this->package() );
			self::fail( 'A failed visibility clear must reject the management write.' );
		} catch ( RuntimeException $failure ) {
			self::assertSame( 'GitHub visibility preference could not be cleared.', $failure->getMessage() );
		}
		self::assertSame( array(), $wpdb->rows );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Assert rollback preserves the precise serialized fixture document.
		self::assertSame( serialize( array( 'hide_github' => true ) ), $wpdb->general_settings_value );
	}

	private function storage( bool $package_exists = true, ?Database $database = null ): PersistenceFailureTestRepository {
		return new PersistenceFailureTestRepository( $package_exists, $database );
	}

	private function package( string $identifier = 'example/example.php' ): AbstractPackage {
		$package    = new class( $identifier ) extends AbstractPackage {

			public function __construct( private readonly string $identifier = '' ) {
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

	private function release_package(): AbstractPackage {
		$package = $this->package();
		$package->set_source( PackageSource::RELEASE_ASSET, 1 );
		$package->set_subdirectory( null );
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
			'expected_adoption_token'  => 'gh:repository-id:12345678-1234-4234-8234-123456789abc',
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
			'package_type'           => 1,
			'provider'               => 'gh',
			'provider_repository_id' => 'repository-id',
			'adoption_token'         => 'gh:repository-id:12345678-1234-4234-8234-123456789abc',
			'private'                => 0,
			'deployment_policy'      => DeploymentPolicy::MANUAL->value,
			'subdirectory'           => '',
			'credential_id'          => '',
			'source'                 => 'branch',
			'source_revision'        => 1,
		);
	}
}

/** @extends AbstractPackageRepository<Package> */
// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- This concrete test collaborator stays beside the only tests that exercise it.
final class PersistenceFailureTestRepository extends AbstractPackageRepository {

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

	public function adopt_release_for_test( Package $package, ManagedReleaseConfiguration $configuration ): PackageMutationResult {
		return $this->adopt_release_package( $package, $configuration, 1 );
	}

	/** @param array<string, mixed> $input */
	public function edit_for_test( string $identifier, array $input ): PackageMutationResult {
		return $this->edit_package( $identifier, $input );
	}

	protected function package_type(): int {
		return 1;
	}

	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundInExtendedClass -- The fixture implementation of package_exists retains the production method contract; these inputs do not affect this controlled result.
	protected function package_exists( string $identifier ): bool {
		return $this->exists;
	}

	protected function package_from_installation( string $identifier ): Package {
		return new class( $identifier ) extends AbstractPackage {

			public function __construct( private readonly string $identifier = '' ) {
			}

			public function get_identifier(): mixed {
				return $this->identifier;
			}
		};
	}

	protected function not_found_exception(): Throwable {
		return new RuntimeException( 'Package not found.' );
	}
}
