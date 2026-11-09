<?php

declare(strict_types=1);

if ( ! function_exists( 'get_current_screen' ) ) {
	function get_current_screen(): mixed { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WordPress fixture signature.
		return $GLOBALS['ran_booster_native_warning_screen'] ?? null;
	}
}

if ( ! function_exists( 'esc_attr' ) ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WordPress escaping double for the static Notice icon.
	function esc_attr( mixed $value ): string {
		return htmlspecialchars( (string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
	}
}
