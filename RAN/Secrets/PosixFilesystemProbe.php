<?php

declare(strict_types=1);

namespace RAN\Secrets;

// The probe intentionally verifies native local-filesystem behavior.

/**
 * Proves the small set of POSIX behaviors required by the encrypted sidecar.
 */
final class PosixFilesystemProbe {

	public function probe( string $candidate_file ): bool {
		$probe_path = '';
		$handles    = array();
		$passed     = false;
		try {
			if ( 'Windows' === PHP_OS_FAMILY || ! $this->valid_candidate( $candidate_file ) ) {
				return false;
			}
			$site_directory = dirname( $candidate_file );
			$root_directory = dirname( $site_directory );
			if ( ! $this->safe_directory( dirname( $root_directory ), false )
				|| ! $this->ensure_private_directory( $root_directory )
				|| ! $this->ensure_private_directory( $site_directory )
			) {
				return false;
			}

			$probe_path = $site_directory . '/.probe-' . bin2hex( random_bytes( 12 ) );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Exclusive native creation must fail on an existing path and return the handle used for inode/lock checks.
			$first = fopen( $probe_path, 'x+b' );
			if ( false === $first ) {
				return false;
			}
			$handles[] = $first;
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Set and verify native POSIX permission bits on the private file before it can be trusted or installed.
			if ( ! chmod( $probe_path, 0600 ) || ! $this->safe_file( $probe_path, $first ) ) {
				return false;
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- A second native handle proves a held nonblocking flock cannot be acquired again.
			$second = fopen( $probe_path, 'rb' );
			if ( false === $second ) {
				return false;
			}
			$handles[] = $second;
			$passed    = flock( $first, LOCK_EX | LOCK_NB ) && ! flock( $second, LOCK_EX | LOCK_NB );
		} catch ( \Throwable ) {
			$passed = false;
		} finally {
			foreach ( array_reverse( $handles ) as $handle ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close both native lock-probe handles before removing the uniquely named probe entry.
				fclose( $handle );
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Remove the uniquely named native lock-probe entry after closing its handles; failure invalidates the probe.
			if ( '' !== $probe_path && ( is_file( $probe_path ) || is_link( $probe_path ) ) && ! unlink( $probe_path ) ) {
				$passed = false;
			}
		}

		return $passed;
	}

	private function valid_candidate( string $path ): bool {
		return str_starts_with( $path, '/' )
			&& 'secrets.json' === basename( $path )
			&& '.ran-booster' === basename( dirname( dirname( $path ) ) )
			&& 1 === preg_match( '/^[a-f0-9]{16}$/D', basename( dirname( $path ) ) )
			&& ! str_contains( $path, "\0" )
			&& ! str_contains( $path, '//' )
			&& 0 === preg_match( '#(?:^|/)\.{1,2}(?:/|$)#', $path );
	}

	private function safe_directory( string $path, bool $require_private_mode ): bool {
		clearstatcache( true, $path );
		$stat = lstat( $path );

		return false !== $stat
			&& 0040000 === ( $stat['mode'] & 0170000 )
			&& ! is_link( $path )
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- The private local storage boundary checks actual PHP-process writability alongside canonical path and inode checks.
			&& is_writable( $path )
			&& ( ! function_exists( 'posix_geteuid' ) || posix_geteuid() === $stat['uid'] )
			&& ( ! $require_private_mode || 0700 === ( $stat['mode'] & 0777 ) )
			&& ( $require_private_mode || 0 === ( $stat['mode'] & 0022 ) );
	}

	private function ensure_private_directory( string $path ): bool {
		if ( file_exists( $path ) || is_link( $path ) ) {
			return $this->safe_directory( $path, true );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Create the private directory with mode 0700, then verify its native ownership and mode.
		return mkdir( $path, 0700 ) && $this->safe_directory( $path, true );
	}

	/** @param resource $handle */
	private function safe_file( string $path, mixed $handle ): bool {
		$path_stat   = lstat( $path );
		$handle_stat = fstat( $handle );

		return false !== $path_stat
			&& false !== $handle_stat
			&& 0100000 === ( $path_stat['mode'] & 0170000 )
			&& 1 === $path_stat['nlink']
			&& 0600 === ( $path_stat['mode'] & 0777 )
			&& $path_stat['dev'] === $handle_stat['dev']
			&& $path_stat['ino'] === $handle_stat['ino']
			&& ( ! function_exists( 'posix_geteuid' ) || posix_geteuid() === $path_stat['uid'] );
	}
}
