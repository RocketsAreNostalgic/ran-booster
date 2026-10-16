#!/usr/bin/env bash
set -euo pipefail
fail() { printf 'bitbucket-selection-installed: %s\n' "$*" >&2; exit 2; }
[[ "${RAN_BOOSTER_BITBUCKET_TEST_DISPOSABLE:-}" == 1 ]] || fail 'Explicit disposable-site consent is required.'
wordpress=${RAN_BOOSTER_WORDPRESS_PATH:?Set the exact disposable site path.}
archive=${RAN_BOOSTER_BITBUCKET_ARCHIVE:?Set the immutable beta17 ZIP path.}
php_bin=${PHP_BIN:?Set PHP.}
wp_cli=${WP_CLI_BIN:?Set WP-CLI.}
root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)"
phase="$root/tests/WordPress/github-replacement-installed-smoke.php"
proof="$root/tests/WordPress/bitbucket-selection-installed-smoke.php"
fixture="$wordpress/wp-content/plugins/github-replacement"
target="$wordpress/wp-content/plugins/ran-booster-bitbucket"
marker="$wordpress/.ran-booster-disposable-test-site"
for directory in "$wordpress" "$wordpress/wp-content" "$wordpress/wp-content/plugins" "$fixture"; do
 [[ -d "$directory" && ! -L "$directory" && "$(cd "$directory" && pwd -P)" == "$directory" ]] || fail 'Exact non-symlinked site and installed fixture roots are required.'
done
[[ -f "$marker" && ! -L "$marker" && "$(<"$marker")" == 'RAN Booster disposable test site' ]] || fail 'Exact disposable-site marker required.'
[[ -f "$phase" && -f "$proof" ]] || fail 'Shared replacement phases and Bitbucket proof are required.'
[[ ! -e "$target" && ! -L "$target" ]] || fail 'Refusing an existing Bitbucket target.'
[[ -f "$archive" && ! -L "$archive" ]] || fail 'Immutable archive must be a regular local file.'
[[ "$(sha256sum "$archive" | cut -d ' ' -f 1)" == b7963f335c624cf08124040a24a9d51d80c26640faee46e75334783f0547bc5a ]] || fail 'Immutable Bitbucket beta17 ZIP digest mismatch.'
unzip -tqq "$archive"
diff -qr "$root/tests/fixtures/github-replacement" "$fixture"
wp() { "$php_bin" "$wp_cli" "$@" --user=fixture-admin --path="$wordpress"; }
run_phase() { wp eval-file "$phase" "$@"; }
# This read executes the shared exact ABSPATH/content/siteurl/permission guard.
assert_bundled() { run_phase status | jq -e '.available == true and .current.selected == "bundled" and .current.approval == "approved"' >/dev/null; }
assert_bundled
baseline_active="$(wp option get active_plugins --format=json)"
# The preceding historical proof must leave all shared fixture entries inactive.
jq -e 'all(.[]; startswith("github-replacement/") | not)' <<< "$baseline_active" >/dev/null || fail 'Shared replacement entries must start inactive.'
temporary="$(mktemp -d "${RUNNER_TEMP:-${TMPDIR:-/tmp}}/ran-bitbucket-selection.XXXXXXXX")"
owned_target=0
selection_changed=0
restore_selection() {
 run_phase missing && run_phase select && assert_bundled
}
cleanup() {
 local status=$?
 trap - EXIT INT TERM
 set +e
 if (( selection_changed )); then restore_selection || status=1; fi
 if (( owned_target )); then
  wp plugin deactivate ran-booster-bitbucket >/dev/null || status=1
  if [[ -d "$target" && ! -L "$target" ]] && diff -qr "$temporary/ran-booster-bitbucket" "$target"; then
   rm -rf -- "$target" || status=1
  else
   printf 'Owned Bitbucket target changed; retaining it for inspection: %s\n' "$target" >&2
   status=1
  fi
 fi
 [[ "$(wp option get active_plugins --format=json)" == "$baseline_active" ]] || status=1
 if (( status == 0 )); then rm -rf -- "$temporary"; else printf 'Proof failed; retained exact archive extraction: %s\n' "$temporary" >&2; fi
 exit "$status"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
unzip -q "$archive" -d "$temporary"
[[ -d "$temporary/ran-booster-bitbucket" && ! -L "$temporary/ran-booster-bitbucket" ]] || fail 'Archive plugin root unavailable.'
# Claim only a newly created directory; never force-install over an existing path.
mkdir "$target"
owned_target=1
cp -R "$temporary/ran-booster-bitbucket/." "$target/"
diff -qr "$temporary/ran-booster-bitbucket" "$target"
wp plugin activate ran-booster-bitbucket >/dev/null
check_bitbucket() {
 RAN_BOOSTER_BITBUCKET_EXPECT_GH="$1" wp eval-file "$proof"
}
check_bitbucket available
selection_changed=1
run_phase activate-a
check_bitbucket unavailable
run_phase select
check_bitbucket available
run_phase activate-b
check_bitbucket unavailable
run_phase select
check_bitbucket available
run_phase conflict
check_bitbucket unavailable
run_phase missing
check_bitbucket unavailable
restore_selection
selection_changed=0
check_bitbucket available
printf 'Immutable Bitbucket lookup passed in fresh requests across A/B, conflict, missing and bundled selection states.\n'
# Cleanup restores the original active list and removes only byte-identical owned files.
