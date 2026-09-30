<?php

declare(strict_types=1);

namespace RAN\Admin;

/**
 * Prevents Booster-specific notices appearing across unrelated admin screens
 * while retaining the main and network Plugins/Booster routes.
 */
final readonly class BoosterNoticeScope {

	public static function allows( ?string $screen_id = null ): bool {
		if ( null === $screen_id ) {
			$screen    = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
			$screen_id = is_object( $screen ) && isset( $screen->id ) && is_string( $screen->id )
				? $screen->id
				: '';
		}

		return in_array( $screen_id, array( 'plugins', 'plugins-network' ), true )
			|| self::is_booster_screen( $screen_id );
	}

	/** Whether the current screen belongs to Booster's admin page family. */
	public static function is_booster_screen( ?string $screen_id = null ): bool {
		if ( null === $screen_id ) {
			$screen    = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
			$screen_id = is_object( $screen ) && isset( $screen->id ) && is_string( $screen->id )
				? $screen->id
				: '';
		}

		return str_starts_with( $screen_id, 'toplevel_page_ran-booster' )
			|| str_starts_with( $screen_id, 'ran-booster_page_ran-booster' );
	}
}
