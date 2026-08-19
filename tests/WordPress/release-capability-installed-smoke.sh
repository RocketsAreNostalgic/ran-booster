#!/usr/bin/env bash

set -euo pipefail

wordpress="${RAN_BOOSTER_WORDPRESS_PATH:-}"
wp_cli="${WP_CLI_BIN:-wp}"
php_bin="${PHP_BIN:-php}"

if [[ "${RAN_BOOSTER_RELEASE_CAPABILITY_TEST_DISPOSABLE:-}" != '1' ]]; then
	echo 'Set RAN_BOOSTER_RELEASE_CAPABILITY_TEST_DISPOSABLE=1 only for an isolated disposable WordPress installation.' >&2
	exit 2
fi
if [[ -z "$wordpress" || ! -f "$wordpress/wp-load.php" ]]; then
	echo 'Set RAN_BOOSTER_WORDPRESS_PATH to a disposable WordPress installation.' >&2
	exit 2
fi
if [[ "${RAN_BOOSTER_RELEASE_CAPABILITY_TEST_URL:-}" != 'http://localhost' ]]; then
	echo 'The installed release-capability proof requires the exact CI site URL.' >&2
	exit 2
fi
if ! command -v "$wp_cli" >/dev/null 2>&1 || ! command -v "$php_bin" >/dev/null 2>&1 || ! command -v zip >/dev/null 2>&1; then
	echo 'WP-CLI, PHP, and zip are required for the installed release-capability proof.' >&2
	exit 2
fi

wp_cli="$(command -v "$wp_cli")"
wordpress="$(cd "$wordpress" && pwd -P)"
root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)"
proof="$root/tests/WordPress/release-capability-installed-smoke.php"
fixture_source="$root/tests/fixtures/ran-booster-release-capability-provider"
fixture_target="$wordpress/wp-content/plugins/ran-booster-release-capability-provider"
plugin_target="$wordpress/wp-content/plugins/ran-booster-p2-fixture-plugin"
theme_target="$wordpress/wp-content/themes/ran-booster-p2-fixture-theme"
marker="$wordpress/.ran-booster-disposable-test-site"

if [[ -L "$wordpress" || -L "$wordpress/wp-content" || -L "$wordpress/wp-content/plugins" || -L "$wordpress/wp-content/themes" ]]; then
	echo 'The installed release-capability proof refuses symlinked WordPress roots.' >&2
	exit 2
fi
if [[ -L "$marker" || ! -f "$marker" ]] || [[ "$(<"$marker")" != 'RAN Booster disposable test site' ]]; then
	echo "The installed release-capability proof requires $marker with the expected disposable-site marker." >&2
	exit 2
fi
if [[ -e "$fixture_target" || -L "$fixture_target" || -e "$plugin_target" || -L "$plugin_target" || -e "$theme_target" || -L "$theme_target" ]]; then
	echo 'A disposable release-capability fixture target already exists.' >&2
	exit 2
fi
if [[ "$("$php_bin" -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')" != '8.2' ]]; then
	echo 'The installed release-capability proof requires PHP 8.2.' >&2
	exit 2
fi
case "$("$php_bin" "$wp_cli" core version --path="$wordpress")" in
	7.0|7.0.*) ;;
	*)
		echo 'The installed release-capability proof requires WordPress 7.0.x.' >&2
		exit 2
		;;
esac
if [[ "$("$php_bin" "$wp_cli" option get siteurl --path="$wordpress")" != "$RAN_BOOSTER_RELEASE_CAPABILITY_TEST_URL" ]]; then
	echo 'The installed release-capability proof is pointed at an unexpected site URL.' >&2
	exit 2
fi

archive_root="$(mktemp -d "${RUNNER_TEMP:-/tmp}/ran-booster-release-capability.XXXXXX")"
cleanup() {
	"$php_bin" "$wp_cli" option delete ran_booster_p2_plugin_archive ran_booster_p2_theme_archive ran_booster_p2_last_artifact --path="$wordpress" >/dev/null 2>&1 || true
	if [[ -d "$fixture_target" && ! -L "$fixture_target" ]]; then
		"$php_bin" "$wp_cli" plugin deactivate ran-booster-release-capability-provider --path="$wordpress" >/dev/null 2>&1 || true
		"$php_bin" "$wp_cli" plugin delete ran-booster-release-capability-provider --path="$wordpress" >/dev/null 2>&1 || true
	fi
	rm -rf -- "$archive_root"
}
trap cleanup EXIT

mkdir -p "$archive_root/plugin/ran-booster-p2-fixture-plugin" "$archive_root/theme/ran-booster-p2-fixture-theme"
cp -R "$fixture_source" "$fixture_target"

printf '%s\n' '<?php' '/**' ' * Plugin Name: RAN Booster P2 Fixture Plugin' ' * Version: 2.0.0' ' * Update URI: https://p2.invalid/fixtures/plugin' ' */' > "$archive_root/plugin/ran-booster-p2-fixture-plugin/ran-booster-p2-fixture-plugin.php"
printf '%s\n' '/*' 'Theme Name: RAN Booster P2 Fixture Theme' 'Version: 2.0.0' 'Update URI: https://p2.invalid/fixtures/theme' '*/' > "$archive_root/theme/ran-booster-p2-fixture-theme/style.css"
printf '%s\n' '<?php' '// Silence is golden.' > "$archive_root/theme/ran-booster-p2-fixture-theme/index.php"
( cd "$archive_root/plugin" && zip -qr "$archive_root/plugin.zip" ran-booster-p2-fixture-plugin )
( cd "$archive_root/theme" && zip -qr "$archive_root/theme.zip" ran-booster-p2-fixture-theme )

"$php_bin" "$wp_cli" plugin activate ran-booster-release-capability-provider --path="$wordpress"
"$php_bin" "$wp_cli" option add ran_booster_p2_plugin_archive "$archive_root/plugin.zip" --autoload=no --path="$wordpress"
"$php_bin" "$wp_cli" option add ran_booster_p2_theme_archive "$archive_root/theme.zip" --autoload=no --path="$wordpress"

export RAN_BOOSTER_RELEASE_CAPABILITY_ARCHIVE_ROOT="$archive_root"
"$php_bin" "$wp_cli" eval-file "$proof" --user=admin --path="$wordpress"

if [[ -e "$plugin_target" || -L "$plugin_target" || -e "$theme_target" || -L "$theme_target" ]]; then
	echo 'The installed release-capability proof left a package fixture behind.' >&2
	exit 1
fi

"$php_bin" "$wp_cli" option delete ran_booster_p2_plugin_archive ran_booster_p2_theme_archive --path="$wordpress"
"$php_bin" "$wp_cli" plugin deactivate ran-booster-release-capability-provider --path="$wordpress"
"$php_bin" "$wp_cli" plugin delete ran-booster-release-capability-provider --path="$wordpress"
rm -rf -- "$archive_root"
trap - EXIT

if [[ -e "$fixture_target" || -L "$fixture_target" || -e "$archive_root" || -L "$archive_root" ]]; then
	echo 'The installed release-capability proof did not restore its fixture state.' >&2
	exit 1
fi
