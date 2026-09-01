<?php

declare(strict_types=1);

namespace RAN;

if ( ! function_exists( __NAMESPACE__ . '\\__' ) ) {
	function __( string $text, string $domain = 'default' ): string {
		unset( $domain );

		return $text;
	}
}

function trailingslashit( string $value ): string {
	return rtrim( $value, '/\\' ) . '/';
}

/** @param list<string> $dependencies */
function wp_register_style( string $handle, string $source, array $dependencies = array(), int|false|null $version = false ): bool {
	$GLOBALS['ran_booster_asset_test_registered_styles'][ $handle ] = array(
		'source'       => $source,
		'dependencies' => $dependencies,
		'version'      => $version,
	);

	return true;
}

function wp_enqueue_style( string $handle ): void {
	$GLOBALS['ran_booster_asset_test_enqueued_styles'][] = $handle;
}

/** @param list<string> $dependencies */
function wp_register_script( string $handle, string $source, array $dependencies = array(), int|false|null $version = false, bool $footer = false ): bool {
	$GLOBALS['ran_booster_asset_test_script_events'][]               = array(
		'function' => 'wp_register_script',
		'handle'   => $handle,
	);
	$GLOBALS['ran_booster_asset_test_registered_scripts'][ $handle ] = array(
		'source'       => $source,
		'dependencies' => $dependencies,
		'version'      => $version,
		'footer'       => $footer,
	);

	return true;
}

function wp_set_script_translations( string $handle, string $domain, string $path = '' ): bool {
	$GLOBALS['ran_booster_asset_test_script_events'][]       = array(
		'function' => 'wp_set_script_translations',
		'handle'   => $handle,
	);
	$GLOBALS['ran_booster_asset_test_script_translations'][] = compact( 'handle', 'domain', 'path' );

	return true;
}

/** @param array<string, mixed> $data */
function wp_localize_script( string $handle, string $name, array $data ): bool {
	$GLOBALS['ran_booster_asset_test_localized_scripts'][ $handle ][ $name ] = $data;

	return true;
}

function wp_enqueue_script( string $handle ): void {
	$GLOBALS['ran_booster_asset_test_enqueued_scripts'][] = $handle;
}

if ( ! function_exists( __NAMESPACE__ . '\\wp_create_nonce' ) ) {
	function wp_create_nonce( string $action ): string {
		return 'nonce-for-' . $action;
	}
}
