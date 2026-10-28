<?php

declare(strict_types=1);

namespace RAN\Admin;

function wp_safe_remote_get( string $url, array $args ): array {
	$GLOBALS['ran_booster_extension_test_url'] = $url;
	if ( ! empty( $GLOBALS['ran_booster_extension_test_replace_file'] ) ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Test-only fixture custody and cleanup.
		rename( $args['filename'], $args['filename'] . '.original' );
	}
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test-only fixture custody and cleanup.
	file_put_contents( $args['filename'], $GLOBALS['ran_booster_extension_test_download'] );
	return array( 'response' => array( 'code' => $GLOBALS['ran_booster_extension_test_status'] ?? 200 ) );
}
function is_wp_error( mixed $value ): bool {
	return is_object( $value ) && is_a( $value, 'WP_Error' );
}
function wp_remote_retrieve_response_code( array $response ): int {
	return $response['response']['code']; }
function wp_clean_plugins_cache( bool $clear_update_cache = true ): void {}
function get_plugins(): array {
	return $GLOBALS['ran_booster_extension_test_plugins'] ?? array(); }
function is_plugin_active( string $plugin ): bool {
	return '' === $plugin; }
