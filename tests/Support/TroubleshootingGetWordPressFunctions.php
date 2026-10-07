<?php

declare(strict_types=1);

namespace RAN; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- Core owns the established three-character RAN namespace; WPCS requires four characters.

require_once __DIR__ . '/ProviderCredentialDispatcherWordPressFunctions.php';

if ( ! function_exists( __NAMESPACE__ . '\\add_action' ) ) {
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- WordPress add_action stub preserves native argument slots; this fixture records only the values relevant to its assertions.
	function add_action( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): bool {
		$GLOBALS['ran_booster_get_test_actions'][ $hook ][] = $callback;

		return true;
	}
}

if ( ! function_exists( __NAMESPACE__ . '\\register_setting' ) ) {
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- WordPress register_setting stub preserves native argument slots; this fixture records only the values relevant to its assertions.
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
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- WordPress get_admin_url stub preserves native argument slots; this fixture records only the values relevant to its assertions.
	function get_admin_url( ?int $blog_id = null, string $path = '' ): string {
		return 'https://example.test/wp-admin/' . ltrim( $path, '/' );
	}
}

if ( ! function_exists( __NAMESPACE__ . '\\network_admin_url' ) ) {
	function network_admin_url( string $path = '' ): string {
		return 'https://example.test/wp-admin/network/' . ltrim( $path, '/' );
	}
}

if ( ! function_exists( __NAMESPACE__ . '\\add_filter' ) ) {
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- WordPress add_filter stub preserves native argument slots; this fixture records only the values relevant to its assertions.
	function add_filter( string $hook, callable $callback ): bool {
		return true;
	}
}
