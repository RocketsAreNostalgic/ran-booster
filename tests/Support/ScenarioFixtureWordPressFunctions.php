<?php

declare(strict_types=1);

// Isolated WordPress doubles: loaded only within separate PHPUnit processes.

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Preserve the WordPress function identity.
function wp_get_environment_type(): string {
	return $GLOBALS['ran_booster_scenario_environment'] ?? 'development';
}

/** @param array<string, string> $headers
 * @return array{version: string}
 */
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound, Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Preserve the WordPress signature while returning controlled header metadata.
function get_file_data( string $file, array $headers, string $context = '' ): array {
	return array( 'version' => $GLOBALS['ran_booster_scenario_version'] ?? '1.0.0-beta.35' );
}

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Preserve the WordPress function identity.
function get_option( string $key, mixed $fallback = false ): mixed {
	return $GLOBALS['ran_booster_scenario_options'][ $key ] ?? $fallback;
}

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Preserve the WordPress function identity.
function update_option( string $key, mixed $value, bool $autoload = true ): bool {
	$GLOBALS['ran_booster_scenario_options'][ $key ] = $value;
	$GLOBALS['ran_booster_scenario_autoload']        = $autoload;
	return true;
}

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Preserve the WordPress function identity.
function delete_option( string $key ): bool {
	unset( $GLOBALS['ran_booster_scenario_options'][ $key ] );
	return true;
}

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Preserve the WordPress function identity.
function wp_json_encode( mixed $value ): string|false {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Implement the isolated WordPress JSON double.
	return json_encode( $value );
}

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Preserve the WordPress function identity.
function wp_parse_url( string $url, int $component = -1 ): mixed {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Implement the isolated WordPress URL double.
	return parse_url( $url, $component );
}

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound, Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Record the host hook signature without a scheduler.
function add_filter( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): bool {
	$GLOBALS['ran_booster_scenario_hooks'][ $hook ] = $callback;
	return true;
}

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Preserve the WordPress function identity.
function add_action( string $hook, callable $callback ): bool {
	return add_filter( $hook, $callback );
}

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Preserve the WordPress function identity.
function current_user_can( string $capability ): bool {
	return 'manage_options' === $capability && ( $GLOBALS['ran_booster_scenario_admin'] ?? true );
}

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Preserve the WordPress function identity.
function wp_die( string $message ): never {
	throw new RuntimeException( $message );
}

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Preserve the WordPress function identity.
function check_admin_referer( string $action ): int {
	if ( 'ran_booster_scenarios_save' !== $action || ! ( $GLOBALS['ran_booster_scenario_nonce'] ?? true ) ) {
		throw new RuntimeException( 'Invalid nonce.' );
	}
	return 1;
}

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Preserve the WordPress function identity.
function sanitize_key( string $key ): string {
	return (string) preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) );
}

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Preserve the WordPress function identity.
function wp_unslash( string $value ): string {
	return stripslashes( $value );
}

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Preserve the WordPress function identity.
function admin_url( string $path ): string {
	return 'https://disposable.example.test/wp-admin/' . $path;
}

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Stop at redirect before the production callback exits its process.
function wp_safe_redirect( string $url ): bool {
	throw new RuntimeException( $url );
}

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Preserve the WordPress function identity.
function get_temp_dir(): string {
	return sys_get_temp_dir();
}

/** @param array<string, mixed> $args */
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Connect the real bundled provider client to the controlled HTTP boundary without network access.
function wp_remote_get( string $url, array $args ): mixed {
	$response = ran_booster_scenarios_http( false, $args, $url );
	if ( false === $response ) {
		throw new RuntimeException( 'Unexpected real network request: ' . $url );
	}
	return $response;
}

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Preserve the WordPress function identity.
function is_wp_error( mixed $response ): bool {
	return $response instanceof WP_Error;
}

/** @param array<string, mixed> $response */
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Preserve the WordPress function identity.
function wp_remote_retrieve_response_code( array $response ): int {
	return $response['response']['code'];
}

/** @param array<string, mixed> $response */
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Preserve the WordPress function identity.
function wp_remote_retrieve_body( array $response ): string {
	return $response['body'];
}

/** @param array<string, mixed> $response */
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Preserve the WordPress function identity.
function wp_remote_retrieve_header( array $response, string $header ): string {
	return $response['headers'][ strtolower( $header ) ] ?? '';
}
