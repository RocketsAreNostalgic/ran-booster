<?php

declare(strict_types=1);

if ( ! function_exists( '__' ) ) {
	function __( string $text, string $domain = 'default' ): string {
		unset( $domain );

		return $text;
	}
}

if ( ! function_exists( 'status_header' ) ) {
	function status_header( int $code ): void {
		$GLOBALS['ran_booster_test_status_header'] = $code;
	}
}

if ( ! function_exists( 'admin_url' ) ) {
	function admin_url( string $path = '' ): string {
		return 'https://example.test/wp-admin/' . ltrim( $path, '/' );
	}
}

if ( ! function_exists( 'wp_verify_nonce' ) ) {
	function wp_verify_nonce( string $nonce, string $action ): int|false {
		if ( function_exists( 'wp_create_nonce' ) && hash_equals( wp_create_nonce( $action ), $nonce ) ) {
			return 1;
		}

		return false;
	}
}

if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id(): int {
		return (int) ( $GLOBALS['ran_booster_package_operation_user_id'] ?? 1 );
	}
}

if ( ! function_exists( 'wp_is_file_mod_allowed' ) ) {
	function wp_is_file_mod_allowed( string $context ): bool {
		unset( $context );

		return true;
	}
}
