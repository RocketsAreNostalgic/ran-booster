<?php

use RAN\Activity\ActivityStore;
use RAN\Admin\OnboardingPresenter;
use RAN\Deployment\DeploymentPolicy;
use RAN\ManagedRepository;
use RAN\Storage\PluginRepository;
use RAN\Storage\ThemeRepository;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	throw new RuntimeException( 'This proof must run through WP-CLI.' );
}
$ran_booster_overview_root = realpath( ABSPATH );
$ran_booster_overview_url  = getenv( 'RAN_BOOSTER_RECOVERY_TEST_URL' );
if ( false === $ran_booster_overview_url || '' === $ran_booster_overview_url ) {
	$ran_booster_overview_url = 'http://localhost';
}
if ( ! is_string( $ran_booster_overview_root ) || realpath( (string) getenv( 'RAN_BOOSTER_WORDPRESS_PATH' ) ) !== $ran_booster_overview_root
	|| is_link( rtrim( ABSPATH, '/' ) ) || is_link( WP_CONTENT_DIR ) || is_link( WP_PLUGIN_DIR )
	|| realpath( WP_CONTENT_DIR ) !== $ran_booster_overview_root . '/wp-content' || realpath( WP_PLUGIN_DIR ) !== $ran_booster_overview_root . '/wp-content/plugins'
	|| get_option( 'siteurl' ) !== $ran_booster_overview_url || get_option( 'home' ) !== $ran_booster_overview_url
	|| is_link( ABSPATH . '.ran-booster-disposable-test-site' ) || ! is_file( ABSPATH . '.ran-booster-disposable-test-site' )
	|| "RAN Booster disposable test site\n" !== file_get_contents( ABSPATH . '.ran-booster-disposable-test-site' ) ) {
	throw new RuntimeException( 'Overview proof requires the exact marked disposable site and expected URL.' );
}
require_once ABSPATH . 'wp-admin/includes/plugin.php';
$ran_booster_overview_user_ids = array();
foreach ( array( 'overview_admin_one', 'overview_admin_two' ) as $ran_booster_overview_login ) {
	$ran_booster_overview_user = get_user_by( 'login', $ran_booster_overview_login );
	if ( ! $ran_booster_overview_user instanceof WP_User || ! user_can( $ran_booster_overview_user, 'manage_options' ) ) {
		throw new RuntimeException( 'Both synthetic administrators are required.' );
	}
	$ran_booster_overview_user_ids[] = $ran_booster_overview_user->ID;
}
$ran_booster_overview_plugins  = new PluginRepository();
$ran_booster_overview_themes   = new ThemeRepository();
$ran_booster_overview_activity = new ActivityStore();
if ( array() !== $ran_booster_overview_plugins->all_booster_plugins() || array() !== $ran_booster_overview_themes->all_booster_themes() ) {
	throw new RuntimeException( 'Overview proof requires empty managed inventories.' );
}
$ran_booster_overview_dir  = WP_PLUGIN_DIR . '/ran-overview-568-proof';
$ran_booster_overview_id   = 'ran-overview-568-proof/ran-overview-568-proof.php';
$ran_booster_overview_file = WP_PLUGIN_DIR . '/' . $ran_booster_overview_id;
if ( file_exists( $ran_booster_overview_dir ) || is_link( $ran_booster_overview_dir ) || $ran_booster_overview_plugins->has_management_record( $ran_booster_overview_id ) ) {
	throw new RuntimeException( 'Overview fixture path already exists.' );
}
$ran_booster_overview_absent = new stdClass();
$ran_booster_overview_choice = get_option( OnboardingPresenter::PRESENTATION_OPTION, $ran_booster_overview_absent );
$ran_booster_overview_actor  = get_current_user_id();
$ran_booster_overview_tabs   = array();
foreach ( array( 'portability', 'documentation', 'troubleshooting' ) as $ran_booster_overview_key ) {
	$ran_booster_overview_tabs[] = array(
		'key'      => $ran_booster_overview_key,
		'label'    => $ran_booster_overview_key,
		'url'      => admin_url(),
		'active'   => false,
		'provider' => false,
	);
}
$ran_booster_overview_presenter = new OnboardingPresenter();
$ran_booster_overview_storage   = array( 'state' => 'unknown' );
$ran_booster_overview_assert    = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};
$ran_booster_overview_build     = static function ( PluginRepository $plugins ) use ( $ran_booster_overview_presenter, $ran_booster_overview_tabs, $ran_booster_overview_themes, $ran_booster_overview_activity, $ran_booster_overview_storage, $ran_booster_overview_assert ): array {
	$names  = array( OnboardingPresenter::PRESENTATION_OPTION, 'ran_booster_db_version', 'ran_booster_activity_schema', 'ran_booster_credential_expiry_observations', 'home', 'siteurl' );
	$before = array_map( static fn ( string $name ): mixed => get_option( $name ), $names );
	$result = $ran_booster_overview_presenter->build_overview( $ran_booster_overview_tabs, admin_url(), admin_url(), $plugins, $ran_booster_overview_themes, $ran_booster_overview_activity, $ran_booster_overview_storage );
	$after  = array_map( static fn ( string $name ): mixed => get_option( $name ), $names );
	$ran_booster_overview_assert( $before === $after, 'Overview projection changed stored options.' );
	$ran_booster_overview_assert( $ran_booster_overview_storage === $result['storage_summary'], 'Overview changed the supplied storage summary.' );
	return $result;
};
$ran_booster_overview_created   = false;
$ran_booster_overview_stored    = false;
try {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Unique private disposable fixture directory.
	if ( ! mkdir( $ran_booster_overview_dir, 0700 ) ) {
		throw new RuntimeException( 'Could not create Overview fixture directory.' );
	}
	$ran_booster_overview_created  = true;
	$ran_booster_overview_contents = "<?php\n/**\n * Plugin Name: RAN Overview 568 Proof\n * Version: 1.0.0\n */\n";
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Private disposable fixture only.
	if ( strlen( $ran_booster_overview_contents ) !== file_put_contents( $ran_booster_overview_file, $ran_booster_overview_contents ) ) {
		throw new RuntimeException( 'Could not create Overview fixture plugin.' );
	}
	wp_clean_plugins_cache( false );
	delete_option( OnboardingPresenter::PRESENTATION_OPTION );
	$ran_booster_overview_result = $ran_booster_overview_build( $ran_booster_overview_plugins );
	$ran_booster_overview_assert( 'dashboard' === $ran_booster_overview_result['view'] && 0 === $ran_booster_overview_result['inventory']['plugins'] && 0 === $ran_booster_overview_result['inventory']['themes'] && $ran_booster_overview_result['inventory']['complete'], 'Unmanaged installed plugin must not count as managed.' );
	$ran_booster_overview_assert( $ran_booster_overview_result['activity']['available'], 'Installed Activity must be available.' );
	$ran_booster_overview_package = $ran_booster_overview_plugins->installed_plugin_from_file( $ran_booster_overview_id );
	$ran_booster_overview_package->set_repository( new ManagedRepository( 'gh', 'example/overview-proof', 'overview-proof', 'main' ) );
	$ran_booster_overview_package->set_deployment_policy( DeploymentPolicy::DISABLED );
	$ran_booster_overview_stored = true;
	$ran_booster_overview_plugins->store( $ran_booster_overview_package )->require_success();
	$ran_booster_overview_result = $ran_booster_overview_build( $ran_booster_overview_plugins );
	$ran_booster_overview_assert( ! is_plugin_active( $ran_booster_overview_id ) && 1 === $ran_booster_overview_result['inventory']['plugins'] && 1 === $ran_booster_overview_result['inventory']['disabled'] && 'dashboard' === $ran_booster_overview_result['view'], 'Inactive disabled managed plugin must count.' );
	wp_set_current_user( $ran_booster_overview_user_ids[0] );
	update_option( OnboardingPresenter::PRESENTATION_OPTION, 'onboarding', false );
	wp_set_current_user( $ran_booster_overview_user_ids[1] );
	$ran_booster_overview_assert( 'onboarding' === $ran_booster_overview_build( $ran_booster_overview_plugins )['view'], 'Onboarding choice must be shared.' );
	update_option( OnboardingPresenter::PRESENTATION_OPTION, 'dashboard', false );
	wp_set_current_user( $ran_booster_overview_user_ids[0] );
	$ran_booster_overview_assert( 'dashboard' === $ran_booster_overview_build( $ran_booster_overview_plugins )['view'], 'Dashboard choice must be shared.' );
	$ran_booster_overview_unavailable = new class() extends PluginRepository {
		public function all_booster_plugins() {
			throw new RuntimeException( 'Synthetic inventory failure.' );
		}
	};
	$ran_booster_overview_result      = $ran_booster_overview_build( $ran_booster_overview_unavailable );
	$ran_booster_overview_assert( null === $ran_booster_overview_result['inventory']['plugins'] && ! $ran_booster_overview_result['inventory']['complete'] && null === $ran_booster_overview_result['inventory']['disabled'], 'Unavailable inventory must remain unknown.' );
	echo "Overview installed projection proof passed; inventory failure used a synthetic repository.\n";
} finally {
	global $wpdb;
	if ( $ran_booster_overview_stored ) {
		$wpdb->delete(
			ran_booster_table_name(),
			array(
				'package'      => $ran_booster_overview_id,
				'package_type' => 1,
			)
		);
	}
	if ( $ran_booster_overview_created ) {
		if ( is_file( $ran_booster_overview_file ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Remove only the fixture created in this run.
			unlink( $ran_booster_overview_file );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Remove only the fixture directory created in this run.
		rmdir( $ran_booster_overview_dir );
		wp_clean_plugins_cache( false );
	}
	if ( $ran_booster_overview_choice === $ran_booster_overview_absent ) {
		delete_option( OnboardingPresenter::PRESENTATION_OPTION );
	} else {
		update_option( OnboardingPresenter::PRESENTATION_OPTION, $ran_booster_overview_choice, false );
	}
	wp_set_current_user( $ran_booster_overview_actor );
}
