<?php

declare(strict_types=1);

namespace RAN\Deployment;

if ( ! function_exists( __NAMESPACE__ . '\\is_multisite' ) ) {
	function is_multisite(): bool {
		if ( array_key_exists( 'ran_booster_package_mutation_guard_multisite', $GLOBALS ) ) {
			return (bool) $GLOBALS['ran_booster_package_mutation_guard_multisite'];
		}

		return DeploymentArchivePreflightWordPressState::$multisite;
	}
}

if ( ! function_exists( __NAMESPACE__ . '\\wp_is_file_mod_allowed' ) ) {
	function wp_is_file_mod_allowed( string $context ): bool {
		$GLOBALS['ran_booster_package_mutation_guard_contexts'][] = $context;
		if ( array_key_exists( 'ran_booster_package_mutation_guard_file_mods', $GLOBALS ) ) {
			return (bool) $GLOBALS['ran_booster_package_mutation_guard_file_mods'];
		}

		return DeploymentArchivePreflightWordPressState::$fileMods && 'ran-booster' === $context;
	}
}

function get_filesystem_method(): string {
	return DeploymentArchivePreflightWordPressState::$filesystemMethod;
}

function get_temp_dir(): string {
	return \Tests\Deployment\DeploymentArchivePreflightTestEnvironment::temporaryRoot() . DIRECTORY_SEPARATOR;
}

function get_theme_root(): string {
	return \Tests\Deployment\DeploymentArchivePreflightTestEnvironment::themeRoot();
}

function get_bloginfo( string $field ): string {
	return 'version' === $field ? DeploymentArchivePreflightWordPressState::$wordpressVersion : '';
}

/** @param array<string, mixed> $arguments */
function wp_safe_remote_get( string $url, array $arguments ): array {
	++DeploymentArchivePreflightWordPressState::$requests;
	DeploymentArchivePreflightWordPressState::$arguments = $arguments;
	if ( DeploymentArchivePreflightWordPressState::$wpError ) {
		return array( 'wp_error' => true );
	}

	$source = DeploymentArchivePreflightWordPressState::$source;
	$target = is_string( $arguments['filename'] ?? null ) ? $arguments['filename'] : '';
	$limit  = is_int( $arguments['limit_response_size'] ?? null ) ? $arguments['limit_response_size'] : 0;
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Test-only HTTP stream fixture.
	$input = fopen( $source, 'rb' );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Test-only HTTP stream fixture.
	$output = fopen( $target, 'wb' );
	if ( false === $input || false === $output || 0 === $limit ) {
		throw new \RuntimeException( 'The test HTTP stream is unavailable.' );
	}
	stream_copy_to_stream( $input, $output, $limit );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Test-only HTTP stream fixture.
	fclose( $input );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Test-only HTTP stream fixture.
	fclose( $output );

	return array(
		'response' => array( 'code' => DeploymentArchivePreflightWordPressState::$status ),
		'headers'  => DeploymentArchivePreflightWordPressState::$headers,
	);
}

function is_wp_error( mixed $response ): bool {
	return is_array( $response ) && true === ( $response['wp_error'] ?? false );
}

/** @param array<string, mixed> $response */
function wp_remote_retrieve_response_code( array $response ): int {
	return (int) ( $response['response']['code'] ?? 0 );
}

/** @param array<string, mixed> $response */
function wp_remote_retrieve_header( array $response, string $name ): string {
	$headers = is_array( $response['headers'] ?? null ) ? $response['headers'] : array();
	foreach ( $headers as $headerName => $value ) {
		if ( is_string( $headerName ) && 0 === strcasecmp( $headerName, $name ) && is_scalar( $value ) ) {
			return (string) $value;
		}
	}

	return '';
}

function disk_free_space( string $directory ): float|false {
	$directory = rtrim( $directory, DIRECTORY_SEPARATOR );
	if ( array_key_exists( $directory, DeploymentArchivePreflightWordPressState::$freeSpaceByDirectory ) ) {
		return DeploymentArchivePreflightWordPressState::$freeSpaceByDirectory[ $directory ];
	}

	return DeploymentArchivePreflightWordPressState::$freeSpace;
}
