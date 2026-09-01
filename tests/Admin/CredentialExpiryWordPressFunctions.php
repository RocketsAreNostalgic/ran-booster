<?php

declare(strict_types=1);

namespace RAN\Admin;

if ( ! function_exists( __NAMESPACE__ . '\\__' ) ) {
	function __( string $text, string $domain = 'default' ): string {
		return $GLOBALS['ran_booster_admin_test_translations'][ $domain ][ $text ] ?? $text;
	}
}

if ( ! function_exists( __NAMESPACE__ . '\\esc_html_e' ) ) {
	function esc_html_e( string $text, string $domain = 'default' ): void {
		unset( $domain );

		echo esc_html( $text );
	}
}

if ( ! function_exists( __NAMESPACE__ . '\\_n' ) ) {
	function _n( string $single, string $plural, int $number, string $domain = 'default' ): string {
		$key = $single . "\0" . $plural;

		return $GLOBALS['ran_booster_admin_test_translations'][ $domain ][ $key ][ 1 === $number ? 'single' : 'plural' ]
			?? ( 1 === $number ? $single : $plural );
	}
}

if ( ! function_exists( __NAMESPACE__ . '\\esc_html' ) ) {
	function esc_html( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
	}
}

if ( ! function_exists( __NAMESPACE__ . '\\esc_attr' ) ) {
	function esc_attr( string $text ): string {
		return esc_html( $text );
	}
}

if ( ! function_exists( __NAMESPACE__ . '\\esc_url' ) ) {
	function esc_url( string $url ): string {
		return str_replace( '&', '&amp;', $url );
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

if ( ! function_exists( __NAMESPACE__ . '\\is_network_admin' ) ) {
	function is_network_admin(): bool {
		return (bool) ( $GLOBALS['ran_booster_expiry_test_network_admin'] ?? false );
	}
}
