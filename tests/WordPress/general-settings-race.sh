#!/usr/bin/env bash
set -euo pipefail
wordpress="${RAN_BOOSTER_WORDPRESS_PATH:?Set the validated disposable WordPress path.}"
php_bin="${PHP_BIN:-php}"; wp_cli="${WP_CLI_BIN:-wp}"
test -f "$wordpress/.ran-booster-disposable-test-site" && [[ "$(<"$wordpress/.ran-booster-disposable-test-site")" == 'RAN Booster disposable test site' ]] || { echo 'Exact disposable marker required.' >&2; exit 2; }
test -f "$wordpress/wp-load.php" || exit 2
"$php_bin" "$wp_cli" plugin is-active ran-booster --path="$wordpress" >/dev/null
root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"; proof="$root/tests/WordPress/general-settings-race.php"
temp_dir="$(mktemp -d "${TMPDIR:-/tmp}/ran-general-race.XXXXXXXX")"; runs=()
cleanup(){ for item in "${runs[@]:-}"; do IFS=: read -r id state <<<"$item"; RAN_BOOSTER_GENERAL_RACE_ORDER=saved-first "$php_bin" "$wp_cli" eval-file "$proof" cleanup "$id" "$state" --path="$wordpress" >/dev/null 2>&1 || true; done; rm -rf -- "$temp_dir"; }; trap cleanup EXIT
run_case(){
	local order="$1" state="$2" id ready release result a b
	id="$("$php_bin" -r 'echo bin2hex(random_bytes(12));')"; runs+=("$id:$state")
	RAN_BOOSTER_GENERAL_RACE_ORDER="$order" "$php_bin" "$wp_cli" eval-file "$proof" setup "$id" "$state" --path="$wordpress"
	ready="$temp_dir/$id.ready"; release="$temp_dir/$id.release"; result="$temp_dir/$id.json"
	if [[ "$order" == saved-first ]]; then
		RAN_BOOSTER_GENERAL_RACE_ORDER="$order" "$php_bin" "$wp_cli" eval-file "$proof" save "$id" "$state" "$ready" "$release" "$result" --path="$wordpress" & a=$!
		until [[ -e "$ready" ]]; do kill -0 "$a" 2>/dev/null || { wait "$a"; return; }; sleep .05; done
		RAN_BOOSTER_GENERAL_RACE_ORDER="$order" "$php_bin" "$wp_cli" eval-file "$proof" adopt "$id" "$state" '' '' "$result" --path="$wordpress" & b=$!
		touch "$release"; wait "$a"; wait "$b"
	else
		RAN_BOOSTER_GENERAL_RACE_ORDER="$order" "$php_bin" "$wp_cli" eval-file "$proof" adopt "$id" "$state" "$ready" "$release" "$result" --path="$wordpress" & a=$!
		until [[ -e "$ready" ]]; do kill -0 "$a" 2>/dev/null || { wait "$a"; return; }; sleep .05; done
		RAN_BOOSTER_GENERAL_RACE_ORDER="$order" "$php_bin" "$wp_cli" eval-file "$proof" save "$id" "$state" '' '' "$result" --path="$wordpress" & b=$!
		touch "$release"; wait "$a"; wait "$b"
	fi
	RAN_BOOSTER_GENERAL_RACE_ORDER="$order" "$php_bin" "$wp_cli" eval-file "$proof" assert "$id" "$state" '' '' "$result" --path="$wordpress"
	echo "$order/$state: save=$(<"$result.save") adopt=$(<"$result.adopt")"
	RAN_BOOSTER_GENERAL_RACE_ORDER=saved-first "$php_bin" "$wp_cli" eval-file "$proof" cleanup "$id" "$state" --path="$wordpress"
}
for state in absent false true; do run_case saved-first "$state"; run_case managed-first "$state"; done
echo 'General Settings/confirmed GitHub-management MySQL race proof passed.'
