<?php

declare(strict_types=1);

namespace RAN\Admin;

if ( ! function_exists( __NAMESPACE__ . '\\apply_filters' ) ) {
	function apply_filters( string $hook, mixed $value, mixed ...$arguments ): mixed {
		foreach ( $GLOBALS['ran_booster_documentation_test_filters'][ $hook ] ?? array() as $callback ) {
			$value = $callback( $value, ...$arguments );
		}

		return $value;
	}
}

if ( ! function_exists( __NAMESPACE__ . '\\wp_kses_post' ) ) {
	function wp_kses_post( string $content ): string {
		return (string) preg_replace( '#<script\b[^>]*>.*?</script>#is', '', $content );
	}
}

if ( ! function_exists( __NAMESPACE__ . '\\esc_html_e' ) ) {
	function esc_html_e( string $text, string $domain = 'default' ): void {
		unset( $domain );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The test shim is the escaping boundary under test.
		echo htmlspecialchars( $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
	}
}

if ( ! function_exists( __NAMESPACE__ . '\\esc_html' ) ) {
	function esc_html( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
	}
}

if ( ! function_exists( __NAMESPACE__ . '\\esc_attr' ) ) {
	function esc_attr( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
	}
}
