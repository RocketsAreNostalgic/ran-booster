#!/usr/bin/env bash
set -euo pipefail
wordpress="${RAN_BOOSTER_WORDPRESS_PATH:?Set the disposable WordPress path.}"
php_bin="${PHP_BIN:?Set PHP.}"
wp_cli="${WP_CLI_BIN:?Set WP-CLI.}"
root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)"
proof="$root/tests/WordPress/github-replacement-installed-smoke.php"
target="$wordpress/wp-content/plugins/github-replacement"
marker="$wordpress/.ran-booster-disposable-test-site"
[[ -f "$marker" && ! -L "$marker" && "$(<"$marker")" == 'RAN Booster disposable test site' ]] || { echo 'Exact disposable marker required.' >&2; exit 2; }
[[ ! -L "$wordpress" && ! -L "$wordpress/wp-content" && ! -L "$wordpress/wp-content/plugins" && ! -e "$target" && ! -L "$target" ]] || { echo 'Fresh non-symlinked fixture destination required.' >&2; exit 2; }
run_phase() {
 "$php_bin" "$wp_cli" eval-file "$proof" "$@" --user=fixture-admin --path="$wordpress"
}
# Validates ABSPATH, content/plugins roots, siteurl and administrator before copying.
run_phase setup
cp -R "$root/tests/fixtures/github-replacement" "$target"
run_phase activate-a
run_phase reset-counters
run_phase expect-unavailable
run_phase select
run_phase reset-counters
run_phase exercise
run_phase core-first
run_phase reset-counters
run_phase exercise
run_phase replacement-first
run_phase reset-counters
run_phase exercise
run_phase reset-counters
run_phase exercise-temporary
run_phase activate-b
run_phase reset-counters
run_phase expect-unavailable
run_phase select
run_phase reset-counters
run_phase exercise
run_phase conflict
run_phase reset-counters
run_phase expect-unavailable
run_phase incompatible
run_phase reset-counters
run_phase expect-unavailable
run_phase missing
run_phase reset-counters
run_phase expect-unavailable
# Return to bundled requires the same explicit review and protected transition.
run_phase select
run_phase status
# Keep inert fixture files for subsequent consumer phases; never delete unknown targets.
echo 'Installed replacement A/B declaration and transition proof passed.'
