<?php

declare(strict_types=1);

namespace RAN\Secrets;

// The probe intentionally verifies native local-filesystem behavior.
// phpcs:disable WordPress.WP.AlternativeFunctions

/**
 * Proves the small set of POSIX behaviors required by the encrypted sidecar.
 */
final class PosixFilesystemProbe {

	public function probe( string $candidateFile ): bool {
		$probePath = '';
		$handles   = array();
		$passed    = false;
		try {
			if ( 'Windows' === PHP_OS_FAMILY || ! $this->validCandidate( $candidateFile ) ) {
				return false;
			}
			$siteDirectory = dirname( $candidateFile );
			$rootDirectory = dirname( $siteDirectory );
			if ( ! $this->safeDirectory( dirname( $rootDirectory ), false )
				|| ! $this->ensurePrivateDirectory( $rootDirectory )
				|| ! $this->ensurePrivateDirectory( $siteDirectory )
			) {
				return false;
			}

			$probePath = $siteDirectory . '/.probe-' . bin2hex( random_bytes( 12 ) );
			$first     = fopen( $probePath, 'x+b' );
			if ( false === $first ) {
				return false;
			}
			$handles[] = $first;
			if ( ! chmod( $probePath, 0600 ) || ! $this->safeFile( $probePath, $first ) ) {
				return false;
			}
			$second = fopen( $probePath, 'rb' );
			if ( false === $second ) {
				return false;
			}
			$handles[] = $second;
			$passed    = flock( $first, LOCK_EX | LOCK_NB ) && ! flock( $second, LOCK_EX | LOCK_NB );
		} catch ( \Throwable ) {
			$passed = false;
		} finally {
			foreach ( array_reverse( $handles ) as $handle ) {
				fclose( $handle );
			}
			if ( '' !== $probePath && ( is_file( $probePath ) || is_link( $probePath ) ) && ! unlink( $probePath ) ) {
				$passed = false;
			}
		}

		return $passed;
	}

	private function validCandidate( string $path ): bool {
		return str_starts_with( $path, '/' )
			&& 'secrets.json' === basename( $path )
			&& '.ran-booster' === basename( dirname( dirname( $path ) ) )
			&& 1 === preg_match( '/^[a-f0-9]{16}$/D', basename( dirname( $path ) ) )
			&& ! str_contains( $path, "\0" )
			&& ! str_contains( $path, '//' )
			&& 0 === preg_match( '#(?:^|/)\.{1,2}(?:/|$)#', $path );
	}

	private function safeDirectory( string $path, bool $requirePrivateMode ): bool {
		clearstatcache( true, $path );
		$stat = lstat( $path );

		return false !== $stat
			&& 0040000 === ( $stat['mode'] & 0170000 )
			&& ! is_link( $path )
			&& is_writable( $path )
			&& ( ! function_exists( 'posix_geteuid' ) || posix_geteuid() === $stat['uid'] )
			&& ( ! $requirePrivateMode || 0700 === ( $stat['mode'] & 0777 ) )
			&& ( $requirePrivateMode || 0 === ( $stat['mode'] & 0022 ) );
	}

	private function ensurePrivateDirectory( string $path ): bool {
		if ( file_exists( $path ) || is_link( $path ) ) {
			return $this->safeDirectory( $path, true );
		}

		return mkdir( $path, 0700 ) && $this->safeDirectory( $path, true );
	}

	/** @param resource $handle */
	private function safeFile( string $path, mixed $handle ): bool {
		$pathStat   = lstat( $path );
		$handleStat = fstat( $handle );

		return false !== $pathStat
			&& false !== $handleStat
			&& 0100000 === ( $pathStat['mode'] & 0170000 )
			&& 1 === $pathStat['nlink']
			&& 0600 === ( $pathStat['mode'] & 0777 )
			&& $pathStat['dev'] === $handleStat['dev']
			&& $pathStat['ino'] === $handleStat['ino']
			&& ( ! function_exists( 'posix_geteuid' ) || posix_geteuid() === $pathStat['uid'] );
	}
}
