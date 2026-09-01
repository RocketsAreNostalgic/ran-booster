<?php

declare(strict_types=1);

namespace RAN\AddOn\ReleaseTracking;

use InvalidArgumentException;

/**
 * Secret-free release state suitable for package administration pages.
 */
final readonly class ReleaseTrackingStatus {

	public function __construct(
		private string $type,
		private string $identifier,
		private string $source,
		private int $sourceRevision,
		private string $providerRepositoryId,
		private string $deploymentPolicy,
		private ReleaseTrackingEligibility $eligibility,
		private ?ReleaseTrackingPreflight $preflight = null,
		private string $packageRoot = '',
		private string $installedVersion = '',
		private string $latestVersion = '',
		private bool $updateAvailable = false,
		private string $lastCheckedAt = '',
		private string $cooldownUntil = '',
		private string $failureCode = '',
		private string $channel = 'stable',
		private string $nativeOfferReleaseId = ''
	) {
		if ( ! in_array( $this->type, array( 'plugin', 'theme' ), true )
			|| '' === trim( $this->identifier )
			|| ! in_array( $this->source, array( 'branch', 'release_asset' ), true )
			|| $this->sourceRevision < 1
			|| '' === $this->providerRepositoryId
			|| strlen( $this->providerRepositoryId ) > 191
			|| 1 === preg_match( '/[\x00-\x1F\x7F]/', $this->providerRepositoryId )
			|| ! in_array( $this->deploymentPolicy, array( 'disabled', 'manual', 'automatic' ), true )
			|| ! in_array( $this->channel, array( 'stable', 'prerelease' ), true )
			|| ( 'branch' === $this->source && 'stable' !== $this->channel ) ) {
			throw new InvalidArgumentException( 'Release tracking status requires a valid package identity.' );
		}

		foreach ( array( $this->packageRoot, $this->installedVersion, $this->latestVersion, $this->lastCheckedAt, $this->cooldownUntil, $this->failureCode, $this->nativeOfferReleaseId ) as $value ) {
			if ( strlen( $value ) > 255 || 1 === preg_match( '/[\x00-\x1F\x7F]/', $value ) ) {
				throw new InvalidArgumentException( 'Release tracking status values must be bounded display values.' );
			}
		}
	}

	public function type(): string {
		return $this->type;
	}

	public function identifier(): string {
		return $this->identifier;
	}

	public function source(): string {
		return $this->source;
	}

	public function sourceRevision(): int {
		return $this->sourceRevision;
	}

	public function providerRepositoryId(): string {
		return $this->providerRepositoryId;
	}

	public function deploymentPolicy(): string {
		return $this->deploymentPolicy;
	}

	public function channel(): string {
		return $this->channel;
	}

	public function eligibility(): ReleaseTrackingEligibility {
		return $this->eligibility;
	}

	public function eligible(): bool {
		return $this->eligibility->eligible();
	}

	public function preflight(): ?ReleaseTrackingPreflight {
		return $this->preflight;
	}

	public function packageRoot(): string {
		return $this->packageRoot;
	}

	public function installedVersion(): string {
		return $this->installedVersion;
	}

	public function latestVersion(): string {
		return $this->latestVersion;
	}

	public function updateAvailable(): bool {
		return $this->updateAvailable;
	}

	public function lastCheckedAt(): string {
		return $this->lastCheckedAt;
	}

	public function cooldownUntil(): string {
		return $this->cooldownUntil;
	}

	public function failureCode(): string {
		return $this->failureCode;
	}

	public function nativeOfferReleaseId(): string {
		return $this->nativeOfferReleaseId;
	}
}
