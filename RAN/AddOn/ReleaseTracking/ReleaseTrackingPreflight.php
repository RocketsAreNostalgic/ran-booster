<?php

declare(strict_types=1);

namespace RAN\AddOn\ReleaseTracking;

use InvalidArgumentException;
use RAN\RepositoryProvider\RepositoryReleaseWorkflowPreflight;

/**
 * Bounded result of a read-only repository release artifact preflight.
 */
final readonly class ReleaseTrackingPreflight extends RepositoryReleaseWorkflowPreflight {

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

		private string $package_root,

		private string $latest_version = '',

		private string $release_url = '',

		private string $release_tag = '',

		private string $package_header_version = '',

		private string $version_relationship = '',

		private string $reason_code = ''
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

		) || 1 !== preg_match( '/\A[A-Za-z0-9](?:[A-Za-z0-9._-]{0,99})\z/D', $this->package_root )

			|| strlen( $this->latest_version ) > 64

			|| strlen( $this->release_url ) > 512

			|| strlen( $this->release_tag ) > 128

			|| strlen( $this->package_header_version ) > 64

			|| ! in_array( $this->version_relationship, array( '', 'newer', 'same', 'older', 'invalid' ), true )

			|| ( '' !== $this->reason_code && ! in_array( $this->reason_code, self::reason_codes(), true ) )

			|| 1 === preg_match( '/[\x00-\x1F\x7F]/', $this->release_tag . $this->package_header_version )

			|| ( '' !== $this->release_url && ! $this->valid_release_url( $this->release_url ) ) ) {
			throw new InvalidArgumentException( 'Release tracking preflight is invalid.' );
		}


		parent::__construct( $this->code, $this->reason_code );
	}

	public function code(): string {
		return $this->code;
	}

	public function ready(): bool {
		return self::READY === $this->code;
	}


	public function package_root(): string {

		return $this->package_root;
	}


	public function latest_version(): string {

		return $this->latest_version;
	}


	public function release_url(): string {

		return $this->release_url;
	}


	public function release_tag(): string {

		return $this->release_tag;
	}


	public function package_header_version(): string {

		return $this->package_header_version;
	}


	public function version_relationship(): string {

		return $this->version_relationship;
	}

	/**
	 * Return a bounded machine-readable cause without provider response data.
	 */

	public function reason_code(): string {

		return $this->reason_code;
	}

	/** @return list<string> */
	private static function reason_codes(): array {
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

	private function valid_release_url( string $url ): bool {
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
