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

$ran_booster_container = require __DIR__ . '/core-container-fixture.php';
if ( ! $ran_booster_container instanceof CoreContainer ) {
	throw new RuntimeException( 'RAN Booster is not active.' );
}

$ran_booster_database = $ran_booster_container->make( Database::class );
$ran_booster_database->require_supported();
$ran_booster_database->install();

global $wpdb;
$ran_booster_package_table = ran_booster_table_name();
$ran_booster_attempt_table = Database::attempt_table_name();

if ( '13.0' !== Database::$booster_db_version ) {
	throw new RuntimeException( 'The database smoke requires the schema 13.0 lifecycle.' );
}

$ran_booster_schema_seven_package = array(
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
$ran_booster_schema_seven_attempt = array(
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
$wpdb->delete( $ran_booster_package_table, array( 'package' => $ran_booster_schema_seven_package['package'] ) );
$wpdb->delete( $ran_booster_attempt_table, array( 'correlation_id' => $ran_booster_schema_seven_attempt['correlation_id'] ) );

$ran_booster_fetch_row               = static function ( string $ran_booster_table, string $column, int|string $value ) use ( $wpdb ): array {
	$query = is_int( $value )
		? $wpdb->prepare( 'SELECT * FROM %i WHERE %i = %d', $ran_booster_table, $column, $value )
		: $wpdb->prepare( 'SELECT * FROM %i WHERE %i = %s', $ran_booster_table, $column, $value );
	// The query is prepared immediately above with bound table, column and value placeholders.
	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Both query branches above bind identifiers and the value through wpdb::prepare before this execution.
	$row = $wpdb->get_row( $query, ARRAY_A );
	if ( ! is_array( $row ) ) {
		throw new RuntimeException( 'The database smoke could not read a preservation marker.' );
	}

	return $row;
};
$ran_booster_show_create             = static function ( string $ran_booster_table ) use ( $wpdb ): string {
	$row = $wpdb->get_row( $wpdb->prepare( 'SHOW CREATE TABLE %i', $ran_booster_table ), ARRAY_N );
	if ( ! is_array( $row ) || ! isset( $row[1] ) || ! is_string( $row[1] ) ) {
		throw new RuntimeException( 'The database smoke could not inspect a fixture schema.' );
	}

	return $row[1];
};
$ran_booster_set_schema_version      = static function ( string $version ): void {
	if ( ! update_option( Database::VERSION_OPTION, $version, false )
		&& (string) get_option( Database::VERSION_OPTION, '' ) !== $version ) {
		throw new RuntimeException( 'The database smoke could not set its fixture schema version.' );
	}
};
$ran_booster_assert_rejected_version = static function (
	string $version,
	string $expected_reason
) use (
	$ran_booster_attempt_table,
	$ran_booster_fetch_row,
	$ran_booster_package_table,
	$ran_booster_schema_seven_attempt,
	$ran_booster_schema_seven_package,
	$ran_booster_set_schema_version,
	$ran_booster_show_create
): void {
	$package_before = $ran_booster_fetch_row( $ran_booster_package_table, 'package', $ran_booster_schema_seven_package['package'] );
	$attempt_before = $ran_booster_fetch_row( $ran_booster_attempt_table, 'correlation_id', $ran_booster_schema_seven_attempt['correlation_id'] );
	$schemas_before = array( $ran_booster_show_create( $ran_booster_package_table ), $ran_booster_show_create( $ran_booster_attempt_table ) );
	$ran_booster_set_schema_version( $version );

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
		if ( (string) get_option( Database::VERSION_OPTION, '' ) !== $version ) {
			throw new RuntimeException( 'The database smoke changed a rejected schema version.' );
		}
		if ( $package_before !== $ran_booster_fetch_row( $ran_booster_package_table, 'package', $ran_booster_schema_seven_package['package'] )
			|| $attempt_before !== $ran_booster_fetch_row( $ran_booster_attempt_table, 'correlation_id', $ran_booster_schema_seven_attempt['correlation_id'] )
			|| array( $ran_booster_show_create( $ran_booster_package_table ), $ran_booster_show_create( $ran_booster_attempt_table ) ) !== $schemas_before ) {
			throw new RuntimeException( 'The database smoke found mutation after a rejected schema version.' );
		}
	} finally {
		$ran_booster_set_schema_version( Database::$booster_db_version );
	}
};

try {
	if ( false === $wpdb->insert( $ran_booster_package_table, $ran_booster_schema_seven_package )
		|| false === $wpdb->insert( $ran_booster_attempt_table, $ran_booster_schema_seven_attempt ) ) {
		throw new RuntimeException( 'The database smoke could not seed hard-cut preservation rows.' );
	}
	$ran_booster_assert_rejected_version( '6.0', 'unsupported_old_schema' );
	$ran_booster_assert_rejected_version( '7.0', 'unsupported_old_schema' );
	$ran_booster_assert_rejected_version( '8.0', 'unsupported_old_schema' );
	$ran_booster_assert_rejected_version( '9.0', 'unsupported_old_schema' );
	$ran_booster_assert_rejected_version( '10.0', 'unsupported_old_schema' );
	$ran_booster_assert_rejected_version( '11.0', 'unsupported_old_schema' );
	$ran_booster_assert_rejected_version( '12.0', 'unsupported_old_schema' );
	$ran_booster_assert_rejected_version( '10.5', 'unknown_schema_version' );
	$ran_booster_assert_rejected_version( '14.0', 'newer_schema' );
	$ran_booster_assert_rejected_version( 'not-a-version', 'malformed_schema_version' );

	foreach ( array( $ran_booster_package_table, $ran_booster_attempt_table ) as $ran_booster_table ) {
		$ran_booster_table_status = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS WHERE Name = %s', $ran_booster_table ) );
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- MySQL SHOW TABLE STATUS returns the native Engine field.
		if ( ! is_object( $ran_booster_table_status ) || 0 !== strcasecmp( 'InnoDB', (string) ( $ran_booster_table_status->Engine ?? '' ) ) ) {
			throw new RuntimeException( 'The database smoke found a non-InnoDB Booster table.' );
		}
	}
} finally {
	$wpdb->delete( $ran_booster_package_table, array( 'package' => $ran_booster_schema_seven_package['package'] ) );
	$wpdb->delete( $ran_booster_attempt_table, array( 'correlation_id' => $ran_booster_schema_seven_attempt['correlation_id'] ) );
	$ran_booster_set_schema_version( Database::$booster_db_version );
}

$ran_booster_identifier   = 'ran-booster-database-smoke/ran-booster-database-smoke.php';
$ran_booster_directory    = WP_PLUGIN_DIR . '/ran-booster-database-smoke';
$ran_booster_fixture_path = WP_PLUGIN_DIR . '/' . $ran_booster_identifier;
if ( ! wp_mkdir_p( $ran_booster_directory ) ) {
	throw new RuntimeException( 'The database smoke could not create its disposable plugin directory.' );
}
$wpdb->delete(
	$ran_booster_attempt_table,
	array(
		'provider_repository_id' => 'database-smoke',
		'package_slug'           => 'ran-booster-database-smoke',
	)
);

try {
	$ran_booster_contents = "<?php\n/**\n * Plugin Name: RAN Booster Database Smoke\n * Version: 1.0.0\n */\n";
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Disposable CI fixture in the isolated WordPress checkout.
	if ( strlen( $ran_booster_contents ) !== file_put_contents( $ran_booster_fixture_path, $ran_booster_contents ) ) {
		throw new RuntimeException( 'The database smoke could not create its disposable plugin.' );
	}

	$ran_booster_packages       = $ran_booster_container->make( PluginRepository::class );
	$ran_booster_fixture_plugin = $ran_booster_packages->installed_plugin_from_file( $ran_booster_identifier );
	$ran_booster_fixture_plugin->set_repository( new ManagedRepository( 'gh', 'example/database-smoke', 'database-smoke', 'main' ) );
	$ran_booster_fixture_plugin->set_deployment_policy( DeploymentPolicy::DISABLED );
	$ran_booster_packages->store( $ran_booster_fixture_plugin )->require_success();
	$ran_booster_stored = $ran_booster_packages->booster_plugin_from_file( $ran_booster_identifier );
	if ( DeploymentPolicy::DISABLED !== $ran_booster_stored->get_deployment_policy() ) {
		throw new RuntimeException( 'The database smoke could not verify its package record.' );
	}
	$ran_booster_packages->unlink( $ran_booster_identifier )->require_success();

	$ran_booster_attempts = $ran_booster_container->make( DeploymentAttemptRepository::class );
	$ran_booster_request  = new DeploymentRequest(
		'example/database-smoke',
		null,
		false,
		'main',
		'ran-booster-database-smoke',
		null,
		DeploymentPolicy::DISABLED,
		1
	);
	$ran_booster_attempt  = $ran_booster_attempts->admit_and_claim_manual(
		'update',
		'plugin',
		'gh',
		'database-smoke',
		$ran_booster_request,
		'main',
		'branch',
		1
	);
	$ran_booster_finished = $ran_booster_attempts->finish( $ran_booster_attempt->get_id(), DeploymentOutcome::from_code( DeploymentOutcome::CODE_NO_CHANGE ) );
	if ( $ran_booster_finished->get_id() !== $ran_booster_attempts->find_exact( $ran_booster_attempt->get_id() )?->get_id() ) {
		throw new RuntimeException( 'The database smoke could not verify its attempt record.' );
	}
} finally {
	$wpdb->delete( $ran_booster_package_table, array( 'package' => $ran_booster_identifier ) );
	$wpdb->delete(
		$ran_booster_attempt_table,
		array(
			'provider_repository_id' => 'database-smoke',
			'package_slug'           => 'ran-booster-database-smoke',
		)
	);
	// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Removes only the disposable CI fixture created above.
	is_file( $ran_booster_fixture_path ) && unlink( $ran_booster_fixture_path );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Removes only the empty disposable CI fixture directory.
	is_dir( $ran_booster_directory ) && rmdir( $ran_booster_directory );
}

WP_CLI::success( 'RAN Booster database storage smoke passed on ' . $wpdb->db_server_info() . '.' ); // @phpstan-ignore class.notFound (External WP-CLI contract supplied by the installed eval-file process, outside the Composer-locked WordPress dependencies.)
