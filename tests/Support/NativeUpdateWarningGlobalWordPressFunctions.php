<?php

declare(strict_types=1);

if ( ! function_exists( 'get_current_screen' ) ) {
	function get_current_screen(): mixed { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WordPress fixture signature.
		return $GLOBALS['ran_booster_native_warning_screen'] ?? null;
	}
}
