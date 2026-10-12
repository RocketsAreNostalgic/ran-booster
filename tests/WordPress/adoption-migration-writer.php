<?php

use RAN\ManagedRepository;
use RAN\Storage\Database;
use RAN\Storage\PluginRepository;

/** @var list<string> $args WP-CLI fixture arguments. */
[$ran_booster_writer_action, $ran_booster_writer_run, $ran_booster_writer_dir] = array_pad( $args, 3, '' );
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || '1' !== getenv( 'RAN_BOOSTER_RECOVERY_TEST_DISPOSABLE' )
	|| 1 !== preg_match( '/^[a-f0-9]{12}$/D', $ran_booster_writer_run )
	|| realpath( ABSPATH ) !== realpath( (string) getenv( 'RAN_BOOSTER_WORDPRESS_PATH' ) )
	|| realpath( WP_CONTENT_DIR ) !== realpath( ABSPATH ) . '/wp-content'
	|| realpath( WP_PLUGIN_DIR ) !== realpath( ABSPATH ) . '/wp-content/plugins'
	|| is_link( WP_CONTENT_DIR ) || is_link( WP_PLUGIN_DIR )
	|| get_option( 'siteurl' ) !== getenv( 'RAN_BOOSTER_RECOVERY_TEST_URL' )
	|| is_link( ABSPATH . '.ran-booster-disposable-test-site' )
	|| "RAN Booster disposable test site\n" !== file_get_contents( ABSPATH . '.ran-booster-disposable-test-site' )
	|| ! str_starts_with( $ran_booster_writer_dir, sys_get_temp_dir() . '/ran-adoption-migration.' )
	|| realpath( $ran_booster_writer_dir ) !== $ran_booster_writer_dir || is_link( $ran_booster_writer_dir ) ) {
	throw new RuntimeException( 'The migration writer proof requires the exact isolated disposable site.' );
}
// Each process loads one untouched source generation; no old bootstrap hooks are activated.
$ran_booster_writer_autoload = 'old-writer' === $ran_booster_writer_action ? $ran_booster_writer_dir . '/old/autoload.php' : WP_PLUGIN_DIR . '/ran-booster/autoload.php';
require $ran_booster_writer_autoload;
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
function ran_booster_table_name(): string {
	global $wpdb;
	return $wpdb->prefix . 'ran_booster_packages';
}
$ran_booster_writer_filesystem = new WP_Filesystem_Direct( null );
$ran_booster_writer_slug       = 'ran-migration-' . $ran_booster_writer_run;
$ran_booster_writer_id         = $ran_booster_writer_slug . '/entry.php';
$ran_booster_writer_path       = WP_PLUGIN_DIR . '/' . $ran_booster_writer_slug;
$ran_booster_writer_database   = new Database();
$ran_booster_writer_repository = new PluginRepository( $ran_booster_writer_database );
global $wpdb;
if ( 'prepare' === $ran_booster_writer_action ) {
	$ran_booster_writer_database->install();
	if ( 0 !== (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', ran_booster_table_name() ) )
		|| file_exists( $ran_booster_writer_path ) || is_link( $ran_booster_writer_path ) ) {
		throw new RuntimeException( 'The schema fixture refuses unrelated management records or an existing target.' );
	}
	if ( ! wp_mkdir_p( $ran_booster_writer_path ) || ! $ran_booster_writer_filesystem->put_contents( $ran_booster_writer_path . '/entry.php', "<?php\n/* Plugin Name: RAN migration writer\nVersion: 1.0\n*/\n", 0644 ) ) {
		throw new RuntimeException( 'The migration fixture file could not be created.' );
	}
	if ( false === $wpdb->query( $wpdb->prepare( 'ALTER TABLE %i CHANGE COLUMN package_type type tinyint(3) unsigned NOT NULL, DROP COLUMN adoption_token', ran_booster_table_name() ) ) ) {
		throw new RuntimeException( 'The exact schema 13 predecessor could not be restored.' );
	}
	update_option( Database::VERSION_OPTION, '13.0', false );
	return;
}
if ( 'old-writer' === $ran_booster_writer_action ) {
	if ( '13.0' !== Database::$booster_db_version ) {
		throw new RuntimeException( 'The pinned predecessor source was not loaded.' );
	}
	$ran_booster_writer_database->require_ready();
	if ( ! $ran_booster_writer_filesystem->put_contents( $ran_booster_writer_dir . '/ready', 'old schema readiness completed', 0600 ) ) {
		throw new RuntimeException( 'The old writer barrier could not be established.' );
	}
	$ran_booster_writer_deadline = microtime( true ) + 30;
	while ( ! is_file( $ran_booster_writer_dir . '/release' ) ) {
		if ( microtime( true ) > $ran_booster_writer_deadline ) {
			throw new RuntimeException( 'The delayed old writer timed out.' );
		}
		usleep( 20000 );
	}
	wp_clean_plugins_cache( false );
	$ran_booster_writer_package = $ran_booster_writer_repository->installed_plugin_from_file( $ran_booster_writer_id );
	$ran_booster_writer_package->set_repository( new ManagedRepository( 'gh', 'example/' . $ran_booster_writer_slug, $ran_booster_writer_slug, 'main' ) );
	$ran_booster_writer_rejected = false;
	$ran_booster_writer_errors   = $wpdb->suppress_errors( true );
	try {
		try {
			$ran_booster_writer_rejected = ! $ran_booster_writer_repository->adopt( $ran_booster_writer_package )->is_successful();
		} catch ( \RAN\Storage\PackageStorageFailure ) {
			$ran_booster_writer_rejected = true;
		}
		// The old service may reject at its first SELECT. Independently exercise its legacy INSERT shape.
		$ran_booster_writer_insert       = $wpdb->query(
			$wpdb->prepare(
				'INSERT INTO %i (package, type, repository, provider, provider_repository_id, private) VALUES (%s, 1, %s, %s, %s, 0)',
				ran_booster_table_name(),
				$ran_booster_writer_id,
				'example/' . $ran_booster_writer_slug,
				'gh',
				$ran_booster_writer_slug
			)
		);
		$ran_booster_writer_server_error = $wpdb->get_row( 'SHOW WARNINGS', ARRAY_A );
		if ( ! $ran_booster_writer_rejected || false !== $ran_booster_writer_insert || ! is_array( $ran_booster_writer_server_error ) || '1054' !== (string) ( $ran_booster_writer_server_error['Code'] ?? '' ) ) {
			throw new RuntimeException( 'The migrated schema did not fence the old writer and its tokenless INSERT.' );
		}
	} finally {
		$wpdb->suppress_errors( $ran_booster_writer_errors );
	}
	if ( ! $ran_booster_writer_filesystem->put_contents( $ran_booster_writer_dir . '/rejected', 'old service and INSERT rejected', 0600 ) ) {
		throw new RuntimeException( 'The old rejection evidence could not be recorded.' );
	}
	return;
}
if ( 'migrate' === $ran_booster_writer_action ) {
	$ran_booster_writer_database->install();
	if ( '14.0' !== get_option( Database::VERSION_OPTION ) ) {
		throw new RuntimeException( 'The current migration did not finish.' );
	}
	return;
}
if ( 'assert-current' === $ran_booster_writer_action ) {
	if ( ! is_file( $ran_booster_writer_dir . '/rejected' )
		|| 0 !== (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', ran_booster_table_name() ) ) ) {
		throw new RuntimeException( 'The delayed predecessor left management data after migration.' );
	}
	wp_clean_plugins_cache( false );
	$ran_booster_writer_package = $ran_booster_writer_repository->installed_plugin_from_file( $ran_booster_writer_id );
	$ran_booster_writer_package->set_repository( new ManagedRepository( 'gh', 'example/' . $ran_booster_writer_slug, $ran_booster_writer_slug, 'main' ) );
	$ran_booster_writer_repository->adopt( $ran_booster_writer_package )->require_success();
	$ran_booster_writer_token = $ran_booster_writer_repository->booster_plugin_from_file( $ran_booster_writer_id )->get_adoption_token();
	if ( ! is_string( $ran_booster_writer_token ) || ! \RAN\AdoptionIdentity::matches( $ran_booster_writer_token, 'gh', $ran_booster_writer_slug ) ) {
		throw new RuntimeException( 'A current writer failed to create its valid adoption identity.' );
	}
	echo "Pinned schema13 writer and legacy INSERT rejected after migration; current adoption succeeds with a valid token.\n";
	return;
}
if ( 'cleanup' === $ran_booster_writer_action ) {
	// A failed pre-migration process must still leave this disposable site at its supported schema.
	( new Database() )->install();
	$wpdb->delete( ran_booster_table_name(), array( 'package' => $ran_booster_writer_id ) );
	if ( realpath( $ran_booster_writer_path ) !== $ran_booster_writer_path || is_link( $ran_booster_writer_path )
		|| true !== delete_plugins( array( $ran_booster_writer_id ) ) ) {
		throw new RuntimeException( 'The exact migration fixture could not be cleaned up.' );
	}
	return;
}
throw new RuntimeException( 'Unknown migration fixture operation.' );
