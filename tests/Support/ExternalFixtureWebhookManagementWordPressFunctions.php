<?php

declare(strict_types=1);

// Focused nonce fixture loaded only inside isolated webhook-management tests.

if ( ! function_exists( 'wp_create_nonce' ) ) {
	function wp_create_nonce( string $action ): string {
		return hash( 'sha256', 'fixture:' . $action );
	}
}

if ( ! function_exists( 'wp_verify_nonce' ) ) {
	function wp_verify_nonce( string $nonce, string $action ): int|false {
		return hash_equals( wp_create_nonce( $action ), $nonce ) ? 1 : false;
	}
}

if ( ! function_exists( 'wp_nonce_field' ) ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WordPress function double must retain the host-owned name.
	function wp_nonce_field( string $action ): void {
		echo '<input type="hidden" name="_wpnonce" value="' . esc_attr( wp_create_nonce( $action ) ) . '">';
	}
}
