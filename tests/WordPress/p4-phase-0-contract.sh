#!/usr/bin/env bash

set -euo pipefail

source_wordpress="${RAN_BOOSTER_P4_SOURCE_WORDPRESS_PATH:-}"
source_mcp="${RAN_BOOSTER_P4_SOURCE_MCP_ADAPTER_PATH:-}"
wp_cli="${WP_CLI_BIN:-wp}"
php_bin="${PHP_BIN:-php}"

if [[ "${RAN_BOOSTER_P4_PHASE_0_DISPOSABLE:-}" != '1' ]]; then
	echo 'Set RAN_BOOSTER_P4_PHASE_0_DISPOSABLE=1 only for this isolated disposable fixture.' >&2
	exit 2
fi
if [[ -z "$source_wordpress" || ! -f "$source_wordpress/wp-load.php" || -L "$source_wordpress" ]]; then
	echo 'Set RAN_BOOSTER_P4_SOURCE_WORDPRESS_PATH to an exact non-symlinked WordPress source installation.' >&2
	exit 2
fi
if [[ -z "$source_mcp" || ! -f "$source_mcp/mcp-adapter.php" || -L "$source_mcp" ]]; then
	echo 'Set RAN_BOOSTER_P4_SOURCE_MCP_ADAPTER_PATH to the exact MCP Adapter source directory.' >&2
	exit 2
fi
for command_name in "$wp_cli" "$php_bin" jq rsync mktemp; do
	if ! command -v "$command_name" >/dev/null 2>&1; then
		echo "Missing required command: $command_name" >&2
		exit 2
	fi
done

wp_cli="$(command -v "$wp_cli")"
php_bin="$(command -v "$php_bin")"
source_wordpress="$(cd "$source_wordpress" && pwd -P)"
source_mcp="$(cd "$source_mcp" && pwd -P)"
root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)"
proof="$root/tests/WordPress/p4-phase-0-contract.php"
core_fixture="$root/tests/fixtures/ran-booster-p4-phase-0-core"
addon_fixture="$root/tests/fixtures/ran-booster-p4-phase-0-addon"
incompatible_fixture="$root/tests/fixtures/ran-booster-p4-phase-0-incompatible-addon"

if [[ "$("$php_bin" -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')" != '8.2' ]]; then
	echo 'The P4 Phase 0 fixture requires PHP 8.2.' >&2
	exit 2
fi
if [[ "$("$php_bin" "$wp_cli" core version --path="$source_wordpress")" != '7.0.4' ]]; then
	echo 'The P4 Phase 0 fixture requires the exact accepted local WordPress 7.0.4 source.' >&2
	exit 2
fi
if [[ "$("$php_bin" "$wp_cli" --version)" != 'WP-CLI 2.12.0' ]]; then
	echo 'The P4 Phase 0 fixture requires WP-CLI 2.12.0.' >&2
	exit 2
fi
if ! sed -n 's/^ \* Version:[[:space:]]*//p' "$source_mcp/mcp-adapter.php" | grep -qx '0.5.0'; then
	echo 'The P4 Phase 0 fixture requires MCP Adapter 0.5.0.' >&2
	exit 2
fi

site="$(mktemp -d "${RUNNER_TEMP:-/tmp}/ran-booster-p4-phase-0.XXXXXX")"
database="ran_booster_p4_phase_0_$$"
marker="$site/.ran-booster-p4-disposable-site"
database_created=0

cleanup() {
	if [[ "$database_created" == '1' && -f "$site/wp-config.php" && -f "$marker" ]]; then
		"$php_bin" "$wp_cli" db drop --yes --path="$site" >/dev/null 2>&1 || true
	fi
	if [[ -d "$site" && ! -L "$site" && "$site" == */ran-booster-p4-phase-0.* && -f "$marker" ]]; then
		rm -rf -- "$site"
	fi
}
trap cleanup EXIT

rsync -a --exclude='wp-content' --exclude='wp-config.php' "$source_wordpress/" "$site/"
mkdir -p "$site/wp-content/plugins" "$site/wp-content/mu-plugins" "$site/wp-content/themes"
cp -R "$source_mcp" "$site/wp-content/plugins/mcp-adapter"
cp -R "$core_fixture" "$site/wp-content/plugins/ran-booster-p4-phase-0-core"
cp -R "$addon_fixture" "$site/wp-content/plugins/ran-booster-p4-phase-0-addon"
cp -R "$incompatible_fixture" "$site/wp-content/plugins/ran-booster-p4-phase-0-incompatible-addon"
printf 'RAN Booster P4 disposable test site\n' > "$marker"

db_user="$("$php_bin" "$wp_cli" config get DB_USER --path="$source_wordpress")"
db_password="$("$php_bin" "$wp_cli" config get DB_PASSWORD --path="$source_wordpress")"
db_host="$("$php_bin" "$wp_cli" config get DB_HOST --path="$source_wordpress")"

"$php_bin" "$wp_cli" config create \
	--path="$site" \
	--dbname="$database" \
	--dbuser="$db_user" \
	--dbpass="$db_password" \
	--dbhost="$db_host" \
	--skip-check \
	--quiet
"$php_bin" "$wp_cli" db create --path="$site" --quiet
database_created=1
"$php_bin" "$wp_cli" core install \
	--path="$site" \
	--url='http://p4-phase-0.invalid' \
	--title='RAN Booster P4 Phase 0' \
	--admin_user='admin' \
	--admin_password='p4-disposable-admin-password' \
	--admin_email='p4-admin@example.invalid' \
	--skip-email \
	--quiet
subscriber_id="$("$php_bin" "$wp_cli" user create p4-subscriber p4-subscriber@example.invalid --role=subscriber --porcelain --path="$site")"

export RAN_BOOSTER_P4_WORDPRESS_PATH="$site"
export RAN_BOOSTER_P4_SUBSCRIBER_ID="$subscriber_id"
export RAN_BOOSTER_P4_SECRET_CANARY='p4-secret-canary-must-never-escape'

core_plugin='ran-booster-p4-phase-0-core/ran-booster-p4-phase-0-core.php'
addon_plugin='ran-booster-p4-phase-0-addon/ran-booster-p4-phase-0-addon.php'
incompatible_plugin='ran-booster-p4-phase-0-incompatible-addon/ran-booster-p4-phase-0-incompatible-addon.php'
mcp_plugin='mcp-adapter/mcp-adapter.php'
order_core_first="[\"$core_plugin\",\"$addon_plugin\",\"$incompatible_plugin\",\"$mcp_plugin\"]"
order_addon_first="[\"$addon_plugin\",\"$incompatible_plugin\",\"$core_plugin\",\"$mcp_plugin\"]"

shell_checks=0
assert_equal() {
	local expected="$1"
	local actual="$2"
	local message="$3"
	if [[ "$expected" != "$actual" ]]; then
		echo "$message" >&2
		exit 1
	fi
	shell_checks=$((shell_checks + 1))
}
assert_jq() {
	local file="$1"
	local expression="$2"
	local message="$3"
	if ! jq -e "$expression" "$file" >/dev/null; then
		echo "$message" >&2
		exit 1
	fi
	shell_checks=$((shell_checks + 1))
}
assert_absent() {
	local needle="$1"
	shift
	if grep -Fq "$needle" "$@" 2>/dev/null; then
		echo 'A secret canary escaped the disposable fixture boundary.' >&2
		exit 1
	fi
	shell_checks=$((shell_checks + 1))
}

"$php_bin" "$wp_cli" option update active_plugins "$order_core_first" --format=json --path="$site" --quiet
core_first_json="$site/core-first.json"
"$php_bin" "$wp_cli" eval-file "$proof" --user=admin --path="$site" > "$core_first_json"
assert_jq "$core_first_json" '.wordpress == "7.0.4" and .wp_cli == "2.12.0" and .mcp_adapter == "0.5.0" and .assertions >= 28' 'The Core-first executable contract report is incomplete.'

"$php_bin" "$wp_cli" option update active_plugins "$order_addon_first" --format=json --path="$site" --quiet
addon_first_json="$site/addon-first.json"
"$php_bin" "$wp_cli" eval-file "$proof" --user=admin --path="$site" > "$addon_first_json"
assert_jq "$addon_first_json" '.abilities == ["p4-fixture/read-status", "p4-addon-fixture/read-status"] and .mcp_tools == ["p4-fixture-read-status"]' 'The add-on-first ownership contract failed.'

assert_equal '0.0.0-test-only' "$("$php_bin" "$wp_cli" ran-booster version --path="$site")" 'The Core-owned wp ran-booster root is unavailable.'
assert_equal 'addon-ready' "$("$php_bin" "$wp_cli" ran-booster fixture-addon --path="$site")" 'The nested add-on command contribution is unavailable.'

human_output="$(printf '%s' '{"target":"human-target"}' | "$php_bin" "$wp_cli" ran-booster ability run p4-fixture/read-status --input=- --user=admin --path="$site")"
assert_equal 'P4 fixture human-target: ready' "$human_output" 'Human WP-CLI output changed.'

cli_json="$site/cli.json"
cli_stderr="$site/cli.stderr"
printf '%s' '{"target":"json-target"}' | "$php_bin" "$wp_cli" ran-booster ability run p4-fixture/read-status --input=- --format=json --emit-warning --user=admin --path="$site" > "$cli_json" 2> "$cli_stderr"
assert_jq "$cli_json" '.owner == "core" and .target == "json-target" and .status == "ready" and (.actor | type == "number")' 'JSON WP-CLI output is not one schema-valid object.'
if ! grep -Fq 'P4 fixture warning with no request data.' "$cli_stderr"; then
	echo 'The warning-polluted WP-CLI fixture did not isolate its warning on stderr.' >&2
	exit 1
fi
shell_checks=$((shell_checks + 1))

set +e
printf '%s' '{"target":"denied-target"}' | "$php_bin" "$wp_cli" ran-booster ability run p4-fixture/read-status --input=- --format=json --user=p4-subscriber --path="$site" > "$site/denied.stdout" 2> "$site/denied.stderr"
denied_exit=$?
printf '%s' '{broken-json' | "$php_bin" "$wp_cli" ran-booster ability run p4-fixture/read-status --input=- --format=json --user=admin --path="$site" > "$site/invalid.stdout" 2> "$site/invalid.stderr"
invalid_exit=$?
printf '%s' '{"target":"missing-user"}' | "$php_bin" "$wp_cli" ran-booster ability run p4-fixture/read-status --input=- --format=json --path="$site" > "$site/no-user.stdout" 2> "$site/no-user.stderr"
no_user_exit=$?
set -e
assert_equal '1' "$denied_exit" 'Permission denial did not use the exact non-zero exit.'
assert_equal '1' "$invalid_exit" 'Invalid JSON did not use the exact non-zero exit.'
assert_equal '1' "$no_user_exit" 'Missing explicit user did not use the exact non-zero exit.'

"$php_bin" "$wp_cli" plugin deactivate ran-booster-p4-phase-0-addon --path="$site" --quiet
addon_absent="$("$php_bin" "$wp_cli" eval 'echo wp_has_ability("p4-addon-fixture/read-status") ? "present" : "absent";' --user=admin --path="$site")"
assert_equal 'absent' "$addon_absent" 'The add-on declaration survived deactivation.'
"$php_bin" "$wp_cli" plugin activate ran-booster-p4-phase-0-addon --path="$site" --quiet
addon_restored="$("$php_bin" "$wp_cli" eval 'echo wp_has_ability("p4-addon-fixture/read-status") ? "present" : "absent";' --user=admin --path="$site")"
assert_equal 'present' "$addon_restored" 'The compatible add-on declaration did not return after activation.'
incompatible_absent="$("$php_bin" "$wp_cli" eval 'echo wp_has_ability("p4-incompatible-fixture/read-status") ? "present" : "absent";' --user=admin --path="$site")"
assert_equal 'absent' "$incompatible_absent" 'The exact-incompatible add-on contributed a declaration.'

dedicated_list="$site/mcp-dedicated-list.json"
printf '%s\n' '{"jsonrpc":"2.0","id":1,"method":"tools/list","params":{}}' | "$php_bin" "$wp_cli" mcp-adapter serve --server=ran-booster-p4-read-only --user=admin --path="$site" > "$dedicated_list" 2> "$site/mcp-dedicated-list.stderr"
assert_jq "$dedicated_list" '.result.tools | length == 1 and .[0].name == "p4-fixture-read-status"' 'The dedicated MCP server did not expose exactly one direct read tool.'

dedicated_call="$site/mcp-dedicated-call.json"
printf '%s\n' '{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"p4-fixture-read-status","arguments":{"target":"mcp-target"}}}' | "$php_bin" "$wp_cli" mcp-adapter serve --server=ran-booster-p4-read-only --user=admin --path="$site" > "$dedicated_call" 2> "$site/mcp-dedicated-call.stderr"
assert_jq "$dedicated_call" '.result.structuredContent.owner == "core" and .result.structuredContent.target == "mcp-target" and .result.structuredContent.status == "ready"' 'Dedicated MCP STDIO execution failed.'

subscriber_call="$site/mcp-subscriber-call.json"
printf '%s\n' '{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"p4-fixture-read-status","arguments":{"target":"denied-mcp"}}}' | "$php_bin" "$wp_cli" mcp-adapter serve --server=ran-booster-p4-read-only --user=p4-subscriber --path="$site" > "$subscriber_call" 2> "$site/mcp-subscriber-call.stderr"
assert_jq "$subscriber_call" '.error != null or .result.isError == true' 'STDIO did not enforce the explicit low-privilege WordPress user.'

default_call="$site/mcp-default-call.json"
printf '%s\n' '{"jsonrpc":"2.0","id":4,"method":"tools/call","params":{"name":"mcp-adapter-execute-ability","arguments":{"ability_name":"p4-fixture/read-status","parameters":{"target":"default-denied"}}}}' | "$php_bin" "$wp_cli" mcp-adapter serve --server=mcp-adapter-default-server --user=admin --path="$site" > "$default_call" 2> "$site/mcp-default-call.stderr"
assert_jq "$default_call" '.error != null or .result.isError == true or (.result.structuredContent.success == false)' 'The default generic executor reached an MCP-hidden Booster fixture ability.'

assert_absent "$RAN_BOOSTER_P4_SECRET_CANARY" \
	"$core_first_json" "$addon_first_json" "$cli_json" "$cli_stderr" \
	"$site/denied.stdout" "$site/denied.stderr" "$site/invalid.stdout" "$site/invalid.stderr" \
	"$site/no-user.stdout" "$site/no-user.stderr" "$dedicated_list" "$dedicated_call" \
	"$subscriber_call" "$default_call" "$site/wp-content/debug.log"

php_assertions="$(jq -r '.assertions' "$core_first_json")"
jq -n \
	--argjson php_assertions "$php_assertions" \
	--argjson shell_assertions "$shell_checks" \
	--arg wordpress '7.0.4' \
	--arg wp_cli_version '2.12.0' \
	--arg mcp_adapter '0.5.0' \
	'{result:"GO", php_assertions:$php_assertions, shell_assertions:$shell_assertions, wordpress:$wordpress, wp_cli:$wp_cli_version, mcp_adapter:$mcp_adapter, production_delta:0, phase_1_budget:{production_lines:"140-220", production_types:2, persistent_state:0, public_api_markers:0}}'
