<?php

declare(strict_types=1);

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Isolated entrypoint spies replace WordPress.

$GLOBALS['ran_booster_bootstrap_actions']      = array();
$GLOBALS['ran_booster_bootstrap_filters']      = array();
$GLOBALS['ran_booster_activation_callbacks']   = array();
$GLOBALS['ran_booster_deactivation_callbacks'] = array();
$GLOBALS['ran_booster_cleared_cron_hooks']     = array();
$GLOBALS['ran_booster_fired_actions']          = array();

function is_multisite(): bool {
	return true;
}

function did_action( string $hook ): int {
	unset( $hook );

	return 0;
}

function add_action(
	string $hook,
	callable $callback,
	int $priority = 10,
	int $acceptedArgs = 1
): bool {
	$GLOBALS['ran_booster_bootstrap_actions'][] = compact( 'hook', 'callback', 'priority', 'acceptedArgs' );

	return true;
}

function add_filter(
	string $hook,
	callable $callback,
	int $priority = 10,
	int $acceptedArgs = 1
): bool {
	$GLOBALS['ran_booster_bootstrap_filters'][] = compact( 'hook', 'callback', 'priority', 'acceptedArgs' );

	return true;
}

function do_action( string $hook, mixed ...$arguments ): void {
	$GLOBALS['ran_booster_fired_actions'][] = array(
		'hook'      => $hook,
		'arguments' => $arguments,
	);
}

function register_activation_hook( string $file, callable $callback ): void {
	$GLOBALS['ran_booster_activation_callbacks'][ $file ] = $callback;
}

function register_deactivation_hook( string $file, callable $callback ): void {
	$GLOBALS['ran_booster_deactivation_callbacks'][ $file ] = $callback;
}

function get_file_data( string $file, array $headers, string $context = '' ): array {
	unset( $file, $headers, $context );

	return array( 'version' => '0.1.0-alpha.19' );
}

function plugin_dir_path( string $file ): string {
	return dirname( $file ) . '/';
}

function plugin_dir_url( string $file ): string {
	return 'https://example.test/wp-content/plugins/' . basename( dirname( $file ) ) . '/';
}

function plugin_basename( string $file ): string {
	return basename( dirname( $file ) ) . '/' . basename( $file );
}

function wp_clear_scheduled_hook( string $hook, array $arguments = array() ): int {
	$GLOBALS['ran_booster_cleared_cron_hooks'][] = array(
		'hook'      => $hook,
		'arguments' => $arguments,
	);

	return 1;
}

function esc_html__( string $text, string $domain = 'default' ): string {
	unset( $domain );

	return $text;
}

function esc_html_e( string $text, string $domain = 'default' ): void {
	unset( $domain );
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- This isolated WordPress spy performs the escaping it records.
	echo htmlspecialchars( $text, ENT_QUOTES );
}

function esc_url( string $url ): string {
	return filter_var( $url, FILTER_VALIDATE_URL ) ? $url : '';
}

function current_user_can( string $capability ): bool {
	return 'manage_network_plugins' === $capability
		&& (bool) ( $GLOBALS['ran_booster_bootstrap_manage_network_plugins'] ?? true );
}

function wp_die( string $message ): never {
	// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Test spy preserves the already escaped message for assertions.
	throw new RuntimeException( $message );
}
