<?php

declare(strict_types=1);

namespace RAN\Admin;

use InvalidArgumentException;

/**
 * Display-safe managed-package data exposed to trusted add-ons.
 */
final readonly class AdminPackageProjection {

	public function __construct(
		private string $type,
		private string $identifier,
		private string $displayName,
		private string $providerCode,
		private string $source,
		private int $sourceRevision,
		private string $deploymentPolicy,
		private string $settingsUrl
	) {
		if ( ! in_array( $this->type, array( 'plugin', 'theme' ), true ) ) {
			throw new InvalidArgumentException( 'Package projections require a known package type.' );
		}

		if ( '' === trim( $this->identifier ) || strlen( $this->identifier ) > 255 ) {
			throw new InvalidArgumentException( 'Package projections require a bounded identifier.' );
		}

		if ( '' === trim( $this->displayName ) || strlen( $this->displayName ) > 255 ) {
			throw new InvalidArgumentException( 'Package projections require a bounded display name.' );
		}

		if ( '' !== $this->providerCode && 1 !== preg_match( '/^[a-z][a-z0-9_-]{0,31}$/', $this->providerCode ) ) {
			throw new InvalidArgumentException( 'Package projections require a provider code.' );
		}

		if ( ! in_array( $this->source, array( 'branch', 'release_asset' ), true ) || $this->sourceRevision < 1 ) {
			throw new InvalidArgumentException( 'Package projections require a valid source identity.' );
		}

		if ( ! in_array( $this->deploymentPolicy, array( 'disabled', 'manual', 'automatic' ), true ) ) {
			throw new InvalidArgumentException( 'Package projections require a known deployment policy.' );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- This value object can load before WordPress URL helpers.
		$urlParts = parse_url( $this->settingsUrl );
		if ( ! is_array( $urlParts )
			|| ! isset( $urlParts['scheme'], $urlParts['host'] )
			|| ! in_array( strtolower( $urlParts['scheme'] ), array( 'http', 'https' ), true )
			|| isset( $urlParts['user'] )
			|| isset( $urlParts['pass'] )
			|| isset( $urlParts['fragment'] ) ) {
			throw new InvalidArgumentException( 'Package projections require a canonical settings URL.' );
		}
	}

	public function type(): string {
		return $this->type;
	}

	public function identifier(): string {
		return $this->identifier;
	}

	public function displayName(): string {
		return $this->displayName;
	}

	public function providerCode(): string {
		return $this->providerCode;
	}

	public function source(): string {
		return $this->source;
	}

	public function sourceRevision(): int {
		return $this->sourceRevision;
	}

	public function deploymentPolicy(): string {
		return $this->deploymentPolicy;
	}

	public function settingsUrl(): string {
		return $this->settingsUrl;
	}
}
