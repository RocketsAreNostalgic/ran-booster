#!/usr/bin/env bash
set -euo pipefail
wordpress="${RAN_BOOSTER_WORDPRESS_PATH:-}"
wp_cli="${WP_CLI_BIN:-wp}"
php_bin="${PHP_BIN:-php}"
if [[ "${RAN_BOOSTER_RECOVERY_TEST_DISPOSABLE:-}" != '1' || -z "$wordpress" || ! -f "$wordpress/wp-load.php" ]]; then
	echo 'The recovery proof requires an explicitly disposable WordPress installation.' >&2
	exit 2
fi
root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)"
wp_cli="$(command -v "$wp_cli")"
"$php_bin" "$wp_cli" plugin is-active ran-booster --path="$wordpress"
"$php_bin" "$wp_cli" eval-file "$root/tests/WordPress/adoption-recovery-smoke.php" --path="$wordpress"

race="$root/tests/WordPress/adoption-recovery-race.php"
for winner in recovery admission native-recovery native-sibling native-commit-lost; do
	prepare=prepare
	cleanup_action=cleanup
	[[ "$winner" == native-* ]] && prepare=prepare-native && cleanup_action=cleanup-native
	run_id="$("$php_bin" -r 'echo bin2hex(random_bytes(6));')"
	temp_dir="$(mktemp -d "${TMPDIR:-/tmp}/ran-recovery-race.XXXXXXXX")"
	owner_pid=''
	peer_pid=''
	cleanup() {
		local status=$?
		trap - EXIT INT TERM
		for pid in "$owner_pid" "$peer_pid"; do
			if [[ -n "$pid" ]] && kill -0 "$pid" 2>/dev/null; then
				kill "$pid" 2>/dev/null || true
				wait "$pid" 2>/dev/null || true
			fi
		done
		"$php_bin" "$wp_cli" eval-file "$race" "$cleanup_action" "$run_id" "$temp_dir" --path="$wordpress" || status=1
		rm -rf -- "$temp_dir"
		exit "$status"
	}
	trap cleanup EXIT INT TERM
	"$php_bin" "$wp_cli" eval-file "$race" "$prepare" "$run_id" "$temp_dir" --path="$wordpress"
	if [[ "$winner" == native-commit-lost ]]; then
		"$php_bin" "$wp_cli" eval-file "$race" native-commit-lost "$run_id" "$temp_dir" --path="$wordpress"
		"$php_bin" "$wp_cli" eval-file "$race" assert-native-commit-lost "$run_id" "$temp_dir" --path="$wordpress"
		"$php_bin" "$wp_cli" eval-file "$race" "$cleanup_action" "$run_id" "$temp_dir" --path="$wordpress"
		trap - EXIT INT TERM
		rm -rf -- "$temp_dir"
		continue
	fi
	"$php_bin" "$wp_cli" eval-file "$race" "$winner-owner" "$run_id" "$temp_dir" --path="$wordpress" >"$temp_dir/owner-log" 2>&1 &
	owner_pid=$!
	deadline=$((SECONDS + 20))
	until [[ -f "$temp_dir/locked" ]]; do
		if ! kill -0 "$owner_pid" 2>/dev/null || (( SECONDS >= deadline )); then cat "$temp_dir/owner-log"; exit 1; fi
		sleep 0.05
	done
	loser=admission
	[[ "$winner" == admission ]] && loser=recovery
	[[ "$winner" == native-recovery ]] && loser=native-sibling
	[[ "$winner" == native-sibling ]] && loser=native-recovery
	"$php_bin" "$wp_cli" eval-file "$race" "$loser-peer" "$run_id" "$temp_dir" --path="$wordpress" >"$temp_dir/peer-log" 2>&1 &
	peer_pid=$!
	until [[ -f "$temp_dir/attempting" ]]; do
		if ! kill -0 "$peer_pid" 2>/dev/null || (( SECONDS >= deadline )); then cat "$temp_dir/peer-log"; exit 1; fi
		sleep 0.05
	done
	[[ ! -f "$temp_dir/peer-result" ]]
	touch "$temp_dir/release"
	wait "$owner_pid" || { cat "$temp_dir/owner-log"; exit 1; }
	wait "$peer_pid" || { cat "$temp_dir/peer-log"; exit 1; }
	owner_pid=''
	peer_pid=''
	"$php_bin" "$wp_cli" eval-file "$race" "assert-$winner" "$run_id" "$temp_dir" --path="$wordpress"
	"$php_bin" "$wp_cli" eval-file "$race" "$cleanup_action" "$run_id" "$temp_dir" --path="$wordpress"
	trap - EXIT INT TERM
	rm -rf -- "$temp_dir"
done
