<?php

declare(strict_types=1);

if ( ! defined( 'WPINC' ) ) {
	define( 'WPINC', 'test' );
}

if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( mixed $value ): string {
		return htmlspecialchars( (string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( mixed $value ): string {
		return htmlspecialchars( (string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
	}
}

if ( ! function_exists( '__' ) ) {
	function __( string $text, string $domain = 'default' ): string {
		unset( $domain );

		return $text;
	}
}

if ( ! function_exists( '_x' ) ) {
	function _x( string $text, string $context, string $domain = 'default' ): string {
		unset( $context, $domain );

		return $text;
	}
}

if ( ! function_exists( 'esc_html_e' ) ) {
	function esc_html_e( string $text, string $domain = 'default' ): void {
		// phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText,WordPress.WP.I18n.NonSingularStringLiteralDomain -- Test shim forwards fixture strings.
		echo esc_html( __( $text, $domain ) );
	}
}

if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( string $text, string $domain = 'default' ): string {
		// phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText,WordPress.WP.I18n.NonSingularStringLiteralDomain -- Test shim forwards fixture strings.
		return esc_html( __( $text, $domain ) );
	}
}

if ( ! function_exists( 'esc_attr_e' ) ) {
	function esc_attr_e( string $text, string $domain = 'default' ): void {
		// phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText,WordPress.WP.I18n.NonSingularStringLiteralDomain -- Test shim forwards fixture strings.
		echo esc_attr( __( $text, $domain ) );
	}
}

if ( ! function_exists( '_n' ) ) {
	function _n( string $single, string $plural, int $number, string $domain = 'default' ): string {
		unset( $domain );

		return 1 === $number ? $single : $plural;
	}
}

if ( ! function_exists( '_nx' ) ) {
	function _nx( string $single, string $plural, int $number, string $context, string $domain = 'default' ): string {
		unset( $context, $domain );

		return 1 === $number ? $single : $plural;
	}
}

if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( mixed $value ): string {
		return esc_attr( $value );
	}
}

if ( ! function_exists( 'wp_kses_post' ) ) {
	function wp_kses_post( mixed $value ): string {
		return (string) $value;
	}
}

if ( ! function_exists( 'wp_make_link_relative' ) ) {
	function wp_make_link_relative( string $link ): string {
		return (string) preg_replace( '|^(https?:)?//[^/]+(/?.*)|i', '$2', $link );
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( mixed $value ): string {
		return (string) preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) );
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( mixed $value ): string {
		return trim( (string) preg_replace( '/<[^>]*>/', '', (string) $value ) );
	}
}

if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( mixed $value ): mixed {
		return $value;
	}
}

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( mixed $value, bool $removeBreaks = false ): string {
		$value = (string) preg_replace( '/<[^>]*>/', '', (string) $value );

		return $removeBreaks ? trim( (string) preg_replace( '/[\r\n\t ]+/', ' ', $value ) ) : $value;
	}
}

if ( ! function_exists( 'selected' ) ) {
	function selected( mixed $selected, mixed $current = true, bool $display = true ): string {
		$result = (string) $selected === (string) $current ? ' selected="selected"' : '';
		if ( $display ) {
			echo $result; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fixed test attribute.
		}

		return $result;
	}
}

if ( ! function_exists( 'disabled' ) ) {
	function disabled( mixed $disabled, mixed $current = true, bool $display = true ): string {
		$result = (string) $disabled === (string) $current ? ' disabled="disabled"' : '';
		if ( $display ) {
			echo $result; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fixed test attribute.
		}

		return $result;
	}
}

if ( ! function_exists( 'checked' ) ) {
	function checked( mixed $checked, mixed $current = true, bool $display = true ): string {
		$result = (string) $checked === (string) $current ? ' checked="checked"' : '';
		if ( $display ) {
			echo $result; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fixed test attribute.
		}

		return $result;
	}
}

if ( ! function_exists( 'wp_nonce_field' ) ) {
	function wp_nonce_field( string $action ): void {
		echo '<input type="hidden" name="_wpnonce" value="' . esc_attr( $action ) . '">';
	}
}

if ( ! function_exists( 'admin_url' ) ) {
	function admin_url( string $path = '' ): string {
		return 'https://example.test/wp-admin/' . ltrim( $path, '/' );
	}
}

if ( ! function_exists( 'network_admin_url' ) ) {
	function network_admin_url( string $path = '' ): string {
		return 'https://example.test/wp-admin/network/' . ltrim( $path, '/' );
	}
}

if ( ! function_exists( 'rest_url' ) ) {
	function rest_url( string $path = '' ): string {
		return 'https://example.test/wp-json/' . ltrim( $path, '/' );
	}
}

if ( ! function_exists( 'is_multisite' ) ) {
	function is_multisite(): bool {
		return true === ( $GLOBALS['ran_booster_package_view_multisite'] ?? false );
	}
}

if ( ! function_exists( 'add_query_arg' ) ) {
	function add_query_arg( mixed $key, mixed $value = null, mixed $url = null ): string {
		if ( is_array( $key ) ) {
			$base = (string) $value;

			return $base . ( str_contains( $base, '?' ) ? '&' : '?' ) . http_build_query( $key );
		}

		$base = (string) $url;

		return $base . ( str_contains( $base, '?' ) ? '&' : '?' ) . http_build_query( array( (string) $key => $value ) );
	}
}

if ( ! function_exists( 'wp_parse_str' ) ) {
	/** @param array<string, mixed> $output */
	function wp_parse_str( string $input, array &$output ): void {
		parse_str( $input, $output );
	}
}

if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( string $url ): array|false {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Test shim for the WordPress wrapper.
		return parse_url( $url );
	}
}

if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( string $capability ): bool {
		unset( $capability );

		return true;
	}
}

if ( ! function_exists( 'wp_create_nonce' ) ) {
	function wp_create_nonce( string $action ): string {
		return 'nonce-for-' . hash( 'sha256', $action );
	}
}

if ( ! function_exists( 'submit_button' ) ) {
	function submit_button(
		string $text,
		string $type = 'primary',
		string $name = 'submit',
		bool $wrap = true
	): void {
		$button = '<input type="submit" name="' . esc_attr( $name ) . '" class="button button-' . esc_attr( $type ) . '" value="' . esc_attr( $text ) . '">';
		echo $wrap ? '<p class="submit">' . $button . '</p>' : $button; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Test-only escaped fixture markup.
	}
}

if ( ! function_exists( 'is_plugin_active' ) ) {
	function is_plugin_active( string $plugin ): bool {
		return in_array( $plugin, $GLOBALS['ran_booster_bulk_active_plugins'] ?? array(), true );
	}
}
