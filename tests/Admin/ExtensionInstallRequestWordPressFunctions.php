<?php

declare(strict_types=1);

namespace RAN; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- WordPress functions resolve in the production namespace.

function current_user_can( string $capability, mixed ...$args ): bool {
	$GLOBALS['ran_booster_extension_request_capability_args'] = $args;
	return (bool) ( $GLOBALS['ran_booster_extension_request_capabilities'][ $capability ] ?? false );
}
// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- WordPress signature.
function esc_html__( string $message, string $domain = 'default' ): string {
	return $message; }
// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- WordPress signature.
function __( string $message, string $domain = 'default' ): string {
	return $message; }
function wp_die( string $message ): never {
	throw new \RuntimeException( 'denied:' . $message ); }
function wp_unslash( mixed $value ): mixed {
	return $value; }
function sanitize_key( string $value ): string {
	return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $value ) ) ?? '' ); }
function check_admin_referer( string $action, string $query_arg = '_wpnonce' ): bool {
	$GLOBALS['ran_booster_extension_request_nonce_action'] = $action;
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- This stub implements the nonce check itself.
	if ( ( $_POST[ $query_arg ] ?? null ) !== 'good:' . $action ) {
		throw new \RuntimeException( 'bad nonce' ); }
	return true;
}
/** @return array<string, array<string, string>> */
function get_plugins(): array {
	++$GLOBALS['ran_booster_extension_request_plugin_reads'];
	return $GLOBALS['ran_booster_extension_request_plugins'] ?? array();
}
// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Fixed inactive test state.
function is_plugin_active( string $plugin ): bool {
	return (bool) ( $GLOBALS['ran_booster_extension_request_active'] ?? false );
}
// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Fixed inactive test state.
function is_plugin_active_for_network( string $plugin ): bool {
	return false; }
function trailingslashit( string $value ): string {
	return rtrim( $value, '/' ) . '/'; }
function admin_url( string $path ): string {
	return 'http://example.test/wp-admin/' . $path; }
/** @param array<string, string> $args */
function add_query_arg( array|string $args, string $value, ?string $url = null ): string {
	if ( is_array( $args ) ) {
		return $value . '?' . http_build_query( $args );
	}
	return (string) $url . '&' . http_build_query( array( $args => $value ) );
}
function is_multisite(): bool {
	return (bool) ( $GLOBALS['ran_booster_extension_request_multisite'] ?? false );
}
// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Fixture returns the configured network-only state.
function is_network_only_plugin( string $plugin ): bool {
	return (bool) ( $GLOBALS['ran_booster_extension_request_network_only'] ?? false );
}
function activate_plugin( string $plugin, string $redirect, bool $network ): mixed {
	$GLOBALS['ran_booster_extension_request_activation'] = array( $plugin, $redirect, $network );
	$GLOBALS['ran_booster_extension_request_active']     = (bool) ( $GLOBALS['ran_booster_extension_request_becomes_active'] ?? true );
	return $GLOBALS['ran_booster_extension_request_error'] ?? null;
}
function is_wp_error( mixed $value ): bool {
	return is_object( $value ) && method_exists( $value, 'get_error_code' );
}
function wp_safe_redirect( string $location ): never {
	throw new \RuntimeException( 'redirect:' . $location ); }
