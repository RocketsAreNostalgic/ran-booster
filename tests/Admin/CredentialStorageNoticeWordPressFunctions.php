<?php

declare(strict_types=1);

if ( ! function_exists( 'is_network_admin' ) ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WordPress global function fixture.
	function is_network_admin(): bool {
		return (bool) ( $GLOBALS['ran_booster_expiry_test_network_admin'] ?? false );
	}
}
