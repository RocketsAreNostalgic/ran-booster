<?php

declare(strict_types=1);

namespace RAN;

function current_user_can( string $capability ): bool {
	$GLOBALS['ran_booster_test_capability_checks'][] = $capability;

	return $GLOBALS['ran_booster_test_capabilities'][ $capability ] ?? true;
}

function check_admin_referer( string $action, string $queryArg = '_wpnonce' ): bool {
	$GLOBALS['ran_booster_test_nonce_checks'][] = $action;
	if ( false === ( $GLOBALS['ran_booster_test_nonce_valid'] ?? true )
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- This test shim is the nonce-verification boundary.
		|| ( '_wpnonce' !== $queryArg && ! isset( $_POST[ $queryArg ] ) )
	) {
		throw new \RuntimeException( 'Invalid nonce.' );
	}

	return true;
}

function wp_die( string $message ): never {
	// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Test shim preserves the production call for assertions.
	throw new \RuntimeException( $message );
}

if ( ! function_exists( __NAMESPACE__ . '\\wp_unslash' ) ) {
	function wp_unslash( mixed $value ): mixed {
		return $value;
	}
}

if ( ! function_exists( __NAMESPACE__ . '\\sanitize_key' ) ) {
	function sanitize_key( mixed $value ): string {
		return (string) preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) );
	}
}

if ( ! function_exists( __NAMESPACE__ . '\\sanitize_text_field' ) ) {
	function sanitize_text_field( mixed $value ): string {
		return trim( preg_replace( '/<[^>]*>/', '', (string) $value ) );
	}
}

function esc_html__( string $text, string $domain = 'default' ): string {
	return $text;
}

function esc_html( string $text ): string {
	return $text;
}

function esc_html_e( string $text, string $domain = 'default' ): void {
	unset( $domain );

	echo esc_html( $text );
}

function esc_url( string $url ): string {
	return $url;
}

function ran_booster_table_name(): string {
	return 'wp_ran_booster_packages';
}
