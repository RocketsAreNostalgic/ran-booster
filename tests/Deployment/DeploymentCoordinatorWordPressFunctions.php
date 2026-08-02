<?php

declare(strict_types=1);

if ( ! defined( 'WP_PLUGIN_DIR' ) ) {
	define( 'WP_PLUGIN_DIR', sys_get_temp_dir() . '/ran-booster-coordinator-plugins' );
}

if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id(): int {
		return (int) ( $GLOBALS['ran_booster_deployment_administrator_user_id'] ?? 7 );
	}
}

if ( ! function_exists( 'get_theme_root' ) ) {
	function get_theme_root(): string {
		return sys_get_temp_dir() . '/ran-booster-coordinator-themes';
	}
}
