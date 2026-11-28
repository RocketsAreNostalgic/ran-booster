<?php

declare(strict_types=1);

namespace RAN\Admin;

require_once dirname( __DIR__ ) . '/Deployment/PackageMutationGuardWordPressFunctions.php';

function wp_tempnam( string $filename = '', string $dir = '' ): string {
	unset( $filename, $dir );
	return (string) tempnam( sys_get_temp_dir(), 'ran-extension-' );
}

/** @param array{filename: string, limit_response_size: int} $args
 * @return array{response: array{code: int}}
 */
function wp_safe_remote_get( string $url, array $args ): array {
	$GLOBALS['ran_booster_extension_test_url']   = $url;
	$GLOBALS['ran_booster_extension_test_limit'] = $args['limit_response_size'];
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
/** @param array{response: array{code: int}} $response */
function wp_remote_retrieve_response_code( array $response ): int {
	return $response['response']['code']; }
function wp_clean_plugins_cache( bool $clear_update_cache = true ): void {}
/** @return array<string, array<string, string>> */
function get_plugins(): array {
	return $GLOBALS['ran_booster_extension_test_plugins'] ?? array(); }
function is_plugin_active( string $plugin ): bool {
	return '' === $plugin; }
