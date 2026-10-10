<?php

declare(strict_types=1);

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Isolated host-policy fixture must retain the WordPress API name.
function wp_is_file_mod_allowed( string $context ): bool {
	$GLOBALS['ran_booster_diagnostic_policy_contexts'][] = $context;

	return $GLOBALS['ran_booster_diagnostic_policy_allowed'];
}
