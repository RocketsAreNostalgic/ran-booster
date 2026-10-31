<?php

declare(strict_types=1);

namespace RAN\WordPress;

if ( ! function_exists( __NAMESPACE__ . '\\get_option' ) ) {
	function get_option( string $option, mixed $fallback = false ): mixed {
		$value = $GLOBALS['ran_booster_core_check_options'][ $option ] ?? null;
		return is_string( $value ) ? unserialize( $value ) : $fallback; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- This isolated database double models WordPress option serialization.
	}
}
if ( ! function_exists( __NAMESPACE__ . '\\maybe_serialize' ) ) {
	function maybe_serialize( mixed $value ): string {
		return serialize( $value ); } // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- This isolated database double models WordPress option serialization.
}
if ( ! function_exists( __NAMESPACE__ . '\\wp_cache_delete' ) ) {
	function wp_cache_delete( mixed $key, string $group = '' ): bool {
		unset( $key, $group );
		return true; }
}
if ( ! function_exists( __NAMESPACE__ . '\\add_action' ) ) {
	function add_action( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): bool {
		unset( $priority, $accepted_args );
		$GLOBALS['ran_booster_core_check_actions'][ $hook ][] = $callback;
		return true;
	}
}
if ( ! function_exists( __NAMESPACE__ . '\\remove_action' ) ) {
	function remove_action( string $hook, callable $callback, int $priority = 10 ): bool {
		unset( $priority );
		foreach ( $GLOBALS['ran_booster_core_check_actions'][ $hook ] ?? array() as $key => $registered ) {
			if ( $registered === $callback ) {
				unset( $GLOBALS['ran_booster_core_check_actions'][ $hook ][ $key ] ); }
		}
		return true;
	}
}
