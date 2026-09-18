<?php

declare(strict_types=1);

// Focused WordPress Core filesystem doubles intentionally mirror Core names/globals in one support file.
// phpcs:disable Universal.Files.SeparateFunctionsFromOO.Mixed
// phpcs:disable WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid
// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited

if ( ! class_exists( 'WP_Filesystem_Direct', false ) ) {
	class WP_Filesystem_Direct {
	}
}

if ( ! function_exists( 'get_filesystem_method' ) ) {
	function get_filesystem_method(): string {
		return 'direct';
	}
}

if ( ! function_exists( 'WP_Filesystem' ) ) {
	function WP_Filesystem(): bool {
		$GLOBALS['wp_filesystem'] = new WP_Filesystem_Direct();

		return true;
	}
}
