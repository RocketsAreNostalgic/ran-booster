#!/usr/bin/env bash

set -euo pipefail

wordpress="${RAN_BOOSTER_WORDPRESS_PATH:-}"
wp_cli="${WP_CLI_BIN:-wp}"
php_bin="${PHP_BIN:-php}"

if [[ "${RAN_BOOSTER_CORE_UPDATER_TEST_DISPOSABLE:-}" != '1' ]]; then
	echo 'Set RAN_BOOSTER_CORE_UPDATER_TEST_DISPOSABLE=1 only for an isolated disposable WordPress installation.' >&2
	exit 2
fi
if [[ -z "$wordpress" || ! -f "$wordpress/wp-load.php" ]]; then
	echo 'Set RAN_BOOSTER_WORDPRESS_PATH to a disposable WordPress installation.' >&2
	exit 2
fi

wp_cli="$(command -v "$wp_cli")"
wordpress="$(cd "$wordpress" && pwd -P)"
root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)"
marker="$wordpress/.ran-booster-disposable-test-site"

if [[ -L "$marker" || ! -f "$marker" ]] ||
	[[ "$(<"$marker")" != 'RAN Booster disposable test site' ]]; then
	echo 'The core executor smoke test requires the expected disposable-site marker.' >&2
	exit 2
fi
if [[ "$("$php_bin" -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')" != '8.2' ]]; then
	echo 'The core executor smoke test requires PHP 8.2.' >&2
	exit 2
fi

"$php_bin" "$root/tests/WordPress/core-package-executor-non-cron.php"

RAN_BOOSTER_EXECUTOR_ROOT="$root" \
	"$php_bin" "$wp_cli" eval-file "$root/tests/WordPress/core-package-executor-smoke.php" --path="$wordpress"
