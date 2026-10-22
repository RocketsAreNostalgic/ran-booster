<?php

// Test-only loopback router; never loaded by WordPress or a shipped archive.
$ran_booster_redirect_path = $_SERVER['REQUEST_URI'] ?? '';
if ( '/ready' === $ran_booster_redirect_path ) {
	echo 'ready';
	return;
}
$ran_booster_redirect_log = getenv( 'RAN_BOOSTER_REDIRECT_LOG' );
if ( 'cli-server' !== PHP_SAPI || ! is_string( $ran_booster_redirect_log ) || '' === $ran_booster_redirect_log || ! is_string( $ran_booster_redirect_path ) ) {
	throw new RuntimeException( 'The archive redirect router requires its owned CLI server.' );
}
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Exact test-owned network journal records actual requests, not a WordPress file operation.
if ( false === file_put_contents( $ran_booster_redirect_log, json_encode( array( $ran_booster_redirect_path, $_SERVER['HTTP_AUTHORIZATION'] ?? '' ), JSON_THROW_ON_ERROR ) . "\n", FILE_APPEND | LOCK_EX ) ) {
	throw new RuntimeException( 'Could not record the redirect request.' );
}
if ( str_ends_with( $ran_booster_redirect_path, '/start' ) ) {
	header( 'Location: ' . substr( $ran_booster_redirect_path, 0, -5 ) . 'hop', true, 302 );
} elseif ( str_ends_with( $ran_booster_redirect_path, '/hop' ) ) {
	header( 'Location: ' . substr( $ran_booster_redirect_path, 0, -3 ) . 'done', true, 302 );
} else {
	echo 'archive';
}
