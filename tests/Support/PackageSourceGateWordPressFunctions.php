<?php
/**
 * The site facts PackageSourceGate reads, for unit tests that run without WordPress.
 *
 * The bootstrap loads this for every test. With nothing set the site has no explicit environment
 * type and a localhost home, so it is a development site and the gate pauses nothing, as before
 * the gate existed. `ran_booster_test_environment_type` stands for an explicitly set
 * WP_ENVIRONMENT_TYPE and `ran_booster_test_home_url` for the home address.
 *
 * @package RAN\Booster\Tests
 */

declare(strict_types=1);

namespace RAN\Deployment;

function getenv( string $name ): string|false {
	if ( 'WP_ENVIRONMENT_TYPE' === $name ) {
		return isset( $GLOBALS['ran_booster_test_environment_type'] ) ? (string) $GLOBALS['ran_booster_test_environment_type'] : false;
	}
	return \getenv( $name );
}

function wp_get_environment_type(): string {
	$type = getenv( 'WP_ENVIRONMENT_TYPE' );
	return in_array( $type, array( 'local', 'development', 'staging', 'production' ), true ) ? $type : 'production';
}

function home_url(): string {
	return (string) ( $GLOBALS['ran_booster_test_home_url'] ?? 'http://localhost' );
}

function wp_parse_url( string $url, int $component = -1 ): mixed {
	return parse_url( $url, $component ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- This is the test stand-in for wp_parse_url().
}
