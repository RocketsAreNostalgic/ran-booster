<?php

declare(strict_types=1);

namespace RAN\Portability;

use RuntimeException;

/** Exact WordPress-inventory boundary preventing concurrent package authority. */
final class WpPusherCoexistencePolicy {

	public const WP_PUSHER_PLUGIN = 'wppusher/wppusher.php';

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public methods retain the existing caller and activation callback contracts.
	public static function assertPackageMutationAllowed(): void {
		if ( self::conflictActive() ) {
			throw new RuntimeException( 'RAN Booster package mutations are unavailable while WP Pusher is active. Deactivate WP Pusher before continuing.' );
		}
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public methods retain the existing caller and activation callback contracts.
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

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public methods retain the existing caller and activation callback contracts.
	public static function conflictActive(): bool {
		return self::active( self::WP_PUSHER_PLUGIN );
	}

	private static function active( string $plugin ): bool {
		$site_active    = self::site_active_plugins();
		$network_active = self::network_active_plugins();
		if ( ! is_array( $site_active ) || ! is_array( $network_active ) ) {
			return true;
		}

		return in_array( $plugin, $site_active, true ) || array_key_exists( $plugin, $network_active );
	}

	private static function site_active_plugins(): mixed {
		if ( function_exists( __NAMESPACE__ . '\\get_option' ) ) {
			return get_option( 'active_plugins', array() );
		}

		return function_exists( 'get_option' ) ? \get_option( 'active_plugins', array() ) : array();
	}

	private static function network_active_plugins(): mixed {
		if ( function_exists( __NAMESPACE__ . '\\get_site_option' ) ) {
			return get_site_option( 'active_sitewide_plugins', array() );
		}

		return function_exists( 'get_site_option' ) ? \get_site_option( 'active_sitewide_plugins', array() ) : array();
	}
}
