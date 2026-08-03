<?php

declare(strict_types=1);

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Isolated REST boundary spies replace WordPress.

$GLOBALS['ran_booster_webhook_v1_routes'] = array();

function register_rest_route( string $namespace, string $route, array $arguments ): bool {
	$GLOBALS['ran_booster_webhook_v1_routes'][] = compact( 'namespace', 'route', 'arguments' );

	return true;
}

function get_option( string $option, mixed $default = false ): mixed {
	unset( $option );
	$GLOBALS['ran_booster_webhook_v1_operations'][] = 'option';

	return $default;
}

function update_option( string $option, mixed $value, bool|string|null $autoload = null ): bool {
	unset( $option, $value, $autoload );
	$GLOBALS['ran_booster_webhook_v1_operations'][] = 'option';

	return true;
}

function wp_json_encode( mixed $value, int $flags = 0, int $depth = 512 ): string|false {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Isolated WordPress test double.
	return json_encode( $value, $flags, $depth );
}

function wp_remote_request( string $url, array $arguments = array() ): array {
	unset( $url, $arguments );
	$GLOBALS['ran_booster_webhook_v1_operations'][] = 'remote';

	return array();
}

/**
 * Dispatch one recorded REST route using WordPress's namespace-plus-route shape.
 */
function ran_booster_test_dispatch_rest_route( string $requestRoute, mixed $request ): bool {
	foreach ( $GLOBALS['ran_booster_webhook_v1_routes'] as $definition ) {
		$pattern = '#^/' . preg_quote( $definition['namespace'], '#' ) . $definition['route'] . '$#D';
		if ( 1 !== preg_match( $pattern, $requestRoute ) ) {
			continue;
		}

		( $definition['arguments']['callback'] )( $request );

		return true;
	}

	return false;
}
