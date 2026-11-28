<?php

declare(strict_types=1);

namespace RAN\Secrets;

if ( ! function_exists( __NAMESPACE__ . '\\wp_salt' ) ) {
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- The fixture retains the WordPress signature; every scheme shares one test salt.
	function wp_salt( string $scheme = 'auth' ): string {
		return is_string( $GLOBALS['ran_booster_test_wp_salt'] ?? null ) ? $GLOBALS['ran_booster_test_wp_salt'] : 'fixture-site-salt';
	}
}
