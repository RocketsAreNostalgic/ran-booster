#!/usr/bin/env bash

set -euo pipefail

wordpress="${RAN_BOOSTER_WORDPRESS_PATH:-}"
wp_cli="${WP_CLI_BIN:-wp}"
php_bin="${PHP_BIN:-php}"

if [[ -z "$wordpress" || ! -f "$wordpress/wp-load.php" ]]; then
	echo 'Set RAN_BOOSTER_WORDPRESS_PATH to a disposable WordPress installation.' >&2
	exit 2
fi
if ! command -v "$wp_cli" >/dev/null 2>&1 || ! command -v "$php_bin" >/dev/null 2>&1; then
	echo 'WP-CLI and PHP are required for the database storage smoke.' >&2
	exit 2
fi

wp_cli="$(command -v "$wp_cli")"
root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)"

"$php_bin" "$wp_cli" plugin is-active ran-booster --path="$wordpress"
"$php_bin" "$wp_cli" eval-file "$root/tests/WordPress/database-storage-smoke.php" --path="$wordpress"
