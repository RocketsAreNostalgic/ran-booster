#!/usr/bin/env bash
set -euo pipefail
wordpress="${RAN_BOOSTER_WORDPRESS_PATH:-}"
wp_cli="${WP_CLI_BIN:-wp}"
php_bin="${PHP_BIN:-php}"
if [[ "${RAN_BOOSTER_RECOVERY_TEST_DISPOSABLE:-}" != '1' || -z "$wordpress" || ! -f "$wordpress/wp-load.php" ]]; then
	echo 'The migration writer proof requires an explicitly disposable WordPress installation.' >&2
	exit 2
fi
root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)"
wp_cli="$(command -v "$wp_cli")"
predecessor=fc667dd63737db71a762ef479260849da32a7193
[[ "$(git -C "$root" rev-parse "$predecessor^{commit}")" == "$predecessor" ]]
run_id="$("$php_bin" -r 'echo bin2hex(random_bytes(6));')"
temp_dir="$(mktemp -d "${TMPDIR:-/tmp}/ran-adoption-migration.XXXXXXXX")"
fixture="$root/tests/WordPress/adoption-migration-writer.php"
writer_pid=''
prepared=0
cleanup() {
	local status=$?
	trap - EXIT INT TERM
	if [[ -n "$writer_pid" ]] && kill -0 "$writer_pid" 2>/dev/null; then
		kill "$writer_pid" 2>/dev/null || true
		wait "$writer_pid" 2>/dev/null || true
	fi
	if [[ "$prepared" == 1 ]]; then
		"$php_bin" "$wp_cli" eval-file "$fixture" cleanup "$run_id" "$temp_dir" --path="$wordpress" --skip-plugins --skip-themes || status=1
	fi
	rm -rf -- "$temp_dir"
	exit "$status"
}
trap cleanup EXIT INT TERM
mkdir "$temp_dir/old"
git -C "$root" archive "$predecessor" RAN autoload.php | tar -x -C "$temp_dir/old"
"$php_bin" "$wp_cli" eval-file "$fixture" prepare "$run_id" "$temp_dir" --path="$wordpress" --skip-plugins --skip-themes
prepared=1
"$php_bin" "$wp_cli" eval-file "$fixture" old-writer "$run_id" "$temp_dir" --path="$wordpress" --skip-plugins --skip-themes >"$temp_dir/writer-log" 2>&1 &
writer_pid=$!
deadline=$((SECONDS + 25))
until [[ -f "$temp_dir/ready" ]]; do
	if ! kill -0 "$writer_pid" 2>/dev/null || (( SECONDS >= deadline )); then cat "$temp_dir/writer-log"; exit 1; fi
	sleep 0.05
done
"$php_bin" "$wp_cli" eval-file "$fixture" migrate "$run_id" "$temp_dir" --path="$wordpress" --skip-plugins --skip-themes
[[ ! -f "$temp_dir/rejected" ]]
touch "$temp_dir/release"
wait "$writer_pid" || { cat "$temp_dir/writer-log"; exit 1; }
writer_pid=''
"$php_bin" "$wp_cli" eval-file "$fixture" assert-current "$run_id" "$temp_dir" --path="$wordpress" --skip-plugins --skip-themes
