<?php

declare(strict_types=1);

// Focused WordPress Core filesystem doubles intentionally mirror Core names/globals in one support file.

if ( ! class_exists( 'WP_Filesystem_Direct', false ) ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- WordPress class identity is required by the host fixture contract.
	class WP_Filesystem_Direct {
	}
}

if ( ! function_exists( 'get_filesystem_method' ) ) {
	// phpcs:ignore Universal.Files.SeparateFunctionsFromOO.Mixed,WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WordPress function double must retain the host-owned name. WordPress filesystem function and class doubles share this host-contract fixture.
	function get_filesystem_method(): string {
		return 'direct';
	}
}

if ( ! function_exists( 'WP_Filesystem' ) ) {
	// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid,WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WordPress function double must retain the host-owned name. WordPress owns the exact WP_Filesystem function name.
	function WP_Filesystem(): bool {
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- The WordPress filesystem initializer must populate its host-owned wp_filesystem global.
		$GLOBALS['wp_filesystem'] = new WP_Filesystem_Direct();

		return true;
	}
}
