<?php

declare(strict_types=1);


if ( ! function_exists( 'wp_parse_url' ) ) {
	/**
	 * @return array{scheme?: string, host?: string, port?: int, user?: string, pass?: string, path?: string, query?: string, fragment?: string}|false
	 */
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WordPress function double must retain the host-owned name.
	function wp_parse_url( string $url ): array|false {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Unit-test stand-in for WordPress's wp_parse_url().
		return parse_url( $url );
	}
}
