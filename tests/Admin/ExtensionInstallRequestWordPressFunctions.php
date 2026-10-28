<?php

declare(strict_types=1);

namespace RAN; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- WordPress functions resolve in the production namespace.

function current_user_can( string $capability ): bool {
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
	return array(); }
// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Fixed inactive test state.
function is_plugin_active( string $plugin ): bool {
	return false; }
// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Fixed inactive test state.
function is_plugin_active_for_network( string $plugin ): bool {
	return false; }
function trailingslashit( string $value ): string {
	return rtrim( $value, '/' ) . '/'; }
function admin_url( string $path ): string {
	return 'http://example.test/wp-admin/' . $path; }
/** @param array<string, string> $args */
function add_query_arg( array $args, string $url ): string {
	return $url . '?' . http_build_query( $args ); }
function wp_safe_redirect( string $location ): never {
	throw new \RuntimeException( 'redirect:' . $location ); }
