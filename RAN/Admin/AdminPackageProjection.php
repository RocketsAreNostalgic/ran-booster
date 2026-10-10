<?php

declare(strict_types=1);

namespace RAN\Admin;

use InvalidArgumentException;

/**
 * Display-safe managed-package data exposed to trusted add-ons.
 */
final readonly class AdminPackageProjection implements PackageDisplayProjection {
	private string $subdirectory;

	public function __construct(
		private string $type,
		private string $identifier,
		private string $display_name,
		private string $provider_code,
		private string $source,
		private int $source_revision,
		private string $deployment_policy,
		private string $settings_url,
		string $subdirectory = ''
	) {
		if ( ! in_array( $this->type, array( 'plugin', 'theme' ), true ) ) {
			throw new InvalidArgumentException( 'Package projections require a known package type.' );
		}

		if ( '' === trim( $this->identifier ) || strlen( $this->identifier ) > 255 ) {
			throw new InvalidArgumentException( 'Package projections require a bounded identifier.' );
		}

		if ( '' === trim( $this->display_name ) || strlen( $this->display_name ) > 255 ) {
			throw new InvalidArgumentException( 'Package projections require a bounded display name.' );
		}

		if ( '' !== $this->provider_code && 1 !== preg_match( '/^[a-z][a-z0-9_-]{0,31}$/', $this->provider_code ) ) {
			throw new InvalidArgumentException( 'Package projections require a provider code.' );
		}

		if ( ! in_array( $this->source, array( 'branch', 'release_asset' ), true ) || $this->source_revision < 1 ) {
			throw new InvalidArgumentException( 'Package projections require a valid source identity.' );
		}

		$this->subdirectory = trim( $subdirectory );
		if ( strlen( $this->subdirectory ) > 255 ) {
			throw new InvalidArgumentException( 'Package projections require a bounded repository subdirectory.' );
		}

		if ( ! in_array( $this->deployment_policy, array( 'disabled', 'manual', 'automatic' ), true ) ) {
			throw new InvalidArgumentException( 'Package projections require a known deployment policy.' );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- This value object can load before WordPress URL helpers.
		$url_parts = parse_url( $this->settings_url );
		if ( ! is_array( $url_parts )
			|| ! isset( $url_parts['scheme'], $url_parts['host'] )
			|| ! in_array( strtolower( $url_parts['scheme'] ), array( 'http', 'https' ), true )
			|| isset( $url_parts['user'] )
			|| isset( $url_parts['pass'] )
			|| isset( $url_parts['fragment'] ) ) {
			throw new InvalidArgumentException( 'Package projections require a canonical settings URL.' );
		}
	}

	public function type(): string {
		return $this->type;
	}

	public function identifier(): string {
		return $this->identifier;
	}

	public function display_name(): string {
		return $this->display_name;
	}

	public function provider_code(): string {
		return $this->provider_code;
	}

	public function source(): string {
		return $this->source;
	}

	public function source_revision(): int {
		return $this->source_revision;
	}

	public function subdirectory(): string {
		return $this->subdirectory;
	}

	public function deployment_policy(): string {
		return $this->deployment_policy;
	}

	public function settings_url(): string {
		return $this->settings_url;
	}
}
