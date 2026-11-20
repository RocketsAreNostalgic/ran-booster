<?php

declare( strict_types = 1 );

namespace RAN\Admin\WebhookManagement\Display;

// Focused translation fixture for WebhookDisplayModelTest's clean single-file execution.
if ( ! function_exists( __NAMESPACE__ . '\\__' ) ) {
	function __( string $text, string $domain = 'default' ): string {
		return $GLOBALS['ran_booster_webhook_display_test_translations'][ $domain ][ $text ] ?? $text;
	}
}

// Site identity and capability doubles for the receiver sentence; unset, they defer to whichever host double the suite loaded.
if ( ! function_exists( __NAMESPACE__ . '\\is_multisite' ) ) {
	function is_multisite(): bool {
		return $GLOBALS['ran_booster_webhook_display_test_multisite'] ?? \is_multisite();
	}
}

if ( ! function_exists( __NAMESPACE__ . '\\current_user_can' ) ) {
	function current_user_can( string $capability ): bool {
		return $GLOBALS['ran_booster_webhook_display_test_capabilities'][ $capability ] ?? \current_user_can( $capability );
	}
}

if ( ! function_exists( __NAMESPACE__ . '\\get_current_blog_id' ) ) {
	function get_current_blog_id(): int {
		return $GLOBALS['ran_booster_webhook_display_test_blog_id'] ?? 1;
	}
}
