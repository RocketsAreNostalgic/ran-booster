<?php

declare(strict_types=1);

namespace RAN\Admin\ReleaseManagement;

use RuntimeException;

function __( string $text, string $domain = 'default' ): string {
	unset( $domain );

	return $text;
}

function esc_html( mixed $value ): string {
	return htmlspecialchars( (string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
}

function esc_attr( mixed $value ): string {
	return esc_html( $value );
}

function esc_url( mixed $value ): string {
	return esc_attr( $value );
}

function esc_html__( string $text, string $domain = 'default' ): string {
	unset( $domain );

	return esc_html( $text );
}

function esc_html_e( string $text, string $domain = 'default' ): void {
	unset( $domain );
	echo esc_html( $text );
}

function esc_attr_e( string $text, string $domain = 'default' ): void {
	unset( $domain );
	echo esc_attr( $text );
}

function disabled( mixed $disabled, mixed $current = true, bool $display = true ): string {
	$result = (string) $disabled === (string) $current ? ' disabled="disabled"' : '';
	if ( $display ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fixed test attribute fragment.
		echo $result;
	}

	return $result;
}

function checked( mixed $checked, mixed $current = true, bool $display = true ): string {
	$result = (string) $checked === (string) $current ? ' checked="checked"' : '';
	if ( $display ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fixed test attribute fragment.
		echo $result;
	}

	return $result;
}

function wp_nonce_field( string $action, string $name ): void {
	echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( wp_create_nonce( $action ) ) . '">';
}

function wp_create_nonce( string $action ): string {
	return 'nonce-for-' . $action;
}

function wp_verify_nonce( string $nonce, string $action ): int|false {
	$age = $GLOBALS['ran_booster_release_management_test_nonce_age'] ?? null;
	if ( is_int( $age ) ) {
		return $age;
	}

	return hash_equals( wp_create_nonce( $action ), $nonce ) ? 1 : false;
}

function sanitize_key( mixed $value ): string {
	return (string) preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $value ) );
}

function sanitize_text_field( mixed $value ): string {
	return trim( (string) preg_replace( '/<[^>]*>/', '', (string) $value ) );
}

function wp_unslash( mixed $value ): mixed {
	if ( is_array( $value ) ) {
		return array_map( __NAMESPACE__ . '\\wp_unslash', $value );
	}

	return is_string( $value ) ? stripslashes( $value ) : $value;
}

function current_user_can( string $capability ): bool {
	$denied = $GLOBALS['ran_booster_release_management_test_denied_capabilities'] ?? array();

	return ! is_array( $denied ) || ! in_array( $capability, $denied, true );
}

function admin_url( string $path = '' ): string {
	return 'https://example.test/wp-admin/' . ltrim( $path, '/' );
}

function add_query_arg( array|string $key, mixed $value = null, ?string $url = null ): string {
	if ( is_array( $key ) ) {
		$args = $key;
		$url  = is_string( $value ) ? $value : ( $url ?? '' );
	} else {
		$args = array( $key => $value );
		$url  = $url ?? '';
	}

	return $url . ( str_contains( $url, '?' ) ? '&' : '?' ) . http_build_query( $args, '', '&', PHP_QUERY_RFC3986 );
}

function wp_parse_url( string $url, int $component = -1 ): array|int|string|null|false {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- WordPress parser fixture.
	return parse_url( $url, $component );
}

/** @param array<string, mixed> $output */
function wp_parse_str( string $input, array &$output ): void {
	parse_str( $input, $output );
}

function add_action( string $hook, callable $callback, int $priority = 10, int $acceptedArgs = 1 ): bool {
	$GLOBALS['ran_booster_release_management_test_actions'][ $hook ][] = array(
		'callback'      => $callback,
		'priority'      => $priority,
		'accepted_args' => $acceptedArgs,
	);

	return true;
}

function add_filter( string $hook, callable $callback, int $priority = 10, int $acceptedArgs = 1 ): bool {
	$GLOBALS['ran_booster_release_management_test_filters'][ $hook ][] = array(
		'callback'      => $callback,
		'priority'      => $priority,
		'accepted_args' => $acceptedArgs,
	);

	return true;
}

function plugins_url( string $path, string $plugin ): string {
	unset( $plugin );

	return 'https://example.test/wp-content/plugins/ran-booster/' . ltrim( $path, '/' );
}

/** @param list<string> $dependencies */
function wp_enqueue_script( string $handle, string $source = '', array $dependencies = array(), string|bool|null $version = false, bool $footer = false ): void {
	$GLOBALS['ran_booster_release_management_test_scripts'][ $handle ] = compact( 'source', 'dependencies', 'version', 'footer' );
}

/** @param list<string> $dependencies */
function wp_enqueue_style( string $handle, string $source = '', array $dependencies = array(), string|bool|null $version = false ): void {
	$GLOBALS['ran_booster_release_management_test_styles'][ $handle ] = compact( 'source', 'dependencies', 'version' );
}

/** @param array<string, mixed> $data */
function wp_localize_script( string $handle, string $name, array $data ): bool {
	$GLOBALS['ran_booster_release_management_test_localized'][ $handle ][ $name ] = $data;

	return true;
}

function wp_json_encode( mixed $value ): string|false {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- WordPress encoder fixture.
	return json_encode( $value );
}

function wp_safe_redirect( string $url ): bool {
	$GLOBALS['ran_booster_release_management_test_redirect'] = $url;
	throw new RuntimeException( 'native-redirect' );
}

function header( string $header, bool $replace = true, int $responseCode = 0 ): void {
	unset( $replace, $responseCode );
	$GLOBALS['ran_booster_release_management_test_header'] = $header;
	throw new RuntimeException( 'hx-redirect' );
}

function wp_send_json( mixed $response, ?int $statusCode = null, int $flags = 0 ): never {
	$GLOBALS['ran_booster_release_management_test_json'] = array(
		'response'    => $response,
		'status_code' => $statusCode,
		'flags'       => $flags,
	);
	throw new RuntimeException( 'json-response' );
}
