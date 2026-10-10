<?php

declare(strict_types=1);

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Isolated host fixture supplies WordPress's path helper.
function trailingslashit( string $value ): string {
	return rtrim( $value, '/\\' ) . '/';
}
