<?php

declare(strict_types=1);

namespace RAN\Deployment;

use RAN\PackageArtifactLimit;
use RAN\RepositoryProvider\RepositoryReleaseArtifactCustody;
use RuntimeException;
use Throwable;

/**
 * Core-owned transfer from provider custody into one PreparedArtifact.
 */
final class ReleaseArtifactCustodian {
	public static function claim( RepositoryReleaseArtifactCustody $custody ): PreparedArtifact {
		$prepared                  = $custody instanceof PreparedArtifact ? $custody : null;
		$inspection_invoked        = false;
		$source_discard_attempted  = false;
		$source_discard_successful = false;
		$copy_cleanup_successful   = true;

		try {
			$maximum_artifact_bytes = PackageArtifactLimit::resolve();
			$resolved_ref           = $custody->resolved_ref();
			$version                = $custody->version();
			$size                   = $custody->size();
			$sha256                 = $custody->sha256();

			if ( $size < 1
				|| $size > $maximum_artifact_bytes
				|| 1 !== preg_match( '/\A[a-f0-9]{64}\z/D', $sha256 ) ) {
				throw new RuntimeException( 'The release artifact custody evidence is invalid.' );
			}

			// API-10 providers may still return PreparedArtifact covariantly. Keep
			// those implementations load-compatible while new providers can return
			// provider-owned custody without importing this Core concrete type.
			if ( $custody instanceof PreparedArtifact ) {
				$custody->assert_unchanged();

				return $custody;
			}

			$result = $custody->inspect(
				function ( string $source ) use ( &$prepared, &$inspection_invoked, &$copy_cleanup_successful, $resolved_ref, $version, $size, $maximum_artifact_bytes, $sha256 ): PreparedArtifact {
					if ( $inspection_invoked ) {
						throw new RuntimeException();
					}
					$inspection_invoked = true;
					$prepared           = self::copy_to_core( $source, $resolved_ref, $version, $size, $maximum_artifact_bytes, $sha256, $copy_cleanup_successful );

					return $prepared;
				}
			);
			if ( ! $inspection_invoked || ! $prepared instanceof PreparedArtifact || $result !== $prepared ) {
				throw new RuntimeException();
			}

			$source_discard_attempted  = true;
			$source_discard_successful = true === $custody->discard();
			if ( ! $source_discard_successful ) {
				throw new RuntimeException();
			}

			return $prepared;
		} catch ( Throwable ) {
			if ( $prepared instanceof PreparedArtifact ) {
				try {
					$prepared->cleanup();
				} catch ( Throwable ) {
					$copy_cleanup_successful = false;
				}
			}
			if ( ! $source_discard_attempted ) {
				try {
					$source_discard_successful = true === $custody->discard();
				} catch ( Throwable ) {
					$source_discard_successful = false;
				}
			}

			if ( $copy_cleanup_successful && $source_discard_successful ) {
				throw new RuntimeException( 'The release artifact could not be transferred to Core.' );
			}

			throw new ReleaseArtifactCleanupFailure();
		}
	}

	private static function copy_to_core(
		string $source,
		string $resolved_ref,
		string $version,
		int $artifact_size,
		int $maximum_artifact_bytes,
		string $artifact_sha256,
		bool &$copy_cleanup_successful
	): PreparedArtifact {
		$directory     = sys_get_temp_dir() . '/ran-booster-release-' . bin2hex( random_bytes( 16 ) );
		$path          = $directory . '/archive.zip';
		$input         = false;
		$output        = false;
		$copy_identity = null;
		$input_closed  = true;
		$output_closed = true;

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- The random directory is the Core-owned custody boundary.
		if ( ! mkdir( $directory, 0700 ) ) {
			throw new RuntimeException();
		}
		$directory_identity = self::private_directory_identity( $directory );
		if ( null === $directory_identity ) {
			$copy_cleanup_successful = false;
			throw new RuntimeException();
		}

		try {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Provider custody grants this bounded source inspection.
			$input = fopen( $source, 'rb' );
			if ( false === $input ) {
				throw new RuntimeException();
			}
			$input_closed = false;
			if ( self::private_directory_identity( $directory ) !== $directory_identity ) {
				throw new RuntimeException();
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- The exclusive Core destination is the temporary custody boundary.
			$output = fopen( $path, 'x+b' );
			if ( false === $output ) {
				throw new RuntimeException();
			}
			$output_closed = false;
			$copy_identity = self::created_file_identity( $path, $output );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- The Core copy must remain private.
			if ( null === $copy_identity || ! chmod( $path, 0600 ) ) {
				throw new RuntimeException();
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_stream_copy_to_stream -- Copy is fixed-bounded without holding the archive in memory.
			$size = stream_copy_to_stream( $input, $output, $artifact_size + 1 );
			if ( false === $size || $artifact_size !== $size || $size > $maximum_artifact_bytes ) {
				throw new RuntimeException();
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close before Core identity capture.
			$output_closed = fclose( $output );
			$output        = false;
			if ( ! $output_closed ) {
				throw new RuntimeException();
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close provider inspection before TOCTOU recheck.
			$input_closed = fclose( $input );
			$input        = false;
			if ( ! $input_closed ) {
				throw new RuntimeException();
			}
			$prepared_identity = PreparedArtifact::regular_file_identity( $path );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_hash_file -- Custody transfer requires source/copy digest continuity.
			$copy_digest = hash_file( 'sha256', $path );
			if ( ! is_string( $copy_digest )
				|| ! hash_equals( $artifact_sha256, $copy_digest )
				|| self::private_directory_identity( $directory ) !== $directory_identity
				|| self::path_file_identity( $path ) !== $copy_identity
				|| null === $prepared_identity
				|| $size !== $prepared_identity['size'] ) {
				throw new RuntimeException();
			}

			return new PreparedArtifact(
				$path,
				$resolved_ref,
				$version,
				$artifact_sha256,
				$prepared_identity['device'],
				$prepared_identity['inode'],
				$prepared_identity['size'],
				$prepared_identity['permissions'],
				$prepared_identity['links'],
				$directory
			);
		} catch ( Throwable ) {
			if ( is_resource( $input ) ) {
				try {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close failed bounded-copy input before cleanup.
					$input_closed = fclose( $input );
				} catch ( Throwable ) {
					$input_closed = false;
				}
				$input = false;
			}
			if ( is_resource( $output ) ) {
				try {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close failed bounded-copy output before cleanup.
					$output_closed = fclose( $output );
				} catch ( Throwable ) {
					$output_closed = false;
				}
				$output = false;
			}

			$copy_cleanup_successful = $input_closed && $output_closed;
			try {
				$copy_cleanup_successful = self::remove_copy( $path, $directory, $directory_identity, $copy_identity ) && $copy_cleanup_successful;
			} catch ( Throwable ) {
				$copy_cleanup_successful = false;
			}
			throw new RuntimeException();
		} finally {
			if ( is_resource( $input ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Release failed bounded-copy input.
				fclose( $input );
			}
			if ( is_resource( $output ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Release failed bounded-copy output.
				fclose( $output );
			}
		}
	}

	/**
	 * @param array{device:int,inode:int,owner:int,group:int}                $directory_identity
	 * @param array{device:int,inode:int,links:int,owner:int,group:int}|null $copy_identity
	 */
	private static function remove_copy( string $path, string $directory, array $directory_identity, ?array $copy_identity ): bool {
		if ( self::private_directory_identity( $directory ) !== $directory_identity ) {
			return false;
		}
		if ( null !== $copy_identity ) {
			if ( self::path_file_identity( $path ) !== $copy_identity ) {
				return false;
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- This removes only the failed Core-owned copy.
			if ( ! unlink( $path ) ) {
				return false;
			}
			clearstatcache( true, $path );
			if ( file_exists( $path ) || is_link( $path ) ) {
				return false;
			}
		} elseif ( file_exists( $path ) || is_link( $path ) ) {
			return false;
		}
		if ( self::private_directory_identity( $directory ) !== $directory_identity ) {
			return false;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- This removes only the failed Core-owned random directory.
		if ( ! rmdir( $directory ) ) {
			return false;
		}
		clearstatcache( true, $directory );

		return ! file_exists( $directory ) && ! is_link( $directory );
	}

	/** @return array{device:int,inode:int,owner:int,group:int}|null */
	private static function private_directory_identity( string $directory ): ?array {
		clearstatcache( true, $directory );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_lstat -- Symlink-aware identity is required for the Core-owned directory.
		$stat = lstat( $directory );
		$mode = false === $stat ? 0 : (int) ( $stat['mode'] ?? 0 );
		if ( false === $stat || 0040000 !== ( $mode & 0170000 ) || 0700 !== ( $mode & 0777 ) ) {
			return null;
		}

		$identity = array(
			'device' => (int) ( $stat['dev'] ?? -1 ),
			'inode'  => (int) ( $stat['ino'] ?? 0 ),
			'owner'  => (int) ( $stat['uid'] ?? -1 ),
			'group'  => (int) ( $stat['gid'] ?? -1 ),
		);

		return $identity['device'] >= 0 && $identity['inode'] > 0 && $identity['owner'] >= 0 && $identity['group'] >= 0
			? $identity
			: null;
	}

	/** @param resource $stream @return array{device:int,inode:int,links:int,owner:int,group:int}|null */
	private static function created_file_identity( string $path, $stream ): ?array {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fstat -- The exclusive file handle must identify the same Core-owned path.
		$stream_stat = fstat( $stream );
		$path_stat   = self::path_file_stat( $path );
		if ( false === $stream_stat || null === $path_stat ) {
			return null;
		}
		$stream_identity = self::stable_identity( $stream_stat );
		$path_identity   = self::stable_identity( $path_stat );
		if ( $path_identity['device'] < 0 || $path_identity['inode'] <= 0 || 1 !== $path_identity['links'] || $path_identity['owner'] < 0 || $path_identity['group'] < 0 ) {
			return null;
		}

		return $stream_identity === $path_identity ? $path_identity : null;
	}

	/** @return array{device:int,inode:int,links:int,owner:int,group:int}|null */
	private static function path_file_identity( string $path ): ?array {
		$stat = self::path_file_stat( $path );

		return null === $stat ? null : self::stable_identity( $stat );
	}

	/** @return array<string, int>|null */
	private static function path_file_stat( string $path ): ?array {
		clearstatcache( true, $path );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_lstat -- Symlink-aware identity is required for the exclusive Core-owned file.
		$stat = lstat( $path );
		$mode = false === $stat ? 0 : (int) ( $stat['mode'] ?? 0 );

		return false !== $stat && 0100000 === ( $mode & 0170000 ) ? $stat : null;
	}

	/** @param array<string, int> $stat @return array{device:int,inode:int,links:int,owner:int,group:int} */
	private static function stable_identity( array $stat ): array {
		return array(
			'device' => (int) ( $stat['dev'] ?? -1 ),
			'inode'  => (int) ( $stat['ino'] ?? 0 ),
			'links'  => (int) ( $stat['nlink'] ?? 0 ),
			'owner'  => (int) ( $stat['uid'] ?? -1 ),
			'group'  => (int) ( $stat['gid'] ?? -1 ),
		);
	}
}
