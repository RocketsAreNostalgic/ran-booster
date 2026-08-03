<?php

// Recover the active Core container from its WordPress lifecycle callback for
// source-owned WP-CLI proofs. This test-only inspection must never become a
// production accessor.
// phpcs:disable

use RAN\Booster;
use RAN\Internal\CoreContainer;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	throw new RuntimeException( 'The Core container fixture is restricted to source-owned WP-CLI proofs.' );
}

$plugin_file = WP_PLUGIN_DIR . '/ran-booster/ran-booster.php';
$hook_name   = 'activate_' . plugin_basename( $plugin_file );
$hook        = $GLOBALS['wp_filter'][ $hook_name ] ?? null;
$callbacks   = is_object( $hook ) && is_array( $hook->callbacks ?? null )
	? $hook->callbacks
	: array();

foreach ( $callbacks as $priority_callbacks ) {
	foreach ( is_array( $priority_callbacks ) ? $priority_callbacks : array() as $registered ) {
		$callback = is_array( $registered ) ? ( $registered['function'] ?? null ) : null;
		if ( is_array( $callback )
			&& ( $callback[0] ?? null ) instanceof Booster
			&& 'activate' === ( $callback[1] ?? null )
		) {
			$container = ( new ReflectionProperty( Booster::class, 'container' ) )->getValue( $callback[0] );
			if ( $container instanceof CoreContainer ) {
				return $container;
			}
		}
	}
}

throw new RuntimeException( 'The active Core lifecycle callback is unavailable.' );
