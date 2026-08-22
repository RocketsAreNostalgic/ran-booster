<?php

declare(strict_types=1);

namespace RAN\Booster\GitHub;

use RAN\Deployment\PreparedArtifact;
use RAN\RepositoryProvider\RepositoryReleaseArtifact;
use RAN\WPReleaseUpdater\V1\Archive\TemporaryArtifact;
use RAN\WPReleaseUpdater\V1\Provider\GitHub\ProspectiveReleaseArtifact;
use RuntimeException;

/**
 * GitHub updater custody retained until Core finishes with the prepared file.
 *
 * @internal
 */
final class GitHubReleaseArtifact implements RepositoryReleaseArtifact {
	private const MAX_COPY_BYTES = 52428800;

	private bool $handedOff                     = false;
	private ?bool $discardResult                = null;
	private ?TemporaryArtifact $claimedArtifact = null;

	public function __construct(
		private ProspectiveReleaseArtifact $artifact,
		private string $version,
		private string $providerCommitId,
		private string $packageRoot,
		private string $mainFile
	) {
		if ( 1 !== preg_match( '/\A[A-Za-z0-9][A-Za-z0-9._+-]{0,63}\z/D', $version )
			|| ! $this->boundedOpaqueValue( $providerCommitId, 191 )
			|| 1 !== preg_match( '/\A[A-Za-z0-9][A-Za-z0-9._-]{0,190}\z/D', $packageRoot )
			|| 1 !== preg_match( '/\A[A-Za-z0-9][A-Za-z0-9._-]{0,190}\z/D', $mainFile ) ) {
			throw new RuntimeException( 'The GitHub release artifact is invalid.' );
		}
	}

	public function __destruct() {
		if ( ! $this->handedOff ) {
			try {
				$this->discard();
			// phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- The synchronous caller owns the reportable cleanup postcondition.
			} catch ( \Throwable ) {
				// The synchronous caller owns the reportable cleanup postcondition.
			}
		}
	}

	public function discard(): bool {
		if ( $this->handedOff ) {
			return true;
		}
		if ( null !== $this->discardResult ) {
			return $this->discardResult;
		}
		$this->discardResult = null === $this->claimedArtifact
			? true === $this->artifact->discard()
			: true === $this->claimedArtifact->discard();
		if ( $this->discardResult ) {
			$this->claimedArtifact = null;
		}

		return $this->discardResult;
	}

	public function handoffToCore(): PreparedArtifact {
		if ( $this->handedOff || null !== $this->discardResult ) {
			throw new RuntimeException( 'The GitHub release artifact is unavailable.' );
		}

		try {
			$this->claimedArtifact ??= $this->artifact->claimTemporaryArtifact();
			$prepared                = $this->claimedArtifact->inspect(
				fn ( string $source ): PreparedArtifact => $this->copyToCore( $source )
			);
			if ( ! $prepared instanceof PreparedArtifact || ! $this->claimedArtifact->discard() ) {
				$prepared?->cleanup();
				throw new RuntimeException();
			}
			$this->handedOff       = true;
			$this->claimedArtifact = null;

			return $prepared;
		} catch ( \Throwable ) {
			$this->discard();
			throw new RuntimeException( 'The GitHub release artifact could not be prepared.' );
		}
	}

	public function version(): string {
		return $this->version;
	}

	public function packageRoot(): string {
		return $this->packageRoot;
	}

	public function mainFile(): string {
		return $this->mainFile;
	}

	public function identifier( string $packageType ): string {
		if ( ! in_array( $packageType, array( 'plugin', 'theme' ), true ) ) {
			throw new RuntimeException( 'The GitHub release artifact package type is invalid.' );
		}

		return 'plugin' === $packageType
			? $this->packageRoot . '/' . $this->mainFile
			: $this->packageRoot;
	}

	private function boundedOpaqueValue( string $value, int $maximumBytes ): bool {
		return '' !== $value
			&& strlen( $value ) <= $maximumBytes
			&& 1 !== preg_match( '/[\x00-\x1F\x7F]/', $value );
	}

	private function copyToCore( string $source ): PreparedArtifact {
		$sourceIdentity = PreparedArtifact::regularFileIdentity( $source );
		$directory      = sys_get_temp_dir() . '/ran-booster-release-' . bin2hex( random_bytes( 16 ) );
		$path           = $directory . '/archive.zip';
		$input          = false;
		$output         = false;
		$copyIdentity   = null;

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- The random directory is the Core-owned custody boundary.
		if ( null === $sourceIdentity || ! mkdir( $directory, 0700 ) ) {
			throw new RuntimeException();
		}
		$directoryIdentity = self::privateDirectoryIdentity( $directory );
		if ( null === $directoryIdentity ) {
			throw new RuntimeException();
		}

		try {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- The provider source is copied only while its artifact permits inspection.
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
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_stream_copy_to_stream -- The source is copied in a fixed upper bound without holding the archive in memory.
			$size = stream_copy_to_stream( $input, $output, self::MAX_COPY_BYTES + 1 );
			if ( false === $size || self::MAX_COPY_BYTES < $size ) {
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
			$sourceDigest = hash_file( 'sha256', $source );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_hash_file -- Custody transfer requires source/copy digest continuity.
			$copyDigest = hash_file( 'sha256', $path );
			if ( ! is_string( $sourceDigest )
				|| ! is_string( $copyDigest )
				|| ! hash_equals( $sourceDigest, $copyDigest )
				|| $sourceIdentity !== PreparedArtifact::regularFileIdentity( $source )
				|| $directoryIdentity !== self::privateDirectoryIdentity( $directory )
				|| $copyIdentity !== self::pathFileIdentity( $path )
				|| null === $preparedIdentity
				|| $size !== $preparedIdentity['size'] ) {
				throw new RuntimeException();
			}

			return new PreparedArtifact( $path, $this->providerCommitId, $this->version, $sourceDigest, $preparedIdentity['device'], $preparedIdentity['inode'], $preparedIdentity['size'], $preparedIdentity['permissions'], $preparedIdentity['links'], $directory );
		} catch ( \Throwable ) {
			$this->removeCopy( $path, $directory, $directoryIdentity, $copyIdentity );
			throw new RuntimeException();
		} finally {
			if ( is_resource( $input ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Release the failed bounded copy input.
				fclose( $input );
			}
			if ( is_resource( $output ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Release the failed bounded copy output.
				fclose( $output );
			}
		}
	}

	/**
	 * @param array{device:int,inode:int,owner:int,group:int}                $directoryIdentity
	 * @param array{device:int,inode:int,links:int,owner:int,group:int}|null $copyIdentity
	 */
	private function removeCopy( string $path, string $directory, array $directoryIdentity, ?array $copyIdentity ): void {
		if ( $directoryIdentity !== self::privateDirectoryIdentity( $directory ) ) {
			return;
		}
		if ( null !== $copyIdentity ) {
			if ( $copyIdentity !== self::pathFileIdentity( $path ) ) {
				return;
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- This removes only the failed Core-owned copy.
			unlink( $path );
			clearstatcache( true, $path );
			if ( file_exists( $path ) || is_link( $path ) ) {
				return;
			}
		} elseif ( file_exists( $path ) || is_link( $path ) ) {
			return;
		}
		if ( $directoryIdentity === self::privateDirectoryIdentity( $directory ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- This removes only the failed Core-owned random directory.
			rmdir( $directory );
		}
	}

	/** @return array{device:int,inode:int,owner:int,group:int}|null */
	private static function privateDirectoryIdentity( string $directory ): ?array {
		clearstatcache( true, $directory );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_lstat -- Symlink-aware identity is required for the Core-owned directory.
		$stat = lstat( $directory );
		$mode = false === $stat ? 0 : (int) ( $stat['mode'] ?? 0 );
		if ( false === $stat
			|| 0040000 !== ( $mode & 0170000 )
			|| 0700 !== ( $mode & 0777 ) ) {
			return null;
		}

		$identity = array(
			'device' => (int) ( $stat['dev'] ?? -1 ),
			'inode'  => (int) ( $stat['ino'] ?? 0 ),
			'owner'  => (int) ( $stat['uid'] ?? -1 ),
			'group'  => (int) ( $stat['gid'] ?? -1 ),
		);

		return $identity['device'] >= 0
			&& $identity['inode'] > 0
			&& $identity['owner'] >= 0
			&& $identity['group'] >= 0
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
		if ( $pathIdentity['device'] < 0
			|| $pathIdentity['inode'] <= 0
			|| 1 !== $pathIdentity['links']
			|| $pathIdentity['owner'] < 0
			|| $pathIdentity['group'] < 0 ) {
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
