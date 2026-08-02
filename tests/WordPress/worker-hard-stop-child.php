<?php

// Claim one compact attempt, optionally acquire the core lock, then block for SIGKILL.
// phpcs:disable

$phase = $args[0] ?? '';
$barrier = $args[1] ?? '';
if ( ! in_array( $phase, array( 'pre', 'post', 'foreign' ), true ) || ! is_string( $barrier ) || ! str_starts_with( $barrier, sys_get_temp_dir() . DIRECTORY_SEPARATOR ) ) {
	throw new RuntimeException( 'The hard-stop child arguments are invalid.' );
}
$booster  = require __DIR__ . '/core-container-fixture.php';
$attempts = $booster->make( RAN\Deployment\DeploymentAttemptRepository::class );
$claimed  = $attempts->claimNext();
if ( null === $claimed ) {
	throw new RuntimeException( 'The hard-stop child could not claim the seeded attempt.' );
}
if ( 'pre' !== $phase ) {
	$coordinator = $booster->make( RAN\Deployment\DeploymentCoordinator::class );
	( new ReflectionMethod( $coordinator, 'acquireCoreLock' ) )->invoke( $coordinator );
	$attempts->markMutationStarted( $claimed->getId() );
}
$marker = fopen( $barrier, 'x' );
if ( false === $marker ) {
	throw new RuntimeException( 'The hard-stop barrier could not be created.' );
}
fwrite( $marker, $phase . ':' . $claimed->getId() . ':' . $claimed->getCorrelationId() . "\n" );
fflush( $marker );
fclose( $marker );
while ( true ) {
	usleep( 100000 );
}
