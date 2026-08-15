<?php

declare(strict_types=1);

namespace RAN;

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__, 2 ) . '/' );
}

/** @param callable|null $callback */
function add_menu_page( string $pageTitle, string $menuTitle, string $capability, string $menuSlug, ?callable $callback = null, string $iconUrl = '' ): void {
	$GLOBALS['ran_booster_extensions_page_menus'][] = array(
		'page_title' => $pageTitle,
		'menu_title' => $menuTitle,
		'capability' => $capability,
		'menu_slug'  => $menuSlug,
		'callback'   => $callback,
		'icon_url'   => $iconUrl,
	);
}

/** @param callable|null $callback */
function add_submenu_page( string $parentSlug, string $pageTitle, string $menuTitle, string $capability, string $menuSlug, ?callable $callback = null ): void {
	$GLOBALS['ran_booster_extensions_page_submenus'][] = array(
		'parent_slug' => $parentSlug,
		'page_title'  => $pageTitle,
		'menu_title'  => $menuTitle,
		'capability'  => $capability,
		'menu_slug'   => $menuSlug,
		'callback'    => $callback,
	);
}

function get_plugins(): array {
	$failure = $GLOBALS['ran_booster_extensions_plugins_failure'] ?? null;
	if ( $failure instanceof \Throwable ) {
		throw $failure;
	}

	return $GLOBALS['ran_booster_extensions_plugins'] ?? array();
}

function is_plugin_active( string $plugin ): bool {
	return in_array( $plugin, $GLOBALS['ran_booster_extensions_active_plugins'] ?? array(), true );
}

function is_plugin_active_for_network( string $plugin ): bool {
	return in_array( $plugin, $GLOBALS['ran_booster_extensions_network_active_plugins'] ?? array(), true );
}

if ( ! function_exists( __NAMESPACE__ . '\\trailingslashit' ) ) {
	function trailingslashit( string $value ): string {
		return rtrim( $value, '/\\' ) . '/';
	}
}

if ( ! function_exists( __NAMESPACE__ . '\\esc_attr' ) ) {
	function esc_attr( mixed $value ): string {
		return htmlspecialchars( (string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
	}
}
