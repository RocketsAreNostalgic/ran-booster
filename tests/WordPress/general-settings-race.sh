#!/usr/bin/env bash
set -euo pipefail
wordpress="${RAN_BOOSTER_WORDPRESS_PATH:?Set the validated disposable WordPress path.}"
php_bin="${PHP_BIN:-php}"; wp_cli="${WP_CLI_BIN:-wp}"
test -f "$wordpress/.ran-booster-disposable-test-site" && [[ "$(<"$wordpress/.ran-booster-disposable-test-site")" == 'RAN Booster disposable test site' ]] || { echo 'Exact disposable marker required.' >&2; exit 2; }
test -f "$wordpress/wp-load.php" || exit 2
if ! command -v "$php_bin" >/dev/null 2>&1 || ! command -v "$wp_cli" >/dev/null 2>&1; then
	echo 'WP-CLI and PHP are required for the General Settings race proof.' >&2
	exit 2
fi
php_bin="$(command -v "$php_bin")"; wp_cli="$(command -v "$wp_cli")"
if [[ ! -x "$php_bin" || ! -x "$wp_cli" ]]; then echo 'WP-CLI and PHP must be executable files.' >&2; exit 2; fi
"$php_bin" "$wp_cli" plugin is-active ran-booster --path="$wordpress" >/dev/null
root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"; proof="$root/tests/WordPress/general-settings-race.php"
temp_dir="$(mktemp -d "${TMPDIR:-/tmp}/ran-general-race.XXXXXXXX")"; runs=()
pid_a=''; pid_b=''
cleanup(){
	local status=$? pid item id state
	trap - EXIT INT TERM
	for pid in "$pid_a" "$pid_b"; do
		if [[ -n "$pid" ]]; then
			if kill -0 "$pid" 2>/dev/null; then kill -KILL "$pid" 2>/dev/null || true; fi
			wait "$pid" 2>/dev/null || true
		fi
	done
	for item in "${runs[@]:-}"; do
		[[ -n "$item" ]] || continue
		IFS=: read -r id state <<<"$item"
		RAN_BOOSTER_GENERAL_RACE_ORDER=saved-first "$php_bin" "$wp_cli" eval-file "$proof" cleanup "$id" "$state" --path="$wordpress" >/dev/null 2>&1 || { [[ "$status" -ne 0 ]] || status=1; }
	done
	rm -rf -- "$temp_dir" || { [[ "$status" -ne 0 ]] || status=1; }
	exit "$status"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
wait_ready(){
	local ready="$1" pid="$2" other="${3:-}" attempt
	for (( attempt=0; attempt<300; attempt++ )); do
		[[ -e "$ready" ]] && return 0
		if ! kill -0 "$pid" 2>/dev/null || { [[ -n "$other" ]] && ! kill -0 "$other" 2>/dev/null; }; then
			if ! kill -0 "$pid" 2>/dev/null; then wait "$pid" || true; fi
			echo 'A race participant exited before the barrier.' >&2
			return 1
		fi
		sleep .05
	done
	echo 'A race participant did not reach the barrier.' >&2
	return 1
}
run_case(){
	local order="$1" state="$2" id ready release result attempt observation
	id="$("$php_bin" -r 'echo bin2hex(random_bytes(12));')"; runs+=("$id:$state")
	RAN_BOOSTER_GENERAL_RACE_ORDER="$order" "$php_bin" "$wp_cli" eval-file "$proof" setup "$id" "$state" --path="$wordpress"
	ready="$temp_dir/$id.ready"; release="$temp_dir/$id.release"; result="$temp_dir/$id.json"; attempt="$temp_dir/$id.attempt"
	if [[ "$order" == saved-first ]]; then
		RAN_BOOSTER_GENERAL_RACE_ORDER="$order" "$php_bin" "$wp_cli" eval-file "$proof" save "$id" "$state" "$ready" "$release" "$result" --path="$wordpress" & pid_a=$!
		wait_ready "$ready" "$pid_a"
		RAN_BOOSTER_GENERAL_RACE_ORDER="$order" RAN_BOOSTER_GENERAL_RACE_ATTEMPT="$attempt" "$php_bin" "$wp_cli" eval-file "$proof" adopt "$id" "$state" '' '' "$result" --path="$wordpress" & pid_b=$!
		wait_ready "$attempt" "$pid_b" "$pid_a"
		for (( observation=0; observation<5; observation++ )); do
			if ! kill -0 "$pid_a" 2>/dev/null || ! kill -0 "$pid_b" 2>/dev/null || [[ -e "$result.save" || -e "$result.adopt" ]]; then
				echo 'A race participant completed before release.' >&2; return 1
			fi
			sleep .05
		done
		touch "$release"; wait "$pid_a"; pid_a=''; wait "$pid_b"; pid_b=''
	else
		RAN_BOOSTER_GENERAL_RACE_ORDER="$order" "$php_bin" "$wp_cli" eval-file "$proof" adopt "$id" "$state" "$ready" "$release" "$result" --path="$wordpress" & pid_a=$!
		wait_ready "$ready" "$pid_a"
		RAN_BOOSTER_GENERAL_RACE_ORDER="$order" RAN_BOOSTER_GENERAL_RACE_ATTEMPT="$attempt" "$php_bin" "$wp_cli" eval-file "$proof" save "$id" "$state" '' '' "$result" --path="$wordpress" & pid_b=$!
		wait_ready "$attempt" "$pid_b" "$pid_a"
		for (( observation=0; observation<5; observation++ )); do
			if ! kill -0 "$pid_a" 2>/dev/null || ! kill -0 "$pid_b" 2>/dev/null || [[ -e "$result.save" || -e "$result.adopt" ]]; then
				echo 'A race participant completed before release.' >&2; return 1
			fi
			sleep .05
		done
		touch "$release"; wait "$pid_a"; pid_a=''; wait "$pid_b"; pid_b=''
	fi
	RAN_BOOSTER_GENERAL_RACE_ORDER="$order" "$php_bin" "$wp_cli" eval-file "$proof" assert "$id" "$state" '' '' "$result" --path="$wordpress"
	echo "$order/$state: save=$(<"$result.save") adopt=$(<"$result.adopt")"
	RAN_BOOSTER_GENERAL_RACE_ORDER=saved-first "$php_bin" "$wp_cli" eval-file "$proof" cleanup "$id" "$state" --path="$wordpress"
}
for state in absent false true; do run_case saved-first "$state"; run_case managed-first "$state"; done
echo 'General Settings/confirmed GitHub-management MySQL race proof passed.'
