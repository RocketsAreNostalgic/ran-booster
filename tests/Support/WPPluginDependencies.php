<?php

declare(strict_types=1);

if ( ! class_exists( 'WP_Plugin_Dependencies' ) ) {
	final class WP_Plugin_Dependencies {
		public static function initialize(): void {
			++$GLOBALS['ran_booster_bulk_dependency_initializations'];
		}

		public static function has_active_dependents( string $plugin ): bool {
			return in_array( $plugin, $GLOBALS['ran_booster_bulk_plugins_with_active_dependents'] ?? array(), true );
		}
	}
}
