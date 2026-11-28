<?php

use RAN\Deployment\DeploymentAttemptRepository;
use RAN\Deployment\DeploymentPolicy;
use RAN\Deployment\DeploymentRequest;
use RAN\Internal\CoreContainer;
use RAN\ManagedRepository;
use RAN\PackageRemoval\PackageRemovalService;
use RAN\Storage\Database;
use RAN\Storage\PluginRepository;

/** @var list<string> $args Arguments supplied by WP-CLI eval-file. */
[$ran_booster_race_action, $ran_booster_race_run, $ran_booster_race_dir] = array_pad( $args, 3, '' );
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || '1' !== getenv( 'RAN_BOOSTER_RECOVERY_TEST_DISPOSABLE' )
	|| 1 !== preg_match( '/^[a-f0-9]{12}$/D', $ran_booster_race_run )
	|| realpath( ABSPATH ) !== realpath( (string) getenv( 'RAN_BOOSTER_WORDPRESS_PATH' ) )
	|| realpath( WP_CONTENT_DIR ) !== realpath( ABSPATH ) . '/wp-content'
	|| realpath( WP_PLUGIN_DIR ) !== realpath( ABSPATH ) . '/wp-content/plugins'
	|| is_link( WP_CONTENT_DIR ) || is_link( WP_PLUGIN_DIR )
	|| get_option( 'siteurl' ) !== getenv( 'RAN_BOOSTER_RECOVERY_TEST_URL' )
	|| is_link( ABSPATH . '.ran-booster-disposable-test-site' )
	|| "RAN Booster disposable test site\n" !== file_get_contents( ABSPATH . '.ran-booster-disposable-test-site' )
	|| ! str_starts_with( $ran_booster_race_dir, sys_get_temp_dir() . '/ran-recovery-race.' )
	|| realpath( $ran_booster_race_dir ) !== $ran_booster_race_dir || is_link( $ran_booster_race_dir ) ) {
	throw new RuntimeException( 'The race proof requires exact disposable site and marker boundaries.' );
}
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
$ran_booster_race_container = require __DIR__ . '/core-container-fixture.php';
if ( ! $ran_booster_race_container instanceof CoreContainer ) {
	throw new RuntimeException( 'Core is unavailable.' );
}
$ran_booster_race_filesystem = new WP_Filesystem_Direct( null );
$ran_booster_race_slug       = 'ran-recovery-race-' . $ran_booster_race_run;
$ran_booster_race_identifier = $ran_booster_race_slug . '/entry.php';
$ran_booster_race_path       = WP_PLUGIN_DIR . '/' . $ran_booster_race_slug;
$ran_booster_race_key        = 'ran_recovery_race_' . $ran_booster_race_run;
$ran_booster_race_plugins    = $ran_booster_race_container->make( PluginRepository::class );
$ran_booster_race_removal    = $ran_booster_race_container->make( PackageRemovalService::class );
$ran_booster_race_attempts   = $ran_booster_race_container->make( DeploymentAttemptRepository::class );
global $wpdb;
$ran_booster_wait_for_peer_contention = static function ( string $dir, WP_Filesystem_Direct $filesystem, string $expected_info_fragment ): void {
	$attempting_value = $filesystem->get_contents( $dir . '/attempting' );
	if ( ! is_string( $attempting_value ) || 1 !== preg_match( '/\A[1-9]\d*\z/D', trim( $attempting_value ) ) ) {
		return;
	}
	$peer_id = (int) trim( $attempting_value );
	if ( ! defined( 'DB_USER' ) || ! defined( 'DB_PASSWORD' ) || ! defined( 'DB_NAME' ) || ! defined( 'DB_HOST' )
		|| ! is_string( DB_USER ) || ! is_string( DB_PASSWORD ) || ! is_string( DB_NAME ) || ! is_string( DB_HOST ) ) {
		throw new RuntimeException( 'Race peer contention DB constants are unavailable.' );
	}
	global $wpdb;
	$host_parts = $wpdb->parse_db_host( DB_HOST );
	if ( ! is_array( $host_parts ) ) {
		throw new RuntimeException( 'Race peer contention DB host could not be parsed.' );
	}
	$port   = is_string( $host_parts[1] ) && '' !== $host_parts[1] ? (int) $host_parts[1] : null;
	$socket = is_string( $host_parts[2] ) && '' !== $host_parts[2] ? $host_parts[2] : null;
	// phpcs:ignore WordPress.DB.RestrictedClasses.mysql__mysqli -- Observe peer PROCESSLIST lock contention on a raw mysqli session without invoking WordPress query hooks inside an active owner transaction.
	$control = new \mysqli( $host_parts[0], DB_USER, DB_PASSWORD, DB_NAME, $port, $socket );
	try {
		$peer_deadline = microtime( true ) + 10.0;
		$contending    = false;
		$escaped_like  = $control->real_escape_string( '%' . str_replace( array( '\\', '%', '_' ), array( '\\\\', '\\%', '\\_' ), $expected_info_fragment ) . '%' );
		while ( microtime( true ) < $peer_deadline ) {
			$alive_result = $control->query( 'SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE ID = ' . $peer_id );
			// phpcs:ignore WordPress.DB.RestrictedClasses.mysql__mysqli -- Read single-scalar PROCESSLIST count from mysqli_result.
			if ( ! $alive_result instanceof \mysqli_result ) {
				throw new RuntimeException( 'Race peer PROCESSLIST query failed.' );
			}
			$alive_row = $alive_result->fetch_row();
			$alive_result->free();
			if ( 0 === (int) ( $alive_row[0] ?? 0 ) ) {
				throw new RuntimeException( 'Race peer exited before lock contention.' );
			}
			$waiting_result = $control->query(
				'SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE ID = ' . $peer_id . " AND COMMAND = 'Query' AND INFO LIKE '" . $escaped_like . "' AND INFO LIKE '%FOR UPDATE%'"
			);
			// phpcs:ignore WordPress.DB.RestrictedClasses.mysql__mysqli -- Read single-scalar PROCESSLIST contention count from mysqli_result.
			if ( ! $waiting_result instanceof \mysqli_result ) {
				throw new RuntimeException( 'Race peer contention query failed.' );
			}
			$waiting_row = $waiting_result->fetch_row();
			$waiting_result->free();
			if ( (int) ( $waiting_row[0] ?? 0 ) >= 1 ) {
				$contending = true;
				break;
			}
			usleep( 10000 );
		}
		if ( ! $contending || is_file( $dir . '/peer-result' ) ) {
			throw new RuntimeException( 'Race peer did not block on the management-row lock.' );
		}
	} finally {
		$control->close();
	}
};
// Complete real bootstrap before the owner can hold the management row. Otherwise
// the peer blocks before this fixture can observe its intended transaction boundary.
if ( str_ends_with( $ran_booster_race_action, '-peer' ) ) {
	if ( ! $ran_booster_race_filesystem->put_contents( $ran_booster_race_dir . '/bootstrapped', 'ready', 0600 ) ) {
		throw new RuntimeException( 'Race peer bootstrap barrier unavailable.' );
	}
	$ran_booster_race_deadline = microtime( true ) + 20;
	while ( ! is_file( $ran_booster_race_dir . '/start-peer' ) ) {
		if ( microtime( true ) > $ran_booster_race_deadline ) {
			throw new RuntimeException( 'Race peer bootstrap barrier timed out.' );
		}
		usleep( 20000 );
	}
}
if ( 'prepare' === $ran_booster_race_action ) {
	if ( file_exists( $ran_booster_race_path ) || is_link( $ran_booster_race_path ) || ! wp_mkdir_p( $ran_booster_race_path )
		|| ! $ran_booster_race_filesystem->put_contents( $ran_booster_race_path . '/entry.php', "<?php\n/* Plugin Name: RAN recovery race\nVersion: 1.0\n*/\n", 0644 ) ) {
		throw new RuntimeException( 'Race fixture target is unavailable.' );
	}
	wp_clean_plugins_cache( false );
	$ran_booster_race_package = $ran_booster_race_plugins->installed_plugin_from_file( $ran_booster_race_identifier );
	$ran_booster_race_package->set_repository( new ManagedRepository( 'gh', 'example/' . $ran_booster_race_slug, $ran_booster_race_slug, 'main' ) );
	$ran_booster_race_plugins->adopt( $ran_booster_race_package )->require_success();
	update_option( $ran_booster_race_key, $ran_booster_race_removal->recovery_confirmation( 'plugin', $ran_booster_race_identifier ), false );

	return;
}
if ( 'cleanup' === $ran_booster_race_action ) {
	if ( realpath( $ran_booster_race_path ) !== $ran_booster_race_path || is_link( $ran_booster_race_path ) ) {
		throw new RuntimeException( 'Unsafe race cleanup target.' );
	}
	$wpdb->delete( Database::attempt_table_name(), array( 'package_slug' => $ran_booster_race_slug ) );
	$wpdb->delete(
		ran_booster_table_name(),
		array(
			'package'      => $ran_booster_race_identifier,
			'package_type' => 1,
		)
	);
	delete_option( $ran_booster_race_key );
	if ( true !== delete_plugins( array( $ran_booster_race_identifier ) ) ) {
		throw new RuntimeException( 'Race fixture cleanup failed.' );
	}
	return;
}
if ( 'assert-recovery' === $ran_booster_race_action || 'assert-admission' === $ran_booster_race_action ) {
	$ran_booster_race_owner   = $ran_booster_race_filesystem->get_contents( $ran_booster_race_dir . '/owner-result' );
	$ran_booster_race_peer    = $ran_booster_race_filesystem->get_contents( $ran_booster_race_dir . '/peer-result' );
	$ran_booster_race_present = $ran_booster_race_plugins->has_management_record( $ran_booster_race_identifier );
	$ran_booster_race_count   = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE package_slug = %s', Database::attempt_table_name(), $ran_booster_race_slug ) );
	if ( 'success' !== $ran_booster_race_owner || 'rejected' !== $ran_booster_race_peer
		|| ( 'assert-recovery' === $ran_booster_race_action ? $ran_booster_race_present || 0 !== $ran_booster_race_count : ! $ran_booster_race_present || 1 !== $ran_booster_race_count ) ) {
		throw new RuntimeException( 'Admission/recovery transaction ordering failed.' );
	}
	echo esc_html( $ran_booster_race_action . ': exact management-row election passed.' ) . PHP_EOL;
	return;
}
$ran_booster_race_confirmation = get_option( $ran_booster_race_key );
if ( ! is_array( $ran_booster_race_confirmation )
	|| ! is_string( $ran_booster_race_confirmation['adoption_token'] ?? null )
	|| ! is_string( $ran_booster_race_confirmation['digest'] ?? null )
	|| ! is_string( $ran_booster_race_confirmation['installed_digest'] ?? null ) ) {
	throw new RuntimeException( 'Race confirmation is unavailable.' );
}
$ran_booster_race_owner  = str_ends_with( $ran_booster_race_action, '-owner' );
$ran_booster_race_paused = false;
$ran_booster_race_filter = static function ( string $query ) use ( &$ran_booster_race_paused, $ran_booster_race_owner, $ran_booster_race_dir, $ran_booster_race_filesystem, $ran_booster_wait_for_peer_contention ): string {
	$target = $ran_booster_race_owner ? Database::attempt_table_name() : ran_booster_table_name();
	if ( ! $ran_booster_race_paused && str_contains( $query, $target ) && str_contains( $query, 'FOR UPDATE' ) ) {
		$ran_booster_race_paused = true;
		if ( ! $ran_booster_race_filesystem->put_contents( $ran_booster_race_dir . ( $ran_booster_race_owner ? '/locked' : '/attempting' ), 'ready', 0600 ) ) {
			throw new RuntimeException( 'Race barrier unavailable.' );
		}
		if ( $ran_booster_race_owner ) {
			// Both owners reached attempt access only after their actual management SELECT FOR UPDATE returned.
			$deadline = microtime( true ) + 20;
			while ( ! is_file( $ran_booster_race_dir . '/release' ) ) {
				if ( microtime( true ) > $deadline ) {
					throw new RuntimeException( 'Race owner barrier timed out.' );
				}
				usleep( 20000 );
			}
			$ran_booster_wait_for_peer_contention( $ran_booster_race_dir, $ran_booster_race_filesystem, ran_booster_table_name() );
		}
	}
	return $query;
};
add_filter( 'query', $ran_booster_race_filter );
$ran_booster_race_result = 'success';
try {
	if ( str_starts_with( $ran_booster_race_action, 'recovery-' ) ) {
		if ( ! $ran_booster_race_owner ) {
			$ran_booster_race_peer_id = (int) $wpdb->get_var( 'SELECT CONNECTION_ID()' );
			if ( $ran_booster_race_peer_id <= 0 || ! $ran_booster_race_filesystem->put_contents( $ran_booster_race_dir . '/attempting', (string) $ran_booster_race_peer_id, 0600 ) ) {
				throw new RuntimeException( 'Recovery peer race barrier unavailable.' );
			}
			$ran_booster_race_paused = true;
		}
		$ran_booster_race_removal->recover( 'plugin', $ran_booster_race_identifier, $ran_booster_race_confirmation['adoption_token'], $ran_booster_race_confirmation['digest'], $ran_booster_race_confirmation['installed_digest'] );
	} elseif ( str_starts_with( $ran_booster_race_action, 'admission-' ) ) {
		$ran_booster_race_request = new DeploymentRequest( 'example/' . $ran_booster_race_slug, null, false, 'main', $ran_booster_race_slug, null, DeploymentPolicy::MANUAL, null, installed_identifier: $ran_booster_race_identifier, adoption_token: $ran_booster_race_confirmation['adoption_token'] );
		$ran_booster_race_attempts->admit_and_claim_manual( 'update', 'plugin', 'gh', $ran_booster_race_slug, $ran_booster_race_request, 'main', 'branch', 1 );
	} else {
		throw new RuntimeException( 'Unknown race operation.' );
	}
} catch ( Throwable ) {
	$ran_booster_race_result = 'rejected';
} finally {
	remove_filter( 'query', $ran_booster_race_filter );
}
if ( ! $ran_booster_race_paused || ! $ran_booster_race_filesystem->put_contents( $ran_booster_race_dir . ( $ran_booster_race_owner ? '/owner-result' : '/peer-result' ), $ran_booster_race_result, 0600 ) ) {
	throw new RuntimeException( 'Race process did not reach the required management-row schedule.' );
}
