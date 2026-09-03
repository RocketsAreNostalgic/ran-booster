#!/usr/bin/env bash

set -euo pipefail

fail() {
	echo "localisation-installed-smoke-contract: $*" >&2
	exit 1
}

root=$(git rev-parse --show-toplevel)
script="$root/tests/WordPress/localisation-installed-smoke.sh"
fake_php="$root/tests/fixtures/localisation-smoke-fake-php.sh"
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

result="$(run_smoke "$wordpress")"
[[ "${result%%$'\n'*}" == '2' ]] || fail 'the absent marker did not fail.'
[[ "$result" == *'expected disposable-site marker'* ]] || fail 'the absent marker reached a later gate.'

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

runtime_read=$(grep -n 'WP_CONTENT_DIR' "$script" | head -n 1 | cut -d: -f1)
fixture_copy=$(grep -n 'cp "\$fixture_mo"' "$script" | cut -d: -f1)
wplang_mutation=$(grep -n 'option update WPLANG\|update_option( "WPLANG"' "$script" | head -n 1 | cut -d: -f1)
[[ -n "$runtime_read" && -n "$fixture_copy" && -n "$wplang_mutation" ]] \
	|| fail 'the runtime target safety contract is incomplete.'
(( runtime_read < fixture_copy && runtime_read < wplang_mutation )) \
	|| fail 'the runtime target is not validated before fixture or WPLANG mutation.'
grep -Fq '"ABSPATH" => realpath( ABSPATH )' "$script" \
	&& grep -Fq '"WP_PLUGIN_DIR" => realpath( WP_PLUGIN_DIR )' "$script" \
	&& grep -Fq '"active" => in_array' "$script" \
	&& grep -Fq 'exact active RAN Booster target' "$script" \
	|| fail 'the smoke script does not read and validate the exact active runtime target.'
if grep -Fq 'plugin_target="$wordpress/wp-content/plugins/ran-booster"' "$script"; then
	fail 'the smoke script still hard-codes the default plugin target.'
fi

fake_php="$temporary_dir/localisation-smoke-fake-php"
cp "$root/tests/fixtures/localisation-smoke-fake-php.sh" "$fake_php"
chmod +x "$fake_php"
custom_content="$temporary_dir/external-content"
custom_plugins="$custom_content/custom-plugins"
mkdir -p "$custom_plugins"
printf '%s\n' 'RAN Booster disposable test site' > "$wordpress/.ran-booster-disposable-test-site"
runtime=$(jq -cn --arg root "$wordpress" --arg content "$custom_content" --arg plugins "$custom_plugins" '{ABSPATH:$root,WP_CONTENT_DIR:$content,WP_PLUGIN_DIR:$plugins,plugin_target:($plugins + "/ran-booster"),plugin_file:($plugins + "/ran-booster/ran-booster.php"),plugin_basename:"ran-booster/ran-booster.php",active:true}')
mutation="$temporary_dir/wplang-mutated"
set +e
output="$(RAN_BOOSTER_LOCALISATION_TEST_DISPOSABLE=1 RAN_BOOSTER_LOCALISATION_TEST_URL=http://localhost RAN_BOOSTER_WORDPRESS_PATH="$wordpress" WP_CLI_BIN="$fake_php" PHP_BIN="$fake_php" RAN_BOOSTER_LOCALISATION_FAKE_RUNTIME="$runtime" RAN_BOOSTER_LOCALISATION_FAKE_WPLANG_MUTATION="$mutation" "$BASH" "$script" 2>&1)"
status=$?
set -e
[[ "$status" == '2' ]] || fail 'the external custom content directory did not fail.'
[[ "$output" == *'exact active RAN Booster target'* ]] || fail 'the external custom content directory reached a later gate.'
[[ ! -e "$mutation" ]] || fail 'the rejected external custom content directory mutated WPLANG.'
[[ ! -e "$wordpress/wp-content/plugins/ran-booster/languages/ran-booster-fr_FR.mo" ]] || fail 'the rejected custom runtime copied fixtures into the conventional plugin tree.'
