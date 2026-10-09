<?php

declare(strict_types=1);


$GLOBALS['ran_booster_webhook_v1_routes'] = array();

/**
 * @param array<string, mixed> $arguments
 */
function register_rest_route( string $namespace, string $route, array $arguments ): bool { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound,Universal.NamingConventions.NoReservedKeywordParameterNames.namespaceFound,Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Exact WordPress spy identity and signature are required. Preserve the WordPress signature; compact() reads namespace, route and arguments to record the route.
	$GLOBALS['ran_booster_webhook_v1_routes'][] = compact( 'namespace', 'route', 'arguments' );

	return true;
}

function get_option( string $option, mixed $default = false ): mixed { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound,Universal.NamingConventions.NoReservedKeywordParameterNames.defaultFound -- Exact WordPress spy identity and signature are required. Preserve the WordPress function parameter signature.
	unset( $option );
	$GLOBALS['ran_booster_webhook_v1_operations'][] = 'option';

	return $default;
}

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Exact WordPress function identity is required by this isolated boundary spy.
function update_option( string $option, mixed $value, bool|string|null $autoload = null ): bool {
	unset( $option, $value, $autoload );
	$GLOBALS['ran_booster_webhook_v1_operations'][] = 'option';

	return true;
}

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Exact WordPress function identity is required by this isolated boundary spy.
function wp_json_encode( mixed $value, int $flags = 0, int $depth = 512 ): string|false {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Isolated WordPress test double.
	return json_encode( $value, $flags, $depth );
}

/**
 * @param array<string, mixed> $arguments
 * @return array{}
 */
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Exact WordPress function identity is required by this isolated boundary spy.
function wp_remote_request( string $url, array $arguments = array() ): array {
	unset( $url, $arguments );
	$GLOBALS['ran_booster_webhook_v1_operations'][] = 'remote';

	return array();
}

/**
 * Dispatch one recorded REST route using WordPress's namespace-plus-route shape.
 */
function ran_booster_test_dispatch_rest_route( string $request_route, mixed $request ): bool {
	foreach ( $GLOBALS['ran_booster_webhook_v1_routes'] as $definition ) {
		$pattern = '#^/' . preg_quote( $definition['namespace'], '#' ) . $definition['route'] . '$#D';
		if ( 1 !== preg_match( $pattern, $request_route ) ) {
			continue;
		}

		( $definition['arguments']['callback'] )( $request );

		return true;
	}

	return false;
}
