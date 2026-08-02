<?php

// Independent claimant that proves contention exists only at the native lock.
// phpcs:disable

$phase = $args[0] ?? '';
$result_marker = $args[1] ?? '';
if ( ! in_array( $phase, array( 'pre', 'post', 'foreign' ), true )
	|| ! is_string( $result_marker )
	|| ! str_starts_with( $result_marker, sys_get_temp_dir() . DIRECTORY_SEPARATOR ) ) {
	throw new RuntimeException( 'The contender marker path is invalid.' );
}

$attempts = ran_booster()->make( RAN\Deployment\DeploymentAttemptRepository::class );
$attempt  = $attempts->claimNext();
if ( null === $attempt ) {
	throw new RuntimeException( 'The contender could not claim the second attempt.' );
}
$coordinator = ran_booster()->make( RAN\Deployment\DeploymentCoordinator::class );
$acquire     = new ReflectionMethod( $coordinator, 'acquireCoreLock' );
$release     = new ReflectionMethod( $coordinator, 'releaseCoreLock' );
$suffix      = '';

if ( 'pre' === $phase ) {
	$token = $acquire->invoke( $coordinator );
	if ( ! is_string( $token ) || ! $release->invoke( $coordinator, $token ) ) {
		throw new RuntimeException( 'The contender could not acquire and exactly release the available core lock.' );
	}
	$attempts->finish( $attempt->getId(), RAN\Deployment\DeploymentOutcome::fromCode( RAN\Deployment\DeploymentOutcome::CODE_NO_CHANGE ) );
	$suffix = 'core-lock-available';
} else {
	try {
		$acquire->invoke( $coordinator );
		throw new RuntimeException( 'The contender unexpectedly acquired the retained core lock.' );
	} catch ( RuntimeException $exception ) {
		if ( ! str_contains( $exception->getMessage(), 'already running' ) ) {
			throw $exception;
		}
	}
	$attempts->finish( $attempt->getId(), RAN\Deployment\DeploymentOutcome::fromCode( RAN\Deployment\DeploymentOutcome::CODE_LOCK_UNAVAILABLE ) );
	$suffix = 'core-lock-contended';
}

$marker = fopen( $result_marker, 'x' );
if ( false === $marker ) {
	throw new RuntimeException( 'The contender marker could not be created.' );
}
fwrite( $marker, 'claimed:' . $attempt->getCorrelationId() . ':' . $suffix . "\n" );
fclose( $marker );
