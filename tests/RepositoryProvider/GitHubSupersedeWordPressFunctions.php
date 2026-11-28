<?php

declare(strict_types=1);

if ( ! function_exists( 'plugin_basename' ) ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- The registry attributes a claim through this WordPress API; the fixture plugin directory stands in for WP_PLUGIN_DIR.
	function plugin_basename( string $file ): string {
		$root = str_replace( '\\', '/', dirname( __DIR__ ) ) . '/fixtures/github-supersede/';
		$file = str_replace( '\\', '/', $file );
		// Like WordPress, a file outside the plugin directories keeps its path, trimmed of slashes.
		return str_starts_with( $file, $root ) ? substr( $file, strlen( $root ) ) : trim( $file, '/' );
	}
}
