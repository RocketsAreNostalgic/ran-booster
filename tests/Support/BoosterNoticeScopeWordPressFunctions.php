<?php

declare(strict_types=1);

/** Test-owned mutable current-screen observation. */
function get_current_screen(): mixed { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WordPress fixture signature.
	return $GLOBALS['ran_booster_notice_scope_screen'] ?? null;
}
