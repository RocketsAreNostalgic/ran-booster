#!/usr/bin/env bash
# Exercise the exact published Example through Core's installed adoption boundary.
set -euo pipefail
wordpress="${RAN_BOOSTER_WORDPRESS_PATH:?Set the exact disposable WordPress path.}"
php_bin="${PHP_BIN:?Set PHP.}"
wp_cli="${WP_CLI_BIN:?Set WP-CLI.}"
[[ "${RAN_BOOSTER_GITHUB_EXAMPLE_DISPOSABLE:-}" == 1 ]] || { echo 'Example proof requires explicit disposable consent.' >&2; exit 2; }
root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)"
proof="$root/tests/WordPress/github-example-installed-smoke.php"
RAN_BOOSTER_EXAMPLE_EXPECTED_VERSION="$(python3 -c 'import json,sys; print(json.load(open(sys.argv[1]))["version"])' "$root/tests/fixtures/github-example.json")"
export RAN_BOOSTER_EXAMPLE_EXPECTED_VERSION
source_plugin="$root/vendor/.test-fixtures/ran-booster-github-example"
target="$wordpress/wp-content/plugins/ran-booster-github-example"
marker="$wordpress/.ran-booster-disposable-test-site"
[[ -f "$marker" && ! -L "$marker" && "$(<"$marker")" == 'RAN Booster disposable test site' ]] || { echo 'Exact disposable marker required.' >&2; exit 2; }
[[ ! -L "$wordpress" && ! -L "$wordpress/wp-content" && ! -L "$wordpress/wp-content/plugins" && ! -e "$target" && ! -L "$target" ]] || { echo 'Fresh non-symlinked Example destination required.' >&2; exit 2; }
[[ -d "$source_plugin" && ! -L "$source_plugin" ]] || { echo 'Prepare the pinned published Example first.' >&2; exit 2; }
run_phase() { "$php_bin" "$wp_cli" eval-file "$proof" "$@" --use-include --user=fixture-admin --path="$wordpress"; }
# The PHP guard verifies exact ABSPATH, roots, localhost URL, marker and admin
# before any plugin bytes or active-plugin state are changed.
RAN_BOOSTER_EXAMPLE_ORIGINAL_ACTIVE="$(run_phase setup)"
export RAN_BOOSTER_EXAMPLE_ORIGINAL_ACTIVE
cp -R "$source_plugin" "$target"
diff -qr "$source_plugin" "$target"
restore_selection() {
  run_phase deactivate --skip-plugins=ran-booster-github-example &&
    run_phase select-bundled && run_phase restore-order && run_phase expect-bundled
}
trap restore_selection EXIT
run_phase activate
run_phase expect-unavailable
run_phase select-example
for order in core-first example-first; do
  run_phase "$order"
  run_phase exercise
done
restore_selection
trap - EXIT
diff -qr "$source_plugin" "$target"
echo 'Published Example installed adoption, real anonymous operations, both load orders and explicit bundled return passed.'
