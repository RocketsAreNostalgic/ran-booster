<?php

declare(strict_types=1);

// Focused translation fixture for the external tab add-on process.

if ( ! function_exists( '__' ) ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WordPress function double must retain the host-owned name.
	function __( string $text, string $domain = 'default' ): string {
		return $GLOBALS['ran_booster_external_fixture_addon_translations'][ $domain ][ $text ] ?? $text;
	}
}
