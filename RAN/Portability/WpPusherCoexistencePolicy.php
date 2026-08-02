<?php

declare(strict_types=1);

namespace RAN\Portability;

use RuntimeException;

/** Exact WordPress-inventory boundary preventing concurrent package authority. */
final class WpPusherCoexistencePolicy {

	public const WP_PUSHER_PLUGIN = 'wppusher/wppusher.php';

	public static function assertPackageMutationAllowed(): void {
		if ( self::conflictActive() ) {
			throw new RuntimeException( 'RAN Booster package mutations are unavailable while WP Pusher is active. Deactivate WP Pusher before continuing.' );
		}
	}

	public static function blockWpPusherActivation( string $plugin ): void {
		if ( self::WP_PUSHER_PLUGIN === $plugin ) {
			wp_die(
				esc_html__(
					'WP Pusher cannot be activated while RAN Booster is active. Keep WP Pusher inactive and use the migration guidance in Booster.',
					'ran-booster'
				)
			);
		}
	}

	public static function conflictActive(): bool {
		return self::active( self::WP_PUSHER_PLUGIN );
	}

	private static function active( string $plugin ): bool {
		$siteActive    = self::siteActivePlugins();
		$networkActive = self::networkActivePlugins();
		if ( ! is_array( $siteActive ) || ! is_array( $networkActive ) ) {
			return true;
		}

		return in_array( $plugin, $siteActive, true ) || array_key_exists( $plugin, $networkActive );
	}

	private static function siteActivePlugins(): mixed {
		if ( function_exists( __NAMESPACE__ . '\\get_option' ) ) {
			return get_option( 'active_plugins', array() );
		}

		return function_exists( 'get_option' ) ? \get_option( 'active_plugins', array() ) : array();
	}

	private static function networkActivePlugins(): mixed {
		if ( function_exists( __NAMESPACE__ . '\\get_site_option' ) ) {
			return get_site_option( 'active_sitewide_plugins', array() );
		}

		return function_exists( 'get_site_option' ) ? \get_site_option( 'active_sitewide_plugins', array() ) : array();
	}
}
