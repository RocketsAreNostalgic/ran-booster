<?php

declare(strict_types=1);

namespace RAN\PackageRemoval;

if ( ! defined( 'ABSPATH' ) ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Exercise the WordPress-owned constant without changing its runtime identity.
	define( 'ABSPATH', dirname( __DIR__ ) . '/fixtures/wordpress/' );
}

/**
 * @param array<array-key, string> $plugins
 */
function delete_plugins( array $plugins ): mixed {
	$GLOBALS['ran_booster_package_removal_gateway_events'][] = array( 'delete', $plugins );
	$result = $GLOBALS['ran_booster_package_removal_gateway_result'] ?? false;
	if ( $result instanceof \Throwable ) {
		throw $result;
	}

	return $result;
}

function wp_clean_plugins_cache( bool $clear_update_cache = true ): void {
	$GLOBALS['ran_booster_package_removal_gateway_events'][] = array( 'clean', $clear_update_cache );
}

function get_site_transient( string $name ): mixed {
	$value = $GLOBALS['ran_booster_package_removal_transients'][ $name ] ?? false;
	return is_object( $value ) ? clone $value : $value;
}

function set_site_transient( string $name, mixed $value ): bool {
	if ( true === ( $GLOBALS['ran_booster_package_removal_transient_failure'] ?? false ) ) {
		return false;
	}
	$GLOBALS['ran_booster_package_removal_transients'][ $name ] = $value;
	return true;
}

/** @return array<string,array{Name:string}> */
function get_plugins(): array {
	return array( 'example/example.php' => array( 'Name' => 'Example' ) );
}

/** @return array<string, \RAN\Tests\PackageRemoval\InstalledThemeFixture> */
function wp_get_themes(): array {
	return array( 'example' => new \RAN\Tests\PackageRemoval\InstalledThemeFixture() );
}

function get_theme_root( string $stylesheet ): string {
	unset( $stylesheet );
	return WP_PLUGIN_DIR;
}

function wp_clean_themes_cache( bool $clear_update_cache = true ): void {
	$GLOBALS['ran_booster_package_removal_gateway_events'][] = array( 'clean_themes', $clear_update_cache );
}

function wp_get_theme( string $stylesheet ): \RAN\Tests\PackageRemoval\InstalledThemeFixture {
	return $GLOBALS['ran_booster_cached_theme_targets'][ $stylesheet ] ??= new \RAN\Tests\PackageRemoval\InstalledThemeFixture( $stylesheet );
}

function delete_theme( string $stylesheet ): mixed {
	$GLOBALS['ran_booster_package_removal_gateway_events'][] = array( 'delete_theme', $stylesheet );
	if ( $GLOBALS['ran_booster_theme_files_removed'] ?? false ) {
		$GLOBALS['ran_booster_removed_theme_targets'][ $stylesheet ] = true;
	}
	$result = $GLOBALS['ran_booster_package_removal_gateway_result'] ?? false;
	if ( $result instanceof \Throwable ) {
		throw $result;
	}
	return $result;
}
