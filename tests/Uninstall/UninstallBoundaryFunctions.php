<?php

declare(strict_types=1);

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Isolated WordPress boundary double.
function __( string $text, string $domain ): string {
	unset( $domain );
	return $text;
}
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Isolated WordPress boundary double.
function esc_html( string $text ): string {
	return $text;
}
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Isolated WordPress boundary double.
function esc_html__( string $text, string $domain ): string {
	unset( $domain );
	return $text;
}
/** @param array<string, mixed> $args */
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Isolated WordPress boundary double.
function wp_die( string $message, string $title, array $args ): never {
	unset( $title, $args );
	throw new LogicException( $message );
}
