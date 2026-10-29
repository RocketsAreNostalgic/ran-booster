<?php

declare(strict_types=1);

if ( ! function_exists( 'is_plugin_active' ) ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WordPress fixture keeps the platform function name.
	function is_plugin_active( string $plugin_file ): bool {
		return true === ( $GLOBALS['ran_booster_plugin_row_active'][ $plugin_file ] ?? false );
	}
}
