<?php

declare(strict_types=1);

namespace RAN\Booster\GitHub\ReleaseDeployments\WorkflowAssistance;

if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}

$ranBoosterRoot = dirname( __DIR__, 5 );
foreach ( array(
	'ReleaseTrackingEligibility.php',
	'ReleaseTrackingPreflight.php',
	'ReleaseTrackingResult.php',
	'ReleaseTrackingStatus.php',
	'ReleaseTrackingFacade.php',
) as $releaseTrackingFile ) {
	require_once $ranBoosterRoot . '/RAN/AddOn/ReleaseTracking/' . $releaseTrackingFile;
}

if ( ! function_exists( __NAMESPACE__ . '\\wp_json_encode' ) ) {
	function wp_json_encode( mixed $value, int $flags = 0, int $depth = 512 ): string|false {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Minimal test shim for WordPress's wrapper.
		return json_encode( $value, $flags, $depth );
	}
}

if ( ! function_exists( __NAMESPACE__ . '\\wp_remote_retrieve_response_code' ) ) {
	/** @param array<string,mixed> $response */
	function wp_remote_retrieve_response_code( array $response ): int {
		$code = $response['response']['code'] ?? 0;
		return is_int( $code ) ? $code : 0;
	}
}

if ( ! function_exists( __NAMESPACE__ . '\\wp_remote_retrieve_body' ) ) {
	/** @param array<string,mixed> $response */
	function wp_remote_retrieve_body( array $response ): string {
		$body = $response['body'] ?? '';
		return is_string( $body ) ? $body : '';
	}
}

if ( ! function_exists( __NAMESPACE__ . '\\wp_parse_url' ) ) {
	function wp_parse_url( string $url, int $component = -1 ): array|int|string|null|false {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Minimal test shim for WordPress's wrapper.
		return parse_url( $url, $component );
	}
}

if ( ! function_exists( __NAMESPACE__ . '\\get_option' ) ) {
	function get_option( string $option, mixed $fallback = false ): mixed {
		return $GLOBALS['ran_booster_release_deployments_test_options'][ $option ] ?? $fallback;
	}
}

if ( ! function_exists( __NAMESPACE__ . '\\update_option' ) ) {
	function update_option( string $option, mixed $value, mixed $autoload = null ): bool {
		$GLOBALS['ran_booster_release_deployments_test_option_updates'][] = array( $option, $value, $autoload );
		$stored = $GLOBALS['ran_booster_release_deployments_test_option_override'] ?? $value;
		$GLOBALS['ran_booster_release_deployments_test_options'][ $option ] = $stored;
		return true;
	}
}

if ( ! function_exists( __NAMESPACE__ . '\\get_current_user_id' ) ) {
	function get_current_user_id(): int {
		return 1;
	}
}

if ( ! function_exists( __NAMESPACE__ . '\\set_transient' ) ) {
	function set_transient( string $key, mixed $value, int $expiration ): bool {
		$GLOBALS['ran_booster_release_deployments_test_transients'][ $key ]            = $value;
		$GLOBALS['ran_booster_release_deployments_test_transient_expirations'][ $key ] = $expiration;
		return true;
	}
}

if ( ! function_exists( __NAMESPACE__ . '\\get_transient' ) ) {
	function get_transient( string $key ): mixed {
		return $GLOBALS['ran_booster_release_deployments_test_transients'][ $key ] ?? false;
	}
}

if ( ! function_exists( __NAMESPACE__ . '\\delete_transient' ) ) {
	function delete_transient( string $key ): bool {
		unset( $GLOBALS['ran_booster_release_deployments_test_transients'][ $key ] );
		return true;
	}
}
