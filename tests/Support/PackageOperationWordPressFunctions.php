<?php

declare(strict_types=1);

namespace RAN;

if ( ! function_exists( __NAMESPACE__ . '\\wp_unslash' ) ) {
	function wp_unslash( mixed $value ): mixed {
		return $value;
	}
}

if ( ! function_exists( __NAMESPACE__ . '\\sanitize_key' ) ) {
	function sanitize_key( mixed $value ): string {
		return strtolower( (string) preg_replace( '/[^a-z0-9_-]/i', '', (string) $value ) );
	}
}

if ( ! function_exists( __NAMESPACE__ . '\\sanitize_text_field' ) ) {
	function sanitize_text_field( mixed $value ): string {
		return trim( (string) $value );
	}
}

if ( ! function_exists( __NAMESPACE__ . '\\esc_html' ) ) {
	function esc_html( mixed $value ): string {
		return htmlspecialchars( (string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
	}
}

if ( ! function_exists( __NAMESPACE__ . '\\esc_url' ) ) {
	function esc_url( mixed $value ): string {
		return (string) $value;
	}
}

if ( ! function_exists( __NAMESPACE__ . '\\is_wp_error' ) ) {
	function is_wp_error( mixed $value ): bool {
		return $value instanceof \WP_Error;
	}
}

if ( ! function_exists( __NAMESPACE__ . '\\wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( mixed $value, bool $removeBreaks = false ): string {
		unset( $removeBreaks );

		return (string) $value;
	}
}

if ( ! function_exists( __NAMESPACE__ . '\\is_multisite' ) ) {
	function is_multisite(): bool {
		return false;
	}
}

if ( ! function_exists( __NAMESPACE__ . '\\admin_url' ) ) {
	function admin_url( string $path = '' ): string {
		return 'https://example.test/wp-admin/' . ltrim( $path, '/' );
	}
}

if ( ! function_exists( __NAMESPACE__ . '\\network_admin_url' ) ) {
	function network_admin_url( string $path = '' ): string {
		return 'https://example.test/wp-admin/network/' . ltrim( $path, '/' );
	}
}

if ( ! function_exists( __NAMESPACE__ . '\\get_admin_url' ) ) {
	function get_admin_url( ?int $blogId = null, string $path = '' ): string {
		unset( $blogId );

		return admin_url( $path );
	}
}

if ( ! function_exists( __NAMESPACE__ . '\\wp_create_nonce' ) ) {
	function wp_create_nonce( string $action ): string {
		return 'nonce-for-' . hash( 'sha256', $action );
	}
}

if ( ! function_exists( __NAMESPACE__ . '\\wp_verify_nonce' ) ) {
	function wp_verify_nonce( string $nonce, string $action ): int|false {
		return hash_equals( wp_create_nonce( $action ), $nonce ) ? 1 : false;
	}
}
