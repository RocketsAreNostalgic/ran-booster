<?php

declare(strict_types=1);

namespace RAN\Secrets;

/** Loaded only by the process-isolated zero-inode regression. */

/** @return array<int|string, int>|false */
function lstat( string $path ): array|false {
	$stat = \lstat( $path );
	if ( false !== $stat && empty( $GLOBALS['ran_booster_fixture_zero_handle_inode_only'] ) ) {
		$stat['ino'] = 0;
	}

	return $stat;
}

/**
 * @param resource $stream
 * @return array<int|string, int>|false
 */
function fstat( $stream ): array|false {
	$stat      = \fstat( $stream );
	$remaining = $GLOBALS['ran_booster_fixture_handle_checks_before_zero_inode'] ?? 0;
	if ( is_int( $remaining ) && 0 < $remaining ) {
		$GLOBALS['ran_booster_fixture_handle_checks_before_zero_inode'] = $remaining - 1;
		return $stat;
	}
	if ( false !== $stat ) {
		$stat['ino'] = 0;
	}

	return $stat;
}
