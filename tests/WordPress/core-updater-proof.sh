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

expected_php="${RAN_BOOSTER_TEST_PHP_VERSION-8.2}"
if [[ ! "$expected_php" =~ ^[0-9]+\.[0-9]+$ ]] || ! actual_php="$("$php_bin" -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')" || [[ "$actual_php" != "$expected_php" ]]; then
	echo "The WordPress-core updater proof requires PHP $expected_php (major.minor)." >&2
	exit 2
fi

expected_wordpress="${RAN_BOOSTER_TEST_WORDPRESS_VERSION-}"
version="$("$php_bin" "$wp_cli" core version --path="$wordpress")" || exit 2
if [[ ${RAN_BOOSTER_TEST_WORDPRESS_VERSION+x} ]]; then
	if [[ ! "$expected_wordpress" =~ ^[0-9]+\.[0-9]+(\.[0-9]+)?$ ]] || [[ "$version" != "$expected_wordpress" ]]; then
		echo "The WordPress-core updater proof requires WordPress $expected_wordpress (exact version)." >&2
		exit 2
	fi
else
	case "$version" in
		7.0|7.0.*) ;;
		*)
			echo 'The WordPress-core updater proof requires WordPress 7.0.x.' >&2
			exit 2
			;;
	esac
fi

"$php_bin" "$wp_cli" eval-file "$proof" --path="$wordpress"
