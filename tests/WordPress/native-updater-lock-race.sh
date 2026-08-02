#!/usr/bin/env bash

set -euo pipefail

wordpress="${RAN_BOOSTER_WORDPRESS_PATH:-}"
wp_cli="${WP_CLI_BIN:-wp}"
php_bin="${PHP_BIN:-php}"
if [[ "${RAN_BOOSTER_LOCK_RACE_TEST_DISPOSABLE:-}" != '1' ]]; then
	echo 'Set RAN_BOOSTER_LOCK_RACE_TEST_DISPOSABLE=1 only for an isolated disposable WordPress installation.' >&2
	exit 2
fi
if [[ -z "$wordpress" || ! -f "$wordpress/wp-load.php" ]]; then
	echo 'Set RAN_BOOSTER_WORDPRESS_PATH to a disposable WordPress installation.' >&2
	exit 2
fi
if ! command -v "$wp_cli" >/dev/null 2>&1 || ! command -v "$php_bin" >/dev/null 2>&1; then
	echo 'WP-CLI and PHP are required for the native-lock race proof.' >&2
	exit 2
fi

wp_cli="$(command -v "$wp_cli")"
wordpress="$(cd "$wordpress" && pwd -P)"
root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)"
proof="$root/tests/WordPress/native-updater-lock-race.php"
disposable_marker="$wordpress/.ran-booster-disposable-test-site"
if [[ -L "$disposable_marker" || ! -f "$disposable_marker" ]] ||
	[[ "$(<"$disposable_marker")" != 'RAN Booster disposable test site' ]]; then
	echo "The native-lock proof requires $disposable_marker with the expected disposable-site marker." >&2
	exit 2
fi
if [[ "$("$php_bin" -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')" != '8.2' ]]; then
	echo 'The native-lock race proof requires PHP 8.2.' >&2
	exit 2
fi

run_id="$("$php_bin" -r 'echo bin2hex(random_bytes(12));')"
temp_dir="$(mktemp -d "${TMPDIR:-/tmp}/ran-booster-native-lock.XXXXXXXX")"
ready_a="$temp_dir/ready-a"
ready_b="$temp_dir/ready-b"
release_marker="$temp_dir/release"
result_a="$temp_dir/result-a.json"
result_b="$temp_dir/result-b.json"
log_a="$temp_dir/child-a.log"
log_b="$temp_dir/child-b.log"
pid_a=''
pid_b=''
original_engine=''

cleanup() {
	local status=$?
	trap - EXIT INT TERM
	for pid in "$pid_a" "$pid_b"; do
		if [[ -n "$pid" ]] && kill -0 "$pid" 2>/dev/null; then
			kill -KILL "$pid" 2>/dev/null || true
			wait "$pid" 2>/dev/null || true
		fi
	done
	"$php_bin" "$wp_cli" eval-file "$proof" cleanup "$run_id" --path="$wordpress" >/dev/null 2>&1 || status=1
	if [[ -n "$original_engine" ]]; then
		"$php_bin" "$wp_cli" eval-file "$proof" set-engine "$run_id" "$original_engine" --path="$wordpress" >/dev/null 2>&1 || status=1
	fi
	rm -rf -- "$temp_dir"
	exit "$status"
}
trap cleanup EXIT INT TERM

"$php_bin" "$wp_cli" plugin is-active ran-booster --path="$wordpress"
original_engine="$("$php_bin" "$wp_cli" eval-file "$proof" engine "$run_id" --path="$wordpress")"
if [[ "$original_engine" != 'InnoDB' && "$original_engine" != 'MyISAM' ]]; then
	echo "Unsupported original wp_options engine: $original_engine" >&2
	exit 1
fi
"$php_bin" "$wp_cli" eval-file "$proof" cleanup "$run_id" --path="$wordpress"
"$php_bin" "$wp_cli" eval-file "$proof" set-engine "$run_id" MyISAM --path="$wordpress"
"$php_bin" "$wp_cli" eval-file "$proof" prepare "$run_id" --path="$wordpress"

"$php_bin" "$wp_cli" eval-file "$proof" child "$run_id" a "$ready_a" "$release_marker" "$result_a" --path="$wordpress" >"$log_a" 2>&1 &
pid_a=$!
"$php_bin" "$wp_cli" eval-file "$proof" child "$run_id" b "$ready_b" "$release_marker" "$result_b" --path="$wordpress" >"$log_b" 2>&1 &
pid_b=$!

for _ in {1..150}; do
	if [[ -e "$ready_a" && -e "$ready_b" ]]; then
		break
	fi
	for pid in "$pid_a" "$pid_b"; do
		if ! kill -0 "$pid" 2>/dev/null; then
			cat "$log_a" >&2
			cat "$log_b" >&2
			echo 'A native-lock participant exited before the barrier.' >&2
			exit 1
		fi
	done
	sleep 0.1
done
if [[ ! -e "$ready_a" || ! -e "$ready_b" ]]; then
	echo 'Both native-lock participants did not reach the barrier.' >&2
	exit 1
fi

# The PHP expression is intentionally passed literally to the selected runtime.
# shellcheck disable=SC2016
"$php_bin" -r '$file = fopen($argv[1], "x"); if (false === $file) { exit(1); } fwrite($file, "release\n"); fclose($file);' "$release_marker"
if ! wait "$pid_a"; then cat "$log_a" >&2; exit 1; fi
pid_a=''
if ! wait "$pid_b"; then cat "$log_b" >&2; exit 1; fi
pid_b=''

"$php_bin" "$wp_cli" eval-file "$proof" assert "$run_id" '' "$result_a" "$result_b" --path="$wordpress"

echo 'Native updater-lock race proof passed on MyISAM wp_options.'
