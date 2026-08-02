#!/usr/bin/env bash

set -euo pipefail

wordpress="${RAN_BOOSTER_WORDPRESS_PATH:-}"
wp_cli="${WP_CLI_BIN:-wp}"
php_bin="${PHP_BIN:-php}"

if [[ -z "$wordpress" || ! -f "$wordpress/wp-load.php" ]]; then
	echo 'Set RAN_BOOSTER_WORDPRESS_PATH to a disposable WordPress installation.' >&2
	exit 2
fi

if ! command -v "$wp_cli" >/dev/null 2>&1; then
	echo 'WP-CLI is required for the delivery-intake race proof.' >&2
	exit 2
fi
if ! command -v "$php_bin" >/dev/null 2>&1; then
	echo 'PHP is required for the delivery-intake race proof.' >&2
	exit 2
fi
wp_cli="$(command -v "$wp_cli")"

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
control="$root/tests/WordPress/delivery-intake-race-control.php"
child="$root/tests/WordPress/delivery-intake-race-child.php"
run_id="$("$php_bin" -r 'echo bin2hex(random_bytes(12));')"
temp_dir="$(mktemp -d "${TMPDIR:-/tmp}/ran-booster-delivery-race.XXXXXXXX")"
ready_a="$temp_dir/ready-a"
ready_b="$temp_dir/ready-b"
release_marker="$temp_dir/release"
result_a="$temp_dir/result-a.json"
result_b="$temp_dir/result-b.json"
log_a="$temp_dir/child-a.log"
log_b="$temp_dir/child-b.log"
pid_a=''
pid_b=''

cleanup() {
	local status=$?
	trap - EXIT INT TERM
	for pid in "$pid_a" "$pid_b"; do
		if [[ -n "$pid" ]] && kill -0 "$pid" 2>/dev/null; then
			kill -KILL "$pid" 2>/dev/null || true
			wait "$pid" 2>/dev/null || true
		fi
	done
	"$php_bin" "$wp_cli" eval-file "$control" cleanup "$run_id" --path="$wordpress" >/dev/null 2>&1 || true
	rm -rf -- "$temp_dir"
	exit "$status"
}
trap cleanup EXIT INT TERM

"$php_bin" "$wp_cli" plugin is-active ran-booster --path="$wordpress"
"$php_bin" "$wp_cli" eval-file "$control" cleanup "$run_id" --path="$wordpress" >/dev/null
cron_before="$("$php_bin" "$wp_cli" eval-file "$control" cron-state "$run_id" --path="$wordpress")"

"$php_bin" "$wp_cli" eval-file "$child" a "$run_id" "$ready_a" "$release_marker" "$result_a" --path="$wordpress" >"$log_a" 2>&1 &
pid_a=$!
"$php_bin" "$wp_cli" eval-file "$child" b "$run_id" "$ready_b" "$release_marker" "$result_b" --path="$wordpress" >"$log_b" 2>&1 &
pid_b=$!

both_ready='false'
for _ in {1..150}; do
	if [[ -e "$ready_a" && -e "$ready_b" ]]; then
		both_ready='true'
		break
	fi
	if ! kill -0 "$pid_a" 2>/dev/null; then
		cat "$log_a" >&2
		echo 'Delivery-intake child A exited before the admission barrier.' >&2
		exit 1
	fi
	if ! kill -0 "$pid_b" 2>/dev/null; then
		cat "$log_b" >&2
		echo 'Delivery-intake child B exited before the admission barrier.' >&2
		exit 1
	fi
	sleep 0.1
done

if [[ 'true' != "$both_ready" ]]; then
	cat "$log_a" >&2
	cat "$log_b" >&2
	echo 'Both delivery-intake children did not reach the admission barrier within fifteen seconds.' >&2
	exit 1
fi

# The PHP expression is intentionally passed literally to the selected runtime.
# shellcheck disable=SC2016
"$php_bin" -r '$path = $argv[1]; $file = fopen($path, "x"); if (false === $file) { exit(1); } fwrite($file, "release\n"); fflush($file); fclose($file);' "$release_marker"

if ! wait "$pid_a"; then
	cat "$log_a" >&2
	echo 'Delivery-intake child A failed after the race was released.' >&2
	exit 1
fi
pid_a=''
if ! wait "$pid_b"; then
	cat "$log_b" >&2
	echo 'Delivery-intake child B failed after the race was released.' >&2
	exit 1
fi
pid_b=''

test -s "$result_a"
test -s "$result_b"
"$php_bin" "$wp_cli" eval-file "$control" assert "$run_id" "$result_a" "$result_b" --path="$wordpress"

cron_after="$("$php_bin" "$wp_cli" eval-file "$control" cron-state "$run_id" --path="$wordpress")"
if [[ "$cron_before" != "$cron_after" ]]; then
	echo 'Concurrent repository intake changed the deployment worker cron state.' >&2
	exit 1
fi

echo 'Delivery-intake race proof passed: concurrent admission converged on one immutable target set without partial rows.'
