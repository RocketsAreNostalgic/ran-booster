<?php

declare(strict_types=1);

namespace RAN\Secrets;

if ( ! function_exists( __NAMESPACE__ . '\\__' ) ) {
	function __( string $text, string $domain = 'default' ): string {
		return $GLOBALS['ran_booster_secrets_test_translations'][ $domain ][ $text ] ?? $text;
	}
}

if ( ! function_exists( __NAMESPACE__ . '\\_x' ) ) {
	function _x( string $text, string $context, string $domain = 'default' ): string {
		return $GLOBALS['ran_booster_secrets_test_translations'][ $domain ][ $context . "\004" . $text ] ?? $text;
	}
}
