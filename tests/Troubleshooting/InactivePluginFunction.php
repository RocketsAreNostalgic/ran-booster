<?php

declare(strict_types=1);

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound, Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Fixed isolated WordPress function fixture.
function is_plugin_active( string $plugin ): bool {
	return false;
}
