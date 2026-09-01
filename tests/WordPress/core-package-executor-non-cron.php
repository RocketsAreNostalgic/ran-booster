<?php

declare(strict_types=1);

// A separate process proves that protected non-cron requests can reach core.

use RAN\Deployment\PreparedArtifact;
use RAN\WordPress\CorePackageExecutionFailure;
use RAN\WordPress\CorePackageExecutor;

require_once dirname( __DIR__, 2 ) . '/RAN/PackageSubdirectory.php';
require_once dirname( __DIR__, 2 ) . '/RAN/Deployment/PreparedArtifact.php';
require_once dirname( __DIR__, 2 ) . '/RAN/Runtime/RuntimeSupport.php';
require_once dirname( __DIR__, 2 ) . '/RAN/WordPress/CorePackageExecutionFailure.php';
require_once dirname( __DIR__, 2 ) . '/RAN/WordPress/CorePackageExecutionResult.php';
require_once dirname( __DIR__, 2 ) . '/RAN/WordPress/CorePackageExecutor.php';

function wp_doing_cron(): bool {
	return false;
}

function add_filter(): void {}
function remove_filter(): void {}
function add_action(): void {}
function remove_action(): void {}
function has_action(): bool {
	return false;
}

if ( ! defined( 'WP_PLUGIN_DIR' ) ) {
	define( 'WP_PLUGIN_DIR', sys_get_temp_dir() );
}

$fixturePath = tempnam( sys_get_temp_dir(), 'ran-booster-non-cron-' );
if ( false === $fixturePath ) {
	throw new RuntimeException( 'The non-cron fixture could not be created.' );
}
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Disposable identity fixture.
file_put_contents( $fixturePath, 'immutable fixture' );
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- PreparedArtifact requires a private file.
chmod( $fixturePath, 0600 );
$identity = PreparedArtifact::regularFileIdentity( $fixturePath );
if ( null === $identity ) {
	throw new RuntimeException( 'The non-cron fixture identity is unavailable.' );
}
$artifact = new PreparedArtifact(
	$fixturePath,
	str_repeat( 'a', 40 ),
	'1.0.0',
	hash_file( 'sha256', $fixturePath ),
	$identity['device'],
	$identity['inode'],
	$identity['size'],
	$identity['permissions'],
	$identity['links']
);
$calls    = array();
$executor = new CorePackageExecutor(
	static function ( string $action, string $type ) use ( &$calls ): bool {
		$calls[] = array( $action, $type );

		return false;
	}
);

try {
	$result = $executor->updatePlugin( $artifact, 'example', null, 'example/example.php' );
	if ( CorePackageExecutionFailure::WORDPRESS_REFUSED !== $result->getFailure() ) {
		throw new RuntimeException( 'The non-cron update did not return the core operation result.' );
	}
	$result = $executor->installPlugin( $artifact, 'example', null );
	if ( CorePackageExecutionFailure::WORDPRESS_REFUSED !== $result->getFailure() ) {
		throw new RuntimeException( 'The non-cron install did not return the core operation result.' );
	}
	if ( array( array( 'update', 'plugin' ), array( 'install', 'plugin' ) ) !== $calls ) {
		throw new RuntimeException( 'The executor did not run both non-cron core operations.' );
	}
} finally {
	$artifact->cleanup();
}
