<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Isolated CLI harness locals do not enter shipped plugin scope; declarations and hooks remain checked.

// Independent claimant that proves contention exists only at the native lock.

$phase         = $args[0] ?? '';
$result_marker = $args[1] ?? '';
if ( ! in_array( $phase, array( 'pre', 'post', 'foreign' ), true )
	|| ! is_string( $result_marker )
	|| ! str_starts_with( $result_marker, sys_get_temp_dir() . DIRECTORY_SEPARATOR ) ) {
	throw new RuntimeException( 'The contender marker path is invalid.' );
}

$booster  = require __DIR__ . '/core-container-fixture.php';
$attempts = $booster->make( RAN\Deployment\DeploymentAttemptRepository::class );
$attempt  = $attempts->claim_next();
if ( null === $attempt ) {
	throw new RuntimeException( 'The contender could not claim the second attempt.' );
}
$lock   = $booster->make( RAN\WordPress\WordPressUpdaterLock::class );
$suffix = '';

if ( 'pre' === $phase ) {
	$token = $lock->acquire();
	if ( ! $lock->release( $token ) ) {
		throw new RuntimeException( 'The contender could not acquire and exactly release the available core lock.' );
	}
	$attempts->finish( $attempt->get_id(), RAN\Deployment\DeploymentOutcome::from_code( RAN\Deployment\DeploymentOutcome::CODE_NO_CHANGE ) );
	$suffix = 'core-lock-available';
} else {
	try {
		$lock->acquire();
		throw new RuntimeException( 'The contender unexpectedly acquired the retained core lock.' );
	} catch ( RuntimeException $exception ) {
		if ( ! str_contains( $exception->getMessage(), 'already running' ) ) {
			throw $exception;
		}
	}
	$attempts->finish( $attempt->get_id(), RAN\Deployment\DeploymentOutcome::from_code( RAN\Deployment\DeploymentOutcome::CODE_LOCK_UNAVAILABLE ) );
	$suffix = 'core-lock-contended';
}

// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Disposable fixture owns exact native files, streams and cleanup paths; WordPress filesystem abstraction would change the proof.
$marker = fopen( $result_marker, 'x' );
if ( false === $marker ) {
	throw new RuntimeException( 'The contender marker could not be created.' );
}
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Disposable fixture owns exact native files, streams and cleanup paths; WordPress filesystem abstraction would change the proof.
fwrite( $marker, 'claimed:' . $attempt->get_correlation_id() . ':' . $suffix . "\n" );
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Disposable fixture owns exact native files, streams and cleanup paths; WordPress filesystem abstraction would change the proof.
fclose( $marker );
