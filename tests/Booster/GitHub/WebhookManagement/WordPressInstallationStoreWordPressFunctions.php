<?php

declare( strict_types = 1 );

namespace RAN\Booster\GitHub\WebhookManagement\Installation;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Focused option-store fixture.

if ( ! function_exists( __NAMESPACE__ . '\\get_option' ) ) {
	function get_option( string $name, mixed $default = false ): mixed {
		return $GLOBALS['ran_booster_assisted_hooks_test_options'][ $name ] ?? $default;
	}
}

if ( ! function_exists( __NAMESPACE__ . '\\wp_cache_delete' ) ) {
	function wp_cache_delete( string $key, string $group = '' ): bool {
		unset( $key, $group );

		return true;
	}
}
