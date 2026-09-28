<?php

declare(strict_types=1);

namespace RAN\WordPress;

use JsonException;

/**
 * Resolves whether Booster may participate in WordPress-native update discovery.
 */
final class CoreSelfUpdatePolicy {

	public const CONFIGURATION = 'RAN_BOOSTER_SELF_UPDATE_MODE';
	public const MODE_AUTO     = 'auto';
	public const MODE_ENABLED  = 'enabled';
	public const MODE_DISABLED = 'disabled';

	private const MARKER_FILE     = 'ran-booster-release.json';
	private const MARKER_SCHEMA   = 'ran-booster-core-release';
	private const MARKER_VERSION  = 1;
	private const MAX_MARKER_SIZE = 4096;

	private function __construct(
		private readonly string $requested_mode,
		private readonly string $effective_mode,
		private readonly string $reason,
		private readonly ?string $marker_version = null,
		private readonly ?string $marker_commit = null
	) {
	}

	public static function detect( string $plugin_file, string $plugin_version ): self {
		$requested_mode = self::requested_mode();
		if ( self::MODE_ENABLED === $requested_mode ) {
			return new self( $requested_mode, self::MODE_ENABLED, 'configuration_enabled' );
		}
		if ( self::MODE_DISABLED === $requested_mode ) {
			return new self( $requested_mode, self::MODE_DISABLED, 'configuration_disabled' );
		}
		if ( self::MODE_AUTO !== $requested_mode ) {
			return new self( 'invalid', self::MODE_DISABLED, 'configuration_invalid' );
		}

		$plugin_root = dirname( $plugin_file );
		if ( self::has_source_tree_indicator( $plugin_root ) ) {
			return new self( $requested_mode, self::MODE_DISABLED, 'source_checkout' );
		}

		$marker = self::release_marker( $plugin_root, $plugin_version );
		if ( null === $marker ) {
			return new self( $requested_mode, self::MODE_DISABLED, 'release_marker_missing_or_invalid' );
		}

		return new self(
			$requested_mode,
			self::MODE_ENABLED,
			'verified_release',
			$marker['version'],
			$marker['commit']
		);
	}

	public function allows_native_discovery(): bool {
		return self::MODE_ENABLED === $this->effective_mode;
	}

	/**
	 * Return bounded, non-secret state for Core-owned troubleshooting UI.
	 *
	 * @return array{
	 *   requested_mode:string,
	 *   effective_mode:string,
	 *   reason:string,
	 *   marker_version:?string,
	 *   marker_commit:?string
	 * }
	 */
	public function diagnostics(): array {
		return array(
			'requested_mode' => $this->requested_mode,
			'effective_mode' => $this->effective_mode,
			'reason'         => $this->reason,
			'marker_version' => $this->marker_version,
			'marker_commit'  => $this->marker_commit,
		);
	}

	private static function requested_mode(): string {
		if ( ! defined( self::CONFIGURATION ) ) {
			return self::MODE_AUTO;
		}

		$value = constant( self::CONFIGURATION );
		return is_string( $value ) ? strtolower( trim( $value ) ) : 'invalid';
	}

	private static function has_source_tree_indicator( string $plugin_root ): bool {
		return is_dir( $plugin_root . '/.git' )
			|| is_file( $plugin_root . '/.git' )
			|| is_link( $plugin_root . '/.git' )
			|| is_file( $plugin_root . '/composer.json' );
	}

	/**
	 * @return array{version:string,commit:string}|null
	 */
	private static function release_marker( string $plugin_root, string $plugin_version ): ?array {
		$path = $plugin_root . '/' . self::MARKER_FILE;
		if ( is_link( $path ) || ! is_file( $path ) || ! is_readable( $path ) ) {
			return null;
		}

		$size = filesize( $path );
		if ( false === $size || 0 === $size || self::MAX_MARKER_SIZE < $size ) {
			return null;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read-only plugin-owned release provenance.
		$contents = file_get_contents( $path );
		if ( ! is_string( $contents ) ) {
			return null;
		}

		try {
			$marker = json_decode( $contents, true, 16, JSON_THROW_ON_ERROR );
		} catch ( JsonException ) {
			return null;
		}
		if ( ! is_array( $marker ) ) {
			return null;
		}

		$keys = array_keys( $marker );
		sort( $keys );
		if ( array( 'commit', 'schema', 'schema_version', 'version' ) !== $keys
			|| self::MARKER_SCHEMA !== ( $marker['schema'] ?? null )
			|| self::MARKER_VERSION !== ( $marker['schema_version'] ?? null )
			|| ! is_string( $marker['version'] ?? null )
			|| 1 !== preg_match( '/\A[0-9A-Za-z][0-9A-Za-z.+-]{0,79}\z/D', $marker['version'] )
			|| $plugin_version !== ( $marker['version'] ?? null )
			|| ! is_string( $marker['commit'] ?? null )
			|| 1 !== preg_match( '/\A[0-9a-f]{40}\z/D', $marker['commit'] )
		) {
			return null;
		}

		return array(
			'version' => $marker['version'],
			'commit'  => $marker['commit'],
		);
	}
}
