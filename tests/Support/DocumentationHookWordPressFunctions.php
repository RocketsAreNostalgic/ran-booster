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

if ( ! function_exists( __NAMESPACE__ . '\\do_action' ) ) {
	function do_action( string $hook, mixed ...$arguments ): void {
		foreach ( $GLOBALS['ran_booster_admin_view_actions'][ $hook ] ?? array() as $callback ) {
			$callback( ...$arguments );
		}
	}
}

if ( ! function_exists( __NAMESPACE__ . '\\wp_kses_post' ) ) {
	function wp_kses_post( string $content ): string {
		return (string) preg_replace( '#<script\b[^>]*>.*?</script>#is', '', $content );
	}
}

if ( ! function_exists( __NAMESPACE__ . '\\wp_kses_allowed_html' ) ) {
	/** @return array<string, array<string, true>> */
	function wp_kses_allowed_html( string $context ): array {
		unset( $context );

		return array(
			'h3'     => array(
				'id' => true,
			),
			'p'      => array(
				'id' => true,
			),
			'strong' => array(
				'id' => true,
			),
		);
	}
}

if ( ! function_exists( __NAMESPACE__ . '\\wp_kses' ) ) {
	/** @param array<string, array<string, true>> $allowedHtml */
	function wp_kses( string $content, array $allowedHtml ): string {
		$content = wp_kses_post( $content );

		$content = (string) preg_replace_callback(
			'/<(h3|p|strong)\\b([^>]*)>/i',
			static function ( array $matches ) use ( $allowedHtml ): string {
				$tag        = strtolower( $matches[1] );
				$attributes = $matches[2];

				if ( ! isset( $allowedHtml[ $tag ]['id'] ) ) {
					$attributes = (string) preg_replace( "/\\s+id\\s*=\\s*(?:\\\"[^\\\"]*\\\"|'[^']*'|[^\\s>]+)/i", '', $attributes );
				}

				return '<' . $tag . $attributes . '>';
			},
			$content
		);

		return $content;
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

if ( ! function_exists( __NAMESPACE__ . '\\esc_html__' ) ) {
	function esc_html__( string $text, string $domain = 'default' ): string {
		unset( $domain );

		return esc_html( $text );
	}
}

if ( ! function_exists( __NAMESPACE__ . '\\esc_attr' ) ) {
	function esc_attr( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
	}
}
