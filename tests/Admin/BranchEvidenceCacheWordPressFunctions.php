<?php

declare(strict_types=1);

// phpcs:ignore Universal.Namespaces.DisallowCurlyBraceSyntax.Forbidden, Universal.Namespaces.DisallowDeclarationWithoutName.Forbidden -- Isolated WordPress cache boundary.
namespace {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Exact host API exercised by the isolated test.
	function wp_cache_delete( string $key, string $group ): bool {
		$GLOBALS['ran_booster_branch_cache_deletes'][] = array( $key, $group );
		unset( $GLOBALS['ran_booster_branch_cache'][ $key ] );
		return true;
	}
}

// phpcs:ignore Universal.Namespaces.OneDeclarationPerFile.MultipleFound, Universal.Namespaces.DisallowCurlyBraceSyntax.Forbidden -- Namespaced option boundary prevents interference with unrelated WordPress fixtures.
namespace RAN\Admin {
	function get_option( string $name, mixed $fallback = false ): mixed {
		return $GLOBALS['ran_booster_branch_cache'][ $name ] ??= $GLOBALS['ran_booster_branch_database'][ $name ] ?? $fallback;
	}
}
