<?php

// Independent claimant that proves contention exists only at the native lock.

$ran_booster_phase         = $args[0] ?? '';
$ran_booster_result_marker = $args[1] ?? '';
if ( ! in_array( $ran_booster_phase, array( 'pre', 'post', 'foreign' ), true )
	|| ! is_string( $ran_booster_result_marker )
	|| ! str_starts_with( $ran_booster_result_marker, sys_get_temp_dir() . DIRECTORY_SEPARATOR ) ) {
	throw new RuntimeException( 'The contender marker path is invalid.' );
}

$ran_booster_booster  = require __DIR__ . '/core-container-fixture.php';
$ran_booster_attempts = $ran_booster_booster->make( RAN\Deployment\DeploymentAttemptRepository::class );
$ran_booster_attempt  = $ran_booster_attempts->claim_next();
if ( null === $ran_booster_attempt ) {
	throw new RuntimeException( 'The contender could not claim the second attempt.' );
}
$ran_booster_lock   = $ran_booster_booster->make( RAN\WordPress\WordPressUpdaterLock::class );
$ran_booster_suffix = '';

if ( 'pre' === $ran_booster_phase ) {
	$ran_booster_token = $ran_booster_lock->acquire();
	if ( ! $ran_booster_lock->release( $ran_booster_token ) ) {
		throw new RuntimeException( 'The contender could not acquire and exactly release the available core lock.' );
	}
	$ran_booster_attempts->finish( $ran_booster_attempt->get_id(), RAN\Deployment\DeploymentOutcome::from_code( RAN\Deployment\DeploymentOutcome::CODE_NO_CHANGE ) );
	$ran_booster_suffix = 'core-lock-available';
} else {
	try {
		$ran_booster_lock->acquire();
		throw new RuntimeException( 'The contender unexpectedly acquired the retained core lock.' );
	} catch ( RuntimeException $exception ) {
		if ( ! str_contains( $exception->getMessage(), 'already running' ) ) {
			throw $exception;
		}
	}
	$ran_booster_attempts->finish( $ran_booster_attempt->get_id(), RAN\Deployment\DeploymentOutcome::from_code( RAN\Deployment\DeploymentOutcome::CODE_LOCK_UNAVAILABLE ) );
	$ran_booster_suffix = 'core-lock-contended';
}

// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Disposable fixture owns exact native files, streams and cleanup paths; WordPress filesystem abstraction would change the proof.
$ran_booster_marker = fopen( $ran_booster_result_marker, 'x' );
if ( false === $ran_booster_marker ) {
	throw new RuntimeException( 'The contender marker could not be created.' );
}
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Disposable fixture owns exact native files, streams and cleanup paths; WordPress filesystem abstraction would change the proof.
fwrite( $ran_booster_marker, 'claimed:' . $ran_booster_attempt->get_correlation_id() . ':' . $ran_booster_suffix . "\n" );
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Disposable fixture owns exact native files, streams and cleanup paths; WordPress filesystem abstraction would change the proof.
fclose( $ran_booster_marker );
