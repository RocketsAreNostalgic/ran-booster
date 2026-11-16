<?php

declare(strict_types=1);

// Global escaping shim for NoticeIcon, used only by separate-process tests.
if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( mixed $value ): string { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WordPress fixture signature.
		return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' );
	}
}
