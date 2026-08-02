<?php

declare(strict_types=1);

namespace RAN\Admin;

function __( string $text, string $domain = 'default' ): string {
	unset( $domain );

	return $text;
}

function esc_html_e( string $text, string $domain = 'default' ): void {
	unset( $domain );

	echo esc_html( $text );
}

function _n( string $single, string $plural, int $number, string $domain = 'default' ): string {
	unset( $domain );

	return 1 === $number ? $single : $plural;
}

function esc_html( string $text ): string {
	return htmlspecialchars( $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
}

function esc_attr( string $text ): string {
	return esc_html( $text );
}

function esc_url( string $url ): string {
	return str_replace( '&', '&amp;', $url );
}

function admin_url( string $path = '' ): string {
	return 'https://example.test/wp-admin/' . ltrim( $path, '/' );
}

function network_admin_url( string $path = '' ): string {
	return 'https://example.test/wp-admin/network/' . ltrim( $path, '/' );
}

function is_network_admin(): bool {
	return (bool) ( $GLOBALS['ran_booster_expiry_test_network_admin'] ?? false );
}
