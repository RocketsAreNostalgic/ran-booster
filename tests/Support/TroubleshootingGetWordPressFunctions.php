<?php

declare(strict_types=1);

namespace RAN;

require_once __DIR__ . '/ProviderCredentialDispatcherWordPressFunctions.php';

if ( ! function_exists( __NAMESPACE__ . '\\add_action' ) ) {
	function add_action( string $hook, callable $callback, int $priority = 10, int $acceptedArgs = 1 ): bool {
		$GLOBALS['ran_booster_get_test_actions'][ $hook ][] = $callback;

		return true;
	}
}

if ( ! function_exists( __NAMESPACE__ . '\\register_setting' ) ) {
	function register_setting( string $group, string $name ): bool {
		return true;
	}
}

if ( ! function_exists( __NAMESPACE__ . '\\is_multisite' ) ) {
	function is_multisite(): bool {
		return false;
	}
}

if ( ! function_exists( __NAMESPACE__ . '\\wp_doing_ajax' ) ) {
	function wp_doing_ajax(): bool {
		return false;
	}
}

if ( ! function_exists( __NAMESPACE__ . '\\get_admin_url' ) ) {
	function get_admin_url( ?int $blogId = null, string $path = '' ): string {
		return 'https://example.test/wp-admin/' . ltrim( $path, '/' );
	}
}

if ( ! function_exists( __NAMESPACE__ . '\\network_admin_url' ) ) {
	function network_admin_url( string $path = '' ): string {
		return 'https://example.test/wp-admin/network/' . ltrim( $path, '/' );
	}
}

if ( ! function_exists( __NAMESPACE__ . '\\add_filter' ) ) {
	function add_filter( string $hook, callable $callback ): bool {
		return true;
	}
}
