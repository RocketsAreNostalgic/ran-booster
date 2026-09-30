<?php

declare(strict_types=1);

namespace RAN\Admin;

final class DevelopmentEnvironmentDetector {

	public static function is_likely(): bool {
		if (
			in_array( wp_get_environment_type(), array( 'local', 'development' ), true )
			|| wp_is_development_mode( 'plugin' )
			|| wp_is_development_mode( 'theme' )
			|| ( defined( 'WP_DEBUG' ) && (bool) WP_DEBUG )
		) {
			return true;
		}

		$site_url = wp_parse_url( home_url() );
		if ( ! is_array( $site_url ) ) {
			return false;
		}

		$host = strtolower( trim( (string) ( $site_url['host'] ?? '' ), '[]' ) );
		if (
			in_array( $host, array( 'localhost', '127.0.0.1', '::1' ), true )
			|| str_ends_with( $host, '.localhost' )
		) {
			return true;
		}

		$port = isset( $site_url['port'] ) ? (int) $site_url['port'] : null;
		if ( null === $port ) {
			return false;
		}

		$scheme       = strtolower( (string) ( $site_url['scheme'] ?? '' ) );
		$default_port = 'https' === $scheme ? 443 : ( 'http' === $scheme ? 80 : null );

		return null === $default_port || $port !== $default_port;
	}
}
