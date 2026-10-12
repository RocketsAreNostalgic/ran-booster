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
$ran_booster_race_filter = static function ( string $query ) use ( &$ran_booster_race_paused, $ran_booster_race_owner, $ran_booster_race_dir, $ran_booster_race_filesystem ): string {
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
		}
	}
	return $query;
};
add_filter( 'query', $ran_booster_race_filter );
$ran_booster_race_result = 'success';
try {
	if ( str_starts_with( $ran_booster_race_action, 'recovery-' ) ) {
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
