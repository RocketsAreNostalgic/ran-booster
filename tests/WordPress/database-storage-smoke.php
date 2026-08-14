<?php

use RAN\Deployment\DeploymentAttemptRepository;
use RAN\Deployment\DeploymentOutcome;
use RAN\Deployment\DeploymentPolicy;
use RAN\Deployment\DeploymentRequest;
use RAN\Internal\CoreContainer;
use RAN\ManagedRepository;
use RAN\Storage\Database;
use RAN\Storage\DatabaseLifecycleFailure;
use RAN\Storage\PluginRepository;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	throw new RuntimeException( 'This proof must run through WP-CLI.' );
}

$container = require __DIR__ . '/core-container-fixture.php';
if ( ! $container instanceof CoreContainer ) {
	throw new RuntimeException( 'RAN Booster is not active.' );
}

$database = $container->make( Database::class );
$database->requireSupported();
$database->install();

global $wpdb;
$packageTable = ran_booster_table_name();
$attemptTable = Database::attemptTableName();

if ( '13.0' !== Database::$booster_db_version ) {
	throw new RuntimeException( 'The database smoke requires the schema 13.0 lifecycle.' );
}

$schemaSevenPackage = array(
	'package'                => 'ran-booster-schema-seven-marker/ran-booster-schema-seven-marker.php',
	'repository'             => 'example/schema-seven-marker',
	'branch'                 => 'schema-seven',
	'type'                   => 1,
	'deployment_policy'      => DeploymentPolicy::DISABLED->value,
	'provider'               => 'gh',
	'provider_repository_id' => 'schema-seven-marker',
	'private'                => 0,
	'credential_id'          => null,
	'subdirectory'           => 'preserved-subdirectory',
);
$schemaSevenAttempt = array(
	'correlation_id'         => 'schema7preservation0000000000000',
	'source'                 => 'manual',
	'operation'              => 'update',
	'package_type'           => 'plugin',
	'package_slug'           => 'ran-booster-schema-seven-marker',
	'provider'               => 'gh',
	'provider_repository_id' => 'schema-seven-marker',
	'requested_ref'          => 'schema-seven',
	'resolved_ref'           => '0123456789abcdef0123456789abcdef01234567',
	'delivery_id'            => null,
	'delivery_digest'        => null,
	'state'                  => 'finished',
	'mutation_started_at'    => '2026-01-02 03:04:05',
	'outcome_code'           => DeploymentOutcome::CODE_NO_CHANGE,
	'request_json'           => '{"schema":7,"preserve":true}',
	'created_at'             => '2026-01-02 03:04:00',
	'finished_at'            => '2026-01-02 03:04:06',
);
$wpdb->delete( $packageTable, array( 'package' => $schemaSevenPackage['package'] ) );
$wpdb->delete( $attemptTable, array( 'correlation_id' => $schemaSevenAttempt['correlation_id'] ) );

$fetchRow              = static function ( string $table, string $column, int|string $value ) use ( $wpdb ): array {
	$query = is_int( $value )
		? $wpdb->prepare( 'SELECT * FROM %i WHERE %i = %d', $table, $column, $value )
		: $wpdb->prepare( 'SELECT * FROM %i WHERE %i = %s', $table, $column, $value );
	// The query is prepared immediately above with bound table, column and value placeholders.
	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	$row = $wpdb->get_row( $query, ARRAY_A );
	if ( ! is_array( $row ) ) {
		throw new RuntimeException( 'The database smoke could not read a preservation marker.' );
	}

	return $row;
};
$showCreate            = static function ( string $table ) use ( $wpdb ): string {
	$row = $wpdb->get_row( $wpdb->prepare( 'SHOW CREATE TABLE %i', $table ), ARRAY_N );
	if ( ! is_array( $row ) || ! isset( $row[1] ) || ! is_string( $row[1] ) ) {
		throw new RuntimeException( 'The database smoke could not inspect a fixture schema.' );
	}

	return $row[1];
};
$setSchemaVersion      = static function ( string $version ): void {
	if ( ! update_option( Database::VERSION_OPTION, $version, false )
		&& $version !== (string) get_option( Database::VERSION_OPTION, '' ) ) {
		throw new RuntimeException( 'The database smoke could not set its fixture schema version.' );
	}
};
$assertRejectedVersion = static function (
	string $version,
	string $expectedReason
) use (
	$attemptTable,
	$fetchRow,
	$packageTable,
	$schemaSevenAttempt,
	$schemaSevenPackage,
	$setSchemaVersion,
	$showCreate
): void {
	$packageBefore = $fetchRow( $packageTable, 'package', $schemaSevenPackage['package'] );
	$attemptBefore = $fetchRow( $attemptTable, 'correlation_id', $schemaSevenAttempt['correlation_id'] );
	$schemasBefore = array( $showCreate( $packageTable ), $showCreate( $attemptTable ) );
	$setSchemaVersion( $version );

	$rejected = false;
	try {
		try {
			( new Database() )->maybeUpgrade();
		} catch ( DatabaseLifecycleFailure $failure ) {
			if ( $expectedReason !== $failure->reason() ) {
				throw new RuntimeException( 'The database smoke received the wrong schema-version failure.' );
			}
			$rejected = true;
		}
		if ( ! $rejected ) {
			throw new RuntimeException( 'The database smoke accepted an unsupported stored schema version.' );
		}
		if ( $version !== (string) get_option( Database::VERSION_OPTION, '' ) ) {
			throw new RuntimeException( 'The database smoke changed a rejected schema version.' );
		}
		if ( $packageBefore !== $fetchRow( $packageTable, 'package', $schemaSevenPackage['package'] )
			|| $attemptBefore !== $fetchRow( $attemptTable, 'correlation_id', $schemaSevenAttempt['correlation_id'] )
			|| $schemasBefore !== array( $showCreate( $packageTable ), $showCreate( $attemptTable ) ) ) {
			throw new RuntimeException( 'The database smoke found mutation after a rejected schema version.' );
		}
	} finally {
		$setSchemaVersion( Database::$booster_db_version );
	}
};

$originalPrefix        = $wpdb->prefix;
$isolatedPrefix        = $originalPrefix . 'ran_booster_schema_smoke_';
$isolatedPackageTable  = $isolatedPrefix . 'ran_booster_packages';
$isolatedAttemptTable  = $isolatedPrefix . 'ran_booster_deployment_attempts';
$isolatedAuditTable    = $isolatedPrefix . 'ran_booster_rejected_admission_audit';
$wrongPrefixAuditTable = $originalPrefix . 'ran_booster_rejected_admission_audit';
$ownsWrongPrefixAudit  = false;
$dropIsolatedTables    = static function () use ( $isolatedAttemptTable, $isolatedPackageTable, $isolatedAuditTable, $wrongPrefixAuditTable, $wpdb, &$ownsWrongPrefixAudit ): void {
	foreach ( array( $isolatedPackageTable, $isolatedAttemptTable, $isolatedAuditTable ) as $table ) {
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) );
	}
	if ( $ownsWrongPrefixAudit ) {
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wrongPrefixAuditTable ) );
		$ownsWrongPrefixAudit = false;
	}
};
$wpdb->last_error      = '';
$existingAuditTable    = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wrongPrefixAuditTable ) ) );
if ( '' !== trim( (string) $wpdb->last_error ) ) {
	throw new RuntimeException( 'The database smoke could not preflight the current-prefix legacy audit table.' );
}
if ( null !== $existingAuditTable ) {
	throw new RuntimeException( 'The database smoke refuses to replace a pre-existing current-prefix legacy audit table.' );
}
$dropIsolatedTables();
try {
	if ( false === $wpdb->insert( $packageTable, $schemaSevenPackage )
	|| false === $wpdb->insert( $attemptTable, $schemaSevenAttempt ) ) {
		throw new RuntimeException( 'The database smoke could not seed hard-cut preservation rows.' );
	}
	$assertRejectedVersion( '6.0', 'unsupported_old_schema' );
	$assertRejectedVersion( '7.0', 'unsupported_old_schema' );
	$assertRejectedVersion( '8.0', 'unsupported_old_schema' );
	$assertRejectedVersion( '9.0', 'unsupported_old_schema' );
	$assertRejectedVersion( '10.5', 'unknown_schema_version' );
	$assertRejectedVersion( '14.0', 'newer_schema' );
	$assertRejectedVersion( 'not-a-version', 'malformed_schema_version' );

	foreach ( array( $packageTable, $attemptTable ) as $table ) {
		$tableStatus = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS WHERE Name = %s', $table ) );
		if ( ! is_object( $tableStatus ) || 0 !== strcasecmp( 'InnoDB', (string) ( $tableStatus->Engine ?? '' ) ) ) {
			throw new RuntimeException( 'The database smoke found a non-InnoDB Booster table.' );
		}
	}

	if ( false === $wpdb->query( $wpdb->prepare( 'CREATE TABLE %i LIKE %i', $isolatedPackageTable, $packageTable ) )
		|| false === $wpdb->query( $wpdb->prepare( 'CREATE TABLE %i LIKE %i', $isolatedAttemptTable, $attemptTable ) )
		|| false === $wpdb->query(
			$wpdb->prepare(
				'CREATE TABLE %i (
					id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
					event varchar(32) NOT NULL,
					attempt_id bigint(20) unsigned NOT NULL,
					correlation_id char(32) NOT NULL,
					package_type varchar(8) NOT NULL,
					package_slug varchar(191) NOT NULL,
					actor_id bigint(20) unsigned NOT NULL,
					operation varchar(32) NOT NULL,
					occurred_at datetime NOT NULL,
					PRIMARY KEY (id),
					KEY deduplication (event, attempt_id, actor_id, operation, occurred_at, id),
					KEY activity (occurred_at, id),
					KEY attempt_activity (attempt_id, occurred_at, id)
				) ENGINE=InnoDB',
				$isolatedAuditTable
			)
		) ) {
		throw new RuntimeException( 'The database smoke could not create its isolated schema 12.0 tables.' );
	}
	if ( false === $wpdb->query( $wpdb->prepare( 'CREATE TABLE %i LIKE %i', $wrongPrefixAuditTable, $isolatedAuditTable ) ) ) {
		throw new RuntimeException( 'The database smoke could not create its wrong-prefix containment fixture.' );
	}
	$ownsWrongPrefixAudit = true;
	if ( false === $wpdb->insert( $isolatedPackageTable, $schemaSevenPackage )
		|| false === $wpdb->insert( $isolatedAttemptTable, $schemaSevenAttempt ) ) {
		throw new RuntimeException( 'The database smoke could not seed its schema 12.0 fixture.' );
	}

	// Point only this disposable lifecycle instance at isolated, prefixed clones.
	$wpdb->prefix = $isolatedPrefix;
	$setSchemaVersion( '12.0' );
	$isolatedPackageBefore = $fetchRow( $isolatedPackageTable, 'package', $schemaSevenPackage['package'] );
	$isolatedAttemptBefore = $fetchRow( $isolatedAttemptTable, 'correlation_id', $schemaSevenAttempt['correlation_id'] );
	( new Database( $wpdb ) )->maybeUpgrade();
	$isolatedPackageAfter = $fetchRow( $isolatedPackageTable, 'package', $schemaSevenPackage['package'] );
	$isolatedAttemptAfter = $fetchRow( $isolatedAttemptTable, 'correlation_id', $schemaSevenAttempt['correlation_id'] );
	foreach ( array_keys( $schemaSevenPackage ) as $column ) {
		if ( $isolatedPackageBefore[ $column ] !== $isolatedPackageAfter[ $column ] ) {
			throw new RuntimeException( 'The database smoke changed a schema 12.0 package field.' );
		}
	}
	foreach ( array_keys( $schemaSevenAttempt ) as $column ) {
		if ( $isolatedAttemptBefore[ $column ] !== $isolatedAttemptAfter[ $column ] ) {
			throw new RuntimeException( 'The database smoke changed a schema 12.0 attempt field.' );
		}
	}
	if ( '13.0' !== (string) get_option( Database::VERSION_OPTION, '' )
		|| ! array_key_exists( 'resolved_at', $isolatedAttemptAfter )
		|| ! array_key_exists( 'resolved_by', $isolatedAttemptAfter )
		|| null !== $isolatedAttemptAfter['resolved_at']
		|| null !== $isolatedAttemptAfter['resolved_by']
		|| null !== $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS WHERE Name = %s', $isolatedAuditTable ) )
		|| ! is_object( $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS WHERE Name = %s', $wrongPrefixAuditTable ) ) ) ) {
		throw new RuntimeException( 'The database smoke could not verify the schema 12.0 to 13.0 lifecycle.' );
	}

	if ( false === $wpdb->query( $wpdb->prepare( 'ALTER TABLE %i DROP COLUMN %i', $isolatedPackageTable, 'subdirectory' ) ) ) {
		throw new RuntimeException( 'The database smoke could not build its hard-cut legacy-column fixture.' );
	}
	$setSchemaVersion( '10.0' );
	$isolatedSchemasBefore = array( $showCreate( $isolatedPackageTable ), $showCreate( $isolatedAttemptTable ) );
	$incompatibleRejected  = false;
	try {
		( new Database( $wpdb ) )->maybeUpgrade();
	} catch ( DatabaseLifecycleFailure $failure ) {
		if ( 'incompatible_schema' !== $failure->reason() ) {
			throw new RuntimeException( 'The database smoke received the wrong incompatible-schema failure.' );
		}
		$incompatibleRejected = true;
	}
	if ( ! $incompatibleRejected ) {
		throw new RuntimeException( 'The database smoke repaired a pre-Phase-2 schema gap.' );
	}
	if ( '10.0' !== (string) get_option( Database::VERSION_OPTION, '' )
		|| $isolatedSchemasBefore !== array( $showCreate( $isolatedPackageTable ), $showCreate( $isolatedAttemptTable ) ) ) {
		throw new RuntimeException( 'The database smoke mutated a rejected hard-cut schema.' );
	}
} finally {
	$wpdb->prefix = $originalPrefix;
	$dropIsolatedTables();
	$wpdb->delete( $packageTable, array( 'package' => $schemaSevenPackage['package'] ) );
	$wpdb->delete( $attemptTable, array( 'correlation_id' => $schemaSevenAttempt['correlation_id'] ) );
	$setSchemaVersion( Database::$booster_db_version );
}

$identifier  = 'ran-booster-database-smoke/ran-booster-database-smoke.php';
$directory   = WP_PLUGIN_DIR . '/ran-booster-database-smoke';
$fixturePath = WP_PLUGIN_DIR . '/' . $identifier;
if ( ! wp_mkdir_p( $directory ) ) {
	throw new RuntimeException( 'The database smoke could not create its disposable plugin directory.' );
}
$wpdb->delete(
	$attemptTable,
	array(
		'provider_repository_id' => 'database-smoke',
		'package_slug'           => 'ran-booster-database-smoke',
	)
);

try {
	$contents = "<?php\n/**\n * Plugin Name: RAN Booster Database Smoke\n * Version: 1.0.0\n */\n";
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Disposable CI fixture in the isolated WordPress checkout.
	if ( strlen( $contents ) !== file_put_contents( $fixturePath, $contents ) ) {
		throw new RuntimeException( 'The database smoke could not create its disposable plugin.' );
	}

	$packages      = $container->make( PluginRepository::class );
	$fixturePlugin = $packages->installedPluginFromFile( $identifier );
	$fixturePlugin->setRepository( new ManagedRepository( 'gh', 'example/database-smoke', 'database-smoke', 'main' ) );
	$fixturePlugin->setDeploymentPolicy( DeploymentPolicy::DISABLED );
	$packages->store( $fixturePlugin )->requireSuccess();
	$stored = $packages->boosterPluginFromFile( $identifier );
	if ( DeploymentPolicy::DISABLED !== $stored->getDeploymentPolicy() ) {
		throw new RuntimeException( 'The database smoke could not verify its package record.' );
	}
	$packages->unlink( $identifier )->requireSuccess();

	$attempts = $container->make( DeploymentAttemptRepository::class );
	$request  = new DeploymentRequest(
		'example/database-smoke',
		null,
		false,
		'main',
		'ran-booster-database-smoke',
		null,
		DeploymentPolicy::DISABLED,
		1
	);
	$attempt  = $attempts->admitAndClaimManual(
		'update',
		'plugin',
		'gh',
		'database-smoke',
		$request,
		'main',
		'branch',
		1
	);
	$finished = $attempts->finish( $attempt->getId(), DeploymentOutcome::fromCode( DeploymentOutcome::CODE_NO_CHANGE ) );
	if ( $finished->getId() !== $attempts->findExact( $attempt->getId() )?->getId() ) {
		throw new RuntimeException( 'The database smoke could not verify its attempt record.' );
	}
} finally {
	$wpdb->delete( $packageTable, array( 'package' => $identifier ) );
	$wpdb->delete(
		$attemptTable,
		array(
			'provider_repository_id' => 'database-smoke',
			'package_slug'           => 'ran-booster-database-smoke',
		)
	);
	// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Removes only the disposable CI fixture created above.
	is_file( $fixturePath ) && unlink( $fixturePath );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Removes only the empty disposable CI fixture directory.
	is_dir( $directory ) && rmdir( $directory );
}

WP_CLI::success( 'RAN Booster database storage smoke passed on ' . $wpdb->db_server_info() . '.' );
