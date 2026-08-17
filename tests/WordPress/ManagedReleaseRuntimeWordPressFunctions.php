<?php

declare(strict_types=1);

if ( ! defined( 'WP_PLUGIN_DIR' ) ) {
	define( 'WP_PLUGIN_DIR', '/test/plugins' );
}

if ( ! function_exists( 'get_theme_root' ) ) {
	function get_theme_root(): string {
		return '/test/themes';
	}
}

if ( ! function_exists( 'ran_booster_table_name' ) ) {
	function ran_booster_table_name(): string {
		return 'wp_ran_booster_packages';
	}
}

if ( ! function_exists( 'doing_action' ) ) {
	function doing_action( string $hook ): bool {
		return $hook === ( $GLOBALS['ran_booster_runtime_action'] ?? '' );
	}
}

if ( ! function_exists( 'add_action' ) ) {
	function add_action(
		string $hook,
		callable $callback,
		int $priority = 10,
		int $acceptedArgs = 1
	): bool {
		$GLOBALS['ran_booster_runtime_actions'][] = compact( 'hook', 'callback', 'priority', 'acceptedArgs' );

		return true;
	}
}

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter(
		string $hook,
		callable $callback,
		int $priority = 10,
		int $acceptedArgs = 1
	): bool {
		return add_action( $hook, $callback, $priority, $acceptedArgs );
	}
}
