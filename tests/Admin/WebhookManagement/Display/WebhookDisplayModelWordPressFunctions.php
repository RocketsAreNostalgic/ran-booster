<?php

declare( strict_types = 1 );

namespace RAN\Admin\WebhookManagement\Display;

// Focused translation fixture for WebhookDisplayModelTest's clean single-file execution.
if ( ! function_exists( __NAMESPACE__ . '\\__' ) ) {
	function __( string $text, string $domain = 'default' ): string {
		return $GLOBALS['ran_booster_webhook_display_test_translations'][ $domain ][ $text ] ?? $text;
	}
}
