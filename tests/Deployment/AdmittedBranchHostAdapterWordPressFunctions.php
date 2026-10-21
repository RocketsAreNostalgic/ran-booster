<?php

declare(strict_types=1);


namespace RAN\Deployment;

if ( ! defined( 'ABSPATH' ) ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Exercise the WordPress-owned constant without changing its runtime identity.
	define( 'ABSPATH', sys_get_temp_dir() . '/ran-booster-admitted-parity-wp/' );
}
if ( ! defined( 'WP_CONTENT_DIR' ) ) {
	define( 'WP_CONTENT_DIR', sys_get_temp_dir() . '/ran-booster-admitted-parity-content' );
}
if ( ! defined( 'WP_PLUGIN_DIR' ) ) {
	define( 'WP_PLUGIN_DIR', sys_get_temp_dir() . '/ran-booster-admitted-parity-plugins' );
}

if ( ! function_exists( __NAMESPACE__ . '\\get_filesystem_method' ) ) {
	function get_filesystem_method(): string {
		return (string) ( $GLOBALS['ran_booster_admitted_filesystem_method'] ?? 'direct' );
	}
}

if ( ! function_exists( __NAMESPACE__ . '\\get_temp_dir' ) ) {
	function get_temp_dir(): string {
		return (string) ( $GLOBALS['ran_booster_admitted_temp_root'] ?? sys_get_temp_dir() );
	}
}

if ( ! function_exists( __NAMESPACE__ . '\\disk_free_space' ) ) {
	function disk_free_space( string $directory ): float|false {
		$values = $GLOBALS['ran_booster_admitted_disk_free_space'] ?? null;
		if ( is_array( $values ) && array_key_exists( $directory, $values ) ) {
			$value = $values[ $directory ];
			return false === $value ? false : (float) $value;
		}

		return \disk_free_space( $directory );
	}
}

if ( ! function_exists( __NAMESPACE__ . '\\wp_safe_remote_get' ) ) {
	/** @param array<string, mixed> $arguments */
	function wp_safe_remote_get( string $url, array $arguments ): mixed {
		$before = $GLOBALS['ran_booster_admitted_http_request_args'] ?? null;
		if ( is_callable( $before ) ) {
			$before( $url, $arguments );
		}
		foreach ( $GLOBALS['ran_booster_admitted_http_guards'] ?? array() as $guard ) {
			$guard( false, $arguments, $url );
		}
		$GLOBALS['ran_booster_admitted_http_calls'][] = array(
			'url'       => $url,
			'arguments' => $arguments,
		);

		$responses                                      = $GLOBALS['ran_booster_admitted_http_responses'] ?? array( 200 );
		$response                                       = array_shift( $responses );
		$GLOBALS['ran_booster_admitted_http_responses'] = $responses;
		if ( 'wp_error' === $response ) {
			return array( 'ran_booster_test_wp_error' => true );
		}

		$status = is_int( $response ) ? $response : ( is_array( $response ) ? (int) ( $response['response']['code'] ?? 200 ) : 200 );
		if ( $status >= 200 && $status < 300 ) {
			$source      = $GLOBALS['ran_booster_admitted_download_fixture'] ?? null;
			$destination = $arguments['filename'] ?? null;
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy -- Test-only streamed HTTP fixture.
			if ( ! is_string( $source ) || ! is_string( $destination ) || ! copy( $source, $destination ) ) {
				return array( 'ran_booster_test_wp_error' => true );
			}
		}

		return is_array( $response ) ? $response : array( 'response' => array( 'code' => $status ) );
	}
}

if ( ! function_exists( __NAMESPACE__ . '\\is_wp_error' ) ) {
	function is_wp_error( mixed $response ): bool {
		return is_array( $response ) && true === ( $response['ran_booster_test_wp_error'] ?? false );
	}
}

if ( ! function_exists( __NAMESPACE__ . '\\wp_remote_retrieve_response_code' ) ) {
	function wp_remote_retrieve_response_code( mixed $response ): int {
		return is_array( $response ) ? (int) ( $response['response']['code'] ?? 0 ) : 0;
	}
}

/** @param callable(mixed,array<string,mixed>,string):mixed $callback */
function add_filter( string $hook, callable $callback, int $priority, int $accepted_args ): bool {
	if ( 'pre_http_request' !== $hook || PHP_INT_MAX !== $priority || 3 !== $accepted_args ) {
		throw new \RuntimeException( 'Unexpected archive guard hook.' );
	}
	$GLOBALS['ran_booster_admitted_http_guards'][] = $callback;
	return true;
}

/** @param callable(mixed,array<string,mixed>,string):mixed $callback */
function remove_filter( string $hook, callable $callback, int $priority ): bool {
	if ( 'pre_http_request' !== $hook || PHP_INT_MAX !== $priority ) {
		throw new \RuntimeException( 'Unexpected archive guard removal.' );
	}
	foreach ( $GLOBALS['ran_booster_admitted_http_guards'] ?? array() as $key => $guard ) {
		if ( $callback === $guard ) {
			unset( $GLOBALS['ran_booster_admitted_http_guards'][ $key ] );
		}
	}
	return true;
}

/** @return string|list<string> */
function wp_remote_retrieve_header( mixed $response, string $header ): string|array {
	$value = is_array( $response ) ? ( $response['headers'][ $header ] ?? '' ) : '';
	return is_string( $value ) || ( is_array( $value ) && array_is_list( $value ) && count( array_filter( $value, 'is_string' ) ) === count( $value ) ) ? $value : '';
}
