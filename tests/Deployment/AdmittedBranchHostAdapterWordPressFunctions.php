<?php

declare(strict_types=1);

namespace {
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', sys_get_temp_dir() . '/ran-booster-admitted-parity-wp/' );
	}
	if ( ! defined( 'WP_CONTENT_DIR' ) ) {
		define( 'WP_CONTENT_DIR', sys_get_temp_dir() . '/ran-booster-admitted-parity-content' );
	}
	if ( ! defined( 'WP_PLUGIN_DIR' ) ) {
		define( 'WP_PLUGIN_DIR', sys_get_temp_dir() . '/ran-booster-admitted-parity-plugins' );
	}
}

namespace RAN\Deployment {
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

	if ( ! function_exists( __NAMESPACE__ . '\\wp_safe_remote_get' ) ) {
		/** @param array<string, mixed> $arguments */
		function wp_safe_remote_get( string $url, array $arguments ): mixed {
			$GLOBALS['ran_booster_admitted_http_calls'][] = array(
				'url'       => $url,
				'arguments' => $arguments,
			);

			$responses = $GLOBALS['ran_booster_admitted_http_responses'] ?? array( 200 );
			$response  = array_shift( $responses );
			$GLOBALS['ran_booster_admitted_http_responses'] = $responses;
			if ( 'wp_error' === $response ) {
				return array( 'ran_booster_test_wp_error' => true );
			}

			$status = is_int( $response ) ? $response : 200;
			if ( $status >= 200 && $status < 300 ) {
				$source      = $GLOBALS['ran_booster_admitted_download_fixture'] ?? null;
				$destination = $arguments['filename'] ?? null;
				if ( ! is_string( $source ) || ! is_string( $destination ) || ! copy( $source, $destination ) ) {
					return array( 'ran_booster_test_wp_error' => true );
				}
			}

			return array( 'response' => array( 'code' => $status ) );
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
}
