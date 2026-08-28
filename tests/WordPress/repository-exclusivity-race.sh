#!/usr/bin/env bash
set -euo pipefail
wordpress="${RAN_BOOSTER_WORDPRESS_PATH:?Set the validated disposable WordPress path.}"
php_bin="${PHP_BIN:-php}"; wp_cli="${WP_CLI_BIN:-wp}"
test -f "$wordpress/.ran-booster-disposable-test-site" && [[ "$(<"$wordpress/.ran-booster-disposable-test-site")" == 'RAN Booster disposable test site' ]] || { echo 'Exact disposable marker required.' >&2; exit 2; }
test -f "$wordpress/wp-load.php" || exit 2
root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"; proof="$root/tests/WordPress/repository-exclusivity-race.php"
temp_dir="$(mktemp -d "${TMPDIR:-/tmp}/ran-booster-exclusivity.XXXXXXXX")"; runs=()
cleanup(){ for run_id in "${runs[@]:-}"; do "$php_bin" "$wp_cli" eval-file "$proof" cleanup "$run_id" --path="$wordpress" >/dev/null 2>&1 || true; done; rm -rf -- "$temp_dir"; }; trap cleanup EXIT
run_race(){
	local order="$1" run_id ready_a="$temp_dir/$1-a-ready" ready_b="$temp_dir/$1-b-ready" release="$temp_dir/$1-release" result_a="$temp_dir/$1-a.json" result_b="$temp_dir/$1-b.json"
	run_id="$("$php_bin" -r 'echo bin2hex(random_bytes(12));')"; runs+=("$run_id")
	"$php_bin" "$wp_cli" eval-file "$proof" setup "$run_id" --path="$wordpress"
	if [[ "$order" == 'branch-first' ]]; then
		"$php_bin" "$wp_cli" eval-file "$proof" branch "$run_id" "$ready_a" "$release" "$result_a" --path="$wordpress" & a=$!
		until [[ -e "$ready_a" ]]; do sleep .05; done; touch "$release"; wait "$a"
		"$php_bin" "$wp_cli" eval-file "$proof" release "$run_id" "$ready_b" "$release" "$result_b" --path="$wordpress" & b=$!; wait "$b"
	elif [[ "$order" == 'release-first' ]]; then
		"$php_bin" "$wp_cli" eval-file "$proof" release "$run_id" "$ready_a" "$release" "$result_a" --path="$wordpress" & a=$!
		until [[ -e "$ready_a" ]]; do sleep .05; done; touch "$release"; wait "$a"
		"$php_bin" "$wp_cli" eval-file "$proof" branch "$run_id" "$ready_b" "$release" "$result_b" --path="$wordpress" & b=$!; wait "$b"
	else
		"$php_bin" "$wp_cli" eval-file "$proof" branch "$run_id" "$ready_a" "$release" "$result_a" --path="$wordpress" & a=$!
		"$php_bin" "$wp_cli" eval-file "$proof" release "$run_id" "$ready_b" "$release" "$result_b" --path="$wordpress" & b=$!
		until [[ -e "$ready_a" && -e "$ready_b" ]]; do sleep .05; done; touch "$release"; wait "$a"; wait "$b"
	fi
	"$php_bin" "$wp_cli" eval-file "$proof" assert "$run_id" "$result_a" "$result_b" --path="$wordpress"
}
run_race branch-first
run_race release-first
run_race simultaneous
echo 'Repository-exclusivity race proof passed: both winner orders and simultaneous admission.'
