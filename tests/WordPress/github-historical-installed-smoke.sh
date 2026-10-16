#!/usr/bin/env bash
set -euo pipefail
: "${RAN_BOOSTER_WORDPRESS_PATH:?Set the exact disposable site path.}"
: "${PHP_BIN:?Set PHP.}"
: "${WP_CLI_BIN:?Set WP-CLI.}"
[[ "${RAN_BOOSTER_GITHUB_HISTORICAL_DISPOSABLE:-}" == 1 ]] || { echo 'Historical proof requires explicit disposable consent.' >&2; exit 2; }
root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)"
phase="$root/tests/WordPress/github-replacement-installed-smoke.php"
proof="$root/tests/WordPress/github-historical-installed-smoke.php"
# Run the shared fixture shell first. It validates and installs A/B, then returns
# to bundled GitHub, leaving inert fixture files for these consumer phases.
bash "$root/tests/WordPress/github-replacement-installed-smoke.sh"
run_phase() { "$PHP_BIN" "$WP_CLI_BIN" eval-file "$phase" "$@" --user=fixture-admin --path="$RAN_BOOSTER_WORDPRESS_PATH"; }
restore_selection() {
 unset RAN_BOOSTER_GITHUB_HISTORICAL_MODE
 run_phase missing && run_phase select
}
trap restore_selection EXIT
run_phase activate-a
run_phase select
for mode in unsupported wrong-commit success; do
 export RAN_BOOSTER_GITHUB_HISTORICAL_MODE="$mode"
 "$PHP_BIN" "$WP_CLI_BIN" eval-file "$proof" "$mode" --user=fixture-admin --path="$RAN_BOOSTER_WORDPRESS_PATH"
done
unset RAN_BOOSTER_GITHUB_HISTORICAL_MODE
restore_selection
trap - EXIT
