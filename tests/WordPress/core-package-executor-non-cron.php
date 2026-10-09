<?php

declare(strict_types=1);

// A separate process proves that protected non-cron requests can reach core.

use RAN\Deployment\PreparedArtifact;
use RAN\WordPress\CorePackageExecutionFailure;
use RAN\WordPress\CorePackageExecutor;

$ran_booster_wordpress_path = getenv( 'RAN_BOOSTER_WORDPRESS_PATH' );
if ( ! is_string( $ran_booster_wordpress_path ) || '' === trim( $ran_booster_wordpress_path ) ) {
	throw new RuntimeException( 'The non-cron installed runtime path is unavailable.' );
}
$ran_booster_shared_path = rtrim( $ran_booster_wordpress_path, '/\\' ) . '/wp-content/plugins/ran-booster/vendor/ran/updater-support/src/RepositoryRelativePath.php';
if ( ! is_file( $ran_booster_shared_path ) ) {
	throw new RuntimeException( 'The non-cron shared path dependency is unavailable.' );
}
require_once $ran_booster_shared_path;
require_once dirname( __DIR__, 2 ) . '/RAN/RepositoryProvider/RepositoryReleaseArtifactCustody.php';
require_once dirname( __DIR__, 2 ) . '/RAN/PackageSubdirectory.php';
require_once dirname( __DIR__, 2 ) . '/RAN/Deployment/PreparedArtifact.php';
require_once dirname( __DIR__, 2 ) . '/RAN/Runtime/RuntimeSupport.php';
require_once dirname( __DIR__, 2 ) . '/RAN/WordPress/CorePackageExecutionFailure.php';
require_once dirname( __DIR__, 2 ) . '/RAN/WordPress/CorePackageExecutionResult.php';
require_once dirname( __DIR__, 2 ) . '/RAN/WordPress/CorePackageExecutor.php';

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WordPress function double must retain the host-owned name.
function wp_doing_cron(): bool {
	return false;
}

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WordPress function double must retain the host-owned name.
function add_filter(): void {}
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WordPress function double must retain the host-owned name.
function remove_filter(): void {}
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WordPress function double must retain the host-owned name.
function add_action(): void {}
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WordPress function double must retain the host-owned name.
function remove_action(): void {}
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WordPress function double must retain the host-owned name.
function has_action(): bool {
	return false;
}

if ( ! defined( 'WP_PLUGIN_DIR' ) ) {
	define( 'WP_PLUGIN_DIR', sys_get_temp_dir() );
}

$ran_booster_fixture_path = tempnam( sys_get_temp_dir(), 'ran-booster-non-cron-' );
if ( false === $ran_booster_fixture_path ) {
	throw new RuntimeException( 'The non-cron fixture could not be created.' );
}
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Disposable identity fixture.
file_put_contents( $ran_booster_fixture_path, 'immutable fixture' );
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- PreparedArtifact requires a private file.
chmod( $ran_booster_fixture_path, 0600 );
$ran_booster_identity = PreparedArtifact::regular_file_identity( $ran_booster_fixture_path );
if ( null === $ran_booster_identity ) {
	throw new RuntimeException( 'The non-cron fixture identity is unavailable.' );
}
$ran_booster_digest = hash_file( 'sha256', $ran_booster_fixture_path );
if ( false === $ran_booster_digest ) {
	throw new RuntimeException( 'The non-cron fixture digest is unavailable.' );
}
$ran_booster_artifact = new PreparedArtifact(
	$ran_booster_fixture_path,
	str_repeat( 'a', 40 ),
	'1.0.0',
	$ran_booster_digest,
	$ran_booster_identity['device'],
	$ran_booster_identity['inode'],
	$ran_booster_identity['size'],
	$ran_booster_identity['permissions'],
	$ran_booster_identity['links']
);
$ran_booster_calls    = array();
$ran_booster_executor = new CorePackageExecutor(
	static function ( string $action, string $type ) use ( &$ran_booster_calls ): bool {
		$ran_booster_calls[] = array( $action, $type );

		return false;
	}
);

try {
	$ran_booster_result = $ran_booster_executor->update_plugin( $ran_booster_artifact, 'example', null, 'example/example.php' );
	if ( CorePackageExecutionFailure::WORDPRESS_REFUSED !== $ran_booster_result->get_failure() ) {
		throw new RuntimeException( 'The non-cron update did not return the core operation result.' );
	}
	$ran_booster_result = $ran_booster_executor->install_plugin( $ran_booster_artifact, 'example', null );
	if ( CorePackageExecutionFailure::WORDPRESS_REFUSED !== $ran_booster_result->get_failure() ) {
		throw new RuntimeException( 'The non-cron install did not return the core operation result.' );
	}
	if ( array( array( 'update', 'plugin' ), array( 'install', 'plugin' ) ) !== $ran_booster_calls ) {
		throw new RuntimeException( 'The executor did not run both non-cron core operations.' );
	}
} finally {
	$ran_booster_artifact->cleanup();
}
