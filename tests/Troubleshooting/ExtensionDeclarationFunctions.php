<?php

declare(strict_types=1);

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- Fixture supplies the production namespace translation boundary.
namespace RAN;

/** Local translation fixture for isolated declaration tests. */
// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Retain the WordPress translation signature.
function __( string $text, string $domain ): string {
	return $text;
}
