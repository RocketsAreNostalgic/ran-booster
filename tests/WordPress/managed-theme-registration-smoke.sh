#!/usr/bin/env bash

set -euo pipefail

wordpress="${RAN_BOOSTER_WORDPRESS_PATH:-}"
wp_cli="${WP_CLI_BIN:-wp}"
php_bin="${PHP_BIN:-php}"

if [[ "${RAN_BOOSTER_MANAGED_THEME_REGISTRATION_TEST_DISPOSABLE:-}" != '1' ]]; then
	echo 'Set RAN_BOOSTER_MANAGED_THEME_REGISTRATION_TEST_DISPOSABLE=1 only for an isolated disposable WordPress installation.' >&2
	exit 2
fi
if [[ -z "$wordpress" || ! -f "$wordpress/wp-load.php" ]]; then
	echo 'Set RAN_BOOSTER_WORDPRESS_PATH to a disposable WordPress installation.' >&2
	exit 2
fi
if ! command -v "$wp_cli" >/dev/null 2>&1 || ! command -v "$php_bin" >/dev/null 2>&1; then
	echo 'WP-CLI and PHP are required for the managed-theme registration proof.' >&2
	exit 2
fi

wp_cli="$(command -v "$wp_cli")"
php_bin="$(command -v "$php_bin")"
wordpress="$(cd "$wordpress" && pwd -P)"
root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)"
marker="$wordpress/.ran-booster-disposable-test-site"

if [[ -L "$marker" || ! -f "$marker" ]] ||
	[[ "$(<"$marker")" != 'RAN Booster disposable test site' ]]; then
	echo "The managed-theme registration proof requires $marker with the expected disposable-site marker." >&2
	exit 2
fi
if [[ "$($php_bin -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')" != '8.2' ]]; then
	echo 'The managed-theme registration proof requires PHP 8.2.' >&2
	exit 2
fi

version="$($php_bin "$wp_cli" core version --path="$wordpress")"
case "$version" in
	7.0.*) ;;
	*)
		echo 'The managed-theme registration proof requires a supported WordPress 7.0.x fixture.' >&2
		exit 2
		;;
esac

fixtures="$root/tests/WordPress/fixtures"
for stylesheet in ran-booster-managed-active ran-booster-managed-inactive; do
	theme_target="$wordpress/wp-content/themes/$stylesheet"
	if [[ -e "$theme_target" ]]; then
		echo "The managed-theme fixture target already exists: $stylesheet" >&2
		exit 2
	fi
	cp -R "$fixtures/$stylesheet" "$theme_target"
done

"$php_bin" "$wp_cli" theme activate ran-booster-managed-active --path="$wordpress"
"$php_bin" "$wp_cli" eval-file "$root/tests/WordPress/managed-theme-registration-seed.php" --path="$wordpress" --user=admin
"$php_bin" "$wp_cli" eval-file "$root/tests/WordPress/managed-theme-registration-assert.php" --path="$wordpress" --user=admin
