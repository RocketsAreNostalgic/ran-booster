<?php

declare(strict_types=1);

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- Model the public namespace of the WP-CLI config locator.
namespace WP_CLI\Utils;

/** Mirrors the cached locator result supplied by the CLI bootstrap. */
function locate_wp_config(): string|false {
	return $GLOBALS['ran_booster_cli_selected_config'] ?? false;
}
