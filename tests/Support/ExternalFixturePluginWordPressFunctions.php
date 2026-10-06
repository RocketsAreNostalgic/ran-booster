<?php

declare(strict_types=1);

// Focused global WordPress hook fixture for the physically separate provider plugin.

if ( ! defined( 'ABSPATH' ) ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Exercise the WordPress-owned constant without changing its runtime identity.
	define( 'ABSPATH', dirname( __DIR__ ) . '/fixtures/wordpress/' );
}

if ( ! function_exists( 'add_action' ) ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound, Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Preserve the WordPress hook-registration signature; this fixture records callbacks without scheduling priority. WordPress function double must retain the host-owned name.
	function add_action( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): bool {
		$GLOBALS['ran_booster_external_fixture_actions'][ $hook ][] = $callback;

		return true;
	}
}
