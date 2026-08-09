<?php

declare(strict_types=1);

namespace RAN\Secrets;

// Native filesystem seams model host-owned ancestors that a test user cannot chown.
// phpcs:disable WordPress.WP.AlternativeFunctions

/** @return array<int|string, int>|false */
function lstat( string $path ): array|false {
	$stat = \lstat( $path );
	$mock = $GLOBALS['ran_booster_shared_host_stat'] ?? null;
	if ( false !== $stat && is_array( $mock ) && $path === ( $mock['path'] ?? null ) ) {
		$stat['uid'] = $mock['uid'];
		$stat['gid'] = $mock['gid'];
	}

	return $stat;
}

function is_writable( string $path ): bool {
	$mock = $GLOBALS['ran_booster_shared_host_stat'] ?? null;

	return is_array( $mock ) && $path === ( $mock['path'] ?? null )
		? false
		: \is_writable( $path );
}

function posix_geteuid(): int {
	$mock = $GLOBALS['ran_booster_shared_host_identity'] ?? null;

	return is_array( $mock ) && is_int( $mock['uid'] ?? null ) ? $mock['uid'] : \posix_geteuid();
}

function posix_getegid(): int {
	$mock = $GLOBALS['ran_booster_shared_host_identity'] ?? null;

	return is_array( $mock ) && is_int( $mock['gid'] ?? null ) ? $mock['gid'] : \posix_getegid();
}

/** @return list<int>|false */
function posix_getgroups(): array|false {
	$mock = $GLOBALS['ran_booster_shared_host_identity'] ?? null;

	return is_array( $mock ) && array_key_exists( 'groups', $mock ) ? $mock['groups'] : \posix_getgroups();
}
