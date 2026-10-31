<?php
/** Isolated hook bus for the real public release-updater lifecycle. */

declare(strict_types=1);

$ran_booster_queued_admission_fixture_root = $GLOBALS['ran_booster_queued_admission_fixture_root'] ?? null;
if ( ! is_string( $ran_booster_queued_admission_fixture_root ) || ! str_starts_with( $ran_booster_queued_admission_fixture_root, sys_get_temp_dir() . '/ran-booster-queued-admission-' ) ) {
	throw new RuntimeException( 'The queued-admission fixture root is invalid.' );
}
foreach ( array( 'add_action', 'add_filter', 'doing_action', 'did_action', '__', 'plugin_basename', 'get_current_network_id', 'get_file_data' ) as $ran_booster_queued_admission_function ) {
	if ( function_exists( $ran_booster_queued_admission_function ) ) {
		$ran_booster_queued_admission_reflection = new ReflectionFunction( $ran_booster_queued_admission_function );
		if ( realpath( __FILE__ ) === realpath( (string) $ran_booster_queued_admission_reflection->getFileName() ) ) {
			if ( defined( 'WP_PLUGIN_DIR' ) && ! str_starts_with( WP_PLUGIN_DIR, sys_get_temp_dir() . '/ran-booster-queued-admission-' ) ) {
				throw new RuntimeException( 'The queued-admission test fixture root changed unexpectedly.' );
			}
			if ( ! defined( 'WP_PLUGIN_DIR' ) ) {
				define( 'WP_PLUGIN_DIR', $ran_booster_queued_admission_fixture_root );
			}
			$GLOBALS['ran_booster_queued_admission_fixture_root'] = WP_PLUGIN_DIR;

			$GLOBALS['ran_booster_queued_admission_fixture_loaded'] = true;

			$GLOBALS['ran_booster_queued_admission_hooks'] = array();

			$GLOBALS['wpdb'] = new stdClass(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restore only this owned isolated composition fixture after PHPUnit process state separation.
			return;
		}
		throw new RuntimeException( 'The queued-admission test cannot share the ' . $ran_booster_queued_admission_function . ' WordPress function fixture from ' . (string) $ran_booster_queued_admission_reflection->getFileName() . '.' );
	}
}
if ( defined( 'WP_PLUGIN_DIR' ) || class_exists( 'WP_Error', false ) ) {
	throw new RuntimeException( 'The queued-admission test cannot share a WordPress runtime fixture.' );
}
$GLOBALS['ran_booster_queued_admission_hooks'] = array();

$GLOBALS['ran_booster_queued_admission_fixture_loaded'] = true;

$GLOBALS['wpdb'] = new stdClass(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Composition needs only the real updater's object-typed database boundary; no query is executed by this registration test.

define( 'WP_PLUGIN_DIR', $ran_booster_queued_admission_fixture_root );

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Test fixture must expose the WordPress hook API used by the real runtime.
function add_action( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): bool {
	$GLOBALS['ran_booster_queued_admission_hooks'][ $hook ][] = array(
		'callback'      => $callback,
		'priority'      => $priority,
		'accepted_args' => $accepted_args,
	);

	return true;
}

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Test fixture must expose the WordPress filter API used by the actual Core block.
function add_filter( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): bool {
	return add_action( $hook, $callback, $priority, $accepted_args );
}

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- The updater decides whether it may schedule against this exact lifecycle query.
function doing_action( string $hook ): bool {
	unset( $hook );

	return false;
}

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- The updater decides whether it may schedule against this exact lifecycle query.
function did_action( string $hook ): int {
	unset( $hook );

	return 0;
}

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- The extracted production block needs its normal translation boundary.
function __( string $text, string $domain = 'default' ): string {
	unset( $domain );

	return $text;
}

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Eval cannot retain the production file's magic __FILE__; fixture maps it to the Core identity.
function plugin_basename( string $file ): string {
	unset( $file );

	return 'ran-booster/ran-booster.php';
}

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- The real runtime resolves the isolated single-site identity through this WordPress API.
function get_current_network_id(): int {
	return 1;
}

/**
 * @param array<string, string> $headers
 * @return array<string, string>
 */
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- The real runtime reads the copied Core plugin header through the WordPress API.
function get_file_data( string $file, array $headers, string $context = '' ): array {
	unset( $context );
	$contents = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Bounded fixture reads its exact copied plugin header.
	$data     = array();
	foreach ( $headers as $field => $header ) {
		$data[ $field ] = is_string( $contents ) && 1 === preg_match( '/^[ \\t\\/*#@]*' . preg_quote( $header, '/' ) . ':(.*)$/mi', $contents, $match ) ? trim( $match[1] ) : '';
	}

	return $data;
}
