<?php

declare(strict_types=1);

namespace RAN\AddOn\ReleaseTracking;

use InvalidArgumentException;

/**
 * Bounded result of a read-only repository release artifact preflight.
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
		private string $versionRelationship = '',
		private string $reasonCode = ''
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
			|| ( '' !== $this->reasonCode && ! in_array( $this->reasonCode, self::reasonCodes(), true ) )
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

	/**
	 * Return a bounded machine-readable cause without provider response data.
	 */
	public function reasonCode(): string {
		return $this->reasonCode;
	}

	/** @return list<string> */
	private static function reasonCodes(): array {
		return array(
			'provider_unavailable',
			'no_releases',
			'invalid_release',
			'release_identity_mismatch',
			'release_incompatible',
			'release_version_mismatch',
			'package_header_missing',
			'package_header_invalid',
			'package_archive_unreadable',
			'package_zip_extension_unavailable',
			'package_archive_size_invalid',
			'package_archive_too_large',
			'package_archive_path_unsafe',
			'package_archive_path_duplicate',
			'package_archive_root_invalid',
			'package_archive_entry_duplicate',
			'package_archive_entry_limit',
			'release_version_invalid',
			'package_update_uri_missing',
			'package_update_uri_invalid',
			'package_compatibility_missing',
			'package_compatibility_invalid',
			'package_header_ambiguous',
		);
	}

	private function validReleaseUrl( string $url ): bool {
		if ( 1 === preg_match( '/[\x00-\x20\x7F]/', $url )
			|| false === filter_var( $url, FILTER_VALIDATE_URL ) ) {
			return false;
		}

		$parts = function_exists( 'wp_parse_url' )
			? wp_parse_url( $url )
			: parse_url( $url ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- This value object is also exercised without a WordPress bootstrap.

		return is_array( $parts )
			&& 'https' === ( $parts['scheme'] ?? null )
			&& is_string( $parts['host'] ?? null )
			&& '' !== $parts['host']
			&& ! isset( $parts['user'] )
			&& ! isset( $parts['pass'] )
			&& is_string( $parts['path'] ?? null )
			&& str_starts_with( $parts['path'], '/' );
	}
}
