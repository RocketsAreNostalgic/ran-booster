<?php

declare(strict_types=1);

namespace RAN\Tests\Storage;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use RAN\Storage\Database;
use RAN\RepositoryProvider\GitHubReplacementDeclaration;
use RAN\Storage\DatabaseCompatibilityFailure;
use RAN\Storage\DatabaseLifecycleFailure;
use RuntimeException;
use RAN\Tests\RANBoosterTestCase;

require_once __DIR__ . '/StorageTestEnvironment.php';

#[CoversClass( Database::class )]
#[CoversClass( DatabaseLifecycleFailure::class )]
final class DatabaseSchemaMigrationTest extends RANBoosterTestCase {

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this exact lifecycle override name.
	protected function setUp(): void {
		global $ran_booster_storage_test_option_apply_write,
			$ran_booster_storage_test_option_write_result,
			$ran_booster_storage_test_options,
			$wpdb;

		$ran_booster_storage_test_options                 = array();
		$ran_booster_storage_test_option_apply_write      = true;
		$ran_booster_storage_test_option_write_result     = true;
		$GLOBALS['ran_booster_storage_test_schema_unset'] = true;
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Focused WordPress database test double.
		$wpdb = new StorageTestWpdb();
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this exact lifecycle override name.
	protected function tearDown(): void {
		unset( $GLOBALS['ran_booster_storage_test_schema_unset'] );
	}

	public function test_partial_capability_connection_keeps_current_schema_readiness_but_cannot_install(): void {
		global $ran_booster_storage_test_options;
		$ran_booster_storage_test_options[ Database::VERSION_OPTION ] = Database::$booster_db_version;
		$connection = new class() {
			public int $schema_reads = 0;

			public function db_server_info(): string {
				return '8.4.6';
			}

			/** @param list<mixed> $arguments */
			public function __call( string $name, array $arguments ): mixed {
				if ( 'get_results' === $name && array( 'SHOW ENGINES' ) === $arguments ) {
					return array(
						(object) array(
							'Engine'  => 'InnoDB',
							'Support' => 'DEFAULT',
						),
					);
				}
				++$this->schema_reads;
				throw new RuntimeException( 'schema-method-canary' );
			}
		};
		$database   = new Database( $connection );
		$database->require_ready();
		self::assertTrue( $database->is_ready() );
		try {
			$database->install();
			self::fail( 'Schema installation requires a declared schema connection.' );
		} catch ( DatabaseLifecycleFailure $failure ) {
			self::assertSame( 'schema_operation_failed', $failure->reason() );
			self::assertStringNotContainsString( 'canary', $failure->getMessage() );
		}
		self::assertSame( 0, $connection->schema_reads );
		self::assertSame( Database::$booster_db_version, $ran_booster_storage_test_options[ Database::VERSION_OPTION ] );
	}

	public function test_schema_admission_does_not_replace_existing_version_rejection(): void {
		global $ran_booster_storage_test_options;
		$ran_booster_storage_test_options[ Database::VERSION_OPTION ] = '12.0';
		$connection = new \RAN\Tests\Support\CredentialUsageDatabase();
		try {
			( new Database( $connection ) )->install();
			self::fail( 'The stored unsupported version must be rejected first.' );
		} catch ( DatabaseLifecycleFailure $failure ) {
			self::assertSame( 'unsupported_old_schema', $failure->reason() );
		}
		self::assertSame( array(), $connection->prepared );
	}

	public function test_missing_engine_query_stays_a_cached_safe_capability_failure(): void {
		$connection = new class() {
			public int $identity_reads = 0;

			public function db_server_info(): string {
				++$this->identity_reads;
				return '8.4.6';
			}
		};
		$database   = new Database( $connection );
		for ( $attempt = 0; $attempt < 2; ++$attempt ) {
			try {
				$database->require_supported();
				self::fail( 'A missing mandatory engine read must fail closed.' );
			} catch ( DatabaseCompatibilityFailure $failure ) {
				self::assertSame( 'capability_probe_failed', $failure->reason() );
			}
		}
		self::assertSame( 1, $connection->identity_reads );
	}

	public function test_optional_error_suppression_failures_preserve_probe_failure_and_restore_scope(): void {
		foreach ( array( true, false ) as $fail_suppression ) {
			$connection = new class( $fail_suppression ) {
				public string $last_error = 'preserved-error';
				/** @var list<bool> */
				public array $suppression_calls = array();

				public function __construct( private bool $fail_suppression ) {
				}

				public function suppress_errors( bool $suppress ): bool {
					$this->suppression_calls[] = $suppress;
					if ( $this->fail_suppression || ! $suppress ) {
						throw new RuntimeException( 'suppression-canary' );
					}
					return false;
				}

				public function db_server_info(): never {
					throw new RuntimeException( 'identity-canary' );
				}
			};
			try {
				( new Database( $connection ) )->require_supported();
				self::fail( 'The failed probe must remain a safe capability failure.' );
			} catch ( DatabaseCompatibilityFailure $failure ) {
				self::assertSame( 'capability_probe_failed', $failure->reason() );
				self::assertStringNotContainsString( 'canary', $failure->getMessage() );
			}
			self::assertSame( 'preserved-error', $connection->last_error );
			self::assertSame( $fail_suppression ? array( true ) : array( true, false ), $connection->suppression_calls );
		}
	}

	public function test_fresh_install_creates_and_verifies_only_the_current_tables(): void {
		global $ran_booster_storage_test_options, $wpdb;

		( new Database( bundled_declaration: $this->declaration() ) )->install();

		self::assertCount( 2, $wpdb->schemas );
		self::assertArrayHasKey( 'wp_ran_booster_packages', $wpdb->schema_tables );
		self::assertArrayHasKey( 'wp_ran_booster_deployment_attempts', $wpdb->schema_tables );
		self::assertArrayNotHasKey( 'wp_ran_booster_rejected_admission_audit', $wpdb->schema_tables );

		$package_schema = $wpdb->schemas[0];
		self::assertStringContainsString( 'deployment_policy varchar(10) NOT NULL', $package_schema );
		self::assertStringContainsString( "source varchar(16) NOT NULL DEFAULT 'branch'", $package_schema );
		self::assertStringContainsString( "source_revision bigint(20) unsigned NOT NULL DEFAULT '1'", $package_schema );
		self::assertStringContainsString( 'source_previous varchar(16) DEFAULT NULL', $package_schema );
		self::assertStringContainsString( 'source_changed_at datetime DEFAULT NULL', $package_schema );
		self::assertStringContainsString( 'source_changed_by bigint(20) unsigned DEFAULT NULL', $package_schema );
		self::assertStringContainsString( 'release_configuration text DEFAULT NULL', $package_schema );
		self::assertStringNotContainsString( 'release_candidate', $package_schema );
		self::assertStringNotContainsString( 'release_discovery_state', $package_schema );
		self::assertStringNotContainsString( 'release_cooldown_until', $package_schema );
		self::assertStringNotContainsString( 'last_deployed_release', $package_schema );
		self::assertStringNotContainsString( 'release_discovery', $package_schema );
		self::assertStringNotContainsString( 'status tinyint', $package_schema );
		self::assertStringNotContainsString( 'ptd tinyint', $package_schema );
		self::assertCount( 18, $wpdb->schema_tables['wp_ran_booster_packages']['columns'] );
		self::assertCount( 3, $wpdb->schema_tables['wp_ran_booster_packages']['indexes'] );

		$attempt_schema = $wpdb->schemas[1];
		self::assertStringContainsString( 'package_slug varchar(191) NOT NULL', $attempt_schema );
		self::assertStringContainsString( "package_source varchar(16) NOT NULL DEFAULT 'branch'", $attempt_schema );
		self::assertStringContainsString( "package_source_revision bigint(20) unsigned NOT NULL DEFAULT '0'", $attempt_schema );
		self::assertStringNotContainsString( 'release_identity', $attempt_schema );
		self::assertStringContainsString( 'request_json text NOT NULL', $attempt_schema );
		self::assertStringContainsString( 'resolved_at datetime DEFAULT NULL', $attempt_schema );
		self::assertStringContainsString( 'resolved_by bigint(20) unsigned DEFAULT NULL', $attempt_schema );
		self::assertStringContainsString( 'UNIQUE KEY webhook_target (provider, delivery_id, package_type, package_slug)', $attempt_schema );
		self::assertCount( 22, $wpdb->schema_tables['wp_ran_booster_deployment_attempts']['columns'] );
		self::assertCount( 5, $wpdb->schema_tables['wp_ran_booster_deployment_attempts']['indexes'] );
		self::assertStringContainsString( 'ENGINE=InnoDB', $attempt_schema );

		self::assertSame( '15.0', $ran_booster_storage_test_options[ Database::VERSION_OPTION ] );
	}

	/** @return list<array{string, bool}> */
	public static function server_support_provider(): array {
		return array(
			array( '8.0.0', true ),
			array( '8.4.6', true ),
			array( '5.7.44', false ),
			array( '10.11.0-MariaDB', true ),
			array( '5.5.5-10.11.13-MariaDB-0ubuntu0.24.04.1', true ),
			array( '10.10.8-MariaDB', false ),
			array( 'PostgreSQL 17.5', false ),
			array( '3.49.1 SQLite', false ),
			array( '8.0.11-TiDB-v8.5.2', false ),
			array( '', false ),
		);
	}

	#[DataProvider( 'server_support_provider' )]
	public function test_capability_preflight_classifies_only_the_supported_server_formats( string $server_info, bool $supported ): void {
		global $wpdb;

		$wpdb->server_info = $server_info;
		$database          = new Database( $wpdb );

		self::assertSame( $supported, $database->is_supported() );
		self::assertSame( $supported ? 1 : 0, $wpdb->capability_reads );
	}

	public function test_capability_preflight_is_request_cached(): void {
		global $wpdb;

		$database = new Database( $wpdb );

		$database->require_supported();
		$database->require_supported();

		self::assertSame( 1, $wpdb->capability_reads );
	}

	/** @return array<string, array{bool, string}> */
	public static function capability_probe_failure_provider(): array {
		return array(
			'server identity probe' => array( true, 'server-info-canary' ),
			'storage engine probe'  => array( false, 'engine-probe-canary' ),
		);
	}

	#[DataProvider( 'capability_probe_failure_provider' )]
	public function test_capability_probe_failures_become_cached_safe_states_and_restore_wpdb_errors(
		bool $fail_server_identity,
		string $canary
	): void {
		$connection = new DatabaseCapabilityProbeFailureConnection( $fail_server_identity );
		$database   = new Database( $connection );

		try {
			$database->require_supported();
			self::fail( 'Expected the failed capability probe to enter the safe state.' );
		} catch ( DatabaseCompatibilityFailure $failure ) {
			self::assertSame( 'capability_probe_failed', $failure->reason() );
			self::assertStringNotContainsString( $canary, $failure->getMessage() );
		}

		self::assertSame( 'preserved-error', $connection->last_error );
		self::assertFalse( $connection->errors_suppressed );
		self::assertFalse( $database->is_supported() );
	}

	public function test_unavailable_inno_db_fails_before_schema_or_version_mutation(): void {
		global $ran_booster_storage_test_options, $wpdb;

		$ran_booster_storage_test_options[ Database::VERSION_OPTION ] = '10.0';
		$wpdb->innodb_support = 'NO';
		$wpdb->rows[]         = array(
			'id'      => 1,
			'package' => 'preserved/plugin.php',
		);

		try {
			( new Database( $wpdb ) )->maybe_upgrade();
			self::fail( 'Expected unavailable InnoDB to fail closed.' );
		} catch ( DatabaseCompatibilityFailure $failure ) {
			self::assertSame( 'innodb_unavailable', $failure->reason() );
		}

		self::assertSame(
			array(
				array(
					'id'      => 1,
					'package' => 'preserved/plugin.php',
				),
			),
			$wpdb->rows
		);
		self::assertSame( array(), $wpdb->schemas );
		self::assertSame( array(), $wpdb->queries );
		self::assertSame( '10.0', $ran_booster_storage_test_options[ Database::VERSION_OPTION ] );
	}

	public function test_current_version_recreates_only_amissing_table_without_deleting_history(): void {
		global $ran_booster_storage_test_options, $wpdb;

		( new Database( bundled_declaration: $this->declaration() ) )->install();
		$attempt_schema = $wpdb->schema_tables['wp_ran_booster_deployment_attempts'];
		unset( $wpdb->schema_tables['wp_ran_booster_packages'] );
		$ran_booster_storage_test_options[ Database::VERSION_OPTION ] = '15.0';
		$wpdb->schemas = array();
		$wpdb->queries = array();

		( new Database( bundled_declaration: $this->declaration() ) )->install();

		self::assertCount( 1, $wpdb->schemas );
		self::assertSame( $attempt_schema, $wpdb->schema_tables['wp_ran_booster_deployment_attempts'] );
		self::assertSame( array(), $wpdb->queries );
	}

	public function test_current_version_maybe_upgrade_keeps_the_cheap_fast_path(): void {
		global $ran_booster_storage_test_options, $wpdb;

		( new Database( bundled_declaration: $this->declaration() ) )->install();
		$wpdb->schemas = array();
		$ran_booster_storage_test_options[ Database::VERSION_OPTION ] = '15.0';
		$wpdb->successful_reads_before_failure                        = 0;
		$database = new Database( bundled_declaration: $this->declaration() );

		$database->maybe_upgrade();

		self::assertTrue( $database->is_ready() );
		self::assertSame( array(), $wpdb->schemas );

		$wpdb->successful_reads_before_failure = null;
		$database->install();
		self::assertCount( 0, $wpdb->schemas, 'Explicit installation must verify a current schema without unnecessary DDL.' );
	}

	public function test_current_schema_leaves_retired_prerelease_audit_table_untouched(): void {
		global $ran_booster_storage_test_options, $wpdb;

		( new Database( bundled_declaration: $this->declaration() ) )->install();
		$wpdb->schema_tables['wp_ran_booster_rejected_admission_audit'] = $wpdb->schema_tables['wp_ran_booster_deployment_attempts'];
		$wpdb->schemas = array();
		$wpdb->queries = array();

		( new Database( bundled_declaration: $this->declaration() ) )->install();

		self::assertSame( '15.0', $ran_booster_storage_test_options[ Database::VERSION_OPTION ] );
		self::assertArrayHasKey( 'wp_ran_booster_rejected_admission_audit', $wpdb->schema_tables );
		self::assertSame( array(), $wpdb->schemas );
		self::assertSame( array(), $wpdb->queries );
	}

	/** @return array<string, array{string, string, string}> */
	public static function unsafe_missing_schema_provider(): array {
		return array(
			'package required column'  => array( 'wp_ran_booster_packages', 'columns', 'package' ),
			'package phase-two column' => array( 'wp_ran_booster_packages', 'columns', 'release_configuration' ),
			'attempt required column'  => array( 'wp_ran_booster_deployment_attempts', 'columns', 'request_json' ),
			'attempt phase-two column' => array( 'wp_ran_booster_deployment_attempts', 'columns', 'package_source' ),
			'package primary key'      => array( 'wp_ran_booster_packages', 'indexes', 'PRIMARY' ),
			'attempt primary key'      => array( 'wp_ran_booster_deployment_attempts', 'indexes', 'PRIMARY' ),
			'package unique index'     => array( 'wp_ran_booster_packages', 'indexes', 'package_type' ),
			'package provider index'   => array( 'wp_ran_booster_packages', 'indexes', 'provider_identity' ),
			'attempt unique index'     => array( 'wp_ran_booster_deployment_attempts', 'indexes', 'webhook_target' ),
			'attempt phase-two index'  => array( 'wp_ran_booster_deployment_attempts', 'indexes', 'queue' ),
		);
	}

	#[DataProvider( 'unsafe_missing_schema_provider' )]
	public function test_current_schema_rejects_any_missing_contract_before_ddl(
		string $table,
		string $section,
		string $name
	): void {
		global $ran_booster_storage_test_options, $wpdb;

		$this->install_current_schema();
		unset( $wpdb->schema_tables[ $table ][ $section ][ $name ] );
		if ( 'columns' === $section ) {
			unset( $wpdb->schema_tables[ $table ]['columnMetadata'][ $name ] );
		}

		$this->assert_incompatible_schema_fails_before_ddl( $wpdb );
	}

	/** @return array<string, array{mixed, string}> */
	public static function rejected_version_provider(): array {
		return array(
			'schema 13 retired'  => array( '13.0', 'unsupported_old_schema' ),
			'schema 14 retired'  => array( '14.0', 'unsupported_old_schema' ),
			'malformed'          => array( 'not-a-version', 'malformed_schema_version' ),
			'pre-preservation'   => array( '4.0', 'unsupported_old_schema' ),
			'previous contract'  => array( '5.0', 'unsupported_old_schema' ),
			'older hard cut'     => array( '6.5', 'unsupported_old_schema' ),
			'previous hard cut'  => array( '7.0', 'unsupported_old_schema' ),
			'untagged schema 8'  => array( '8.0', 'unsupported_old_schema' ),
			'untagged schema 9'  => array( '9.0', 'unsupported_old_schema' ),
			'beta schema 10'     => array( '10.0', 'unsupported_old_schema' ),
			'beta schema 11'     => array( '11.0', 'unsupported_old_schema' ),
			'beta schema 12'     => array( '12.0', 'unsupported_old_schema' ),
			'unknown transition' => array( '10.5', 'unknown_schema_version' ),
			'newer'              => array( '16.0', 'newer_schema' ),
			'wrong type'         => array( 5, 'malformed_schema_version' ),
		);
	}

	#[DataProvider( 'rejected_version_provider' )]
	public function test_rejected_version_fails_before_ddl_or_data_mutation( mixed $version, string $reason ): void {
		global $ran_booster_storage_test_options, $wpdb;

		$ran_booster_storage_test_options[ Database::VERSION_OPTION ] = $version;
		$wpdb->rows[] = array(
			'id'      => 1,
			'package' => 'preserved/plugin.php',
		);

		try {
			( new Database( bundled_declaration: $this->declaration() ) )->maybe_upgrade();
			self::fail( 'Expected an unsupported schema version to fail closed.' );
		} catch ( DatabaseLifecycleFailure $failure ) {
			self::assertSame( $reason, $failure->reason() );
			self::assertSame( DatabaseLifecycleFailure::REQUIREMENT, $failure->getMessage() );
		}

		self::assertSame( array(), $wpdb->schemas );
		self::assertSame( array(), $wpdb->queries );
		self::assertSame(
			array(
				array(
					'id'      => 1,
					'package' => 'preserved/plugin.php',
				),
			),
			$wpdb->rows
		);
		self::assertSame( $version, $ran_booster_storage_test_options[ Database::VERSION_OPTION ] );
	}

	public function test_wrong_engine_fails_before_recording_version(): void {
		global $ran_booster_storage_test_options, $wpdb;

		$this->install_current_schema();
		$wpdb->schema_tables['wp_ran_booster_deployment_attempts']['engine'] = 'MyISAM';

		try {
			( new Database( bundled_declaration: $this->declaration() ) )->install();
			self::fail( 'Expected a non-InnoDB table to fail closed.' );
		} catch ( DatabaseLifecycleFailure $failure ) {
			self::assertSame( 'wrong_storage_engine', $failure->reason() );
		}

		self::assertSame( '15.0', $ran_booster_storage_test_options[ Database::VERSION_OPTION ] );
	}

	public function test_changed_attempt_column_type_is_incompatible_and_preserved(): void {
		global $ran_booster_storage_test_options, $wpdb;

		$this->install_current_schema();
		$wpdb->schema_tables['wp_ran_booster_deployment_attempts']['columns']['request_json'] = 'longtext';

		$this->assert_incompatible_schema_fails_before_ddl( $wpdb );
		self::assertSame( 'longtext', $wpdb->schema_tables['wp_ran_booster_deployment_attempts']['columns']['request_json'] );
	}

	/** @return array<string, array{string, string, string, bool|string}> */
	public static function incompatible_column_metadata_provider(): array {
		return array(
			'nullability'    => array( 'wp_ran_booster_deployment_attempts', 'request_json', 'nullable', true ),
			'default value'  => array( 'wp_ran_booster_packages', 'branch', 'default', 'trunk' ),
			'auto increment' => array( 'wp_ran_booster_packages', 'id', 'extra', '' ),
		);
	}

	#[DataProvider( 'incompatible_column_metadata_provider' )]
	public function test_current_schema_rejects_incompatible_column_metadata(
		string $table,
		string $column,
		string $attribute,
		bool|string $value
	): void {
		global $ran_booster_storage_test_options, $wpdb;

		$this->install_current_schema();

		$wpdb->schema_tables[ $table ]['columnMetadata'][ $column ][ $attribute ] = $value;

		$this->assert_incompatible_schema_fails_before_ddl( $wpdb );
	}

	public function test_current_schema_rejects_prefixed_index_before_ddl(): void {
		global $ran_booster_storage_test_options, $wpdb;

		$this->install_current_schema();

		$wpdb->schema_tables['wp_ran_booster_deployment_attempts']['indexes']['queue']['prefixes'][0] = 10;

		$this->assert_incompatible_schema_fails_before_ddl( $wpdb );
	}

	public function test_incompatible_attempt_table_fails_closed_without_ddl_or_deletion(): void {
		global $ran_booster_storage_test_options, $wpdb;

		$this->install_current_schema();
		$wpdb->rows[] = array(
			'id'             => 1,
			'correlation_id' => 'preserved',
		);
		$wpdb->schema_tables['wp_ran_booster_deployment_attempts']['indexes']['webhook_target']['unique'] = false;
		$wpdb->queries = array();
		$wpdb->schemas = array();
		$database      = new Database( bundled_declaration: $this->declaration() );

		foreach ( array( 1, 2 ) as $_attempt ) {
			try {
				$database->install();
				self::fail( 'Expected incompatible attempt storage to fail closed.' );
			} catch ( DatabaseLifecycleFailure $failure ) {
				self::assertSame( 'incompatible_schema', $failure->reason() );
			}
		}

		self::assertSame( array(), $wpdb->schemas );
		self::assertSame( array(), $wpdb->queries );
		self::assertSame(
			array(
				array(
					'id'             => 1,
					'correlation_id' => 'preserved',
				),
			),
			$wpdb->rows
		);
		self::assertSame( '15.0', $ran_booster_storage_test_options[ Database::VERSION_OPTION ] );
		self::assertFalse( $database->is_ready() );
	}

	public function test_unreadable_attempt_table_fails_closed_without_ddl(): void {
		global $ran_booster_storage_test_options, $wpdb;

		$this->install_current_schema();
		$wpdb->schemas                         = array();
		$wpdb->successful_reads_before_failure = 2;

		try {
			( new Database( bundled_declaration: $this->declaration() ) )->install();
			self::fail( 'Expected unreadable attempt storage to fail closed.' );
		} catch ( DatabaseLifecycleFailure $failure ) {
			self::assertSame( 'schema_read_failed', $failure->reason() );
			self::assertStringNotContainsString( 'database details', $failure->getMessage() );
		}

		self::assertSame( array(), $wpdb->schemas );
		self::assertSame( '15.0', $ran_booster_storage_test_options[ Database::VERSION_OPTION ] );
	}

	public function test_version_write_failure_is_cached_and_retryable_with_anew_lifecycle(): void {
		global $ran_booster_storage_test_option_apply_write,
			$ran_booster_storage_test_option_write_result,
			$ran_booster_storage_test_options,
			$wpdb;

		( new Database( bundled_declaration: $this->declaration() ) )->install();
		unset( $ran_booster_storage_test_options[ Database::VERSION_OPTION ] );
		$ran_booster_storage_test_option_apply_write  = false;
		$ran_booster_storage_test_option_write_result = false;
		$database                                     = new Database( bundled_declaration: $this->declaration() );

		foreach ( array( 1, 2 ) as $_attempt ) {
			try {
				$database->install();
				self::fail( 'Expected the version write to fail.' );
			} catch ( DatabaseLifecycleFailure $failure ) {
				self::assertSame( 'version_write_failed', $failure->reason() );
			}
		}
		self::assertArrayNotHasKey( Database::VERSION_OPTION, $ran_booster_storage_test_options );

		$ran_booster_storage_test_option_apply_write  = true;
		$ran_booster_storage_test_option_write_result = true;
		( new Database( bundled_declaration: $this->declaration() ) )->install();

		self::assertSame( '15.0', $ran_booster_storage_test_options[ Database::VERSION_OPTION ] );
	}

	/** @return array<string, array{bool, string}> */
	public static function version_comparison_provider(): array {
		return array(
			'failed write readback'         => array( false, 'version_write_failed' ),
			'successful write verification' => array( true, 'version_verification_failed' ),
		);
	}

	#[DataProvider( 'version_comparison_provider' )]
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_version_comparison_reads_expected_version_before_option_filter( bool $write_result, string $reason ): void {
		global $ran_booster_storage_test_option_write_result, $ran_booster_storage_test_options;

		$expected_version                                = Database::$booster_db_version;
		$previous_options                                = $ran_booster_storage_test_options;
		$previous_write_result                           = $ran_booster_storage_test_option_write_result;
		$previous_read_filter                            = $GLOBALS['ran_booster_storage_test_option_read'] ?? null;
		$version_reads                                   = 0;
		$ran_booster_storage_test_option_write_result    = $write_result;
		$GLOBALS['ran_booster_storage_test_option_read'] = static function ( string $option ) use ( &$version_reads ): void {
			if ( Database::VERSION_OPTION !== $option || 2 !== ++$version_reads ) {
				return;
			}

			// Model an option filter changing both the expected version and returned option.
			Database::$booster_db_version                           = '999.0';
			$GLOBALS['ran_booster_storage_test_options'][ $option ] = '999.0';
		};

		try {
			try {
				( new Database( bundled_declaration: $this->declaration() ) )->install();
				self::fail( 'Expected comparison against the version read before the option filter.' );
			} catch ( DatabaseLifecycleFailure $failure ) {
				self::assertSame( $reason, $failure->reason() );
			}
			self::assertSame( 2, $version_reads );
			self::assertSame( '999.0', Database::$booster_db_version );
		} finally {
			Database::$booster_db_version                 = $expected_version;
			$ran_booster_storage_test_options             = $previous_options;
			$ran_booster_storage_test_option_write_result = $previous_write_result;
			if ( null === $previous_read_filter ) {
				unset( $GLOBALS['ran_booster_storage_test_option_read'] );
			} else {
				$GLOBALS['ran_booster_storage_test_option_read'] = $previous_read_filter;
			}
		}
	}

	public function test_version_verification_failure_does_not_claim_readiness(): void {
		global $ran_booster_storage_test_option_apply_write,
			$ran_booster_storage_test_options;

		$ran_booster_storage_test_option_apply_write = false;
		$database                                    = new Database( bundled_declaration: $this->declaration() );

		try {
			$database->install();
			self::fail( 'Expected the absent written version to fail verification.' );
		} catch ( DatabaseLifecycleFailure $failure ) {
			self::assertSame( 'version_verification_failed', $failure->reason() );
		}

		self::assertFalse( $database->is_ready() );
		self::assertArrayNotHasKey( Database::VERSION_OPTION, $ran_booster_storage_test_options );
	}

	public function test_post_delta_verification_failure_does_not_record_version(): void {
		global $ran_booster_storage_test_options, $wpdb;

		$wpdb->schema_engine = 'MyISAM';

		try {
			( new Database( bundled_declaration: $this->declaration() ) )->install();
			self::fail( 'Expected the created non-InnoDB tables to fail verification.' );
		} catch ( DatabaseLifecycleFailure $failure ) {
			self::assertSame( 'wrong_storage_engine', $failure->reason() );
		}

		self::assertArrayNotHasKey( Database::VERSION_OPTION, $ran_booster_storage_test_options );
	}

	public function test_readiness_inspection_is_passive_until_readiness_is_required(): void {
		global $ran_booster_storage_test_options, $wpdb;

		$database = new Database( bundled_declaration: $this->declaration() );

		self::assertFalse( $database->is_ready() );
		self::assertSame( array(), $wpdb->schemas );

		$database->install();
		self::assertTrue( $database->is_ready() );
		self::assertCount( 2, $wpdb->schemas );
	}

	public function test_word_press_options_engine_must_support_selection_before_version_advances(): void {
		global $ran_booster_storage_test_options, $wpdb;
		$wpdb->options_engine = 'MyISAM';
		try {
			( new Database( bundled_declaration: $this->declaration() ) )->install();
			self::fail( 'Selection authority requires transactional options storage.' );
		} catch ( DatabaseLifecycleFailure $failure ) {
			self::assertSame( 'wrong_storage_engine', $failure->reason() );
		}
		self::assertArrayNotHasKey( Database::VERSION_OPTION, $ran_booster_storage_test_options );
		self::assertNull( $wpdb->selection_value );
	}

	public function test_exact_predecessor_backfills_valid_identity_without_hydration_and_preserves_history(): void {
		global $wpdb, $ran_booster_storage_test_options;
		$this->install_current_schema();
		$table = ran_booster_table_name();
		$this->restore_predecessor_type_column();
		unset( $wpdb->schema_tables[ $table ]['columns']['adoption_token'], $wpdb->schema_tables[ $table ]['columnMetadata']['adoption_token'] );
		$ran_booster_storage_test_options[ Database::VERSION_OPTION ] = '13.0';
		$wpdb->rows = array(
			array(
				'id'                     => 1,
				'type'                   => 1,
				'package'                => 'example/example.php',
				'provider'               => 'gh',
				'provider_repository_id' => '001:opaque',
				'private'                => 'broken',
			),
			array(
				'id'                     => 2,
				'type'                   => 2,
				'package'                => 'theme',
				'provider'               => 'INVALID',
				'provider_repository_id' => '123',
			),
		);
		$this->historical_identity_upgrade();
		self::assertSame( '13.0', $ran_booster_storage_test_options[ Database::VERSION_OPTION ] );
		self::assertTrue( \RAN\AdoptionIdentity::matches( (string) $wpdb->stored_rows()[0]['adoption_token'], 'gh', '001:opaque' ) );
		self::assertNull( $wpdb->stored_rows()[1]['adoption_token'] );
		self::assertContains( 'ALTER TABLE `wp_ran_booster_packages` CHANGE COLUMN type package_type tinyint(3) unsigned NOT NULL, ADD adoption_token varchar(643) DEFAULT NULL', $wpdb->queries );
		self::assertSame( 1, $wpdb->stored_rows()[0]['package_type'] );
		self::assertSame( 2, $wpdb->stored_rows()[1]['package_type'] );
		self::assertSame( 'broken', $wpdb->stored_rows()[0]['private'] );
		self::assertStringNotContainsString( 'UPDATE `wp_ran_booster_deployment_attempts`', implode( "\n", $wpdb->queries ) );
	}

	public function test_prior_candidate_interrupted_ddl_renames_without_reminting(): void {
		global $wpdb, $ran_booster_storage_test_options;
		$this->install_current_schema();
		$this->restore_predecessor_type_column();
		$ran_booster_storage_test_options[ Database::VERSION_OPTION ] = '13.0';
		$token      = \RAN\AdoptionIdentity::create( 'gh', 'opaque' );
		$wpdb->rows = array(
			array(
				'id'                     => 1,
				'type'                   => 2,
				'provider'               => 'gh',
				'provider_repository_id' => 'opaque',
				'adoption_token'         => $token,
			),
		);
		$this->historical_identity_upgrade();
		self::assertSame( $token, $wpdb->stored_rows()[0]['adoption_token'] );
		self::assertSame( 2, $wpdb->stored_rows()[0]['package_type'] );
		self::assertArrayNotHasKey( 'type', $wpdb->stored_rows()[0] );
		self::assertContains( 'ALTER TABLE `wp_ran_booster_packages` CHANGE COLUMN type package_type tinyint(3) unsigned NOT NULL', $wpdb->queries );
		self::assertSame( array( 'package_type', 'package' ), $wpdb->schema_tables[ ran_booster_table_name() ]['indexes']['package_type']['columns'] );
	}

	public function test_impossible_renamed_predecessor_without_token_is_not_repaired(): void {
		global $wpdb, $ran_booster_storage_test_options;
		$this->install_current_schema();
		$table = ran_booster_table_name();
		unset( $wpdb->schema_tables[ $table ]['columns']['adoption_token'], $wpdb->schema_tables[ $table ]['columnMetadata']['adoption_token'] );
		$ran_booster_storage_test_options[ Database::VERSION_OPTION ] = '13.0';
		$queries = $wpdb->queries;
		try {
			$this->historical_identity_upgrade();
			self::fail( 'The atomic migration cannot produce a renamed column without its token column.' );
		} catch ( DatabaseLifecycleFailure $failure ) {
			self::assertSame( 'incompatible_schema', $failure->reason() );
		}
		self::assertSame( $queries, $wpdb->queries );
		self::assertSame( '13.0', $ran_booster_storage_test_options[ Database::VERSION_OPTION ] );
	}

	public function test_current_schema_with_old_column_fails_inspection_without_repair(): void {
		global $wpdb;
		$this->install_current_schema();
		$this->restore_predecessor_type_column();
		$queries = $wpdb->queries;
		$this->assert_incompatible_schema_fails_before_ddl( $wpdb );
		self::assertSame( $queries, $wpdb->queries );
	}

	public function test_historical_identity_retry_and_current_install_preserve_already_assigned_identity(): void {
		global $wpdb, $ran_booster_storage_test_options, $ran_booster_storage_test_option_apply_write, $ran_booster_storage_test_option_write_result;
		$this->install_current_schema();
		$ran_booster_storage_test_options[ Database::VERSION_OPTION ] = '13.0';
		$wpdb->rows = array(
			array(
				'id'                     => 1,
				'provider'               => 'bb',
				'provider_repository_id' => '{opaque}',
				'adoption_token'         => null,
			),
		);
		$this->historical_identity_upgrade();
		$token = $wpdb->stored_rows()[0]['adoption_token'];
		self::assertIsString( $token );
		$ran_booster_storage_test_option_apply_write  = true;
		$ran_booster_storage_test_option_write_result = true;
		$this->historical_identity_upgrade();
		self::assertSame( $token, $wpdb->stored_rows()[0]['adoption_token'] );
		$wpdb->rows[0]['adoption_token']                              = null;
		$ran_booster_storage_test_options[ Database::VERSION_OPTION ] = Database::$booster_db_version;
		( new Database() )->install();
		self::assertNull( $wpdb->stored_rows()[0]['adoption_token'], 'Current schemas never silently remint a missing token.' );
	}

	public function test_identity_backfill_failure_rolls_back_and_keeps_previous_marker(): void {
		global $wpdb, $ran_booster_storage_test_options;
		$this->install_current_schema();
		$ran_booster_storage_test_options[ Database::VERSION_OPTION ] = '13.0';
		$wpdb->rows                   = array(
			array(
				'id'                     => 1,
				'provider'               => 'gh',
				'provider_repository_id' => '123',
				'adoption_token'         => null,
			),
		);
		$wpdb->query_failure_contains = 'COMMIT';
		try {
			$this->historical_identity_upgrade();
			self::fail( 'Failed identity commit must block readiness.' );
		} catch ( DatabaseLifecycleFailure $failure ) {
			self::assertSame( 'identity_commit_failed', $failure->reason() );
		}
		self::assertNull( $wpdb->stored_rows()[0]['adoption_token'] );
		self::assertSame( '13.0', $ran_booster_storage_test_options[ Database::VERSION_OPTION ] );
	}

	public function test_recovery_readiness_never_migrates_or_repairs_tables(): void {
		global $wpdb, $ran_booster_storage_test_options;
		$this->install_current_schema();
		$ran_booster_storage_test_options[ Database::VERSION_OPTION ] = '13.0';
		$queries = $wpdb->queries;
		try {
			( new Database( bundled_declaration: $this->declaration() ) )->require_recovery_ready();
			self::fail( 'Recovery must not migrate an old database.' );
		} catch ( DatabaseLifecycleFailure $failure ) {
			self::assertSame( 'unsupported_old_schema', $failure->reason() );
		}
		self::assertSame( $queries, $wpdb->queries );
		self::assertSame( '13.0', $ran_booster_storage_test_options[ Database::VERSION_OPTION ] );
		$ran_booster_storage_test_options[ Database::VERSION_OPTION ] = Database::$booster_db_version;
		unset( $wpdb->schema_tables[ Database::attempt_table_name() ] );
		try {
			( new Database( bundled_declaration: $this->declaration() ) )->require_recovery_ready();
			self::fail( 'Recovery must not recreate a missing attempt table.' );
		} catch ( DatabaseLifecycleFailure ) {
			self::assertArrayNotHasKey( Database::attempt_table_name(), $wpdb->schema_tables );
		}
		self::assertSame( $queries, $wpdb->queries );
	}

	public function test_fresh_selection_is_verified_before_version_and_retry_preserves_existing_choice(): void {
		global $wpdb, $ran_booster_storage_test_options;
		$this->install_current_schema();
		$state = json_decode( (string) $wpdb->selection_value, true, 16, JSON_THROW_ON_ERROR );
		self::assertSame( 'bundled', $state['selected'] );
		self::assertSame( 'bundled-test', $state['declaration']['implementation_id'] );
		self::assertSame( Database::$booster_db_version, $ran_booster_storage_test_options[ Database::VERSION_OPTION ] );
		$state['selected']             = 'replacement';
		$state['selection_generation'] = 7;
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Canonical stored authority fixture.
		$wpdb->selection_value = json_encode( $state, JSON_THROW_ON_ERROR );
		$selected              = $wpdb->selection_value;
		unset( $ran_booster_storage_test_options[ Database::VERSION_OPTION ] );
		$this->install_current_schema();
		self::assertSame( $selected, $wpdb->selection_value );
	}

	public function test_selection_write_failure_never_advances_version(): void {
		global $wpdb, $ran_booster_storage_test_options;
		$wpdb->query_failure_contains = 'INSERT IGNORE';
		try {
			$this->install_current_schema();
			self::fail();
		} catch ( DatabaseLifecycleFailure $failure ) {
			self::assertSame( 'selection_write_failed', $failure->reason() );
		}
		self::assertArrayNotHasKey( Database::VERSION_OPTION, $ran_booster_storage_test_options );
		self::assertNull( $wpdb->selection_value );
	}

	public function test_corrupt_selection_readback_never_advances_version(): void {
		global $wpdb, $ran_booster_storage_test_options;
		$wpdb->selection_value = '{}';
		try {
			$this->install_current_schema();
			self::fail();
		} catch ( DatabaseLifecycleFailure $failure ) {
			self::assertSame( 'selection_invalid', $failure->reason() );
		}
		self::assertSame( '{}', $wpdb->selection_value );
		self::assertArrayNotHasKey( Database::VERSION_OPTION, $ran_booster_storage_test_options );
	}

	public function test_current_missing_or_corrupt_selection_is_not_repaired_or_global_runtime_block(): void {
		global $wpdb;
		$this->install_current_schema();
		foreach ( array( null, '{}' ) as $invalid ) {
			$wpdb->selection_value = $invalid;
			$queries               = $wpdb->queries;
			$runtime               = new Database();
			$runtime->require_ready();
			self::assertTrue( $runtime->is_ready() );
			try {
				$this->install_current_schema();
				self::fail();
			} catch ( DatabaseLifecycleFailure $failure ) {
				self::assertSame( null === $invalid ? 'selection_read_failed' : 'selection_invalid', $failure->reason() );
			}
			self::assertSame( $invalid, $wpdb->selection_value );
			self::assertSame( $queries, $wpdb->queries );
		}
	}

	public function test_absent_version_requires_explicit_install_and_host_declaration(): void {
		global $wpdb, $ran_booster_storage_test_options;
		foreach ( array( false, true ) as $explicit ) {
			try {
				$database = new Database();
				if ( $explicit ) {
					$database->install();
				} else {
					$database->require_ready();
				}
				self::fail();
			} catch ( DatabaseLifecycleFailure $failure ) {
				self::assertSame( 'selection_install_required', $failure->reason() );
			}
		}
		self::assertSame( array(), $wpdb->queries );
		self::assertSame( array(), $wpdb->schemas );
		self::assertArrayNotHasKey( Database::VERSION_OPTION, $ran_booster_storage_test_options );
	}

	/** Historical schema-13 evidence remains test-only; public lifecycle rejects this version. */
	private function historical_identity_upgrade(): void {
		( new HistoricalIdentityMigration( new Database() ) )->migrate_adoption_identities( ran_booster_table_name(), Database::attempt_table_name() );
	}

	private function declaration(): GitHubReplacementDeclaration {
		return new GitHubReplacementDeclaration(
			array(
				'plugin_basename'        => 'booster/main.php',
				'implementation_id'      => 'bundled-test',
				'compatibility_revision' => '1',
				'provider_api_version'   => 15,
				'api_credential_formats' => array( 'github-classic-pat-v1' ),
				'webhook_signing_format' => null,
				'facets'                 => array(),
			)
		);
	}

	private function restore_predecessor_type_column(): void {
		global $wpdb;
		$table                           = &$wpdb->schema_tables[ ran_booster_table_name() ];
		$table['columns']['type']        = $table['columns']['package_type'];
		$table['columnMetadata']['type'] = $table['columnMetadata']['package_type'];
		unset( $table['columns']['package_type'], $table['columnMetadata']['package_type'] );
		$table['indexes']['package_type']['columns'] = array( 'type', 'package' );
	}

	private function install_current_schema(): void {
		( new Database( bundled_declaration: $this->declaration() ) )->install();
	}

	private function assert_incompatible_schema_fails_before_ddl( StorageTestWpdb $wpdb ): void {
		global $ran_booster_storage_test_options;

		$wpdb->schemas = array();
		try {
			( new Database( bundled_declaration: $this->declaration() ) )->install();
			self::fail( 'Expected an incompatible schema to fail closed.' );
		} catch ( DatabaseLifecycleFailure $failure ) {
			self::assertSame( 'incompatible_schema', $failure->reason() );
		}

		self::assertSame( array(), $wpdb->schemas );
		self::assertSame( '15.0', $ran_booster_storage_test_options[ Database::VERSION_OPTION ] );
	}
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- This concrete test collaborator stays beside the only tests that exercise it.
final class DatabaseCapabilityProbeFailureConnection {
	public string $last_error      = 'preserved-error';
	public bool $errors_suppressed = false;

	public function __construct( private bool $fail_server_identity ) {
	}

	public function suppress_errors( bool $suppress ): bool {
		$previous                = $this->errors_suppressed;
		$this->errors_suppressed = $suppress;

		return $previous;
	}

	public function db_server_info(): string {
		if ( $this->fail_server_identity ) {
			throw new RuntimeException( 'server-info-canary' );
		}

		return '8.4.6';
	}

	/** @return never */
	public function get_results( string $query ): array {
		// The probe must contain and replace this test-only low-level detail.
		throw new RuntimeException( $query . ' engine-probe-canary' );
	}
}
