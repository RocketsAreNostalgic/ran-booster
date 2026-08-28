<?php

declare(strict_types=1);

namespace RAN;

function is_wp_error( mixed $value ): bool {
	return $value instanceof \WP_Error;
}

function wp_strip_all_tags( mixed $value, bool $removeBreaks = false ): string {
	unset( $removeBreaks );

	return (string) $value;
}

function is_multisite(): bool {
	return (bool) ( $GLOBALS['ran_booster_dashboard_test_multisite'] ?? false );
}

function admin_url( string $path = '' ): string {
	return 'https://example.test/wp-admin/' . ltrim( $path, '/' );
}

function network_admin_url( string $path = '' ): string {
	return 'https://example.test/wp-admin/network/' . ltrim( $path, '/' );
}

function add_query_arg( array|string $arguments, string $valueOrUrl, ?string $url = null ): string {
	if ( is_string( $arguments ) ) {
		$arguments = array( $arguments => $valueOrUrl );
		$url       = (string) $url;
	} else {
		$url = $valueOrUrl;
	}

	return $url . ( str_contains( $url, '?' ) ? '&' : '?' ) . http_build_query( $arguments );
}

function absint( mixed $value ): int {
	return abs( (int) $value );
}

function do_action( string $hook, mixed ...$arguments ): void {
	foreach ( $GLOBALS['ran_booster_dashboard_test_actions'][ $hook ] ?? array() as $callback ) {
		$callback( ...$arguments );
	}
}

function apply_filters( string $hook, mixed $value, mixed ...$arguments ): mixed {
	foreach ( $GLOBALS['ran_booster_dashboard_test_filters'][ $hook ] ?? array() as $callback ) {
		$value = $callback( $value, ...$arguments );
	}

	return $value;
}

function wp_get_environment_type(): string {
	return (string) ( $GLOBALS['ran_booster_dashboard_test_environment_type'] ?? 'production' );
}

function wp_is_development_mode( string $mode ): bool {
	$modes = $GLOBALS['ran_booster_dashboard_test_development_modes'] ?? array();

	return in_array( 'all', $modes, true ) || in_array( $mode, $modes, true );
}

function get_current_user_id(): int {
	return (int) ( $GLOBALS['ran_booster_dashboard_test_user_id'] ?? 1 );
}

function get_transient( string $key ): mixed {
	return $GLOBALS['ran_booster_dashboard_test_transients'][ $key ] ?? false;
}

function set_transient( string $key, mixed $value, int $expiration ): bool {
	unset( $expiration );
	$GLOBALS['ran_booster_dashboard_test_transients'][ $key ] = $value;

	return true;
}

function get_user_meta( int $userId, string $key, bool $single ): mixed {
	unset( $single );

	return $GLOBALS['ran_booster_dashboard_test_user_meta'][ $userId ][ $key ] ?? '';
}

if ( ! function_exists( __NAMESPACE__ . '\\wp_create_nonce' ) ) {
	function wp_create_nonce( string $action ): string {
		return 'nonce-for-' . hash( 'sha256', $action );
	}
}

if ( ! function_exists( __NAMESPACE__ . '\\wp_verify_nonce' ) ) {
	function wp_verify_nonce( string $nonce, string $action ): int|false {
		return hash_equals( wp_create_nonce( $action ), $nonce ) ? 1 : false;
	}
}
