#!/usr/bin/env bash
set -euo pipefail

wordpress="${RAN_BOOSTER_WORDPRESS_PATH:?Set the disposable WordPress path.}"
php_bin="${PHP_BIN:?Set Local PHP 8.2.}"
wp_cli="${WP_CLI_BIN:?Set WP-CLI.}"
fixture_source="${RAN_BOOSTER_EXCLUSIVITY_FIXTURE_SOURCE:?Set the pinned public fixture checkout.}"
pin='521faef4133822f42317b132ae39a2c57e1f82b1'
root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)"
proof="$root/tests/WordPress/repository-exclusivity-fixture.php"
marker="$wordpress/.ran-booster-disposable-test-site"
run_id="fixture-$("$php_bin" -r 'echo bin2hex(random_bytes(8));')"
root_target="$wordpress/wp-content/plugins/$run_id"
nested_target="$wordpress/wp-content/plugins/$run_id-branch"
archive_root="$(mktemp -d "${TMPDIR:-/tmp}/ran-booster-fixture.XXXXXXXX")"

[[ "$("$php_bin" -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')" == '8.2' ]] || { echo 'Local PHP 8.2 is required.' >&2; exit 2; }
[[ -f "$marker" && ! -L "$marker" && "$(<"$marker")" == 'RAN Booster disposable test site' ]] || { echo 'Exact disposable marker required.' >&2; exit 2; }
[[ -f "$wordpress/wp-load.php" && ! -L "$wordpress" && ! -L "$wordpress/wp-content" && ! -L "$wordpress/wp-content/plugins" ]] || { echo 'A real, non-symlinked disposable WordPress root is required.' >&2; exit 2; }
[[ "$(git -C "$fixture_source" rev-parse HEAD)" == "$pin" && -z "$(git -C "$fixture_source" status --porcelain=v1)" ]] || { echo 'Fixture checkout must be clean at the exact pinned commit.' >&2; exit 2; }
[[ ! -e "$root_target" && ! -L "$root_target" && ! -e "$nested_target" && ! -L "$nested_target" ]] || { echo 'Fixture target already exists.' >&2; exit 2; }

cleanup() {
	"$php_bin" "$wp_cli" eval-file "$proof" cleanup "$run_id" "$root_target" "$nested_target" "$archive_root" --user=fixture-admin --path="$wordpress" >/dev/null 2>&1 || true
	rm -rf -- "$archive_root"
}
trap cleanup EXIT

mkdir -p "$root_target" "$nested_target"
cp "$fixture_source/booster-fixture-plugin.php" "$root_target/booster-fixture-plugin.php"
cp "$fixture_source/branch-fixture/booster-fixture-branch.php" "$nested_target/booster-fixture-branch.php"
git -C "$fixture_source" archive --format=zip --output="$archive_root/root-release.zip" "$pin" -- booster-fixture-plugin.php

RAN_BOOSTER_EXCLUSIVITY_FIXTURE_PIN="$pin" "$php_bin" "$wp_cli" eval-file "$proof" run "$run_id" "$root_target" "$nested_target" "$archive_root/root-release.zip" --user=fixture-admin --path="$wordpress"
echo "Repository exclusivity fixture proof passed: pin $pin; assertions emitted by WP-CLI."
