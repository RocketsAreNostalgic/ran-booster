<?php

declare(strict_types=1);

// Focused WordPress lifecycle doubles use explicit global state.

if ( ! function_exists( 'wp_clear_scheduled_hook' ) ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- The isolated uninstall fixture must provide this exact WordPress function identity.
	function wp_clear_scheduled_hook( string $hook, array $arguments = array() ): int|false {
		unset( $arguments );
		$GLOBALS['ran_booster_uninstall_cron_calls'][] = $hook;
		if ( false === $GLOBALS['ran_booster_uninstall_cron_result'] ) {
			return false;
		}

		unset( $GLOBALS['ran_booster_uninstall_cron'][ $hook ] );

		return (int) $GLOBALS['ran_booster_uninstall_cron_result'];
	}
}

if ( ! function_exists( 'delete_site_transient' ) ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- The isolated uninstall fixture must provide this exact WordPress function identity.
	function delete_site_transient( string $name ): bool {
		$GLOBALS['ran_booster_uninstall_deleted_transients'][] = $name;
		unset( $GLOBALS['ran_booster_uninstall_transients'][ $name ] );

		return true;
	}
}

if ( ! function_exists( 'get_site_transient' ) ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- The isolated uninstall fixture must provide this exact WordPress function identity.
	function get_site_transient( string $name ): mixed {
		return $GLOBALS['ran_booster_uninstall_transients'][ $name ] ?? false;
	}
}

if ( ! function_exists( 'plugin_basename' ) ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- The isolated uninstall fixture must provide this exact WordPress function identity.
	function plugin_basename( string $file ): string {
		unset( $file );

		return $GLOBALS['ran_booster_uninstall_plugin_basename'] ?? 'ran-booster/ran-booster.php';
	}
}

if ( ! function_exists( 'get_file_data' ) ) {
	/**
	 * @param array<string, string> $headers
	 * @return array<string, string>
	 */
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- The isolated uninstall fixture must provide this exact WordPress function identity.
	function get_file_data( string $file, array $headers, string $context = '' ): array {
		unset( $file, $headers, $context );

		return array(
			'Name'        => 'RAN Booster',
			'PluginURI'   => 'https://github.com/RocketsAreNostalgic/ran-booster',
			'Version'     => '0.1.0-alpha.20',
			'Description' => 'Updater cache identity fixture.',
			'Author'      => 'Rockets Are Nostalgic',
			'RequiresWP'  => '7.0',
			'RequiresPHP' => '8.2',
			'UpdateURI'   => 'https://github.com/RocketsAreNostalgic/ran-booster',
		);
	}
}

if ( ! function_exists( 'delete_option' ) ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- The isolated uninstall fixture must provide this exact WordPress function identity.
	function delete_option( string $name ): bool {
		$GLOBALS['ran_booster_uninstall_deleted_options'][] = $name;
		if ( ( $GLOBALS['ran_booster_uninstall_undeletable_option'] ?? null ) === $name ) {
			return false;
		}
		unset( $GLOBALS['ran_booster_uninstall_options'][ $name ] );

		return true;
	}
}

if ( ! function_exists( 'get_option' ) ) {
	function get_option( string $name, mixed $default = false ): mixed { // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.defaultFound, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Preserve the WordPress function parameter signature. The isolated uninstall fixture must provide this exact WordPress function identity.
		return $GLOBALS['ran_booster_uninstall_options'][ $name ] ?? $default;
	}
}

if ( ! function_exists( 'is_multisite' ) ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- The isolated uninstall fixture must provide this exact WordPress function identity.
	function is_multisite(): bool {
		return (bool) ( $GLOBALS['ran_booster_uninstall_multisite'] ?? false );
	}
}

if ( ! function_exists( 'get_current_blog_id' ) ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- The isolated uninstall fixture must provide this exact WordPress function identity.
	function get_current_blog_id(): int {
		return (int) ( $GLOBALS['ran_booster_uninstall_current_blog_id'] ?? 1 );
	}
}

if ( ! function_exists( 'get_main_site_id' ) ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- The isolated uninstall fixture must provide this exact WordPress function identity.
	function get_main_site_id(): int {
		return (int) ( $GLOBALS['ran_booster_uninstall_main_site_id'] ?? 1 );
	}
}
