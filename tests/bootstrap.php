<?php
/**
 * PHPUnit bootstrap.
 *
 * On macOS `/var` is a symlink to `/private/var`, so `sys_get_temp_dir()` and `realpath()` of the same
 * directory differ and path-equality assertions fail locally. Point `TMPDIR` at the canonical directory
 * before any test asks for it. Linux is unaffected because the two already agree.
 *
 * @package RAN\Booster\Tests
 */

declare(strict_types=1);

// PHP caches the temp directory on the first sys_get_temp_dir() call, so read the environment instead.
$ran_booster_tmpdir = getenv( 'TMPDIR' );
$ran_booster_tmpdir = false === $ran_booster_tmpdir || '' === $ran_booster_tmpdir ? false : realpath( $ran_booster_tmpdir );
if ( false !== $ran_booster_tmpdir ) {
	putenv( 'TMPDIR=' . $ran_booster_tmpdir ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- Canonicalise the test temp directory; no WordPress runtime is loaded here.
}
unset( $ran_booster_tmpdir );

require dirname( __DIR__ ) . '/vendor/autoload.php';
