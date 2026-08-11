<?php

declare(strict_types=1);

namespace RAN\Admin;

function current_user_can( string $capability ): bool {
	if ( array_key_exists( 'ran_booster_test_capabilities', $GLOBALS ) ) {
		$GLOBALS['ran_booster_test_capability_checks'][] = $capability;

		return $GLOBALS['ran_booster_test_capabilities'][ $capability ] ?? true;
	}
	$capabilities = $GLOBALS['ran_booster_repository_admin_capabilities'] ?? null;
	if ( is_array( $capabilities ) && array_key_exists( $capability, $capabilities ) ) {
		return (bool) $capabilities[ $capability ];
	}

	return (bool) ( $GLOBALS['ran_booster_repository_admin_allowed'] ?? true );
}

function wp_add_inline_style( string $handle, string $css ): bool {
	$GLOBALS['ran_booster_repository_admin_inline_styles'][ $handle ][] = $css;

	return true;
}

function check_ajax_referer( string $action, string $queryArg, bool $stop ): bool {
	unset( $action, $queryArg, $stop );

	return (bool) ( $GLOBALS['ran_booster_repository_admin_nonce_valid'] ?? true );
}

function is_uploaded_file( string $filename ): bool {
	return in_array( $filename, $GLOBALS['ran_booster_repository_admin_uploaded_files'] ?? array(), true );
}

function tempnam( string $directory, string $prefix ): string|false {
	$path = \tempnam( $directory, $prefix );
	$GLOBALS['ran_booster_repository_admin_temporary_files'][] = $path;

	return $path;
}

function file_get_contents( string $filename ): string|false {
	if ( array_key_exists( 'ran_booster_repository_admin_file_read', $GLOBALS ) ) {
		$read = $GLOBALS['ran_booster_repository_admin_file_read'];

		return is_callable( $read ) ? $read( $filename ) : false;
	}

	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test shim delegates to the native read used by the production boundary.
	return \file_get_contents( $filename );
}

/**
 * @param array<string, mixed> $data
 * @return array{success: false, data: array<string, mixed>, status: int|null}
 */
function wp_send_json_error( array $data, ?int $statusCode = null ): array {
	return array(
		'success' => false,
		'data'    => $data,
		'status'  => $statusCode,
	);
}

/**
 * @param array<string, mixed> $data
 * @return array{success: true, data: array<string, mixed>}
 */
function wp_send_json_success( array $data ): array {
	return array(
		'success' => true,
		'data'    => $data,
	);
}

function wp_die( string $message = '' ): never {
	// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The focused shim preserves already escaped controller denial copy.
	throw new \RuntimeException( '' !== $message ? $message : 'ran_booster_test_wp_die' );
}

function get_current_user_id(): int {
	return (int) ( $GLOBALS['ran_booster_repository_admin_user_id'] ?? 1 );
}

function update_user_meta( int $userId, string $key, mixed $value ): int|bool {
	if ( (bool) ( $GLOBALS['ran_booster_repository_admin_user_meta_write_fails'] ?? false ) ) {
		return false;
	}

	$GLOBALS['ran_booster_repository_admin_user_meta'][ $userId ][ $key ] = $value;

	return 1;
}

function get_user_meta( int $userId, string $key, bool $single ): mixed {
	unset( $single );

	return $GLOBALS['ran_booster_repository_admin_user_meta'][ $userId ][ $key ] ?? '';
}

function wp_unslash( mixed $value ): mixed {
	return $value;
}

function sanitize_key( mixed $value ): string {
	return (string) preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) );
}

function sanitize_text_field( mixed $value ): string {
	return trim( (string) preg_replace( '/<[^>]*>/', '', (string) $value ) );
}
