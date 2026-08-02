<?php

declare(strict_types=1);

namespace RAN\WordPress;

if ( ! function_exists( __NAMESPACE__ . '\\chmod' ) ) {
	function chmod( string $path, int $permissions ): bool {
		if ( false === ( $GLOBALS['ran_booster_prospective_chmod_allowed'] ?? true ) ) {
			return false;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Test-only proxy for the production call.
		return \chmod( $path, $permissions );
	}
}
