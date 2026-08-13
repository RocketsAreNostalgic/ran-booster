<?php

declare(strict_types=1);

namespace RAN\Booster\GitHub;

require_once __DIR__ . '/RepositoryResolverWpError.php';

/**
 * @param array<string, mixed> $response
 */
function repository_resolver_http_reset( mixed $response ): void {
	$GLOBALS['ran_booster_repository_resolver_responses'] = array( $response );
	$GLOBALS['ran_booster_repository_resolver_requests']  = array();
}

/** @param list<array<string, mixed>> $responses */
function repository_resolver_http_queue( array $responses ): void {
	$GLOBALS['ran_booster_repository_resolver_responses'] = $responses;
	$GLOBALS['ran_booster_repository_resolver_requests']  = array();
}

/**
 * @return list<array{url: string, arguments: array<string, mixed>}>
 */
function repository_resolver_http_requests(): array {
	return $GLOBALS['ran_booster_repository_resolver_requests'] ?? array();
}

/**
 * @param array<string, mixed> $arguments
 * @return array<string, mixed>
 */
function wp_remote_get( string $url, array $arguments ): mixed {
	$GLOBALS['ran_booster_repository_resolver_requests'][] = array(
		'url'       => $url,
		'arguments' => $arguments,
	);

	$response = array_shift( $GLOBALS['ran_booster_repository_resolver_responses'] );
	$limit    = $arguments['limit_response_size'] ?? null;
	if ( is_array( $response )
		&& is_int( $limit )
		&& $limit >= 0
		&& is_string( $response['body'] ?? null )
		&& strlen( $response['body'] ) > $limit ) {
		$response['body'] = substr( $response['body'], 0, $limit );
	}

	return $response;
}

/** @param array<string,mixed> $arguments */
function wp_remote_request( string $url, array $arguments ): mixed {
	$GLOBALS['ran_booster_repository_resolver_requests'][] = array(
		'url'       => $url,
		'arguments' => $arguments,
	);
	$response = array_shift( $GLOBALS['ran_booster_repository_resolver_responses'] );
	$limit    = $arguments['limit_response_size'] ?? null;
	if ( is_array( $response ) && is_int( $limit ) && $limit >= 0 && is_string( $response['body'] ?? null ) && strlen( $response['body'] ) > $limit ) {
		$response['body'] = substr( $response['body'], 0, $limit );
	}

	return $response;
}

function is_wp_error( mixed $response ): bool {
	return $response instanceof RepositoryResolverWpError;
}

/**
 * @param array<string, mixed> $response
 */
function wp_remote_retrieve_response_code( array $response ): int {
	return (int) ( $response['response']['code'] ?? 0 );
}

/**
 * @param array<string, mixed> $response
 */
function wp_remote_retrieve_body( array $response ): string {
	return isset( $response['body'] ) && is_string( $response['body'] ) ? $response['body'] : '';
}

/**
 * @param array<string, mixed> $response
 */
function wp_remote_retrieve_header( array $response, string $header ): string {
	$headers = isset( $response['headers'] ) && is_array( $response['headers'] )
		? $response['headers']
		: array();

	foreach ( $headers as $name => $value ) {
		if ( is_string( $name ) && 0 === strcasecmp( $name, $header ) ) {
			return is_scalar( $value ) ? (string) $value : '';
		}
	}

	return '';
}
