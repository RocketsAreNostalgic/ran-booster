<?php

declare(strict_types=1);

namespace RAN\Admin\Interaction;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Focused Core facade fixture.

function admin_url( string $path = '' ): string {
	return 'https://example.test/wp-admin/' . ltrim( $path, '/' );
}

function wp_make_link_relative( string $link ): string {
	return (string) preg_replace( '|^(https?:)?//[^/]+(/?.*)|i', '$2', $link );
}

function esc_attr( mixed $value ): string {
	return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' );
}

function esc_html( mixed $value ): string {
	return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' );
}

function wp_json_encode( mixed $value ): string|false {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- This is the focused wp_json_encode() test double.
	return json_encode( $value, JSON_UNESCAPED_SLASHES );
}

function add_action( string $hook, callable $callback, int $priority = 10, int $acceptedArgs = 1 ): bool {
	$GLOBALS['ran_booster_interaction_test_actions'][ $hook ][] = array(
		'callback'      => $callback,
		'priority'      => $priority,
		'accepted_args' => $acceptedArgs,
	);

	return true;
}

/**
 * @param array<string, string> $arguments
 */
function add_query_arg( array $arguments, string $url ): string {
	$fragment = '';
	if ( str_contains( $url, '#' ) ) {
		list( $url, $fragment ) = explode( '#', $url, 2 );
		$fragment               = '#' . $fragment;
	}
	$query = array();
	foreach ( $arguments as $key => $value ) {
		$query[] = rawurlencode( (string) $key ) . '=' . $value;
	}

	return $url
		. ( str_contains( $url, '?' ) ? '&' : '?' )
		. implode( '&', $query )
		. $fragment;
}

function wp_create_nonce( string $action ): string {
	return 'nonce-for-' . hash( 'sha256', $action );
}

function wp_verify_nonce( string $nonce, string $action ): int|false {
	return hash_equals( wp_create_nonce( $action ), $nonce ) ? 1 : false;
}

function wp_unslash( string $value ): string {
	return $value;
}

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( mixed $value ): string {
		return strtolower( (string) preg_replace( '/[^a-z0-9_-]/i', '', (string) $value ) );
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( mixed $value ): string {
		return trim( (string) $value );
	}
}

if ( ! function_exists( 'absint' ) ) {
	function absint( mixed $value ): int {
		return abs( (int) $value );
	}
}
