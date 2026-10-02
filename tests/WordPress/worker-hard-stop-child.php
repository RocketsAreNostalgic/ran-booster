<?php

// Claim one compact attempt, optionally acquire the native updater lock, then block for SIGKILL.

$phase   = $args[0] ?? '';
$barrier = $args[1] ?? '';
if ( ! in_array( $phase, array( 'pre', 'post', 'foreign' ), true ) || ! is_string( $barrier ) || ! str_starts_with( $barrier, sys_get_temp_dir() . DIRECTORY_SEPARATOR ) ) {
	throw new RuntimeException( 'The hard-stop child arguments are invalid.' );
}
$booster  = require __DIR__ . '/core-container-fixture.php';
$attempts = $booster->make( RAN\Deployment\DeploymentAttemptRepository::class );
$claimed  = $attempts->claim_next();
if ( null === $claimed ) {
	throw new RuntimeException( 'The hard-stop child could not claim the seeded attempt.' );
}
if ( 'pre' !== $phase ) {
	$lock = $booster->make( RAN\WordPress\WordPressUpdaterLock::class );
	$lock->acquire();
	$attempts->mark_mutation_started( $claimed->get_id() );
}
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Disposable fixture owns exact native files, streams and cleanup paths; WordPress filesystem abstraction would change the proof.
$marker = fopen( $barrier, 'x' );
if ( false === $marker ) {
	throw new RuntimeException( 'The hard-stop barrier could not be created.' );
}
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Disposable fixture owns exact native files, streams and cleanup paths; WordPress filesystem abstraction would change the proof.
fwrite( $marker, $phase . ':' . $claimed->get_id() . ':' . $claimed->get_correlation_id() . "\n" );
fflush( $marker );
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Disposable fixture owns exact native files, streams and cleanup paths; WordPress filesystem abstraction would change the proof.
fclose( $marker );
while ( true ) {
	usleep( 100000 );
}
