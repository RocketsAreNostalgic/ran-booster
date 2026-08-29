<?php

// Disposable-site integration proof for repository exclusivity. phpcs:disable

use RAN\Deployment\DeploymentCoordinator;
use RAN\Deployment\DeploymentPolicy;
use RAN\ManagedRepository;
use RAN\PackageOperation;
use RAN\PackageSource;
use RAN\Storage\PluginRepository;
use RAN\Storage\Database;
use RAN\WordPress\ManagedReleaseConfiguration;
use RAN\WordPress\ManagedReleaseStore;

[$fixture_action, $run, $root_dir, $nested_dir, $archive] = array_pad( $args, 5, '' );
$protected = getenv( 'RAN_BOOSTER_PROTECTED_ROOT' );
$protected = is_string( $protected ) && '' !== trim( $protected ) ? realpath( $protected ) : false;
if ( ! in_array( $fixture_action, array( 'run', 'cleanup' ), true ) || preg_match( '/\Afixture-[a-f0-9]{16}\z/D', $run ) !== 1 ) {
	throw new RuntimeException( 'Invalid fixture proof arguments.' );
}
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! current_user_can( 'manage_options' )
	|| ! is_file( ABSPATH . '.ran-booster-disposable-test-site' )
	|| 'RAN Booster disposable test site' !== trim( (string) file_get_contents( ABSPATH . '.ran-booster-disposable-test-site' ) )
	|| ( false !== $protected && realpath( ABSPATH ) === $protected )
	|| is_link( WP_PLUGIN_DIR ) || is_link( WP_CONTENT_DIR ) ) {
	throw new RuntimeException( 'The exact disposable WordPress site is required.' );
}
$root_id   = basename( $root_dir ) . '/booster-fixture-plugin.php';
$nested_id = basename( $nested_dir ) . '/booster-fixture-branch.php';
$table     = ran_booster_table_name();
$assert    = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Fixed test assertion.
	}
	echo 'assertion: ' . esc_html( $message ) . "\n";
};
$clean     = static function () use ( $table, $root_id, $nested_id, $root_dir, $nested_dir ): void {
	global $wpdb;
	$wpdb->delete(
		$table,
		array(
			'type'    => 1,
			'package' => $root_id,
		)
	);
	$wpdb->delete(
		$table,
		array(
			'type'    => 1,
			'package' => $nested_id,
		)
	);
	if ( is_dir( $root_dir ) && ! is_link( $root_dir ) ) {
		$root_files = glob( $root_dir . '/*' );
		foreach ( false === $root_files ? array() : $root_files as $file ) {
			unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Disposable fixture cleanup.
		}
		rmdir( $root_dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Disposable fixture cleanup.
	}
	if ( is_dir( $nested_dir ) && ! is_link( $nested_dir ) ) {
		$nested_files = glob( $nested_dir . '/*' );
		foreach ( false === $nested_files ? array() : $nested_files as $file ) {
			unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Disposable fixture cleanup.
		}
		rmdir( $nested_dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Disposable fixture cleanup.
	}
};
if ( 'cleanup' === $fixture_action ) {
	$clean();
	return;
}

$pin = getenv( 'RAN_BOOSTER_EXCLUSIVITY_FIXTURE_PIN' );
$assert( '521faef4133822f42317b132ae39a2c57e1f82b1' === $pin, 'exact fixture pin supplied' );
$assert( is_file( $root_dir . '/booster-fixture-plugin.php' ) && is_file( $nested_dir . '/booster-fixture-branch.php' ), 'copied pinned fixture files exist' );
$root_hash = hash_file( 'sha256', $root_dir . '/booster-fixture-plugin.php' );
$zip       = new ZipArchive();
$assert( true === $zip->open( $archive ) && false !== $zip->locateName( 'booster-fixture-plugin.php' ) && false === $zip->locateName( 'branch-fixture/booster-fixture-branch.php' ), 'root release ZIP excludes nested branch fixture' );
$zip->close();

$container         = require __DIR__ . '/core-container-fixture.php';
$plugin_repository = $container->make( PluginRepository::class );
$deploy            = $container->make( DeploymentCoordinator::class );
$repository        = new ManagedRepository( 'gh', 'RocketsAreNostalgic/booster-fixture-plugin', '1315521150', 'main' );
$root              = $plugin_repository->installedPluginFromFile( $root_id );
$root->setRepository( $repository );
$root->setDeploymentPolicy( DeploymentPolicy::DISABLED );
$root->setSource( PackageSource::RELEASE_ASSET, 1 );
$adoption = $plugin_repository->adoptRelease( $root, new ManagedReleaseConfiguration( basename( $root_dir ), 'booster-fixture-plugin.php' ), 1 );
$assert( $adoption->isSuccessful(), 'root Release adoption succeeds: ' . $adoption->getDiagnosticId() );

global $wpdb;
$attempt_table   = Database::attemptTableName();
$before_attempts = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE provider_repository_id = %s', $attempt_table, '1315521150' ) );
$operation       = PackageOperation::fromInput(
	'install-plugin',
	array(
		'provider'               => 'gh',
		'repository'             => 'RocketsAreNostalgic/booster-fixture-plugin',
		'provider_repository_id' => '1315521150',
		'branch'                 => 'main',
		'package_slug'           => basename( $nested_dir ),
		'deployment_policy'      => 'manual',
	)
);
$blocked         = false;
try {
	$result  = $deploy->executeManual( $operation );
	$blocked = 'failed' === ( $result['status'] ?? null );
} catch ( RuntimeException ) {
	$blocked = true; }
$after_attempts = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE provider_repository_id = %s', $attempt_table, '1315521150' ) );
$assert( $blocked && $before_attempts === $after_attempts && $root_hash === hash_file( 'sha256', $root_dir . '/booster-fixture-plugin.php' ), 'Release blocks nested Branch install before attempts or filesystem mutation' );

$nested = $plugin_repository->installedPluginFromFile( $nested_id );
$nested->setRepository( $repository );
$nested->setDeploymentPolicy( DeploymentPolicy::DISABLED );
$assert( ! $plugin_repository->adopt( $nested )->isSuccessful(), 'installed nested Branch adoption is blocked by root Release' );
$assert( $plugin_repository->unlink( $root_id )->isSuccessful(), 'ordinary unlink removes root Release record' );
$root->setSource( PackageSource::BRANCH, 1 );
$assert( $plugin_repository->adopt( $root )->isSuccessful() && $plugin_repository->adopt( $nested )->isSuccessful(), 'root and nested Branch adoption both succeed' );
$store = new ManagedReleaseStore();
$assert( ! $store->transition( 'plugin', $root_id, PackageSource::BRANCH, 1, PackageSource::RELEASE_ASSET, new ManagedReleaseConfiguration( basename( $root_dir ), 'booster-fixture-plugin.php' ), 1 ), 'shared Branch repository refuses root Release transition' );
$assert( $plugin_repository->unlink( $nested_id )->isSuccessful(), 'ordinary unlink removes nested Branch record' );
$assert( $store->transition( 'plugin', $root_id, PackageSource::BRANCH, 1, PackageSource::RELEASE_ASSET, new ManagedReleaseConfiguration( basename( $root_dir ), 'booster-fixture-plugin.php' ), 1 ), 'sole root Branch transitions to Release' );
$assert( $store->transition( 'plugin', $root_id, PackageSource::RELEASE_ASSET, 2, PackageSource::BRANCH, null, 1 ), 'sole root Release returns to Branch' );
$assert( $root_hash === hash_file( 'sha256', $root_dir . '/booster-fixture-plugin.php' ), 'root fixture bytes remain unchanged' );
$clean();
