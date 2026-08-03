<?php

declare(strict_types=1);

namespace RAN\AddOn\ReleaseTracking;

use InvalidArgumentException;

/**
 * Bounded result of a read-only GitHub Release artifact preflight.
 */
final readonly class ReleaseTrackingPreflight {

	public const READY                      = 'ready';
	public const RELEASE_UNAVAILABLE        = 'release_unavailable';
	public const INVALID_RELEASE_ASSETS     = 'invalid_release_assets';
	public const PREFLIGHT_UNAVAILABLE      = 'preflight_unavailable';
	public const RELEASE_VERSION_MISMATCH   = 'release_version_mismatch';
	public const RELEASE_HEADER_MISSING     = 'release_header_missing';
	public const RELEASE_HEADER_INVALID     = 'release_header_invalid';
	public const RELEASE_ARCHIVE_UNREADABLE = 'release_archive_unreadable';

	public function __construct(
		private string $code,
		private string $packageRoot,
		private string $latestVersion = '',
		private string $releaseUrl = '',
		private string $releaseTag = '',
		private string $packageHeaderVersion = '',
		private string $versionRelationship = ''
	) {
		if ( ! in_array(
			$this->code,
			array(
				self::READY,
				self::RELEASE_UNAVAILABLE,
				self::INVALID_RELEASE_ASSETS,
				self::PREFLIGHT_UNAVAILABLE,
				self::RELEASE_VERSION_MISMATCH,
				self::RELEASE_HEADER_MISSING,
				self::RELEASE_HEADER_INVALID,
				self::RELEASE_ARCHIVE_UNREADABLE,
			),
			true
		) || 1 !== preg_match( '/\A[A-Za-z0-9](?:[A-Za-z0-9._-]{0,99})\z/D', $this->packageRoot )
			|| strlen( $this->latestVersion ) > 64
			|| strlen( $this->releaseUrl ) > 512
			|| strlen( $this->releaseTag ) > 128
			|| strlen( $this->packageHeaderVersion ) > 64
			|| ! in_array( $this->versionRelationship, array( '', 'newer', 'same', 'older', 'invalid' ), true )
			|| 1 === preg_match( '/[\x00-\x1F\x7F]/', $this->releaseTag . $this->packageHeaderVersion )
			|| ( '' !== $this->releaseUrl && ! $this->validReleaseUrl( $this->releaseUrl ) ) ) {
			throw new InvalidArgumentException( 'Release tracking preflight is invalid.' );
		}
	}

	public function code(): string {
		return $this->code;
	}

	public function ready(): bool {
		return self::READY === $this->code;
	}

	public function packageRoot(): string {
		return $this->packageRoot;
	}

	public function latestVersion(): string {
		return $this->latestVersion;
	}

	public function releaseUrl(): string {
		return $this->releaseUrl;
	}

	public function releaseTag(): string {
		return $this->releaseTag;
	}

	public function packageHeaderVersion(): string {
		return $this->packageHeaderVersion;
	}

	public function versionRelationship(): string {
		return $this->versionRelationship;
	}

	private function validReleaseUrl( string $url ): bool {
		$parts = function_exists( 'wp_parse_url' )
			? wp_parse_url( $url )
			: parse_url( $url ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- This value object is also exercised without a WordPress bootstrap.

		return is_array( $parts )
			&& 'https' === ( $parts['scheme'] ?? null )
			&& 'github.com' === ( $parts['host'] ?? null )
			&& is_string( $parts['path'] ?? null )
			&& str_starts_with( $parts['path'], '/' );
	}
}
