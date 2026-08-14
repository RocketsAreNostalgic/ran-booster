<?php

declare(strict_types=1);

namespace Tests\Storage;

// phpcs:disable Generic.Files.OneObjectStructurePerFile -- Focused capability-probe fake stays beside its tests.

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use RAN\Storage\Database;
use RAN\Storage\DatabaseCompatibilityFailure;
use RAN\Storage\DatabaseLifecycleFailure;
use RuntimeException;
use Tests\RANBoosterTestCase;

require_once __DIR__ . '/StorageTestEnvironment.php';

#[CoversClass( Database::class )]
#[CoversClass( DatabaseLifecycleFailure::class )]
final class DatabaseSchemaMigrationTest extends RANBoosterTestCase {

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

	protected function tearDown(): void {
		unset( $GLOBALS['ran_booster_storage_test_schema_unset'] );
	}

	public function testFreshInstallCreatesAndVerifiesOnlyTheCurrentTables(): void {
		global $ran_booster_storage_test_options, $wpdb;

		( new Database() )->install();

		self::assertCount( 2, $wpdb->schemas );
		self::assertArrayHasKey( 'wp_ran_booster_packages', $wpdb->schemaTables );
		self::assertArrayHasKey( 'wp_ran_booster_deployment_attempts', $wpdb->schemaTables );
		self::assertArrayNotHasKey( 'wp_ran_booster_rejected_admission_audit', $wpdb->schemaTables );

		$packageSchema = $wpdb->schemas[0];
		self::assertStringContainsString( 'deployment_policy varchar(10) NOT NULL', $packageSchema );
		self::assertStringContainsString( "source varchar(16) NOT NULL DEFAULT 'branch'", $packageSchema );
		self::assertStringContainsString( "source_revision bigint(20) unsigned NOT NULL DEFAULT '1'", $packageSchema );
		self::assertStringContainsString( 'source_previous varchar(16) DEFAULT NULL', $packageSchema );
		self::assertStringContainsString( 'source_changed_at datetime DEFAULT NULL', $packageSchema );
		self::assertStringContainsString( 'source_changed_by bigint(20) unsigned DEFAULT NULL', $packageSchema );
		self::assertStringContainsString( 'release_configuration text DEFAULT NULL', $packageSchema );
		self::assertStringNotContainsString( 'release_candidate', $packageSchema );
		self::assertStringNotContainsString( 'release_discovery_state', $packageSchema );
		self::assertStringNotContainsString( 'release_cooldown_until', $packageSchema );
		self::assertStringNotContainsString( 'last_deployed_release', $packageSchema );
		self::assertStringNotContainsString( 'release_discovery', $packageSchema );
		self::assertStringNotContainsString( 'status tinyint', $packageSchema );
		self::assertStringNotContainsString( 'ptd tinyint', $packageSchema );
		self::assertCount( 17, $wpdb->schemaTables['wp_ran_booster_packages']['columns'] );
		self::assertCount( 3, $wpdb->schemaTables['wp_ran_booster_packages']['indexes'] );

		$attemptSchema = $wpdb->schemas[1];
		self::assertStringContainsString( 'package_slug varchar(191) NOT NULL', $attemptSchema );
		self::assertStringContainsString( "package_source varchar(16) NOT NULL DEFAULT 'branch'", $attemptSchema );
		self::assertStringContainsString( "package_source_revision bigint(20) unsigned NOT NULL DEFAULT '0'", $attemptSchema );
		self::assertStringNotContainsString( 'release_identity', $attemptSchema );
		self::assertStringContainsString( 'request_json text NOT NULL', $attemptSchema );
		self::assertStringContainsString( 'resolved_at datetime DEFAULT NULL', $attemptSchema );
		self::assertStringContainsString( 'resolved_by bigint(20) unsigned DEFAULT NULL', $attemptSchema );
		self::assertStringContainsString( 'UNIQUE KEY webhook_target (provider, delivery_id, package_type, package_slug)', $attemptSchema );
		self::assertCount( 22, $wpdb->schemaTables['wp_ran_booster_deployment_attempts']['columns'] );
		self::assertCount( 5, $wpdb->schemaTables['wp_ran_booster_deployment_attempts']['indexes'] );
		self::assertStringContainsString( 'ENGINE=InnoDB', $attemptSchema );

		self::assertSame( '13.0', $ran_booster_storage_test_options[ Database::VERSION_OPTION ] );
	}

	/** @return list<array{string, bool}> */
	public static function serverSupportProvider(): array {
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

	#[DataProvider( 'serverSupportProvider' )]
	public function testCapabilityPreflightClassifiesOnlyTheSupportedServerFormats( string $serverInfo, bool $supported ): void {
		global $wpdb;

		$wpdb->serverInfo = $serverInfo;
		$database         = new Database( $wpdb );

		self::assertSame( $supported, $database->isSupported() );
		self::assertSame( $supported ? 1 : 0, $wpdb->capabilityReads );
	}

	public function testCapabilityPreflightIsRequestCached(): void {
		global $wpdb;

		$database = new Database( $wpdb );

		$database->requireSupported();
		$database->requireSupported();

		self::assertSame( 1, $wpdb->capabilityReads );
	}

	/** @return array<string, array{bool, string}> */
	public static function capabilityProbeFailureProvider(): array {
		return array(
			'server identity probe' => array( true, 'server-info-canary' ),
			'storage engine probe'  => array( false, 'engine-probe-canary' ),
		);
	}

	#[DataProvider( 'capabilityProbeFailureProvider' )]
	public function testCapabilityProbeFailuresBecomeCachedSafeStatesAndRestoreWpdbErrors(
		bool $failServerIdentity,
		string $canary
	): void {
		$connection = new DatabaseCapabilityProbeFailureConnection( $failServerIdentity );
		$database   = new Database( $connection );

		try {
			$database->requireSupported();
			self::fail( 'Expected the failed capability probe to enter the safe state.' );
		} catch ( DatabaseCompatibilityFailure $failure ) {
			self::assertSame( 'capability_probe_failed', $failure->reason() );
			self::assertStringNotContainsString( $canary, $failure->getMessage() );
		}

		self::assertSame( 'preserved-error', $connection->last_error );
		self::assertFalse( $connection->errorsSuppressed );
		self::assertFalse( $database->isSupported() );
	}

	public function testUnavailableInnoDbFailsBeforeSchemaOrVersionMutation(): void {
		global $ran_booster_storage_test_options, $wpdb;

		$ran_booster_storage_test_options[ Database::VERSION_OPTION ] = '10.0';
		$wpdb->innodbSupport = 'NO';
		$wpdb->rows[]        = array(
			'id'      => 1,
			'package' => 'preserved/plugin.php',
		);

		try {
			( new Database( $wpdb ) )->maybeUpgrade();
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

	public function testCurrentVersionRecreatesOnlyAMissingTableWithoutDeletingHistory(): void {
		global $ran_booster_storage_test_options, $wpdb;

		( new Database() )->install();
		$attemptSchema = $wpdb->schemaTables['wp_ran_booster_deployment_attempts'];
		unset( $wpdb->schemaTables['wp_ran_booster_packages'] );
		$ran_booster_storage_test_options[ Database::VERSION_OPTION ] = '13.0';
		$wpdb->schemas = array();
		$wpdb->queries = array();

		( new Database() )->install();

		self::assertCount( 1, $wpdb->schemas );
		self::assertSame( $attemptSchema, $wpdb->schemaTables['wp_ran_booster_deployment_attempts'] );
		self::assertSame( array(), $wpdb->queries );
	}

	public function testCurrentVersionMaybeUpgradeKeepsTheCheapFastPath(): void {
		global $ran_booster_storage_test_options, $wpdb;

		( new Database() )->install();
		$wpdb->schemas = array();
		$ran_booster_storage_test_options[ Database::VERSION_OPTION ] = '13.0';
		$wpdb->successfulReadsBeforeFailure                           = 0;
		$database = new Database();

		$database->maybeUpgrade();

		self::assertTrue( $database->isReady() );
		self::assertSame( array(), $wpdb->schemas );

		$wpdb->successfulReadsBeforeFailure = null;
		$database->install();
		self::assertCount( 0, $wpdb->schemas, 'Explicit installation must verify a current schema without unnecessary DDL.' );
	}

	public function testTenPointZeroUpgradeAddsOnlyResolutionMetadataAndPreservesRows(): void {
		global $ran_booster_storage_test_options, $wpdb;

		( new Database() )->install();
		unset(
			$wpdb->schemaTables['wp_ran_booster_deployment_attempts']['columns']['resolved_at'],
			$wpdb->schemaTables['wp_ran_booster_deployment_attempts']['columns']['resolved_by'],
			$wpdb->schemaTables['wp_ran_booster_deployment_attempts']['columnMetadata']['resolved_at'],
			$wpdb->schemaTables['wp_ran_booster_deployment_attempts']['columnMetadata']['resolved_by']
		);
		$wpdb->rows[] = array(
			'id'      => 1,
			'package' => 'preserved/plugin.php',
		);
		$ran_booster_storage_test_options[ Database::VERSION_OPTION ] = '10.0';
		$wpdb->schemas = array();
		$wpdb->queries = array();

		( new Database() )->maybeUpgrade();

		self::assertSame( '13.0', $ran_booster_storage_test_options[ Database::VERSION_OPTION ] );
		self::assertCount( 1, $wpdb->schemas );
		self::assertArrayHasKey( 'resolved_at', $wpdb->schemaTables['wp_ran_booster_deployment_attempts']['columns'] );
		self::assertArrayHasKey( 'resolved_by', $wpdb->schemaTables['wp_ran_booster_deployment_attempts']['columns'] );
		self::assertSame(
			array(
				array(
					'id'      => 1,
					'package' => 'preserved/plugin.php',
				),
			),
			$wpdb->rows
		);
	}

	/** @return list<array{string}> */
	public static function supportedLegacyVersionProvider(): array {
		return array(
			array( '10.0' ),
			array( '11.0' ),
			array( '12.0' ),
		);
	}

	#[DataProvider( 'supportedLegacyVersionProvider' )]
	public function testSupportedLegacyVersionsRetireOnlyTheCurrentPrefixAuditTable( string $legacyVersion ): void {
		global $ran_booster_storage_test_options, $wpdb;

		( new Database() )->install();
		$preservedRows = array(
			array(
				'id'      => 1,
				'package' => 'preserved/plugin.php',
			),
		);
		$wpdb->rows    = $preservedRows;
		$wpdb->schemaTables['wp_ran_booster_rejected_admission_audit']    = $wpdb->schemaTables['wp_ran_booster_deployment_attempts'];
		$wpdb->schemaTables['other_ran_booster_rejected_admission_audit'] = $wpdb->schemaTables['wp_ran_booster_deployment_attempts'];
		$ran_booster_storage_test_options[ Database::VERSION_OPTION ]     = $legacyVersion;
		$wpdb->schemas = array();
		$wpdb->queries = array();

		( new Database() )->maybeUpgrade();

		self::assertSame( '13.0', $ran_booster_storage_test_options[ Database::VERSION_OPTION ] );
		self::assertArrayNotHasKey( 'wp_ran_booster_rejected_admission_audit', $wpdb->schemaTables );
		self::assertArrayHasKey( 'other_ran_booster_rejected_admission_audit', $wpdb->schemaTables );
		self::assertSame( $preservedRows, $wpdb->rows );
		self::assertContains( 'DROP TABLE IF EXISTS `wp_ran_booster_rejected_admission_audit`', $wpdb->queries );
	}

	public function testTwelvePointZeroUpgradeSucceedsWhenTheLegacyAuditTableIsAlreadyAbsent(): void {
		global $ran_booster_storage_test_options, $wpdb;

		( new Database() )->install();
		$ran_booster_storage_test_options[ Database::VERSION_OPTION ] = '12.0';
		$wpdb->schemas = array();
		$wpdb->queries = array();

		( new Database() )->maybeUpgrade();

		self::assertSame( '13.0', $ran_booster_storage_test_options[ Database::VERSION_OPTION ] );
		self::assertSame( array(), $wpdb->queries );
	}

	public function testLegacyAuditDropFailureLeavesTheOldVersionAndTable(): void {
		global $ran_booster_storage_test_options, $wpdb;

		( new Database() )->install();
		$wpdb->schemaTables['wp_ran_booster_rejected_admission_audit'] = $wpdb->schemaTables['wp_ran_booster_deployment_attempts'];
		$wpdb->queryFailureContains                                    = 'DROP TABLE';
		$ran_booster_storage_test_options[ Database::VERSION_OPTION ]  = '12.0';

		try {
			( new Database() )->maybeUpgrade();
			self::fail( 'Expected the legacy audit drop to fail closed.' );
		} catch ( DatabaseLifecycleFailure $failure ) {
			self::assertSame( 'legacy_audit_drop_failed', $failure->reason() );
		}

		self::assertSame( '12.0', $ran_booster_storage_test_options[ Database::VERSION_OPTION ] );
		self::assertArrayHasKey( 'wp_ran_booster_rejected_admission_audit', $wpdb->schemaTables );
	}

	public function testLegacyAuditDropMustBeVerifiedBeforeTheVersionAdvances(): void {
		global $ran_booster_storage_test_options, $wpdb;

		( new Database() )->install();
		$wpdb->schemaTables['wp_ran_booster_rejected_admission_audit'] = $wpdb->schemaTables['wp_ran_booster_deployment_attempts'];
		$wpdb->keepDroppedTables                                       = true;
		$ran_booster_storage_test_options[ Database::VERSION_OPTION ]  = '12.0';

		try {
			( new Database() )->maybeUpgrade();
			self::fail( 'Expected the legacy audit absence check to fail closed.' );
		} catch ( DatabaseLifecycleFailure $failure ) {
			self::assertSame( 'legacy_audit_drop_unverified', $failure->reason() );
		}

		self::assertSame( '12.0', $ran_booster_storage_test_options[ Database::VERSION_OPTION ] );
		self::assertArrayHasKey( 'wp_ran_booster_rejected_admission_audit', $wpdb->schemaTables );
	}

	public function testLegacyAuditAbsenceReadFailureLeavesTheOldVersionForSafeReentry(): void {
		global $ran_booster_storage_test_options, $wpdb;

		( new Database() )->install();
		$wpdb->schemaTables['wp_ran_booster_rejected_admission_audit'] = $wpdb->schemaTables['wp_ran_booster_deployment_attempts'];
		$wpdb->successfulTableReadsBeforeFailure                       = 3;
		$ran_booster_storage_test_options[ Database::VERSION_OPTION ]  = '12.0';

		try {
			( new Database() )->maybeUpgrade();
			self::fail( 'Expected the legacy audit absence read to fail closed.' );
		} catch ( DatabaseLifecycleFailure $failure ) {
			self::assertSame( 'legacy_audit_read_failed', $failure->reason() );
			self::assertStringNotContainsString( 'database details', $failure->getMessage() );
		}

		self::assertSame( '12.0', $ran_booster_storage_test_options[ Database::VERSION_OPTION ] );
		self::assertArrayNotHasKey( 'wp_ran_booster_rejected_admission_audit', $wpdb->schemaTables );
		$wpdb->successfulTableReadsBeforeFailure = null;
		( new Database() )->maybeUpgrade();
		self::assertSame( '13.0', $ran_booster_storage_test_options[ Database::VERSION_OPTION ] );
	}

	/** @return array<string, array{string, string, string}> */
	public static function unsafeMissingSchemaProvider(): array {
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

	#[DataProvider( 'unsafeMissingSchemaProvider' )]
	public function testTenPointZeroUpgradeRejectsAnyOtherMissingSchemaBeforeDdl(
		string $table,
		string $section,
		string $name
	): void {
		global $ran_booster_storage_test_options, $wpdb;

		$this->installVersionTenSchema( $wpdb );
		unset( $wpdb->schemaTables[ $table ][ $section ][ $name ] );
		if ( 'columns' === $section ) {
			unset( $wpdb->schemaTables[ $table ]['columnMetadata'][ $name ] );
		}

		$this->assertIncompatibleSchemaFailsBeforeDdl( $wpdb );
	}

	/** @return array<string, array{mixed, string}> */
	public static function rejectedVersionProvider(): array {
		return array(
			'malformed'          => array( 'not-a-version', 'malformed_schema_version' ),
			'pre-preservation'   => array( '4.0', 'unsupported_old_schema' ),
			'previous contract'  => array( '5.0', 'unsupported_old_schema' ),
			'older hard cut'     => array( '6.5', 'unsupported_old_schema' ),
			'previous hard cut'  => array( '7.0', 'unsupported_old_schema' ),
			'untagged schema 8'  => array( '8.0', 'unsupported_old_schema' ),
			'untagged schema 9'  => array( '9.0', 'unsupported_old_schema' ),
			'unknown transition' => array( '10.5', 'unknown_schema_version' ),
			'newer'              => array( '14.0', 'newer_schema' ),
			'wrong type'         => array( 5, 'malformed_schema_version' ),
		);
	}

	#[DataProvider( 'rejectedVersionProvider' )]
	public function testRejectedVersionFailsBeforeDdlOrDataMutation( mixed $version, string $reason ): void {
		global $ran_booster_storage_test_options, $wpdb;

		$ran_booster_storage_test_options[ Database::VERSION_OPTION ] = $version;
		$wpdb->rows[] = array(
			'id'      => 1,
			'package' => 'preserved/plugin.php',
		);

		try {
			( new Database() )->maybeUpgrade();
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

	public function testWrongEngineFailsBeforeRecordingVersion(): void {
		global $ran_booster_storage_test_options, $wpdb;

		$this->installVersionTenSchema( $wpdb );
		$wpdb->schemaTables['wp_ran_booster_deployment_attempts']['engine'] = 'MyISAM';

		try {
			( new Database() )->maybeUpgrade();
			self::fail( 'Expected a non-InnoDB table to fail closed.' );
		} catch ( DatabaseLifecycleFailure $failure ) {
			self::assertSame( 'wrong_storage_engine', $failure->reason() );
		}

		self::assertSame( '10.0', $ran_booster_storage_test_options[ Database::VERSION_OPTION ] );
	}

	public function testChangedAttemptColumnTypeIsIncompatibleAndPreserved(): void {
		global $ran_booster_storage_test_options, $wpdb;

		$this->installVersionTenSchema( $wpdb );
		$wpdb->schemaTables['wp_ran_booster_deployment_attempts']['columns']['request_json'] = 'longtext';

		$this->assertIncompatibleSchemaFailsBeforeDdl( $wpdb );
		self::assertSame( 'longtext', $wpdb->schemaTables['wp_ran_booster_deployment_attempts']['columns']['request_json'] );
	}

	/** @return array<string, array{string, string, string, bool|string}> */
	public static function incompatibleColumnMetadataProvider(): array {
		return array(
			'nullability'    => array( 'wp_ran_booster_deployment_attempts', 'request_json', 'nullable', true ),
			'default value'  => array( 'wp_ran_booster_packages', 'branch', 'default', 'trunk' ),
			'auto increment' => array( 'wp_ran_booster_packages', 'id', 'extra', '' ),
		);
	}

	#[DataProvider( 'incompatibleColumnMetadataProvider' )]
	public function testTenPointZeroUpgradeRejectsIncompatibleColumnMetadata(
		string $table,
		string $column,
		string $attribute,
		bool|string $value
	): void {
		global $ran_booster_storage_test_options, $wpdb;

		$this->installVersionTenSchema( $wpdb );

		$wpdb->schemaTables[ $table ]['columnMetadata'][ $column ][ $attribute ] = $value;

		$this->assertIncompatibleSchemaFailsBeforeDdl( $wpdb );
	}

	public function testTenPointZeroUpgradeRejectsPrefixedIndexBeforeDdl(): void {
		global $ran_booster_storage_test_options, $wpdb;

		$this->installVersionTenSchema( $wpdb );

		$wpdb->schemaTables['wp_ran_booster_deployment_attempts']['indexes']['queue']['prefixes'][0] = 10;

		$this->assertIncompatibleSchemaFailsBeforeDdl( $wpdb );
	}

	public function testIncompatibleAttemptTableFailsClosedWithoutDdlOrDeletion(): void {
		global $ran_booster_storage_test_options, $wpdb;

		$this->installVersionTenSchema( $wpdb );
		$wpdb->rows[] = array(
			'id'             => 1,
			'correlation_id' => 'preserved',
		);
		$wpdb->schemaTables['wp_ran_booster_deployment_attempts']['indexes']['webhook_target']['unique'] = false;
		$wpdb->schemas = array();
		$database      = new Database();

		foreach ( array( 1, 2 ) as $_attempt ) {
			try {
				$database->requireReady();
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
		self::assertSame( '10.0', $ran_booster_storage_test_options[ Database::VERSION_OPTION ] );
		self::assertFalse( $database->isReady() );
	}

	public function testUnreadableAttemptTableFailsClosedWithoutDdl(): void {
		global $ran_booster_storage_test_options, $wpdb;

		$this->installVersionTenSchema( $wpdb );
		$wpdb->schemas                      = array();
		$wpdb->successfulReadsBeforeFailure = 2;

		try {
			( new Database() )->maybeUpgrade();
			self::fail( 'Expected unreadable attempt storage to fail closed.' );
		} catch ( DatabaseLifecycleFailure $failure ) {
			self::assertSame( 'schema_read_failed', $failure->reason() );
			self::assertStringNotContainsString( 'database details', $failure->getMessage() );
		}

		self::assertSame( array(), $wpdb->schemas );
		self::assertSame( '10.0', $ran_booster_storage_test_options[ Database::VERSION_OPTION ] );
	}

	public function testVersionWriteFailureIsCachedAndRetryableWithANewLifecycle(): void {
		global $ran_booster_storage_test_option_apply_write,
			$ran_booster_storage_test_option_write_result,
			$ran_booster_storage_test_options,
			$wpdb;

		( new Database() )->install();
		$wpdb->schemaTables['wp_ran_booster_rejected_admission_audit'] = $wpdb->schemaTables['wp_ran_booster_deployment_attempts'];
		$ran_booster_storage_test_options[ Database::VERSION_OPTION ]  = '12.0';
		$ran_booster_storage_test_option_apply_write                   = false;
		$ran_booster_storage_test_option_write_result                  = false;
		$database = new Database();

		foreach ( array( 1, 2 ) as $_attempt ) {
			try {
				$database->requireReady();
				self::fail( 'Expected the version write to fail.' );
			} catch ( DatabaseLifecycleFailure $failure ) {
				self::assertSame( 'version_write_failed', $failure->reason() );
			}
		}
		self::assertSame( '12.0', $ran_booster_storage_test_options[ Database::VERSION_OPTION ] );
		self::assertArrayNotHasKey( 'wp_ran_booster_rejected_admission_audit', $wpdb->schemaTables );

		$ran_booster_storage_test_option_apply_write  = true;
		$ran_booster_storage_test_option_write_result = true;
		( new Database() )->requireReady();

		self::assertSame( '13.0', $ran_booster_storage_test_options[ Database::VERSION_OPTION ] );
	}

	public function testVersionVerificationFailureDoesNotClaimReadiness(): void {
		global $ran_booster_storage_test_option_apply_write,
			$ran_booster_storage_test_options;

		$ran_booster_storage_test_option_apply_write = false;
		$database                                    = new Database();

		try {
			$database->install();
			self::fail( 'Expected the absent written version to fail verification.' );
		} catch ( DatabaseLifecycleFailure $failure ) {
			self::assertSame( 'version_verification_failed', $failure->reason() );
		}

		self::assertFalse( $database->isReady() );
		self::assertArrayNotHasKey( Database::VERSION_OPTION, $ran_booster_storage_test_options );
	}

	public function testPostDeltaVerificationFailureDoesNotRecordVersion(): void {
		global $ran_booster_storage_test_options, $wpdb;

		$wpdb->schemaEngine = 'MyISAM';

		try {
			( new Database() )->install();
			self::fail( 'Expected the created non-InnoDB tables to fail verification.' );
		} catch ( DatabaseLifecycleFailure $failure ) {
			self::assertSame( 'wrong_storage_engine', $failure->reason() );
		}

		self::assertArrayNotHasKey( Database::VERSION_OPTION, $ran_booster_storage_test_options );
	}

	public function testReadinessInspectionIsPassiveUntilReadinessIsRequired(): void {
		global $ran_booster_storage_test_options, $wpdb;

		$database = new Database();

		self::assertFalse( $database->isReady() );
		self::assertSame( array(), $wpdb->schemas );

		$database->requireReady();
		self::assertTrue( $database->isReady() );
		self::assertCount( 2, $wpdb->schemas );
	}

	public function testWordPressOptionsEngineDoesNotGatePluginSchemaInstallation(): void {
		global $ran_booster_storage_test_options, $wpdb;

		$wpdb->optionsEngine = 'MyISAM';

		( new Database() )->install();

		self::assertSame( '13.0', $ran_booster_storage_test_options[ Database::VERSION_OPTION ] );
		self::assertStringNotContainsString( 'wp_options', implode( "\n", $wpdb->queries ) );
	}

	private function installVersionTenSchema( StorageTestWpdb $wpdb ): void {
		global $ran_booster_storage_test_options;

		( new Database() )->install();
		unset(
			$wpdb->schemaTables['wp_ran_booster_deployment_attempts']['columns']['resolved_at'],
			$wpdb->schemaTables['wp_ran_booster_deployment_attempts']['columns']['resolved_by'],
			$wpdb->schemaTables['wp_ran_booster_deployment_attempts']['columnMetadata']['resolved_at'],
			$wpdb->schemaTables['wp_ran_booster_deployment_attempts']['columnMetadata']['resolved_by']
		);
		$ran_booster_storage_test_options[ Database::VERSION_OPTION ] = '10.0';
	}

	private function assertIncompatibleSchemaFailsBeforeDdl( StorageTestWpdb $wpdb ): void {
		global $ran_booster_storage_test_options;

		$wpdb->schemas = array();
		try {
			( new Database() )->maybeUpgrade();
			self::fail( 'Expected an incompatible schema to fail closed.' );
		} catch ( DatabaseLifecycleFailure $failure ) {
			self::assertSame( 'incompatible_schema', $failure->reason() );
		}

		self::assertSame( array(), $wpdb->schemas );
		self::assertSame( '10.0', $ran_booster_storage_test_options[ Database::VERSION_OPTION ] );
	}
}

final class DatabaseCapabilityProbeFailureConnection {
	public string $last_error     = 'preserved-error';
	public bool $errorsSuppressed = false;

	public function __construct( private bool $failServerIdentity ) {
	}

	public function suppress_errors( bool $suppress ): bool {
		$previous               = $this->errorsSuppressed;
		$this->errorsSuppressed = $suppress;

		return $previous;
	}

	public function db_server_info(): string {
		if ( $this->failServerIdentity ) {
			throw new RuntimeException( 'server-info-canary' );
		}

		return '8.4.6';
	}

	public function get_results( string $query ): array {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The probe must contain and replace this test-only low-level detail.
		throw new RuntimeException( $query . ' engine-probe-canary' );
	}
}

// phpcs:enable Generic.Files.OneObjectStructurePerFile
