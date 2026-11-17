<?php

declare(strict_types=1);

namespace RAN\Admin\Interaction;

require_once __DIR__ . '/InteractionMetaDatabaseDouble.php';

function __( string $text, string $domain = 'default' ): string {
	return $GLOBALS['ran_booster_interaction_test_translations'][ $domain ][ $text ] ?? $text;
}

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

function add_action( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): bool {
	$GLOBALS['ran_booster_interaction_test_actions'][ $hook ][] = array(
		'callback'      => $callback,
		'priority'      => $priority,
		'accepted_args' => $accepted_args,
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

function get_current_user_id(): int {
	return $GLOBALS['ran_booster_interaction_test_user'] ?? 1;
}

function get_current_blog_id(): int {
	return $GLOBALS['ran_booster_interaction_test_blog'] ?? 1;
}

/** Models stripslashes_deep(): WordPress strips one level of slashes from strings, recursively. */
function wp_unslash( mixed $value ): mixed {
	if ( is_array( $value ) ) {
		return array_map( wp_unslash( ... ), $value );
	}

	return is_string( $value ) ? stripslashes( $value ) : $value;
}

/** Models add_magic_quotes(): slashes strings, recursively. */
function wp_slash( mixed $value ): mixed {
	if ( is_array( $value ) ) {
		return array_map( wp_slash( ... ), $value );
	}

	return is_string( $value ) ? addslashes( $value ) : $value;
}

/** Models update_metadata(): unslashes on write; a failing store returns false. */
function update_user_meta( int $user_id, string $key, mixed $value ): int|bool {
	if ( true === ( $GLOBALS['ran_booster_interaction_test_write_fails'] ?? false ) ) {
		return false;
	}
	$GLOBALS['ran_booster_interaction_test_meta'][ $user_id ][ $key ] = wp_unslash( $value );

	return 1;
}

function get_user_meta( int $user_id, string $key, bool $single ): mixed {
	unset( $single );

	return $GLOBALS['ran_booster_interaction_test_meta'][ $user_id ][ $key ] ?? '';
}

/** Models delete_metadata(): the value to match is unslashed before the comparison. */
function delete_user_meta( int $user_id, string $key, mixed $value = '' ): bool {
	$held = $GLOBALS['ran_booster_interaction_test_meta'][ $user_id ] ?? array();
	if ( ! array_key_exists( $key, $held ) || ( '' !== $value && wp_unslash( $value ) !== $held[ $key ] ) ) {
		return false;
	}
	unset( $GLOBALS['ran_booster_interaction_test_meta'][ $user_id ][ $key ] );

	return true;
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


function maybe_serialize( mixed $value ): mixed {
	$GLOBALS['ran_booster_interaction_previous_wpdb'] = $GLOBALS['wpdb'] ?? null;
	// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Install the unit database double only for the claim, restoring the previous global at cache invalidation.
	$GLOBALS['wpdb'] = new InteractionMetaDatabaseDouble();
	// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- The fixture mirrors WordPress metadata serialization for controlled test values.
	return is_array( $value ) || is_object( $value ) ? serialize( $value ) : $value;
}

function wp_cache_delete( int $user_id, string $group ): bool {
	unset( $user_id, $group );
	if ( null === $GLOBALS['ran_booster_interaction_previous_wpdb'] ) {
		unset( $GLOBALS['wpdb'] );
	} else {
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restore the global saved before this controlled unit claim.
		$GLOBALS['wpdb'] = $GLOBALS['ran_booster_interaction_previous_wpdb'];
	}
	unset( $GLOBALS['ran_booster_interaction_previous_wpdb'] );
	return true;
}

function metadata_exists( string $type, int $user_id, string $key ): bool {
	unset( $type );
	return array_key_exists( $key, $GLOBALS['ran_booster_interaction_test_meta'][ $user_id ] ?? array() );
}
