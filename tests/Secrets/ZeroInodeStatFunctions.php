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
	$stat = \fstat( $stream );
	if ( false !== $stat ) {
		$stat['ino'] = 0;
	}

	return $stat;
}
