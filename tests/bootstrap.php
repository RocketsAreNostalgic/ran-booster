<?php
/**
 * PHPUnit bootstrap.
 *
 * Every test process gets its own temporary directory. Several checkouts can run `composer check` at once on
 * one machine, and fixtures with fixed names under the shared system temporary directory then collide: one run's
 * tearDown removes the files another run is using. The directory is removed when the process ends.
 *
 * On macOS `/var` is a symlink to `/private/var`, so `sys_get_temp_dir()` and `realpath()` of the same
 * directory differ and path-equality assertions fail locally. The per-process directory is created under the
 * canonical base, so the two agree. Linux is unaffected because the two already agree.
 *
 * @package RAN\Booster\Tests
 */

declare(strict_types=1);

// PHP caches the temp directory on the first sys_get_temp_dir() call, so read the environment instead.
( static function (): void {
	$base = getenv( 'TMPDIR' );
	$base = false === $base || '' === $base ? sys_get_temp_dir() : $base;
	$base = realpath( $base );
	if ( false === $base ) {
		return;
	}
	$owner     = getmypid();
	$directory = $base . '/ran-booster-tests-' . $owner . '-' . bin2hex( random_bytes( 4 ) );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Create the per-process test directory; no WordPress runtime is loaded here.
	if ( ! mkdir( $directory, 0700 ) ) {
		return;
	}
	putenv( 'TMPDIR=' . $directory ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- Isolate the test temp directory; no WordPress runtime is loaded here.
	register_shutdown_function(
		static function () use ( $directory, $owner ): void {
			// A forked child inherits this handler and must not remove the directory its parent is still using.
			if ( getmypid() !== $owner ) {
				return;
			}
			// Only the directory this process created is removed, and links are unlinked, never followed.
			$entries = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $directory, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
			foreach ( $entries as $entry ) {
				if ( $entry->isDir() && ! $entry->isLink() ) {
					@chmod( $entry->getPathname(), 0700 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Fixtures may leave unreadable directories; removal is best effort.
					@rmdir( $entry->getPathname() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Remove only this process's own directory tree.
				} else {
					@unlink( $entry->getPathname() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink -- Remove only this process's own directory tree.
				}
			}
			@rmdir( $directory ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Remove this process's own directory.
		}
	);
} )();

require dirname( __DIR__ ) . '/vendor/autoload.php';

// Unit tests run on a development site unless a test of the package source gate says otherwise.
require __DIR__ . '/Support/PackageSourceGateWordPressFunctions.php';
