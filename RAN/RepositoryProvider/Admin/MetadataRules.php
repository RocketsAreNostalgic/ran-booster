<?php

declare(strict_types=1);

namespace RAN\RepositoryProvider\Admin;

use InvalidArgumentException;

/**
 * Shared safety floor for provider-owned admin metadata.
 *
 * @internal
 */
final class MetadataRules {

	public const LABEL_LENGTH      = 160;
	public const DETAIL_LENGTH     = 500;
	public const SUMMARY_LENGTH    = 2000;
	public const URL_LENGTH        = 2048;
	public const IDENTIFIER_LENGTH = 64;

	public static function identifier( string $value ): string {
		$hasControls = self::contains_control_characters( $value );
		$value       = trim( $value );

		if ( $hasControls || strlen( $value ) > self::IDENTIFIER_LENGTH || 1 !== preg_match( '/^[a-z][a-z0-9_-]*$/', $value ) ) {
			throw new InvalidArgumentException( 'Provider admin identifiers must be bounded lowercase identifiers.' );
		}

		return $value;
	}

	public static function required_text( string $value, int $maximumLength ): string {
		$hasControls = self::contains_control_characters( $value );
		$value       = trim( $value );

		if ( $hasControls || '' === $value || strlen( $value ) > $maximumLength ) {
			throw new InvalidArgumentException( 'Provider admin text must be bounded, non-empty single-line text.' );
		}

		return $value;
	}

	public static function optional_text( string $value, int $maximumLength ): string {
		$hasControls = self::contains_control_characters( $value );
		$value       = trim( $value );

		if ( $hasControls || strlen( $value ) > $maximumLength ) {
			throw new InvalidArgumentException( 'Provider admin text must be bounded single-line text.' );
		}

		return $value;
	}

	public static function https_url( string $url ): string {
		$hasControls = self::contains_control_characters( $url );
		$url         = trim( $url );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Provider metadata remains usable without WordPress runtime.
		$parts = parse_url( $url );

		if (
			$hasControls
			|| '' === $url
			|| strlen( $url ) > self::URL_LENGTH
			|| false === filter_var( $url, FILTER_VALIDATE_URL )
			|| false === $parts
			|| 'https' !== strtolower( $parts['scheme'] ?? '' )
			|| '' === ( $parts['host'] ?? '' )
			|| isset( $parts['user'] )
			|| isset( $parts['pass'] )
		) {
			throw new InvalidArgumentException( 'Provider documentation URLs must be bounded public HTTPS URLs without credentials.' );
		}

		return $url;
	}

	public static function contains_control_characters( string $value ): bool {
		return 1 === preg_match( '/[\x00-\x1F\x7F]/', $value );
	}
}
