<?php

declare(strict_types=1);

namespace RAN\Portability;

require_once __DIR__ . '/WpPusherCoexistenceWordPressFunctions.php';

/** @var array<string,array<string,string>> $ran_booster_blueprint_headers */
$GLOBALS['ran_booster_blueprint_headers'] = array();

function get_theme_root( string $identifier ): string {
	return sys_get_temp_dir() . '/ran-blueprint-requirements' . ( '' === $identifier ? '/invalid' : '' );
}

/**
 * @param array<string,string> $headers
 * @return array<string,string>
 */
function get_file_data( string $file, array $headers ): array {
	if ( true === ( $GLOBALS['ran_booster_blueprint_read_failure'] ?? false ) ) {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_trigger_error, WordPress.PHP.NoSilencedErrors.Discouraged -- Reproduce WordPress suppressed file-read failure after a successful readability check.
		@trigger_error( 'Simulated read failure', E_USER_WARNING );
		return array_fill_keys( array_keys( $headers ), '' );
	}
	return array_intersect_key( $GLOBALS['ran_booster_blueprint_headers'][ $file ] ?? array(), $headers );
}

function is_plugin_active( string $file ): bool {
	return in_array( $file, $GLOBALS['ran_booster_blueprint_active'] ?? array(), true );
}

/** @return array<string,object> */
function wp_get_themes(): array {
	return $GLOBALS['ran_booster_blueprint_themes'] ?? array();
}
