<?php

declare(strict_types=1);

/** @param list<array<string,mixed>> $responses */
function ran_booster_example_http_queue( array $responses ): void {
	$GLOBALS['ran_booster_example_http_responses'] = $responses;
	$GLOBALS['ran_booster_example_http_requests']  = array();
}

/** @return list<array{url:string,arguments:array<string,mixed>}> */
function ran_booster_example_http_requests(): array {
	return $GLOBALS['ran_booster_example_http_requests'] ?? array();
}

// Only the isolated published-Example parity process loads these WordPress
// doubles. No class occupies the provider's qualified namespace, and unqueued
// requests fail instead of reaching a real network.
if ( ! function_exists( 'wp_remote_get' ) ) {
	/**
	 * @param array<string,mixed> $arguments
	 * @return array<string,mixed>
	 */
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Preserve WordPress's HTTP boundary for the actual published provider and updater packages.
	function wp_remote_get( string $url, array $arguments = array() ): array {
		$GLOBALS['ran_booster_example_http_requests'][] = array(
			'url'       => $url,
			'arguments' => $arguments,
		);
		$response                                       = array_shift( $GLOBALS['ran_booster_example_http_responses'] );
		if ( ! is_array( $response ) ) {
			throw new \RuntimeException( 'Unexpected HTTP request in the published Example proof.' );
		}
		return $response;
	}
}
if ( ! function_exists( 'wp_safe_remote_get' ) ) {
	/**
	 * @param array<string,mixed> $arguments
	 * @return array<string,mixed>
	 */
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- The real updater requires WordPress's safe HTTP entrypoint; return only queued synthetic responses.
	function wp_safe_remote_get( string $url, array $arguments = array() ): array {
		return wp_remote_get( $url, $arguments );
	}
}
if ( ! function_exists( 'is_wp_error' ) ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Preserve WordPress's error predicate for this array-only HTTP fixture.
	function is_wp_error( mixed $response ): bool {
		return ! is_array( $response );
	}
}
if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
	/** @param array<string,mixed> $response */
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Read the response-code projection used by both packages at the WordPress boundary.
	function wp_remote_retrieve_response_code( array $response ): int {
		return (int) ( $response['response']['code'] ?? 0 );
	}
}
if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
	/** @param array<string,mixed> $response */
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Read only the controlled synthetic WordPress HTTP body.
	function wp_remote_retrieve_body( array $response ): string {
		return is_string( $response['body'] ?? null ) ? $response['body'] : '';
	}
}
if ( ! function_exists( 'wp_remote_retrieve_header' ) ) {
	/** @param array<string,mixed> $response */
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Preserve WordPress's response-header projection for bounded release-service reads.
	function wp_remote_retrieve_header( array $response, string $header ): string {
		$value = $response['headers'][ $header ] ?? '';
		return is_string( $value ) ? $value : '';
	}
}
