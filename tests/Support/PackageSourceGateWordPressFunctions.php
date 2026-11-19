<?php
/**
 * The site facts PackageSourceGate reads, for unit tests that run without WordPress.
 *
 * The bootstrap loads this for every test, so unit tests run on a development site where the gate
 * pauses nothing, as they did before the gate existed. A test of the gate sets
 * `ran_booster_test_environment_type` and `ran_booster_test_home_url` to make a site that is not
 * a development site.
 *
 * @package RAN\Booster\Tests
 */

declare(strict_types=1);

namespace RAN\Deployment;

function wp_get_environment_type(): string {
	return (string) ( $GLOBALS['ran_booster_test_environment_type'] ?? 'local' );
}

function home_url(): string {
	return (string) ( $GLOBALS['ran_booster_test_home_url'] ?? 'http://localhost' );
}

function wp_parse_url( string $url, int $component = -1 ): mixed {
	return parse_url( $url, $component ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- This is the test stand-in for wp_parse_url().
}
