<?php

declare(strict_types=1);

namespace RAN; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- WordPress function doubles resolve from Core's established namespace.

if ( ! function_exists( __NAMESPACE__ . '\\add_action' ) ) {
	function add_action( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): bool {
		$GLOBALS['ran_booster_plugin_row_hooks'][] = array(
			'hook'          => $hook,
			'callback'      => $callback,
			'priority'      => $priority,
			'accepted_args' => $accepted_args,
		);

		return true;
	}
}

if ( ! function_exists( __NAMESPACE__ . '\\add_filter' ) ) {
	function add_filter( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): bool {
		return add_action( $hook, $callback, $priority, $accepted_args );
	}
}

if ( ! function_exists( __NAMESPACE__ . '\\current_user_can' ) ) {
	function current_user_can( string $capability ): bool {
		$GLOBALS['ran_booster_plugin_row_capability_checks'][] = $capability;

		return true === ( $GLOBALS['ran_booster_plugin_row_capabilities'][ $capability ] ?? false );
	}
}

if ( ! function_exists( __NAMESPACE__ . '\\is_multisite' ) ) {
	function is_multisite(): bool {
		return true === ( $GLOBALS['ran_booster_plugin_row_multisite'] ?? false );
	}
}

if ( ! function_exists( __NAMESPACE__ . '\\plugin_basename' ) ) {
	function plugin_basename( string $file ): string {
		$GLOBALS['ran_booster_plugin_row_basename_inputs'][] = $file;

		return (string) ( $GLOBALS['ran_booster_plugin_row_basename'] ?? 'ran-booster/ran-booster.php' );
	}
}

if ( ! function_exists( __NAMESPACE__ . '\\admin_url' ) ) {
	function admin_url( string $path = '' ): string {
		$GLOBALS['ran_booster_plugin_row_admin_url_inputs'][] = $path;

		return 'https://example.test/wp-admin/' . ltrim( $path, '/' );
	}
}

if ( ! function_exists( __NAMESPACE__ . '\\esc_url' ) ) {
	function esc_url( string $url ): string {
		return htmlspecialchars( $url, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( __NAMESPACE__ . '\\esc_attr' ) ) {
	function esc_attr( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( __NAMESPACE__ . '\\esc_html__' ) ) {
	function esc_html__( string $text, string $domain = 'default' ): string {
		unset( $domain );

		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( __NAMESPACE__ . '\\esc_html' ) ) {
	function esc_html( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( __NAMESPACE__ . '\\esc_html_e' ) ) {
	function esc_html_e( string $text, string $domain = 'default' ): void {
		unset( $domain );

		echo esc_html( $text );
	}
}
