<?php

declare(strict_types=1);

namespace RAN\Admin;

if ( ! function_exists( __NAMESPACE__ . '\\current_user_can' ) ) {
	function current_user_can( string $capability ): bool {
		return \RAN\current_user_can( $capability );
	}
}

if ( ! function_exists( __NAMESPACE__ . '\\check_admin_referer' ) ) {
	function check_admin_referer( string $action, string $queryArg = '_wpnonce' ): bool {
		return \RAN\check_admin_referer( $action, $queryArg );
	}
}

if ( ! function_exists( __NAMESPACE__ . '\\wp_die' ) ) {
	function wp_die( string $message = '' ): never {
		\RAN\wp_die( $message );
	}
}

if ( ! function_exists( __NAMESPACE__ . '\\wp_unslash' ) ) {
	function wp_unslash( mixed $value ): mixed {
		return \RAN\wp_unslash( $value );
	}
}

if ( ! function_exists( __NAMESPACE__ . '\\sanitize_key' ) ) {
	function sanitize_key( mixed $value ): string {
		return \RAN\sanitize_key( $value );
	}
}

if ( ! function_exists( __NAMESPACE__ . '\\sanitize_text_field' ) ) {
	function sanitize_text_field( mixed $value ): string {
		return \RAN\sanitize_text_field( $value );
	}
}

if ( ! function_exists( __NAMESPACE__ . '\\esc_html' ) ) {
	function esc_html( string $text ): string {
		return \RAN\esc_html( $text );
	}
}

if ( ! function_exists( __NAMESPACE__ . '\\esc_html__' ) ) {
	function esc_html__( string $text, string $domain = 'default' ): string {
		return \RAN\esc_html__( $text, $domain );
	}
}
