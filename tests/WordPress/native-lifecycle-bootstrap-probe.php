<?php

// Installed only as a test MU plugin in the marked disposable CI site.
if ( '1' !== getenv( 'RAN_BOOSTER_RELEASE_CAPABILITY_TEST_DISPOSABLE' ) || ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return; }
// Opt-in query log (#1091): one JSON summary per WP-CLI request, read by native-lifecycle-query-summary.php.
if ( '1' === getenv( 'RAN_BOOSTER_NATIVE_LIFECYCLE_QUERY_LOG' ) ) {
	$ran_booster_query_log = getenv( 'RUNNER_TEMP' ) . '/ran-booster-native-query-log';
	if ( ! is_dir( $ran_booster_query_log ) || is_link( $ran_booster_query_log ) || ( defined( 'SAVEQUERIES' ) && true !== constant( 'SAVEQUERIES' ) ) ) {
		throw new RuntimeException( 'The native lifecycle query log is not exclusively configured.' ); }
	if ( ! defined( 'SAVEQUERIES' ) ) {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- WordPress-owned query log switch, enabled only in the marked disposable site.
		define( 'SAVEQUERIES', true ); }
	register_shutdown_function(
		static function () use ( $ran_booster_query_log ): void {
			global $wpdb;
			$log = array(
				'script' => '',
				'scale'  => (string) getenv( 'RAN_BOOSTER_NATIVE_LIFECYCLE_SCALE' ),
				'total'  => 0,
			);
			foreach ( $GLOBALS['argv'] ?? array() as $argument ) {
				if ( is_string( $argument ) && str_ends_with( $argument, '.php' ) ) {
					$log['script'] = basename( $argument ); }
			}
			foreach ( $wpdb->queries ?? array() as $query ) {
				$sql = trim( (string) $query[0] );
				// Innermost RAN frame issued the query, the innermost outside Storage asked for it, the outermost entered RAN.
				$frames = array_values( array_filter( explode( ', ', (string) $query[2] ), static fn ( string $frame ): bool => str_starts_with( $frame, 'RAN\\' ) ) );
				$owners = array_values( array_filter( $frames, static fn ( string $frame ): bool => ! str_starts_with( $frame, 'RAN\\Storage\\' ) ) );
				$groups = array(
					'by_keyword'   => strtoupper( (string) strtok( $sql, " \t\r\n(" ) ),
					'by_statement' => substr( (string) preg_replace( array( "/'(?:[^'\\\\]|\\\\.)*'/s", '/\b\d+\b/', '/\s+/', '/\((?:\?|N)(?:, ?(?:\?|N))+\)/' ), array( '?', 'N', ' ', '(...)' ), $sql ), 0, 160 ),
					'by_caller'    => $frames[ count( $frames ) - 1 ] ?? '(none)',
					'by_owner'     => $owners[ count( $owners ) - 1 ] ?? '(none)',
					'by_entry'     => $frames[0] ?? '(none)',
				);
				++$log['total'];
				foreach ( $groups as $group => $key ) {
					$log[ $group ][ $key ] = ( $log[ $group ][ $key ] ?? 0 ) + 1;
				}
				// Inclusive cost: the query counts once for every distinct RAN frame on its stack.
				foreach ( array_unique( $frames ) as $frame ) {
					$log['by_frame'][ $frame ] = ( $log['by_frame'][ $frame ] ?? 0 ) + 1;
				}
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test-owned journal in the runner's exclusive temporary directory; the WordPress filesystem API may be unavailable at shutdown.
			file_put_contents( $ran_booster_query_log . '/' . hrtime( true ) . '-' . getmypid() . '.json', (string) wp_json_encode( $log ) );
		}
	);
}
$GLOBALS['ran_booster_c4_bootstrap_probe'] = array(
	'start'                 => hrtime( true ),
	'memory_start'          => memory_get_usage( true ),
	'http'                  => 0,
	'zip'                   => 0,
	'bytes'                 => 0,
	'authorization_headers' => 0,
);
add_filter(
	'pre_http_request',
	static function ( mixed $pre, array $args ): mixed {
		if ( isset( $GLOBALS['ran_booster_c4_bootstrap_probe']['elapsed_ns'] ) ) {
			return $pre; }
		++$GLOBALS['ran_booster_c4_bootstrap_probe']['http'];
		$GLOBALS['ran_booster_c4_bootstrap_probe']['zip']                   += (int) (bool) ( $args['stream'] ?? false );
		$GLOBALS['ran_booster_c4_bootstrap_probe']['authorization_headers'] += (int) isset( $args['headers']['Authorization'] );
		return new WP_Error( 'c4_declaration_http_forbidden' );
	},
	PHP_INT_MIN,
	2
);
add_action(
	'init',
	static function (): void {
		$p                 =& $GLOBALS['ran_booster_c4_bootstrap_probe'];
		$p['elapsed_ns']   = hrtime( true ) - $p['start'];
		$p['memory_delta'] = memory_get_usage( true ) - $p['memory_start'];
		$p['native_hooks'] = 0;
		foreach ( $GLOBALS['wp_filter'] as $hook ) {
			foreach ( $hook->callbacks as $callbacks ) {
				foreach ( $callbacks as $entry ) {
					$callback = $entry['function'];
					if ( is_array( $callback ) && isset( $callback[0] ) && is_object( $callback[0] )
					&& 'RAN\\WPReleaseUpdater\\V1\\WordPress\\NativePackageUpdater' === get_class( $callback[0] ) ) {
						++$p['native_hooks']; }
				}
			}
		}
	},
	PHP_INT_MIN
);
