<?php

declare(strict_types=1);


if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( string $url ): array|false {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Unit-test stand-in for WordPress's wp_parse_url().
		return parse_url( $url );
	}
}
