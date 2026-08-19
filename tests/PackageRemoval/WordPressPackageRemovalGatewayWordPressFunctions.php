<?php

declare(strict_types=1);

namespace RAN\PackageRemoval;

if ( ! defined( 'ABSPATH' ) ) {
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

function wp_clean_plugins_cache( bool $clearUpdateCache = true ): void {
	$GLOBALS['ran_booster_package_removal_gateway_events'][] = array( 'clean', $clearUpdateCache );
}
