<?php

declare(strict_types=1);

// Global WordPress function doubles; the early-translation test requires this in a separate process.

if ( ! function_exists( 'did_action' ) ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WordPress function double must retain the host-owned name.
	function did_action( string $hook ): int {
		return 'init' === $hook && true === ( $GLOBALS['ran_booster_test_init_has_run'] ?? false ) ? 1 : 0;
	}
}

if ( ! function_exists( '__' ) ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WordPress function double must retain the host-owned name.
	function __( string $text, string $domain = 'default' ): string {
		unset( $domain );
		if ( true !== ( $GLOBALS['ran_booster_test_init_has_run'] ?? false ) ) {
			throw new \LogicException( 'Translation requested before init: ' . $text );
		}

		return '[translated] ' . $text;
	}
}
