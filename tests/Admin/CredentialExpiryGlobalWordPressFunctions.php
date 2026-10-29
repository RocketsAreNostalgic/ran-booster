<?php

declare(strict_types=1);

if ( ! function_exists( 'get_current_screen' ) ) {
	function get_current_screen(): object { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WordPress fixture signature.
		return (object) array( 'id' => $GLOBALS['ran_booster_expiry_test_screen'] ?? '' );
	}
}
