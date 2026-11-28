<?php

declare(strict_types=1);

// Loaded only by isolated CredentialUsageReader processes: WordPress declares this helper globally.

if ( ! function_exists( 'get_theme_root' ) ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WordPress function double must retain the host-owned global name.
	function get_theme_root( string $stylesheet_or_template = '' ): string {
		return (string) ( $GLOBALS['ran_booster_credential_usage_theme_roots'][ $stylesheet_or_template ] ?? $GLOBALS['ran_booster_credential_usage_theme_roots'][''] );
	}
}
