<?php

declare(strict_types=1);

if ( ! function_exists( 'get_current_screen' ) ) {
	function get_current_screen(): object { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WordPress fixture signature.
		return (object) array( 'id' => $GLOBALS['ran_booster_expiry_test_screen'] ?? '' );
	}
}

if ( ! function_exists( 'wp_cache_delete' ) ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WordPress cache API test double.
	function wp_cache_delete( int $key, string $group ): bool {
		unset( $key, $group );
		return true;
	}
}
