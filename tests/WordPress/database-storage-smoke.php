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
$database->require_supported();
$database->install();

global $wpdb;
$package_table = ran_booster_table_name();
$attempt_table = Database::attempt_table_name();

if ( '13.0' !== Database::$booster_db_version ) {
	throw new RuntimeException( 'The database smoke requires the schema 13.0 lifecycle.' );
}

$schema_seven_package = array(
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
$schema_seven_attempt = array(
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
$wpdb->delete( $package_table, array( 'package' => $schema_seven_package['package'] ) );
$wpdb->delete( $attempt_table, array( 'correlation_id' => $schema_seven_attempt['correlation_id'] ) );

$fetch_row               = static function ( string $table, string $column, int|string $value ) use ( $wpdb ): array {
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
$show_create             = static function ( string $table ) use ( $wpdb ): string {
	$row = $wpdb->get_row( $wpdb->prepare( 'SHOW CREATE TABLE %i', $table ), ARRAY_N );
	if ( ! is_array( $row ) || ! isset( $row[1] ) || ! is_string( $row[1] ) ) {
		throw new RuntimeException( 'The database smoke could not inspect a fixture schema.' );
	}

	return $row[1];
};
$set_schema_version      = static function ( string $version ): void {
	if ( ! update_option( Database::VERSION_OPTION, $version, false )
		&& $version !== (string) get_option( Database::VERSION_OPTION, '' ) ) {
		throw new RuntimeException( 'The database smoke could not set its fixture schema version.' );
	}
};
$assert_rejected_version = static function (
	string $version,
	string $expected_reason
) use (
	$attempt_table,
	$fetch_row,
	$package_table,
	$schema_seven_attempt,
	$schema_seven_package,
	$set_schema_version,
	$show_create
): void {
	$package_before = $fetch_row( $package_table, 'package', $schema_seven_package['package'] );
	$attempt_before = $fetch_row( $attempt_table, 'correlation_id', $schema_seven_attempt['correlation_id'] );
	$schemas_before = array( $show_create( $package_table ), $show_create( $attempt_table ) );
	$set_schema_version( $version );

	$rejected = false;
	try {
		try {
			( new Database() )->maybe_upgrade();
		} catch ( DatabaseLifecycleFailure $failure ) {
			if ( $expected_reason !== $failure->reason() ) {
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
		if ( $package_before !== $fetch_row( $package_table, 'package', $schema_seven_package['package'] )
			|| $attempt_before !== $fetch_row( $attempt_table, 'correlation_id', $schema_seven_attempt['correlation_id'] )
			|| $schemas_before !== array( $show_create( $package_table ), $show_create( $attempt_table ) ) ) {
			throw new RuntimeException( 'The database smoke found mutation after a rejected schema version.' );
		}
	} finally {
		$set_schema_version( Database::$booster_db_version );
	}
};

try {
	if ( false === $wpdb->insert( $package_table, $schema_seven_package )
		|| false === $wpdb->insert( $attempt_table, $schema_seven_attempt ) ) {
		throw new RuntimeException( 'The database smoke could not seed hard-cut preservation rows.' );
	}
	$assert_rejected_version( '6.0', 'unsupported_old_schema' );
	$assert_rejected_version( '7.0', 'unsupported_old_schema' );
	$assert_rejected_version( '8.0', 'unsupported_old_schema' );
	$assert_rejected_version( '9.0', 'unsupported_old_schema' );
	$assert_rejected_version( '10.0', 'unsupported_old_schema' );
	$assert_rejected_version( '11.0', 'unsupported_old_schema' );
	$assert_rejected_version( '12.0', 'unsupported_old_schema' );
	$assert_rejected_version( '10.5', 'unknown_schema_version' );
	$assert_rejected_version( '14.0', 'newer_schema' );
	$assert_rejected_version( 'not-a-version', 'malformed_schema_version' );

	foreach ( array( $package_table, $attempt_table ) as $table ) {
		$table_status = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS WHERE Name = %s', $table ) );
		if ( ! is_object( $table_status ) || 0 !== strcasecmp( 'InnoDB', (string) ( $table_status->Engine ?? '' ) ) ) {
			throw new RuntimeException( 'The database smoke found a non-InnoDB Booster table.' );
		}
	}
} finally {
	$wpdb->delete( $package_table, array( 'package' => $schema_seven_package['package'] ) );
	$wpdb->delete( $attempt_table, array( 'correlation_id' => $schema_seven_attempt['correlation_id'] ) );
	$set_schema_version( Database::$booster_db_version );
}

$identifier   = 'ran-booster-database-smoke/ran-booster-database-smoke.php';
$directory    = WP_PLUGIN_DIR . '/ran-booster-database-smoke';
$fixture_path = WP_PLUGIN_DIR . '/' . $identifier;
if ( ! wp_mkdir_p( $directory ) ) {
	throw new RuntimeException( 'The database smoke could not create its disposable plugin directory.' );
}
$wpdb->delete(
	$attempt_table,
	array(
		'provider_repository_id' => 'database-smoke',
		'package_slug'           => 'ran-booster-database-smoke',
	)
);

try {
	$contents = "<?php\n/**\n * Plugin Name: RAN Booster Database Smoke\n * Version: 1.0.0\n */\n";
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Disposable CI fixture in the isolated WordPress checkout.
	if ( strlen( $contents ) !== file_put_contents( $fixture_path, $contents ) ) {
		throw new RuntimeException( 'The database smoke could not create its disposable plugin.' );
	}

	$packages       = $container->make( PluginRepository::class );
	$fixture_plugin = $packages->installed_plugin_from_file( $identifier );
	$fixture_plugin->set_repository( new ManagedRepository( 'gh', 'example/database-smoke', 'database-smoke', 'main' ) );
	$fixture_plugin->set_deployment_policy( DeploymentPolicy::DISABLED );
	$packages->store( $fixture_plugin )->require_success();
	$stored = $packages->booster_plugin_from_file( $identifier );
	if ( DeploymentPolicy::DISABLED !== $stored->get_deployment_policy() ) {
		throw new RuntimeException( 'The database smoke could not verify its package record.' );
	}
	$packages->unlink( $identifier )->require_success();

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
	$attempt  = $attempts->admit_and_claim_manual(
		'update',
		'plugin',
		'gh',
		'database-smoke',
		$request,
		'main',
		'branch',
		1
	);
	$finished = $attempts->finish( $attempt->get_id(), DeploymentOutcome::from_code( DeploymentOutcome::CODE_NO_CHANGE ) );
	if ( $finished->get_id() !== $attempts->find_exact( $attempt->get_id() )?->get_id() ) {
		throw new RuntimeException( 'The database smoke could not verify its attempt record.' );
	}
} finally {
	$wpdb->delete( $package_table, array( 'package' => $identifier ) );
	$wpdb->delete(
		$attempt_table,
		array(
			'provider_repository_id' => 'database-smoke',
			'package_slug'           => 'ran-booster-database-smoke',
		)
	);
	// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Removes only the disposable CI fixture created above.
	is_file( $fixture_path ) && unlink( $fixture_path );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Removes only the empty disposable CI fixture directory.
	is_dir( $directory ) && rmdir( $directory );
}

WP_CLI::success( 'RAN Booster database storage smoke passed on ' . $wpdb->db_server_info() . '.' );
