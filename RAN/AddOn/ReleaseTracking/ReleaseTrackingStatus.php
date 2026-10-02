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
		private int $source_revision,
		private string $provider_repository_id,
		private string $deployment_policy,
		private ReleaseTrackingEligibility $eligibility,
		private ?ReleaseTrackingPreflight $preflight = null,
		private string $package_root = '',
		private string $installed_version = '',
		private string $latest_version = '',
		private bool $update_available = false,
		private string $last_checked_at = '',
		private string $cooldown_until = '',
		private string $failure_code = '',
		private string $channel = 'stable',
		private string $native_offer_release_id = ''
	) {
		if ( ! in_array( $this->type, array( 'plugin', 'theme' ), true )
			|| '' === trim( $this->identifier )
			|| ! in_array( $this->source, array( 'branch', 'release_asset' ), true )
			|| $this->source_revision < 1
			|| '' === $this->provider_repository_id
			|| strlen( $this->provider_repository_id ) > 191
			|| 1 === preg_match( '/[\x00-\x1F\x7F]/', $this->provider_repository_id )
			|| ! in_array( $this->deployment_policy, array( 'disabled', 'manual', 'automatic' ), true )
			|| ! in_array( $this->channel, array( 'stable', 'prerelease' ), true )
			|| ( 'branch' === $this->source && 'stable' !== $this->channel ) ) {
			throw new InvalidArgumentException( 'Release tracking status requires a valid package identity.' );
		}

		foreach ( array( $this->package_root, $this->installed_version, $this->latest_version, $this->last_checked_at, $this->cooldown_until, $this->failure_code, $this->native_offer_release_id ) as $value ) {
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

	public function source_revision(): int {
		return $this->source_revision;
	}

	public function provider_repository_id(): string {
		return $this->provider_repository_id;
	}

	public function deployment_policy(): string {
		return $this->deployment_policy;
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

	public function package_root(): string {
		return $this->package_root;
	}

	public function installed_version(): string {
		return $this->installed_version;
	}

	public function latest_version(): string {
		return $this->latest_version;
	}

	public function update_available(): bool {
		return $this->update_available;
	}

	public function last_checked_at(): string {
		return $this->last_checked_at;
	}

	public function cooldown_until(): string {
		return $this->cooldown_until;
	}

	public function failure_code(): string {
		return $this->failure_code;
	}

	public function native_offer_release_id(): string {
		return $this->native_offer_release_id;
	}
}
