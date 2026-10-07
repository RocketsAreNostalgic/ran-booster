<?php

declare(strict_types=1);

namespace RAN\PackageRemoval;

if ( ! defined( 'ABSPATH' ) ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Exercise the WordPress-owned constant without changing its runtime identity.
	define( 'ABSPATH', dirname( __DIR__ ) . '/fixtures/wordpress/' );
}

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
