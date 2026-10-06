<?php

declare(strict_types=1);


$GLOBALS['ran_booster_bootstrap_actions']      = array();
$GLOBALS['ran_booster_bootstrap_filters']      = array();
$GLOBALS['ran_booster_activation_callbacks']   = array();
$GLOBALS['ran_booster_deactivation_callbacks'] = array();
$GLOBALS['ran_booster_cleared_cron_hooks']     = array();
$GLOBALS['ran_booster_fired_actions']          = array();
$GLOBALS['ran_booster_rest_routes']            = array();
$GLOBALS['ran_booster_loaded_textdomains']     = array();

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- The isolated bootstrap spy retains this WordPress-owned function name.
function is_multisite(): bool {
	return $GLOBALS['ran_booster_bootstrap_multisite'] ?? true;
}

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- The isolated bootstrap spy retains this WordPress-owned function name.
function did_action( string $hook ): int {
	unset( $hook );

	return 0;
}

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- The isolated bootstrap spy retains this WordPress-owned function name.
function add_action(
	string $hook,
	callable $callback,
	int $priority = 10,
	int $accepted_args = 1
): bool {
	$GLOBALS['ran_booster_bootstrap_actions'][] = array(
		'hook'         => $hook,
		'callback'     => $callback,
		'priority'     => $priority,
		'acceptedArgs' => $accepted_args,
	);

	return true;
}

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- The isolated bootstrap spy retains this WordPress-owned function name.
function add_filter(
	string $hook,
	callable $callback,
	int $priority = 10,
	int $accepted_args = 1
): bool {
	$GLOBALS['ran_booster_bootstrap_filters'][] = array(
		'hook'         => $hook,
		'callback'     => $callback,
		'priority'     => $priority,
		'acceptedArgs' => $accepted_args,
	);

	return true;
}

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- The isolated bootstrap spy retains this WordPress-owned function name.
function do_action( string $hook, mixed ...$arguments ): void {
	$GLOBALS['ran_booster_fired_actions'][] = array(
		'hook'      => $hook,
		'arguments' => $arguments,
	);
}

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- The isolated bootstrap spy retains this WordPress-owned function name.
function register_activation_hook( string $file, callable $callback ): void {
	$GLOBALS['ran_booster_activation_callbacks'][ $file ] = $callback;
}

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- The isolated bootstrap spy retains this WordPress-owned function name.
function register_deactivation_hook( string $file, callable $callback ): void {
	$GLOBALS['ran_booster_deactivation_callbacks'][ $file ] = $callback;
}

function register_rest_route( string $namespace, string $route, array $arguments ): bool { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound,Universal.NamingConventions.NoReservedKeywordParameterNames.namespaceFound,Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Preserve the WordPress signature; compact() reads namespace, route and arguments to record the route. The isolated bootstrap spy retains this WordPress-owned function name.
	$GLOBALS['ran_booster_rest_routes'][] = compact( 'namespace', 'route', 'arguments' );

	return true;
}

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- The isolated bootstrap spy retains this WordPress-owned function name.
function get_file_data( string $file, array $headers, string $context = '' ): array {
	unset( $file, $headers, $context );

	return array( 'version' => '0.1.0-alpha.19' );
}

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- The isolated bootstrap spy retains this WordPress-owned function name.
function plugin_dir_path( string $file ): string {
	return dirname( $file ) . '/';
}

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- The isolated bootstrap spy retains this WordPress-owned function name.
function plugin_dir_url( string $file ): string {
	return 'https://example.test/wp-content/plugins/' . basename( dirname( $file ) ) . '/';
}

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- The isolated bootstrap spy retains this WordPress-owned function name.
function plugin_basename( string $file ): string {
	return basename( dirname( $file ) ) . '/' . basename( $file );
}

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound,Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- WordPress load_plugin_textdomain stub preserves native argument slots; compact() reads the parameters to record the exact call arguments. The isolated bootstrap spy retains this WordPress-owned function name.
function load_plugin_textdomain( string $domain, bool $deprecated = false, string $path = '' ): bool {
	$GLOBALS['ran_booster_loaded_textdomains'][] = compact( 'domain', 'deprecated', 'path' );

	return true;
}

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- The isolated bootstrap spy retains this WordPress-owned function name.
function wp_clear_scheduled_hook( string $hook, array $arguments = array() ): int {
	$GLOBALS['ran_booster_cleared_cron_hooks'][] = array(
		'hook'      => $hook,
		'arguments' => $arguments,
	);

	return 1;
}

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- The isolated bootstrap spy retains this WordPress-owned function name.
function esc_html__( string $text, string $domain = 'default' ): string {
	unset( $domain );

	return $text;
}

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- The isolated bootstrap spy retains this WordPress-owned function name.
function esc_html_e( string $text, string $domain = 'default' ): void {
	unset( $domain );
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- This isolated WordPress spy performs the escaping it records.
	echo htmlspecialchars( $text, ENT_QUOTES );
}

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- The isolated bootstrap spy retains this WordPress-owned function name.
function esc_url( string $url ): string {
	return filter_var( $url, FILTER_VALIDATE_URL ) ? $url : '';
}

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- The isolated bootstrap spy retains this WordPress-owned function name.
function current_user_can( string $capability ): bool {
	return 'manage_network_plugins' === $capability
		&& (bool) ( $GLOBALS['ran_booster_bootstrap_manage_network_plugins'] ?? true );
}

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- The isolated bootstrap spy retains this WordPress-owned function name.
function wp_die( string $message ): never {
	// Test spy preserves the already escaped message for assertions.
	throw new RuntimeException( $message );
}
