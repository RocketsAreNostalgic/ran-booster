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
if ! command -v "$wp_cli" >/dev/null 2>&1 || ! command -v "$php_bin" >/dev/null 2>&1; then
	echo 'WP-CLI and PHP are required for the WordPress-core updater proof.' >&2
	exit 2
fi

wp_cli="$(command -v "$wp_cli")"
wordpress="$(cd "$wordpress" && pwd -P)"
root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)"
proof="$root/tests/WordPress/core-updater-proof.php"
disposable_marker="$wordpress/.ran-booster-disposable-test-site"

if [[ -L "$disposable_marker" || ! -f "$disposable_marker" ]] ||
	[[ "$(<"$disposable_marker")" != 'RAN Booster disposable test site' ]]; then
	echo "The WordPress-core updater proof requires $disposable_marker with the expected disposable-site marker." >&2
	exit 2
fi

if [[ "$("$php_bin" -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')" != '8.2' ]]; then
	echo 'The WordPress-core updater proof requires PHP 8.2.' >&2
	exit 2
fi

version="$("$php_bin" "$wp_cli" core version --path="$wordpress")"
case "$version" in
	7.0.*) ;;
	*)
		echo 'The WordPress-core updater proof requires WordPress 7.0.x.' >&2
		exit 2
		;;
esac

"$php_bin" "$wp_cli" eval-file "$proof" --path="$wordpress"
