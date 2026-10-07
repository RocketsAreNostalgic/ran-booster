<?php

/** @var list<string> $args Arguments injected by WP-CLI eval-file. */

// Disposable-site integration proof for repository exclusivity.

use RAN\Deployment\DeploymentCoordinator;
use RAN\Deployment\DeploymentPolicy;
use RAN\ManagedRepository;
use RAN\PackageOperation;
use RAN\PackageSource;
use RAN\Storage\PluginRepository;
use RAN\Storage\Database;
use RAN\WordPress\ManagedReleaseConfiguration;
use RAN\WordPress\ManagedReleaseStore;

[$ran_booster_fixture_action, $ran_booster_run, $ran_booster_root_dir, $ran_booster_nested_dir, $ran_booster_archive] = array_pad( $args, 5, '' );
$ran_booster_protected = getenv( 'RAN_BOOSTER_PROTECTED_ROOT' );
$ran_booster_protected = is_string( $ran_booster_protected ) && '' !== trim( $ran_booster_protected ) ? realpath( $ran_booster_protected ) : false;
if ( ! in_array( $ran_booster_fixture_action, array( 'run', 'cleanup' ), true ) || preg_match( '/\Afixture-[a-f0-9]{16}\z/D', $ran_booster_run ) !== 1 ) {
	throw new RuntimeException( 'Invalid fixture proof arguments.' );
}
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! current_user_can( 'manage_options' )
	|| ! is_file( ABSPATH . '.ran-booster-disposable-test-site' )
	|| 'RAN Booster disposable test site' !== trim( (string) file_get_contents( ABSPATH . '.ran-booster-disposable-test-site' ) )
	|| ( false !== $ran_booster_protected && realpath( ABSPATH ) === $ran_booster_protected )
	|| is_link( WP_PLUGIN_DIR ) || is_link( WP_CONTENT_DIR ) ) {
	throw new RuntimeException( 'The exact disposable WordPress site is required.' );
}
$ran_booster_root_id   = basename( $ran_booster_root_dir ) . '/booster-fixture-plugin.php';
$ran_booster_nested_id = basename( $ran_booster_nested_dir ) . '/booster-fixture-branch.php';
$ran_booster_table     = ran_booster_table_name();
$ran_booster_assert    = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message ); // Fixed test assertion.
	}
	echo 'assertion: ' . esc_html( $message ) . "\n";
};
$ran_booster_clean     = static function () use ( $ran_booster_table, $ran_booster_root_id, $ran_booster_nested_id, $ran_booster_root_dir, $ran_booster_nested_dir ): void {
	global $wpdb;
	$wpdb->delete(
		$ran_booster_table,
		array(
			'type'    => 1,
			'package' => $ran_booster_root_id,
		)
	);
	$wpdb->delete(
		$ran_booster_table,
		array(
			'type'    => 1,
			'package' => $ran_booster_nested_id,
		)
	);
	if ( is_dir( $ran_booster_root_dir ) && ! is_link( $ran_booster_root_dir ) ) {
		$root_files = glob( $ran_booster_root_dir . '/*' );
		foreach ( false === $root_files ? array() : $root_files as $file ) {
			unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Disposable fixture cleanup.
		}
		rmdir( $ran_booster_root_dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Disposable fixture cleanup.
	}
	if ( is_dir( $ran_booster_nested_dir ) && ! is_link( $ran_booster_nested_dir ) ) {
		$nested_files = glob( $ran_booster_nested_dir . '/*' );
		foreach ( false === $nested_files ? array() : $nested_files as $file ) {
			unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Disposable fixture cleanup.
		}
		rmdir( $ran_booster_nested_dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Disposable fixture cleanup.
	}
};
if ( 'cleanup' === $ran_booster_fixture_action ) {
	$ran_booster_clean();
	return;
}

$ran_booster_pin = getenv( 'RAN_BOOSTER_EXCLUSIVITY_FIXTURE_PIN' );
$ran_booster_assert( '521faef4133822f42317b132ae39a2c57e1f82b1' === $ran_booster_pin, 'exact fixture pin supplied' );
$ran_booster_assert( is_file( $ran_booster_root_dir . '/booster-fixture-plugin.php' ) && is_file( $ran_booster_nested_dir . '/booster-fixture-branch.php' ), 'copied pinned fixture files exist' );
$ran_booster_root_hash = hash_file( 'sha256', $ran_booster_root_dir . '/booster-fixture-plugin.php' );
$ran_booster_zip       = new ZipArchive();
$ran_booster_assert( true === $ran_booster_zip->open( $ran_booster_archive ) && false !== $ran_booster_zip->locateName( 'booster-fixture-plugin.php' ) && false === $ran_booster_zip->locateName( 'branch-fixture/booster-fixture-branch.php' ), 'root release ZIP excludes nested branch fixture' );
$ran_booster_zip->close();

$ran_booster_container         = require __DIR__ . '/core-container-fixture.php';
$ran_booster_plugin_repository = $ran_booster_container->make( PluginRepository::class );
$ran_booster_deploy            = $ran_booster_container->make( DeploymentCoordinator::class );
$ran_booster_repository        = new ManagedRepository( 'gh', 'RocketsAreNostalgic/booster-fixture-plugin', '1315521150', 'main' );
$ran_booster_root              = $ran_booster_plugin_repository->installed_plugin_from_file( $ran_booster_root_id );
$ran_booster_root->set_repository( $ran_booster_repository );
$ran_booster_root->set_deployment_policy( DeploymentPolicy::DISABLED );
$ran_booster_root->set_source( PackageSource::RELEASE_ASSET, 1 );
$ran_booster_adoption = $ran_booster_plugin_repository->adopt_release( $ran_booster_root, new ManagedReleaseConfiguration( basename( $ran_booster_root_dir ), 'booster-fixture-plugin.php' ), 1 );
$ran_booster_assert( $ran_booster_adoption->is_successful(), 'root Release adoption succeeds: ' . $ran_booster_adoption->get_diagnostic_id() );

global $wpdb;
$ran_booster_attempt_table   = Database::attempt_table_name();
$ran_booster_before_attempts = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE provider_repository_id = %s', $ran_booster_attempt_table, '1315521150' ) );
$ran_booster_operation       = PackageOperation::from_input(
	'install-plugin',
	array(
		'provider'               => 'gh',
		'repository'             => 'RocketsAreNostalgic/booster-fixture-plugin',
		'provider_repository_id' => '1315521150',
		'branch'                 => 'main',
		'package_slug'           => basename( $ran_booster_nested_dir ),
		'deployment_policy'      => 'manual',
	)
);
$ran_booster_blocked         = false;
try {
	$ran_booster_result  = $ran_booster_deploy->execute_manual( $ran_booster_operation );
	$ran_booster_blocked = 'failed' === ( $ran_booster_result['status'] ?? null );
} catch ( RuntimeException ) {
	$ran_booster_blocked = true; }
$ran_booster_after_attempts = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE provider_repository_id = %s', $ran_booster_attempt_table, '1315521150' ) );
$ran_booster_assert( $ran_booster_blocked && $ran_booster_before_attempts === $ran_booster_after_attempts && hash_file( 'sha256', $ran_booster_root_dir . '/booster-fixture-plugin.php' ) === $ran_booster_root_hash, 'Release blocks nested Branch install before attempts or filesystem mutation' );

$ran_booster_nested = $ran_booster_plugin_repository->installed_plugin_from_file( $ran_booster_nested_id );
$ran_booster_nested->set_repository( $ran_booster_repository );
$ran_booster_nested->set_deployment_policy( DeploymentPolicy::DISABLED );
$ran_booster_assert( ! $ran_booster_plugin_repository->adopt( $ran_booster_nested )->is_successful(), 'installed nested Branch adoption is blocked by root Release' );
$ran_booster_assert( $ran_booster_plugin_repository->unlink( $ran_booster_root_id )->is_successful(), 'ordinary unlink removes root Release record' );
$ran_booster_root->set_source( PackageSource::BRANCH, 1 );
$ran_booster_assert( $ran_booster_plugin_repository->adopt( $ran_booster_root )->is_successful() && $ran_booster_plugin_repository->adopt( $ran_booster_nested )->is_successful(), 'root and nested Branch adoption both succeed' );
$ran_booster_store = new ManagedReleaseStore();
$ran_booster_assert( ! $ran_booster_store->transition( 'plugin', $ran_booster_root_id, PackageSource::BRANCH, 1, PackageSource::RELEASE_ASSET, new ManagedReleaseConfiguration( basename( $ran_booster_root_dir ), 'booster-fixture-plugin.php' ), 1 ), 'shared Branch repository refuses root Release transition' );
$ran_booster_assert( $ran_booster_plugin_repository->unlink( $ran_booster_nested_id )->is_successful(), 'ordinary unlink removes nested Branch record' );
$ran_booster_assert( $ran_booster_store->transition( 'plugin', $ran_booster_root_id, PackageSource::BRANCH, 1, PackageSource::RELEASE_ASSET, new ManagedReleaseConfiguration( basename( $ran_booster_root_dir ), 'booster-fixture-plugin.php' ), 1 ), 'sole root Branch transitions to Release' );
$ran_booster_assert( $ran_booster_store->transition( 'plugin', $ran_booster_root_id, PackageSource::RELEASE_ASSET, 2, PackageSource::BRANCH, null, 1 ), 'sole root Release returns to Branch' );
$ran_booster_assert( hash_file( 'sha256', $ran_booster_root_dir . '/booster-fixture-plugin.php' ) === $ran_booster_root_hash, 'root fixture bytes remain unchanged' );
$ran_booster_clean();
