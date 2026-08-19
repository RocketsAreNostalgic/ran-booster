<?php

declare(strict_types=1);

namespace RAN\Booster\GitHub;

use RAN\Deployment\PreparedArtifact;
use RAN\RepositoryProvider\RepositoryReleaseArtifact;
use RuntimeException;

/**
 * GitHub updater custody retained until Core finishes with the prepared file.
 *
 * @internal
 */
final class GitHubReleaseArtifact implements RepositoryReleaseArtifact {

	private bool $handedOff      = false;
	private ?bool $discardResult = null;
	private ?object $claim       = null;

	public function __construct(
		private object $artifact,
		private string $version,
		private string $providerCommitId,
		private string $packageRoot,
		private string $mainFile
	) {
		if ( ! is_callable( array( $artifact, 'discard' ) )
			|| ! is_callable( array( $artifact, 'handoffToCore' ) )
			|| 1 !== preg_match( '/\A[A-Za-z0-9][A-Za-z0-9._+-]{0,63}\z/D', $version )
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
		if ( null === $this->claim ) {
			$this->discardResult = true === $this->artifact->discard();
		} else {
			$this->discardResult = true === $this->claim->discard();
		}
		if ( $this->discardResult ) {
			$this->claim = null;
		}

		return $this->discardResult;
	}

	public function handoffToCore(): PreparedArtifact {
		if ( $this->handedOff || null !== $this->discardResult ) {
			throw new RuntimeException( 'The GitHub release artifact is unavailable.' );
		}

		try {
			if ( null === $this->claim ) {
				$claim = $this->artifact->handoffToCore();
				if ( $claim instanceof \WP_Error
					|| ! is_object( $claim )
					|| ! is_callable( array( $claim, 'discard' ) ) ) {
					throw new RuntimeException();
				}
				$this->claim = $claim;
			}

			$prepared        = PreparedArtifact::fromReleaseClaim(
				$this->claim,
				$this->providerCommitId,
				$this->version
			);
			$this->handedOff = true;
			$this->claim     = null;

			return $prepared;
		} catch ( \Throwable ) {
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
}
