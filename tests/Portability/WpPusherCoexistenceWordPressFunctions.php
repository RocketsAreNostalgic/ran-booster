<?php

declare(strict_types=1);

namespace RAN\Portability;

function get_plugins(): mixed {
	return $GLOBALS['ran_booster_wp_pusher_plugins'] ?? array();
}

function get_option( string $name, mixed $default = false ): mixed {
	return 'active_plugins' === $name
		? ( $GLOBALS['ran_booster_wp_pusher_active_plugins'] ?? $default )
		: $default;
}

function get_site_option( string $name, mixed $default = false ): mixed {
	return 'active_sitewide_plugins' === $name
		? ( $GLOBALS['ran_booster_wp_pusher_network_plugins'] ?? $default )
		: $default;
}

function admin_url( string $path = '' ): string {
	return 'https://example.test/wp-admin/' . ltrim( $path, '/' );
}

function __( string $text, string $domain = 'default' ): string {
	return $text;
}

function esc_attr( string $text ): string {
	return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
}

function esc_html( string $text ): string {
	return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
}

function esc_html__( string $text, string $domain = 'default' ): string {
	return esc_html( $text );
}

function esc_html_e( string $text, string $domain = 'default' ): void {
	echo esc_html( $text );
}

function esc_url( string $url ): string {
	return htmlspecialchars( $url, ENT_QUOTES, 'UTF-8' );
}

function wp_die( string $message ): never {
	// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Test helper captures the already escaped wp_die message.
	throw new \RuntimeException( $message );
}
