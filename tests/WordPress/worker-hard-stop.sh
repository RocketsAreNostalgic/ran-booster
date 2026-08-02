#!/usr/bin/env bash

set -euo pipefail

wordpress="${RAN_BOOSTER_WORDPRESS_PATH:-}"
wp_cli="${WP_CLI_BIN:-wp}"
php_bin="${PHP_BIN:-php}"
if [[ -z "$wordpress" || ! -f "$wordpress/wp-load.php" ]]; then
	echo 'Set RAN_BOOSTER_WORDPRESS_PATH to a disposable WordPress installation.' >&2
	exit 2
fi
wp_cli="$(command -v "$wp_cli")"
root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
control="$root/tests/WordPress/worker-hard-stop-control.php"
child="$root/tests/WordPress/worker-hard-stop-child.php"
contender="$root/tests/WordPress/worker-hard-stop-contender.php"
run_id="$("$php_bin" -r 'echo bin2hex(random_bytes(12));')"
temp_dir="$(mktemp -d "${TMPDIR:-/tmp}/ran-booster-hard-stop.XXXXXXXX")"
child_pid=''

cleanup() {
	local status=$?
	trap - EXIT INT TERM
	if [[ -n "$child_pid" ]] && kill -0 "$child_pid" 2>/dev/null; then kill -KILL "$child_pid" 2>/dev/null || true; wait "$child_pid" 2>/dev/null || true; fi
	for phase in pre post foreign; do "$php_bin" "$wp_cli" eval-file "$control" cleanup "$run_id" "$phase" --path="$wordpress" >/dev/null 2>&1 || status=1; done
	rm -rf -- "$temp_dir"
	exit "$status"
}
trap cleanup EXIT INT TERM

"$php_bin" "$wp_cli" plugin is-active ran-booster --path="$wordpress"
for phase in pre post foreign; do
	barrier="$temp_dir/$phase-barrier"
	contended="$temp_dir/$phase-contended"
	log="$temp_dir/$phase-child.log"
	"$php_bin" "$wp_cli" eval-file "$control" cleanup "$run_id" "$phase" --path="$wordpress" >/dev/null
	"$php_bin" "$wp_cli" eval-file "$control" seed "$run_id" "$phase" --path="$wordpress"
	"$php_bin" "$wp_cli" eval-file "$child" "$phase" "$barrier" --path="$wordpress" >"$log" 2>&1 &
	child_pid=$!
	for _ in {1..100}; do [[ -e "$barrier" ]] && break; kill -0 "$child_pid" 2>/dev/null || { cat "$log" >&2; exit 1; }; sleep 0.1; done
	[[ -e "$barrier" ]] || { cat "$log" >&2; echo "The $phase-fence child did not reach its barrier." >&2; exit 1; }
	kill -KILL "$child_pid"
	set +e; wait "$child_pid"; status=$?; set -e; child_pid=''
	[[ "$status" -eq 137 ]] || { echo "Expected SIGKILL status 137, got $status." >&2; exit 1; }
	"$php_bin" "$wp_cli" eval-file "$control" assert-retained "$run_id" "$phase" --path="$wordpress"
	if [[ 'foreign' == "$phase" ]]; then
		"$php_bin" "$wp_cli" eval-file "$control" replace-core-lock "$run_id" "$phase" --path="$wordpress"
	fi
	"$php_bin" "$wp_cli" eval-file "$contender" "$phase" "$contended" --path="$wordpress"
	if [[ 'pre' == "$phase" ]]; then
		grep -q '^claimed:.*:core-lock-available$' "$contended"
	else
		grep -q '^claimed:.*:core-lock-contended$' "$contended"
	fi
	"$php_bin" "$wp_cli" eval-file "$control" reconcile "$run_id" "$phase" --path="$wordpress"
	"$php_bin" "$wp_cli" eval-file "$control" cleanup "$run_id" "$phase" --path="$wordpress"
done

echo 'Hard-stop proof passed: attempts can claim independently, native lock contention is explicit, and reconciliation never removes the updater lock.'
