<?php

declare(strict_types=1);

// Doubles for the default read-only release check closure. Load only inside a separate test process.

if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- WordPress constant double must retain the host-owned name.
	define( 'MINUTE_IN_SECONDS', 60 );
}

if ( ! function_exists( 'apply_filters' ) ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WordPress function double must retain the host-owned name.
	function apply_filters( string $hook, mixed $value, mixed ...$arguments ): mixed {
		$GLOBALS['ran_booster_read_only_check']['calls'][] = array( $hook, $value, ...$arguments );

		return $GLOBALS['ran_booster_read_only_check']['answer'];
	}
}

if ( ! function_exists( 'get_transient' ) ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WordPress function double must retain the host-owned name.
	function get_transient( string $key ): mixed {
		return $GLOBALS['ran_booster_read_only_check']['transients'][ $key ] ?? false;
	}
}

if ( ! function_exists( 'set_transient' ) ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound,Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- WordPress function double must retain the host-owned name and signature.
	function set_transient( string $key, mixed $value, int $expiration ): bool {
		$GLOBALS['ran_booster_read_only_check']['transients'][ $key ] = $expiration;

		return true;
	}
}
