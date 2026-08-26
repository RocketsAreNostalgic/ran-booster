<?php

declare(strict_types=1);

namespace RAN\Admin\ReleaseManagement\GitHub;

function __( string $text, string $domain = 'default' ): string {
	return \RAN\Admin\ReleaseManagement\__( $text, $domain );
}

function _n( string $single, string $plural, int $number, string $domain = 'default' ): string {
	return 1 === $number ? $single : $plural;
}

function esc_html( mixed $value ): string {
	return \RAN\Admin\ReleaseManagement\esc_html( $value );
}

function esc_attr( mixed $value ): string {
	return \RAN\Admin\ReleaseManagement\esc_attr( $value );
}

function esc_url( mixed $value ): string {
	return \RAN\Admin\ReleaseManagement\esc_url( $value );
}

function esc_html__( string $text, string $domain = 'default' ): string {
	return \RAN\Admin\ReleaseManagement\esc_html__( $text, $domain );
}

function add_action( string $hook, callable $callback, int $priority = 10, int $acceptedArgs = 1 ): bool {
	return \RAN\Admin\ReleaseManagement\add_action( $hook, $callback, $priority, $acceptedArgs );
}

function add_filter( string $hook, callable $callback, int $priority = 10, int $acceptedArgs = 1 ): bool {
	return \RAN\Admin\ReleaseManagement\add_filter( $hook, $callback, $priority, $acceptedArgs );
}

function wp_json_encode( mixed $value ): string|false {
	return \RAN\Admin\ReleaseManagement\wp_json_encode( $value );
}

function wp_safe_redirect( string $url ): bool {
	return \RAN\Admin\ReleaseManagement\wp_safe_redirect( $url );
}

function header( string $header, bool $replace = true, int $responseCode = 0 ): void {
	\RAN\Admin\ReleaseManagement\header( $header, $replace, $responseCode );
}

function wp_unslash( mixed $value ): mixed {
	if ( is_string( $value ) ) {
		$GLOBALS['ran_booster_github_release_workflow_test_unslashed'][] = $value;
		$GLOBALS['ran_booster_github_release_workflow_test_events'][]    = 'unslash:' . $value;
	}
	return \RAN\Admin\ReleaseManagement\wp_unslash( $value );
}

function current_user_can( string $capability ): bool {
	$GLOBALS['ran_booster_github_release_workflow_test_events'][] = 'capability:' . $capability;
	return \RAN\Admin\ReleaseManagement\current_user_can( $capability );
}

function wp_verify_nonce( string $nonce, string $action ): int|false {
	$GLOBALS['ran_booster_github_release_workflow_test_events'][] = 'verify:' . $action;
	return \RAN\Admin\ReleaseManagement\wp_verify_nonce( $nonce, $action );
}

function wp_create_nonce( string $action ): string {
	return \RAN\Admin\ReleaseManagement\wp_create_nonce( $action );
}

function sanitize_key( mixed $value ): string {
	return \RAN\Admin\ReleaseManagement\sanitize_key( $value );
}

function sanitize_text_field( mixed $value ): string {
	return \RAN\Admin\ReleaseManagement\sanitize_text_field( $value );
}

function admin_url( string $path = '' ): string {
	return \RAN\Admin\ReleaseManagement\admin_url( $path );
}

function add_query_arg( array|string $key, mixed $value = null, ?string $url = null ): string {
	return \RAN\Admin\ReleaseManagement\add_query_arg( $key, $value, $url );
}
