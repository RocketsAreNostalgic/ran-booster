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
		private readonly string $requestedMode,
		private readonly string $effectiveMode,
		private readonly string $reason,
		private readonly ?string $markerVersion = null,
		private readonly ?string $markerCommit = null
	) {
	}

	public static function detect( string $pluginFile, string $pluginVersion ): self {
		$requestedMode = self::requestedMode();
		if ( self::MODE_ENABLED === $requestedMode ) {
			return new self( $requestedMode, self::MODE_ENABLED, 'configuration_enabled' );
		}
		if ( self::MODE_DISABLED === $requestedMode ) {
			return new self( $requestedMode, self::MODE_DISABLED, 'configuration_disabled' );
		}
		if ( self::MODE_AUTO !== $requestedMode ) {
			return new self( 'invalid', self::MODE_DISABLED, 'configuration_invalid' );
		}

		$pluginRoot = dirname( $pluginFile );
		if ( self::hasSourceTreeIndicator( $pluginRoot ) ) {
			return new self( $requestedMode, self::MODE_DISABLED, 'source_checkout' );
		}

		$marker = self::releaseMarker( $pluginRoot, $pluginVersion );
		if ( null === $marker ) {
			return new self( $requestedMode, self::MODE_DISABLED, 'release_marker_missing_or_invalid' );
		}

		return new self(
			$requestedMode,
			self::MODE_ENABLED,
			'verified_release',
			$marker['version'],
			$marker['commit']
		);
	}

	public function allowsNativeDiscovery(): bool {
		return self::MODE_ENABLED === $this->effectiveMode;
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
			'requested_mode' => $this->requestedMode,
			'effective_mode' => $this->effectiveMode,
			'reason'         => $this->reason,
			'marker_version' => $this->markerVersion,
			'marker_commit'  => $this->markerCommit,
		);
	}

	private static function requestedMode(): string {
		if ( ! defined( self::CONFIGURATION ) ) {
			return self::MODE_AUTO;
		}

		$value = constant( self::CONFIGURATION );
		return is_string( $value ) ? strtolower( trim( $value ) ) : 'invalid';
	}

	private static function hasSourceTreeIndicator( string $pluginRoot ): bool {
		return is_dir( $pluginRoot . '/.git' )
			|| is_file( $pluginRoot . '/.git' )
			|| is_link( $pluginRoot . '/.git' )
			|| is_file( $pluginRoot . '/composer.json' );
	}

	/**
	 * @return array{version:string,commit:string}|null
	 */
	private static function releaseMarker( string $pluginRoot, string $pluginVersion ): ?array {
		$path = $pluginRoot . '/' . self::MARKER_FILE;
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
			|| $pluginVersion !== ( $marker['version'] ?? null )
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
