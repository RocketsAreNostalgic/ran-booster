<?php

declare(strict_types=1);

require_once __DIR__ . '/WPError.php';

if ( ! function_exists( 'wp_safe_remote_get' ) ) {
	function wp_safe_remote_get( string $url, array $arguments ): array|WP_Error {
		$GLOBALS['ran_booster_release_requests'][] = array( $url, $arguments );
		$response                                  = array_shift( $GLOBALS['ran_booster_release_responses'] );
		if ( $response instanceof WP_Error ) {
			return $response;
		}
		if ( ! is_array( $response ) ) {
			throw new RuntimeException( 'Unexpected neutral updater HTTP request.' );
		}
		if ( isset( $arguments['filename'], $response['file'] ) ) {
			file_put_contents( $arguments['filename'], $response['file'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test-only deterministic download.
			chmod( $arguments['filename'], 0600 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Test-only custody fixture.
		}

		return $response;
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( mixed $value ): bool {
		return $value instanceof WP_Error;
	}
}

if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
	function wp_remote_retrieve_response_code( array $response ): int|string {
		return $response['response']['code'] ?? 0;
	}
}

if ( ! function_exists( 'wp_remote_retrieve_header' ) ) {
	function wp_remote_retrieve_header( array $response, string $name ): mixed {
		return $response['headers'][ strtolower( $name ) ] ?? null;
	}
}

if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
	function wp_remote_retrieve_body( array $response ): string {
		return is_string( $response['body'] ?? null ) ? $response['body'] : '';
	}
}

if ( ! function_exists( 'wp_http_validate_url' ) ) {
	function wp_http_validate_url( string $url ): string|false {
		return $url;
	}
}

if ( ! function_exists( 'wp_tempnam' ) ) {
	function wp_tempnam( string $filename ): string|false {
		unset( $filename );
		$path = tempnam( sys_get_temp_dir(), 'ran-booster-neutral-release-' );
		if ( is_string( $path ) ) {
			chmod( $path, 0600 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Test-only custody fixture.
			$GLOBALS['ran_booster_release_temp_paths'][] = $path;
		}

		return $path;
	}
}
