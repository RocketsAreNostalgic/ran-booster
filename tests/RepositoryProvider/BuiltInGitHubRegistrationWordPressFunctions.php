<?php

declare(strict_types=1);

namespace RAN; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- Core owns the established three-character RAN namespace; WPCS requires four characters.

if ( ! function_exists( __NAMESPACE__ . '\\add_action' ) ) {
	function add_action( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): bool {
		unset( $hook, $callback, $priority, $accepted_args );

		return true;
	}
}
