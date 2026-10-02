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
		return ( $GLOBALS['ran_booster_runtime_action'] ?? '' ) === $hook;
	}
}

if ( ! function_exists( 'add_action' ) ) {
	function add_action(
		string $hook,
		callable $callback,
		int $priority = 10,
		int $accepted_args = 1
	): bool {
		$GLOBALS['ran_booster_runtime_actions'][] = array(
			'hook'         => $hook,
			'callback'     => $callback,
			'priority'     => $priority,
			'acceptedArgs' => $accepted_args,
		);

		return true;
	}
}

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter(
		string $hook,
		callable $callback,
		int $priority = 10,
		int $accepted_args = 1
	): bool {
		return add_action( $hook, $callback, $priority, $accepted_args );
	}
}
