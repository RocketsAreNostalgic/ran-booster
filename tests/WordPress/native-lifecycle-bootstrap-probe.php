<?php

// Installed only as a test MU plugin in the marked disposable CI site.
// phpcs:disable
if ( '1' !== getenv( 'RAN_BOOSTER_RELEASE_CAPABILITY_TEST_DISPOSABLE' ) || ! defined( 'WP_CLI' ) || ! WP_CLI ) { return; }
$GLOBALS['ran_booster_c4_bootstrap_probe'] = array(
	'start' => hrtime( true ), 'memory_start' => memory_get_usage( true ),
	'http' => 0, 'zip' => 0, 'bytes' => 0, 'authorization_headers' => 0,
);
add_filter( 'pre_http_request', static function ( mixed $pre, array $args ): mixed {
	if ( isset( $GLOBALS['ran_booster_c4_bootstrap_probe']['elapsed_ns'] ) ) { return $pre; }
	++$GLOBALS['ran_booster_c4_bootstrap_probe']['http'];
	$GLOBALS['ran_booster_c4_bootstrap_probe']['zip'] += (int) (bool) ( $args['stream'] ?? false );
	$GLOBALS['ran_booster_c4_bootstrap_probe']['authorization_headers'] += (int) isset( $args['headers']['Authorization'] );
	return new WP_Error( 'c4_declaration_http_forbidden' );
}, PHP_INT_MIN, 2 );
add_action( 'init', static function (): void {
	$p =& $GLOBALS['ran_booster_c4_bootstrap_probe'];
	$p['elapsed_ns'] = hrtime( true ) - $p['start'];
	$p['memory_delta'] = memory_get_usage( true ) - $p['memory_start'];
	$p['native_hooks'] = 0;
	foreach ( $GLOBALS['wp_filter'] as $hook ) {
		foreach ( $hook->callbacks as $callbacks ) {
			foreach ( $callbacks as $entry ) {
				$callback = $entry['function'];
				if ( is_array( $callback ) && isset( $callback[0] ) && is_object( $callback[0] )
					&& 'RAN\\WPReleaseUpdater\\V1\\WordPress\\NativePluginUpdater' === get_class( $callback[0] ) ) { ++$p['native_hooks']; }
			}
		}
	}
}, PHP_INT_MIN );
