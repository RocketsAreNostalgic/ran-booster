<?php

declare( strict_types = 1 );

namespace RAN\Admin\WebhookManagement;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Focused management adapter fixture.

function add_action( string $hook, callable $callback, int $priority = 10, int $acceptedArgs = 1 ): bool {
	$GLOBALS['ran_booster_repository_webhook_management_actions'][ $hook ][] = compact( 'callback', 'priority', 'acceptedArgs' );

	return true;
}

function add_filter( string $hook, callable $callback, int $priority = 10, int $acceptedArgs = 1 ): bool {
	$GLOBALS['ran_booster_repository_webhook_management_filters'][ $hook ][] = compact( 'callback', 'priority', 'acceptedArgs' );

	return true;
}

function wp_unslash( mixed $value ): mixed {
	return $value;
}

/** @param list<string> $dependencies */
function wp_enqueue_style( string $handle, string $source, array $dependencies = array(), string|bool|null $version = false ): void {
	$GLOBALS['ran_booster_repository_webhook_management_styles'][] = compact( 'handle', 'source', 'dependencies', 'version' );
}

function current_user_can( string $capability ): bool {
	return $GLOBALS['ran_booster_repository_webhook_management_capabilities'][ $capability ] ?? false;
}

function esc_html__( string $text, string $domain = 'default' ): string {
	unset( $domain );

	return $text;
}
