<?php

declare(strict_types=1);

namespace RAN\Booster\GitHub;

use RAN\Deployment\PreparedArtifact;
use RAN\RepositoryProvider\RepositoryReleaseArtifact;
use RAN\WPReleaseUpdater\V1\Archive\TemporaryArtifact;
use RuntimeException;

/**
 * GitHub updater custody retained until Core finishes with the prepared file.
 *
 * @internal
 */
final class GitHubReleaseArtifact implements RepositoryReleaseArtifact {

	private const MAX_COPY_BYTES = 52428800;

	private bool $handedOff      = false;
	private ?bool $discardResult = null;
	public function __construct(
		private TemporaryArtifact $artifact,
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
		$this->discardResult = true === $this->artifact->discard();

		return $this->discardResult;
	}

	public function handoffToCore(): PreparedArtifact {
		if ( $this->handedOff || null !== $this->discardResult ) {
			throw new RuntimeException( 'The GitHub release artifact is unavailable.' );
		}

		try {
			$prepared = $this->artifact->inspect(
				fn ( string $source ): PreparedArtifact => $this->copyToCore( $source )
			);
			if ( ! ( $prepared instanceof PreparedArtifact ) || ! $this->artifact->discard() ) {
				$prepared?->cleanup();
				throw new RuntimeException();
			}
			$this->handedOff = true;

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

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- The random directory is the Core-owned custody boundary.
		if ( null === $sourceIdentity || ! mkdir( $directory, 0700 ) ) {
			$this->removeCopy( $path, $directory );
			throw new RuntimeException();
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- The provider source is copied only while its artifact permits inspection.
		$input = fopen( $source, 'rb' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- The exclusive Core destination is the temporary custody boundary.
		$output = fopen( $path, 'x+b' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- The Core copy must remain private.
		$private = chmod( $path, 0600 );
		if ( false === $input || false === $output || ! $private ) {
			if ( is_resource( $input ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Release the source stream before failed Core-copy cleanup.
				fclose( $input );
			}
			if ( is_resource( $output ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Release the destination stream before failed Core-copy cleanup.
				fclose( $output );
			}
			$this->removeCopy( $path, $directory );
			throw new RuntimeException();
		}

		try {
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
			$input        = false;
			$copyIdentity = PreparedArtifact::regularFileIdentity( $path );
			$sourceDigest = hash_file( 'sha256', $source );
			$copyDigest   = hash_file( 'sha256', $path );
			if ( ! is_string( $sourceDigest )
				|| ! is_string( $copyDigest )
				|| ! hash_equals( $sourceDigest, $copyDigest )
				|| $sourceIdentity !== PreparedArtifact::regularFileIdentity( $source )
				|| null === $copyIdentity
				|| $size !== $copyIdentity['size'] ) {
				throw new RuntimeException();
			}

			return new PreparedArtifact( $path, $this->providerCommitId, $this->version, $sourceDigest, $copyIdentity['device'], $copyIdentity['inode'], $copyIdentity['size'], $copyIdentity['permissions'], $copyIdentity['links'], $directory );
		} catch ( \Throwable ) {
			$this->removeCopy( $path, $directory );
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

	private function removeCopy( string $path, string $directory ): void {
		if ( is_file( $path ) || is_link( $path ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- This removes only the failed Core-owned copy.
			unlink( $path );
		}
		if ( is_dir( $directory ) && ! is_link( $directory ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- This removes only the failed Core-owned random directory.
			rmdir( $directory );
		}
	}
}
