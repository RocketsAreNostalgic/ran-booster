<?php

declare(strict_types=1);

namespace RAN\Secrets;

// The probe intentionally verifies native local-filesystem behavior.

/**
 * Proves the small set of POSIX behaviors required by the encrypted sidecar.
 */
final class PosixFilesystemProbe {

	/**
	 * @param array{dev:int,ino:int}|null $owned_leaf
	 * @param-out array{dev:int,ino:int}|null $owned_leaf
	 */
	public function probe( string $candidate_file, ?array &$owned_leaf = null ): bool {
		$owned_leaf = null;
		$probe_path = '';
		$handles    = array();
		$first      = false;
		$passed     = false;
		try {
			if ( 'Windows' === PHP_OS_FAMILY || ! $this->valid_candidate( $candidate_file ) ) {
				return false;
			}
			$site_directory = dirname( $candidate_file );
			$root_directory = dirname( $site_directory );
			if ( null === $this->safe_directory( dirname( $root_directory ), false )
				|| ! $this->ensure_private_directory( $root_directory )
				|| ! $this->ensure_deny_stub( $root_directory . '/.htaccess', "Require all denied\n" )
				|| ! $this->ensure_deny_stub( $root_directory . '/index.php', "<?php\n// Silence is golden.\n" )
				|| ! $this->ensure_private_directory( $site_directory, $owned_leaf )
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
			try {
				clearstatcache( true, $probe_path );
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Remove only the probe inode still verified against its creating handle.
				if ( is_resource( $first ) && ( ! is_file( $probe_path ) || ! $this->safe_file( $probe_path, $first ) || ! unlink( $probe_path ) ) ) {
					$passed = false;
				}
			} catch ( \Throwable ) {
				$passed = false;
			} finally {
				foreach ( array_reverse( $handles ) as $handle ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Keep the creating handle open until the final ownership check.
					fclose( $handle );
				}
			}
		}

		return $passed;
	}

	/** @internal Recognize historical and current automatic storage leaf names. */
	public static function is_managed_leaf_name( string $name ): bool {
		return 1 === preg_match( '/\A(?:[a-f0-9]{16}|[a-f0-9]{32})\z/D', $name );
	}

	private function valid_candidate( string $path ): bool {
		return str_starts_with( $path, '/' )
			&& 'ran-booster-store.json' === basename( $path )
			&& '.ran-booster' === basename( dirname( dirname( $path ) ) )
			&& self::is_managed_leaf_name( basename( dirname( $path ) ) )
			&& ! str_contains( $path, "\0" )
			&& ! str_contains( $path, '//' )
			&& 0 === preg_match( '#(?:^|/)\.{1,2}(?:/|$)#', $path );
	}

	/** @return array{dev:int,ino:int}|null */
	private function safe_directory( string $path, bool $require_private_mode ): ?array {
		clearstatcache( true, $path );
		$stat = lstat( $path );

		return false !== $stat
			&& 0040000 === ( $stat['mode'] & 0170000 )
			&& 0 !== $stat['ino']
			&& ! is_link( $path )
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- The private local storage boundary checks actual PHP-process writability alongside canonical path and inode checks.
			&& is_writable( $path )
			&& ( ! function_exists( 'posix_geteuid' ) || posix_geteuid() === $stat['uid'] )
			&& ( $require_private_mode ? 0700 === ( $stat['mode'] & 0777 ) : 0 === ( $stat['mode'] & 0022 ) )
			? array(
				'dev' => $stat['dev'],
				'ino' => $stat['ino'],
			)
			: null;
	}

	/** @param array{dev:int,ino:int}|null $created */
	private function ensure_private_directory( string $path, ?array &$created = null ): bool {
		clearstatcache( true, $path );
		if ( file_exists( $path ) || is_link( $path ) ) {
			return null !== $this->safe_directory( $path, true );
		}

		// Another request may create the shared directory first. Inspect it before continuing.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir, WordPress.PHP.NoSilencedErrors.Discouraged -- A failed exclusive creation is resolved by verifying the current directory.
		$made     = @mkdir( $path, 0700 );
		$identity = is_dir( $path ) ? $this->safe_directory( $path, true ) : null;
		if ( $made ) {
			$created = $identity;
		}

		return null !== $identity;
	}

	/** @param array{dev:int,ino:int} $identity */
	public function remove_owned_empty_leaf( string $candidate_file, array $identity ): void {
		$leaf = dirname( $candidate_file );
		if ( ! $this->valid_candidate( $candidate_file ) || realpath( $leaf ) !== $leaf
			|| $identity !== $this->safe_directory( $leaf, true ) || array( '.', '..' ) !== scandir( $leaf )
			|| $identity !== $this->safe_directory( $leaf, true ) ) {
			return;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir, WordPress.PHP.NoSilencedErrors.Discouraged -- Remove only the verified new leaf if still empty. A raced or failed cleanup preserves it.
		@rmdir( $leaf );
	}

	private function ensure_deny_stub( string $path, string $contents ): bool {
		clearstatcache( true, $path );
		if ( file_exists( $path ) || is_link( $path ) ) {
			return ! is_link( $path ) && is_file( $path );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Exclusive native creation writes the defense-in-depth deny stub without clobbering existing files or following symlinks.
		$handle = fopen( $path, 'xb' );
		if ( false === $handle ) {
			clearstatcache( true, $path );

			return ! is_link( $path ) && is_file( $path );
		}

		$written = false;
		try {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Restrict the defense-in-depth deny stub to owner-only permissions before verifying handle identity.
			if ( ! chmod( $path, 0600 ) || ! $this->safe_file( $path, $handle ) ) {
				return false;
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Write exact bytes to the newly created exclusive handle.
			$bytes   = fwrite( $handle, $contents );
			$written = false !== $bytes && strlen( $contents ) === $bytes && fflush( $handle );
		} finally {
			clearstatcache( true, $path );
			if ( ! $written && is_file( $path ) && $this->safe_file( $path, $handle ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Remove only the partially written inode still owned by this open handle.
				unlink( $path );
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Keep the native handle available for exact ownership verification until cleanup ends.
			fclose( $handle );
		}

		return $written;
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
			&& 0 !== $path_stat['ino']
			&& 0 !== $handle_stat['ino']
			&& $path_stat['dev'] === $handle_stat['dev']
			&& $path_stat['ino'] === $handle_stat['ino']
			&& ( ! function_exists( 'posix_geteuid' ) || posix_geteuid() === $path_stat['uid'] );
	}
}
