<?php

declare(strict_types=1);

namespace RAN\Booster\GitHub;

use RAN\RepositoryProvider\RepositoryReleaseArtifact;
use RAN\RepositoryProvider\RepositoryReleaseArtifactCustody;
use RuntimeException;

/**
 * GitHub-owned release source retained until Core claims or discards it.
 *
 * @internal
 */
final class GitHubReleaseArtifact implements RepositoryReleaseArtifact, RepositoryReleaseArtifactCustody {

	private bool $handedOff      = false;
	private ?bool $discardResult = null;

	public function __construct(
		private object $artifact,
		private string $version,
		private string $providerCommitId,
		private string $packageRoot,
		private string $mainFile,
		private int $artifactSize,
		int $maximumArtifactBytes,
		private string $artifactSha256
	) {
		if ( 1 !== preg_match( '/\A[A-Za-z0-9][A-Za-z0-9._+-]{0,63}\z/D', $version )
			|| ! $this->boundedOpaqueValue( $providerCommitId, 191 )
			|| 1 !== preg_match( '/\A[A-Za-z0-9][A-Za-z0-9._-]{0,190}\z/D', $packageRoot )
			|| 1 !== preg_match( '/\A[A-Za-z0-9][A-Za-z0-9._-]{0,190}\z/D', $mainFile )
			|| $artifactSize < 1 || $maximumArtifactBytes < $artifactSize
			|| 1 !== preg_match( '/\A[a-f0-9]{64}\z/D', $artifactSha256 )
			|| ! method_exists( $artifact, 'inspect' ) || ! method_exists( $artifact, 'discard' ) ) {
			throw new RuntimeException( 'The GitHub release artifact is invalid.' );
		}
	}

	public function __destruct() {
		if ( true !== $this->discardResult ) {
			try {
				$this->discard();
			// phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- The synchronous caller owns the reportable cleanup postcondition.
			} catch ( \Throwable ) {
				// The synchronous caller owns the reportable cleanup postcondition.
			}
		}
	}

	public function discard(): bool {
		if ( true === $this->discardResult ) {
			return true;
		}
		$discarded           = true === $this->artifact->discard();
		$this->discardResult = $discarded ? true : null;

		return $discarded;
	}

	public function handoffToCore(): RepositoryReleaseArtifactCustody {
		if ( $this->handedOff || true === $this->discardResult ) {
			throw new RuntimeException( 'The GitHub release artifact is unavailable.' );
		}

		$this->handedOff = true;

		return $this;
	}

	/** @param callable(string): mixed $inspection */
	public function inspect( callable $inspection ): mixed {
		if ( ! $this->handedOff || true === $this->discardResult ) {
			throw new RuntimeException( 'The GitHub release artifact is unavailable.' );
		}

		return $this->artifact->inspect( $inspection );
	}

	public function resolvedRef(): string {
		return $this->providerCommitId;
	}

	public function size(): int {
		return $this->artifactSize;
	}

	public function sha256(): string {
		return $this->artifactSha256;
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
}
