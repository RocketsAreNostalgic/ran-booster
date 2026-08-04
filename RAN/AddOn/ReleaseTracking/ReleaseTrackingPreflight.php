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
			'release_runtime_unavailable',
			'github_updater_invalid_preflight_target',
			'github_updater_no_eligible_release',
			'github_updater_release_search_budget_exhausted',
			'github_updater_ambiguous_release_asset',
			'github_updater_invalid_release_asset',
			'github_updater_release_asset_too_large',
			'github_updater_missing_asset_digest',
			'github_updater_invalid_release',
			'github_updater_release_is_draft',
			'github_updater_invalid_release_tag',
			'github_updater_prerelease_not_allowed',
			'github_updater_invalid_release_url',
			'github_updater_invalid_tag_commit',
			'github_updater_artifact_continuity_failed',
			'github_updater_repository_identity_changed',
			'github_updater_credentials_unavailable',
			'github_updater_invalid_access_token',
			'github_updater_github_authentication_failed',
			'github_updater_github_forbidden',
			'github_updater_downloaded_artifact_invalid',
			'github_updater_downloaded_digest_mismatch',
			'github_updater_rate_limited',
			'github_updater_http_transport_failed',
			'github_updater_github_http_error',
			'github_updater_download_failed',
			'github_updater_expired_release_asset_url',
			'github_updater_redirect_limit_exceeded',
			'github_updater_response_too_large',
			'github_updater_invalid_json',
			'github_updater_unsafe_release_asset_redirect',
			'github_updater_temp_file_failed',
			'github_updater_invalid_runtime_version',
			'github_updater_check_in_progress',
			'github_updater_release_artifact_unavailable',
			'github_updater_release_check_failed',
			'github_updater_release_preflight_unavailable',
			'github_updater_operation_failed',
			'github_updater_release_assurance_failed',
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
			'github_updater_release_incompatible',
			'package_header_ambiguous',
		);
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
