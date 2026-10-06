<?php

// Recover the active Core container from its WordPress lifecycle callback for
// source-owned WP-CLI proofs. This test-only inspection must never become a
// production accessor.

use RAN\Booster;
use RAN\Internal\CoreContainer;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	throw new RuntimeException( 'The Core container fixture is restricted to source-owned WP-CLI proofs.' );
}

$ran_booster_plugin_file = WP_PLUGIN_DIR . '/ran-booster/ran-booster.php';
$ran_booster_hook_name   = 'activate_' . plugin_basename( $ran_booster_plugin_file );
$ran_booster_hook        = $GLOBALS['wp_filter'][ $ran_booster_hook_name ] ?? null;
$ran_booster_callbacks   = is_object( $ran_booster_hook ) && is_array( $ran_booster_hook->callbacks ?? null )
	? $ran_booster_hook->callbacks
	: array();

foreach ( $ran_booster_callbacks as $ran_booster_priority_callbacks ) {
	foreach ( is_array( $ran_booster_priority_callbacks ) ? $ran_booster_priority_callbacks : array() as $ran_booster_registered ) {
		$ran_booster_callback = is_array( $ran_booster_registered ) ? ( $ran_booster_registered['function'] ?? null ) : null;
		if ( is_array( $ran_booster_callback )
			&& ( $ran_booster_callback[0] ?? null ) instanceof Booster
			&& 'activate' === ( $ran_booster_callback[1] ?? null )
		) {
			$ran_booster_container = ( new ReflectionProperty( Booster::class, 'container' ) )->getValue( $ran_booster_callback[0] );
			if ( $ran_booster_container instanceof CoreContainer ) {
				return $ran_booster_container;
			}
		}
	}
}

throw new RuntimeException( 'The active Core lifecycle callback is unavailable.' );
