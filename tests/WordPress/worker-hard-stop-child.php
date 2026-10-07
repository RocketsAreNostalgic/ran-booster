<?php

// Claim one compact attempt, optionally acquire the native updater lock, then block for SIGKILL.

$ran_booster_phase   = $args[0] ?? '';
$ran_booster_barrier = $args[1] ?? '';
if ( ! in_array( $ran_booster_phase, array( 'pre', 'post', 'foreign' ), true ) || ! is_string( $ran_booster_barrier ) || ! str_starts_with( $ran_booster_barrier, sys_get_temp_dir() . DIRECTORY_SEPARATOR ) ) {
	throw new RuntimeException( 'The hard-stop child arguments are invalid.' );
}
$ran_booster_booster  = require __DIR__ . '/core-container-fixture.php';
$ran_booster_attempts = $ran_booster_booster->make( RAN\Deployment\DeploymentAttemptRepository::class );
$ran_booster_claimed  = $ran_booster_attempts->claim_next();
if ( null === $ran_booster_claimed ) {
	throw new RuntimeException( 'The hard-stop child could not claim the seeded attempt.' );
}
if ( 'pre' !== $ran_booster_phase ) {
	$ran_booster_lock = $ran_booster_booster->make( RAN\WordPress\WordPressUpdaterLock::class );
	$ran_booster_lock->acquire();
	$ran_booster_attempts->mark_mutation_started( $ran_booster_claimed->get_id() );
}
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Disposable fixture owns exact native files, streams and cleanup paths; WordPress filesystem abstraction would change the proof.
$ran_booster_marker = fopen( $ran_booster_barrier, 'x' );
if ( false === $ran_booster_marker ) {
	throw new RuntimeException( 'The hard-stop barrier could not be created.' );
}
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Disposable fixture owns exact native files, streams and cleanup paths; WordPress filesystem abstraction would change the proof.
fwrite( $ran_booster_marker, $ran_booster_phase . ':' . $ran_booster_claimed->get_id() . ':' . $ran_booster_claimed->get_correlation_id() . "\n" );
fflush( $ran_booster_marker );
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Disposable fixture owns exact native files, streams and cleanup paths; WordPress filesystem abstraction would change the proof.
fclose( $ran_booster_marker );
while ( true ) { // @phpstan-ignore while.alwaysTrue (Hard-stop child deliberately waits forever for the parent to kill it at the filesystem barrier.)
	usleep( 100000 );
}
