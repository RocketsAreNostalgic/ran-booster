<?php

declare(strict_types=1);

// Focused global WordPress hook fixture for the physically separate provider plugin.

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/fixtures/wordpress/' );
}

if ( ! function_exists( 'add_action' ) ) {
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Preserve the WordPress hook-registration signature; this fixture records callbacks without scheduling priority.
	function add_action( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): bool {
		$GLOBALS['ran_booster_external_fixture_actions'][ $hook ][] = $callback;

		return true;
	}
}
