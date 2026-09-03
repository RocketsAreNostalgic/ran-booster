#!/usr/bin/env bash

set -euo pipefail

wordpress="${RAN_BOOSTER_WORDPRESS_PATH:-}"
wp_cli="${WP_CLI_BIN:-wp}"
php_bin="${PHP_BIN:-php}"

has_symlink_component() {
	local candidate parent

	candidate="$1"
	while :; do
		if [[ -L "$candidate" ]]; then
			return 0
		fi
		parent="$(dirname -- "$candidate")"
		[[ "$parent" == "$candidate" ]] && return 1
		candidate="$parent"
	done
}

if [[ "${RAN_BOOSTER_LOCALISATION_TEST_DISPOSABLE:-}" != '1' ]]; then
	echo 'Set RAN_BOOSTER_LOCALISATION_TEST_DISPOSABLE=1 only for an isolated disposable WordPress installation.' >&2
	exit 2
fi
if [[ -z "$wordpress" || ! -f "$wordpress/wp-load.php" ]]; then
	echo 'Set RAN_BOOSTER_WORDPRESS_PATH to a disposable WordPress installation.' >&2
	exit 2
fi
if [[ "${RAN_BOOSTER_LOCALISATION_TEST_URL:-}" != 'http://localhost' ]]; then
	echo 'The installed localisation proof requires the exact CI site URL.' >&2
	exit 2
fi
if has_symlink_component "$wordpress"; then
	echo 'The installed localisation proof refuses symlinked WordPress roots.' >&2
	exit 2
fi
if ! command -v "$wp_cli" >/dev/null 2>&1 || ! command -v "$php_bin" >/dev/null 2>&1 || ! command -v jq >/dev/null 2>&1; then
	echo 'WP-CLI, PHP, and jq are required for the installed localisation proof.' >&2
	exit 2
fi

wp_cli="$(command -v "$wp_cli")"
php_bin="$(command -v "$php_bin")"
wordpress="$(cd "$wordpress" && pwd -P)"
root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)"
proof="$root/tests/WordPress/localisation-installed-smoke.php"
fixture_languages="$root/tests/fixtures/i18n"
plugin_target="$wordpress/wp-content/plugins/ran-booster"
languages_target="$plugin_target/languages"
marker="$wordpress/.ran-booster-disposable-test-site"
fixture_mo="$fixture_languages/ran-booster-fr_FR.mo"

if [[ -L "$wordpress" || -L "$wordpress/wp-content" || -L "$wordpress/wp-content/plugins" ]]; then
	echo 'The installed localisation proof refuses symlinked WordPress roots.' >&2
	exit 2
fi
if [[ -L "$marker" || ! -f "$marker" ]] || ! cmp -s "$marker" <(printf '%s\n' 'RAN Booster disposable test site'); then
	echo "The installed localisation proof requires $marker with the expected disposable-site marker." >&2
	exit 2
fi
if [[ -L "$plugin_target" || ! -f "$plugin_target/ran-booster.php" || -L "$languages_target" || ! -f "$languages_target/ran-booster.pot" ]]; then
	echo 'The installed localisation proof requires a real installed RAN Booster archive with its production POT.' >&2
	exit 2
fi
if compgen -G "$languages_target/ran-booster-fr_FR.*" >/dev/null \
	|| compgen -G "$languages_target/ran-booster-fr_FR-*.json" >/dev/null; then
	echo 'The installed localisation proof requires no pre-existing French fixture files.' >&2
	exit 2
fi
if [[ ! -f "$fixture_mo" ]]; then
	echo 'The installed localisation proof requires the French MO fixture.' >&2
	exit 2
fi
shopt -s nullglob
json_fixtures=( "$fixture_languages"/ran-booster-fr_FR-*.json )
shopt -u nullglob
if [[ ${#json_fixtures[@]} -ne 7 ]]; then
	echo 'The installed localisation proof requires seven source-hashed French Jed JSON fixtures.' >&2
	exit 2
fi
if [[ "$("$php_bin" -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')" != '8.2' ]]; then
	echo 'The installed localisation proof requires PHP 8.2.' >&2
	exit 2
fi
case "$("$php_bin" "$wp_cli" core version --path="$wordpress")" in
	7.0|7.0.*) ;;
	*)
		echo 'The installed localisation proof requires WordPress 7.0.x.' >&2
		exit 2
		;;
esac
if [[ "$("$php_bin" "$wp_cli" option get siteurl --path="$wordpress")" != "$RAN_BOOSTER_LOCALISATION_TEST_URL" ]]; then
	echo 'The installed localisation proof is pointed at an unexpected site URL.' >&2
	exit 2
fi
"$php_bin" "$wp_cli" plugin is-active ran-booster --path="$wordpress"

original_wplang=''
had_wplang='false'
if original_wplang="$("$php_bin" "$wp_cli" option get WPLANG --format=json --path="$wordpress" 2>/dev/null)"; then
	had_wplang='true'
	original_wplang="$(printf '%s' "$original_wplang" | jq -er 'if type == "string" then . else error("WPLANG must be a string") end')"
fi
copied_mo=''
copied_json=()
wplang_changed='false'
cleanup() {
	if [[ "$wplang_changed" == 'true' ]]; then
		if [[ "$had_wplang" == 'true' ]]; then
			"$php_bin" "$wp_cli" option update WPLANG "$original_wplang" --path="$wordpress" >/dev/null 2>&1 || true
		else
			"$php_bin" "$wp_cli" option delete WPLANG --path="$wordpress" >/dev/null 2>&1 || true
		fi
	fi
	[[ -z "$copied_mo" || ! -f "$copied_mo" ]] || rm -f -- "$copied_mo"
	for copied_file in "${copied_json[@]}"; do
		[[ ! -f "$copied_file" ]] || rm -f -- "$copied_file"
	done
}
trap cleanup EXIT INT TERM

copied_mo="$languages_target/$(basename "$fixture_mo")"
cp "$fixture_mo" "$copied_mo"
for fixture_json in "${json_fixtures[@]}"; do
	copied_file="$languages_target/$(basename "$fixture_json")"
	cp "$fixture_json" "$copied_file"
	copied_json+=( "$copied_file" )
done
wplang_changed='true'
"$php_bin" "$wp_cli" eval '
add_filter(
	"get_available_languages",
	static function ( array $languages ): array {
		$languages[] = "fr_FR";
		return array_values( array_unique( $languages ) );
	}
);
if ( ! update_option( "WPLANG", "fr_FR" ) && "fr_FR" !== get_option( "WPLANG", "" ) ) {
	throw new RuntimeException( "The installed localisation proof could not set WPLANG." );
}
if ( "fr_FR" !== get_option( "WPLANG", "" ) ) {
	throw new RuntimeException( "The installed localisation proof did not retain WPLANG." );
}
' --path="$wordpress" >/dev/null
"$php_bin" "$wp_cli" eval-file "$proof" --user=admin --path="$wordpress"

echo 'Installed localisation proof passed: French PHP and all seven Jed translations loaded from the archive fixture.'
