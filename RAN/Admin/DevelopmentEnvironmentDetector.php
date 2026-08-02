<?php

declare(strict_types=1);

namespace RAN\Admin;

final class DevelopmentEnvironmentDetector {

	public static function isLikely(): bool {
		if (
			in_array( wp_get_environment_type(), array( 'local', 'development' ), true )
			|| wp_is_development_mode( 'plugin' )
			|| wp_is_development_mode( 'theme' )
			|| ( defined( 'WP_DEBUG' ) && (bool) WP_DEBUG )
		) {
			return true;
		}

		$siteUrl = wp_parse_url( home_url() );
		if ( ! is_array( $siteUrl ) ) {
			return false;
		}

		$host = strtolower( trim( (string) ( $siteUrl['host'] ?? '' ), '[]' ) );
		if (
			in_array( $host, array( 'localhost', '127.0.0.1', '::1' ), true )
			|| str_ends_with( $host, '.localhost' )
		) {
			return true;
		}

		$port = isset( $siteUrl['port'] ) ? (int) $siteUrl['port'] : null;
		if ( null === $port ) {
			return false;
		}

		$scheme      = strtolower( (string) ( $siteUrl['scheme'] ?? '' ) );
		$defaultPort = 'https' === $scheme ? 443 : ( 'http' === $scheme ? 80 : null );

		return null === $defaultPort || $port !== $defaultPort;
	}
}
