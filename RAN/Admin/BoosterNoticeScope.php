<?php

declare(strict_types=1);

namespace RAN\Admin;

/**
 * Prevents Booster-specific notices appearing across unrelated admin screens
 * while retaining the main and network Plugins/Booster routes.
 */
final readonly class BoosterNoticeScope {

	public static function allows( ?string $screenId = null ): bool {
		if ( null === $screenId ) {
			$screen   = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
			$screenId = is_object( $screen ) && isset( $screen->id ) && is_string( $screen->id )
				? $screen->id
				: '';
		}

		return in_array( $screenId, array( 'plugins', 'plugins-network' ), true )
			|| str_starts_with( $screenId, 'toplevel_page_ran-booster' )
			|| str_starts_with( $screenId, 'ran-booster_page_ran-booster' );
	}
}
