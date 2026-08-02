<?php

declare(strict_types=1);

namespace RAN\WordPress;

use RAN\Deployment\PreparedArtifact;

/**
 * Exact release archive plus the inspected identity needed for initial adoption.
 */
final class ProspectiveReleaseArtifact {

	private bool $handedOff      = false;
	private ?bool $discardResult = null;
	private ?object $claim       = null;

	public function __construct(
		private object $artifact,
		private int $releaseId,
		private string $tag,
		private string $version,
		private string $commit,
		private string $detailsUrl,
		private string $packageRoot,
		private string $mainFile
	) {
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
			$this->discardResult = $this->artifact->discard();
		} else {
			$this->discardResult = $this->claim->discard();
		}
		if ( $this->discardResult ) {
			$this->claim = null;
		}

		return $this->discardResult;
	}

	/**
	 * Transfer the updater-owned file to Core and freeze its local identity.
	 *
	 * @return PreparedArtifact|\WP_Error
	 */
	public function handoffToCore(): PreparedArtifact|\WP_Error {
		if ( $this->handedOff || null !== $this->discardResult ) {
			return new \WP_Error(
				'github_updater_artifact_already_claimed',
				'The release artifact can be handed to WordPress Core only once.'
			);
		}

		if ( null === $this->claim ) {
			$claimed = $this->artifact->handoffToCore();
			if ( $claimed instanceof \WP_Error ) {
				return $claimed;
			}
			$this->claim = $claimed;
		}

		try {
			$attestation = $this->claim->assertUnchanged();
			$identity    = $attestation['identity'];

			$prepared        = new PreparedArtifact(
				$this->claim->path(),
				$this->commit,
				$this->version,
				$attestation['sha256'],
				$identity['dev'],
				$identity['ino'],
				$identity['size'],
				$identity['mode'] & 0777,
				$identity['nlink']
			);
			$this->handedOff = true;
			$this->claim     = null;

			return $prepared;
		} catch ( \Throwable ) {
			return new \WP_Error(
				'github_updater_release_artifact_unavailable',
				'The exact release archive could not be prepared.'
			);
		}
	}

	public function releaseId(): int {
		return $this->releaseId;
	}

	public function tag(): string {
		return $this->tag;
	}

	public function version(): string {
		return $this->version;
	}

	public function commit(): string {
		return $this->commit;
	}

	public function detailsUrl(): string {
		return $this->detailsUrl;
	}

	public function packageRoot(): string {
		return $this->packageRoot;
	}

	public function mainFile(): string {
		return $this->mainFile;
	}

	public function identifier( string $type ): string {
		return 'plugin' === $type
			? $this->packageRoot . '/' . $this->mainFile
			: $this->packageRoot;
	}
}
