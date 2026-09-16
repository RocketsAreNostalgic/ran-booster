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
		$prepared                = $custody instanceof PreparedArtifact ? $custody : null;
		$inspectionInvoked       = false;
		$sourceDiscardAttempted  = false;
		$sourceDiscardSuccessful = false;
		$copyCleanupSuccessful   = true;

		try {
			$maximumArtifactBytes = PackageArtifactLimit::resolve();
			$resolvedRef          = $custody->resolvedRef();
			$version              = $custody->version();
			$size                 = $custody->size();
			$sha256               = $custody->sha256();

			if ( $size < 1
				|| $size > $maximumArtifactBytes
				|| 1 !== preg_match( '/\A[a-f0-9]{64}\z/D', $sha256 ) ) {
				throw new RuntimeException( 'The release artifact custody evidence is invalid.' );
			}

			// API-10 providers may still return PreparedArtifact covariantly. Keep
			// those implementations load-compatible while new providers can return
			// provider-owned custody without importing this Core concrete type.
			if ( $custody instanceof PreparedArtifact ) {
				$custody->assertUnchanged();

				return $custody;
			}

			$result = $custody->inspect(
				function ( string $source ) use ( &$prepared, &$inspectionInvoked, &$copyCleanupSuccessful, $resolvedRef, $version, $size, $maximumArtifactBytes, $sha256 ): PreparedArtifact {
					if ( $inspectionInvoked ) {
						throw new RuntimeException();
					}
					$inspectionInvoked = true;
					$prepared          = self::copyToCore( $source, $resolvedRef, $version, $size, $maximumArtifactBytes, $sha256, $copyCleanupSuccessful );

					return $prepared;
				}
			);
			if ( ! $inspectionInvoked || ! $prepared instanceof PreparedArtifact || $result !== $prepared ) {
				throw new RuntimeException();
			}

			$sourceDiscardAttempted  = true;
			$sourceDiscardSuccessful = true === $custody->discard();
			if ( ! $sourceDiscardSuccessful ) {
				throw new RuntimeException();
			}

			return $prepared;
		} catch ( Throwable ) {
			if ( $prepared instanceof PreparedArtifact ) {
				try {
					$prepared->cleanup();
				} catch ( Throwable ) {
					$copyCleanupSuccessful = false;
				}
			}
			if ( ! $sourceDiscardAttempted ) {
				try {
					$sourceDiscardSuccessful = true === $custody->discard();
				} catch ( Throwable ) {
					$sourceDiscardSuccessful = false;
				}
			}

			throw new RuntimeException(
				$copyCleanupSuccessful && $sourceDiscardSuccessful
					? 'The release artifact could not be transferred to Core.'
					: 'The release artifact transfer could not be cleaned up safely.'
			);
		}
	}

	private static function copyToCore(
		string $source,
		string $resolvedRef,
		string $version,
		int $artifactSize,
		int $maximumArtifactBytes,
		string $artifactSha256,
		bool &$copyCleanupSuccessful
	): PreparedArtifact {
		$directory    = sys_get_temp_dir() . '/ran-booster-release-' . bin2hex( random_bytes( 16 ) );
		$path         = $directory . '/archive.zip';
		$input        = false;
		$output       = false;
		$copyIdentity = null;

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- The random directory is the Core-owned custody boundary.
		if ( ! mkdir( $directory, 0700 ) ) {
			throw new RuntimeException();
		}
		$directoryIdentity = self::privateDirectoryIdentity( $directory );
		if ( null === $directoryIdentity ) {
			$copyCleanupSuccessful = false;
			throw new RuntimeException();
		}

		try {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Provider custody grants this bounded source inspection.
			$input = fopen( $source, 'rb' );
			if ( false === $input || $directoryIdentity !== self::privateDirectoryIdentity( $directory ) ) {
				throw new RuntimeException();
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- The exclusive Core destination is the temporary custody boundary.
			$output = fopen( $path, 'x+b' );
			if ( false === $output ) {
				throw new RuntimeException();
			}
			$copyIdentity = self::createdFileIdentity( $path, $output );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- The Core copy must remain private.
			if ( null === $copyIdentity || ! chmod( $path, 0600 ) ) {
				throw new RuntimeException();
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_stream_copy_to_stream -- Copy is fixed-bounded without holding the archive in memory.
			$size = stream_copy_to_stream( $input, $output, $artifactSize + 1 );
			if ( false === $size || $artifactSize !== $size || $size > $maximumArtifactBytes ) {
				throw new RuntimeException();
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close before Core identity capture.
			if ( ! fclose( $output ) ) {
				$output = false;
				throw new RuntimeException();
			}
			$output = false;
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close provider inspection before TOCTOU recheck.
			if ( ! fclose( $input ) ) {
				$input = false;
				throw new RuntimeException();
			}
			$input            = false;
			$preparedIdentity = PreparedArtifact::regularFileIdentity( $path );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_hash_file -- Custody transfer requires source/copy digest continuity.
			$copyDigest = hash_file( 'sha256', $path );
			if ( ! is_string( $copyDigest )
				|| ! hash_equals( $artifactSha256, $copyDigest )
				|| $directoryIdentity !== self::privateDirectoryIdentity( $directory )
				|| $copyIdentity !== self::pathFileIdentity( $path )
				|| null === $preparedIdentity
				|| $size !== $preparedIdentity['size'] ) {
				throw new RuntimeException();
			}

			return new PreparedArtifact(
				$path,
				$resolvedRef,
				$version,
				$artifactSha256,
				$preparedIdentity['device'],
				$preparedIdentity['inode'],
				$preparedIdentity['size'],
				$preparedIdentity['permissions'],
				$preparedIdentity['links'],
				$directory
			);
		} catch ( Throwable ) {
			$inputClosed = true;
			if ( is_resource( $input ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close failed bounded-copy input before cleanup.
				$inputClosed = fclose( $input );
				$input       = false;
			}
			$outputClosed = true;
			if ( is_resource( $output ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close failed bounded-copy output before cleanup.
				$outputClosed = fclose( $output );
				$output       = false;
			}

			$copyCleanupSuccessful = $inputClosed && $outputClosed;
			$copyCleanupSuccessful = self::removeCopy( $path, $directory, $directoryIdentity, $copyIdentity ) && $copyCleanupSuccessful;
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
	 * @param array{device:int,inode:int,owner:int,group:int}                $directoryIdentity
	 * @param array{device:int,inode:int,links:int,owner:int,group:int}|null $copyIdentity
	 */
	private static function removeCopy( string $path, string $directory, array $directoryIdentity, ?array $copyIdentity ): bool {
		if ( $directoryIdentity !== self::privateDirectoryIdentity( $directory ) ) {
			return false;
		}
		if ( null !== $copyIdentity ) {
			if ( $copyIdentity !== self::pathFileIdentity( $path ) ) {
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
		if ( $directoryIdentity !== self::privateDirectoryIdentity( $directory ) ) {
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
	private static function privateDirectoryIdentity( string $directory ): ?array {
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
	private static function createdFileIdentity( string $path, $stream ): ?array {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fstat -- The exclusive file handle must identify the same Core-owned path.
		$streamStat = fstat( $stream );
		$pathStat   = self::pathFileStat( $path );
		if ( false === $streamStat || null === $pathStat ) {
			return null;
		}
		$streamIdentity = self::stableIdentity( $streamStat );
		$pathIdentity   = self::stableIdentity( $pathStat );
		if ( $pathIdentity['device'] < 0 || $pathIdentity['inode'] <= 0 || 1 !== $pathIdentity['links'] || $pathIdentity['owner'] < 0 || $pathIdentity['group'] < 0 ) {
			return null;
		}

		return $streamIdentity === $pathIdentity ? $pathIdentity : null;
	}

	/** @return array{device:int,inode:int,links:int,owner:int,group:int}|null */
	private static function pathFileIdentity( string $path ): ?array {
		$stat = self::pathFileStat( $path );

		return null === $stat ? null : self::stableIdentity( $stat );
	}

	/** @return array<string, int>|null */
	private static function pathFileStat( string $path ): ?array {
		clearstatcache( true, $path );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_lstat -- Symlink-aware identity is required for the exclusive Core-owned file.
		$stat = lstat( $path );
		$mode = false === $stat ? 0 : (int) ( $stat['mode'] ?? 0 );

		return false !== $stat && 0100000 === ( $mode & 0170000 ) ? $stat : null;
	}

	/** @param array<string, int> $stat @return array{device:int,inode:int,links:int,owner:int,group:int} */
	private static function stableIdentity( array $stat ): array {
		return array(
			'device' => (int) ( $stat['dev'] ?? -1 ),
			'inode'  => (int) ( $stat['ino'] ?? 0 ),
			'links'  => (int) ( $stat['nlink'] ?? 0 ),
			'owner'  => (int) ( $stat['uid'] ?? -1 ),
			'group'  => (int) ( $stat['gid'] ?? -1 ),
		);
	}
}
