<?php
/**
 * WordPress site-transient double for the persisted offer reader test.
 *
 * @package RAN\Booster\Tests
 */

declare(strict_types=1);

// Separate-process projections use the pinned comparator without starting the selected-runtime lifecycle.
require_once dirname( __DIR__, 3 ) . '/vendor/ran/wp-release-updater/src/Contract/ReleaseVersion.php';

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WordPress function double must retain the host-owned name.
function get_site_transient( string $name ): mixed {
	return $GLOBALS['ran_booster_test_site_transients'][ $name ] ?? false;
}
