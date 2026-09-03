#!/usr/bin/env bash

set -euo pipefail

fail() {
	echo "localisation-installed-smoke-contract: $*" >&2
	exit 1
}

root=$(git rev-parse --show-toplevel)
script="$root/tests/WordPress/localisation-installed-smoke.sh"
TMPDIR=$(CDPATH='' cd -- "${TMPDIR:-/tmp}" && pwd -P)
temporary_dir=$(mktemp -d "${TMPDIR:-/tmp}/ran-booster-localisation-smoke.XXXXXX")
trap 'rm -rf "$temporary_dir"' EXIT HUP INT TERM
wordpress="$temporary_dir/wordpress"
mkdir -p "$wordpress/wp-content/plugins"
touch "$wordpress/wp-load.php"

run_smoke() {
	local output status

	set +e
	output="$(RAN_BOOSTER_LOCALISATION_TEST_DISPOSABLE=1 RAN_BOOSTER_LOCALISATION_TEST_URL=http://localhost RAN_BOOSTER_WORDPRESS_PATH="$1" bash "$script" 2>&1)"
	status=$?
	set -e
	printf '%s\n%s' "$status" "$output"
}

printf '%s\n' 'RAN Booster disposable test site' > "$wordpress/.ran-booster-disposable-test-site"
result="$(run_smoke "$wordpress")"
[[ "${result%%$'\n'*}" == '2' ]] || fail 'the exact marker did not reach the installed-archive gate.'
[[ "$result" == *'requires a real installed RAN Booster archive'* ]] || fail 'the exact marker was not accepted before the installed-archive gate.'

printf '%s\n\n' 'RAN Booster disposable test site' > "$wordpress/.ran-booster-disposable-test-site"
result="$(run_smoke "$wordpress")"
[[ "${result%%$'\n'*}" == '2' ]] || fail 'the extra-newline marker did not fail.'
[[ "$result" == *'expected disposable-site marker'* ]] || fail 'the extra-newline marker reached a later gate.'

ln -s "$wordpress" "$temporary_dir/linked-wordpress"
result="$(run_smoke "$temporary_dir/linked-wordpress")"
[[ "${result%%$'\n'*}" == '2' ]] || fail 'the symlinked root did not fail.'
[[ "$result" == *'refuses symlinked WordPress roots'* ]] || fail 'the symlinked root reached a later gate.'

mkdir -p "$temporary_dir/real-parent/site/wp-content/plugins" "$temporary_dir/lexical-parent"
touch "$temporary_dir/real-parent/site/wp-load.php"
ln -s "$temporary_dir/real-parent" "$temporary_dir/lexical-parent/linked"
result="$(run_smoke "$temporary_dir/lexical-parent/linked/site")"
[[ "${result%%$'\n'*}" == '2' ]] || fail 'the symlinked parent did not fail.'
[[ "$result" == *'refuses symlinked WordPress roots'* ]] || fail 'the symlinked parent reached a later gate.'

empty_path="$temporary_dir/without-jq"
mkdir "$empty_path"
wp_cli=$(command -v wp)
php_bin=$(command -v php)
set +e
output="$(PATH="$empty_path" RAN_BOOSTER_LOCALISATION_TEST_DISPOSABLE=1 RAN_BOOSTER_LOCALISATION_TEST_URL=http://localhost RAN_BOOSTER_WORDPRESS_PATH="$wordpress" WP_CLI_BIN="$wp_cli" PHP_BIN="$php_bin" "$BASH" "$script" 2>&1)"
status=$?
set -e
[[ "$status" == '2' ]] || fail 'the missing jq preflight did not fail.'
[[ "$output" == *'WP-CLI, PHP, and jq are required'* ]] || fail 'the missing jq preflight was unclear.'
