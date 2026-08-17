<?php

declare( strict_types = 1 );

namespace RAN\Booster\GitHub;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Focused legacy assisted-hooks fixture.

function add_action( string $hook, callable $callback, int $priority = 10, int $acceptedArgs = 1 ): bool {
	$GLOBALS['ran_booster_github_webhook_management_actions'][ $hook ][] = compact( 'callback', 'priority', 'acceptedArgs' );

	return true;
}

function current_user_can( string $capability ): bool {
	return $GLOBALS['ran_booster_github_webhook_management_capabilities'][ $capability ] ?? false;
}

function esc_html__( string $text, string $domain = 'default' ): string {
	unset( $domain );

	return $text;
}
