<?php

declare( strict_types = 1 );

namespace RAN\Admin\WebhookManagement;

function add_action( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): bool {
	$GLOBALS['ran_booster_repository_webhook_management_actions'][ $hook ][] = array(
		'callback'     => $callback,
		'priority'     => $priority,
		'acceptedArgs' => $accepted_args,
	);

	return true;
}

function add_filter( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): bool {
	$GLOBALS['ran_booster_repository_webhook_management_filters'][ $hook ][] = array(
		'callback'     => $callback,
		'priority'     => $priority,
		'acceptedArgs' => $accepted_args,
	);

	return true;
}

function wp_unslash( mixed $value ): mixed {
	return $value;
}

/** @param list<string> $dependencies */
// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- WordPress wp_enqueue_style stub preserves native argument slots; compact() reads the parameters to record the exact call arguments.
function wp_enqueue_style( string $handle, string $source, array $dependencies = array(), string|bool|null $version = false ): void {
	$GLOBALS['ran_booster_repository_webhook_management_styles'][] = compact( 'handle', 'source', 'dependencies', 'version' );
}

/** @param list<string> $dependencies */
// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- WordPress wp_enqueue_script stub preserves native argument slots; compact() reads the parameters to record the exact call arguments.
function wp_enqueue_script( string $handle, string $source, array $dependencies = array(), string|bool|null $version = false, bool $in_footer = false ): void {
	$GLOBALS['ran_booster_repository_webhook_management_scripts'][] = compact( 'handle', 'source', 'dependencies', 'version', 'in_footer' );
}

function current_user_can( string $capability ): bool {
	return $GLOBALS['ran_booster_repository_webhook_management_capabilities'][ $capability ] ?? false;
}

function esc_html__( string $text, string $domain = 'default' ): string {
	unset( $domain );

	return $text;
}

function wp_verify_nonce( string $nonce, string $action ): int|false {
	return hash_equals( 'nonce-for-' . hash( 'sha256', $action ), $nonce ) ? 1 : false;
}

/** @param array<string, mixed> $data */
function wp_send_json_error( array $data, ?int $status_code = null ): void {
	$GLOBALS['ran_booster_repository_webhook_management_json'][] = array(
		'success' => false,
		'data'    => $data,
		'status'  => $status_code,
	);
}

/** @param array<string, mixed> $data */
function wp_send_json_success( array $data ): void {
	$GLOBALS['ran_booster_repository_webhook_management_json'][] = array(
		'success' => true,
		'data'    => $data,
	);
}
