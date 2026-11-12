<?php

declare(strict_types=1);

// Global WordPress function doubles; the early-translation test requires this in a separate process.

require_once __DIR__ . '/Storage/PackageStorageFailureWordPressFunctions.php';

if ( ! function_exists( 'trailingslashit' ) ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WordPress function double must retain the host-owned name.
	function trailingslashit( string $value ): string {
		return rtrim( $value, '/\\' ) . '/';
	}
}

if ( ! function_exists( 'is_plugin_active' ) ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WordPress function double must retain the host-owned name.
	function is_plugin_active( string $plugin ): bool {
		unset( $plugin );
		return false;
	}
}

if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WordPress function double must retain the host-owned name.
	function is_plugin_active_for_network( string $plugin ): bool {
		unset( $plugin );
		return false;
	}
}
