<?php

declare(strict_types=1);

namespace RAN\Admin;

/**
 * Prevents Booster-specific notices appearing across unrelated admin screens
 * while retaining the main and network Plugins/Booster routes.
 */
final class BoosterNoticeScope {

	private static string $pending_html = '';

	/** @internal Capture only Core-owned, escaped notice output for the Booster shell. */
	public static function render( callable $notice ): void {
		if ( ! self::is_booster_screen() ) {
			$notice();
			return;
		}

		ob_start();
		try {
			$notice();
			self::$pending_html .= (string) ob_get_contents();
		} finally {
			ob_end_clean();
		}
	}

	/** @internal Emit captured Core-owned notice HTML once. */
	public static function render_shell_notices(): void {
		$html               = self::$pending_html;
		self::$pending_html = '';
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Captured Core-owned escaped notice HTML.
	}


	public static function allows( ?string $screen_id = null ): bool {
		if ( null === $screen_id ) {
			$screen    = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
			$screen_id = self::screen_id_from( $screen );
		}

		return in_array( $screen_id, array( 'plugins', 'plugins-network' ), true )
			|| self::is_booster_screen( $screen_id );
	}

	/** Whether the current screen belongs to Booster's admin page family. */
	public static function is_booster_screen( ?string $screen_id = null ): bool {
		if ( null === $screen_id ) {
			$screen    = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
			$screen_id = self::screen_id_from( $screen );
		}

		return str_starts_with( $screen_id, 'toplevel_page_ran-booster' )
			|| str_starts_with( $screen_id, 'ran-booster_page_ran-booster' );
	}

	/** Validate WordPress's mutable current-screen observation before selecting notice scope. */
	private static function screen_id_from( mixed $screen ): string {
		return is_object( $screen ) && isset( $screen->id ) && is_string( $screen->id )
			? $screen->id
			: '';
	}
}
