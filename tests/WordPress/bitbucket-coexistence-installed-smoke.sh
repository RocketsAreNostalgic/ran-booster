#!/usr/bin/env bash
# The immutable released Bitbucket add-on keeps working in fresh requests while
# GitHub is bundled, superseded by a plugin, or unavailable after a failed claim.
# Requires github-supersede-installed-smoke.sh first: it leaves both claimant
# plugins installed and inactive.
set -euo pipefail
unset UNZIP UNZIPOPT
fail() { printf 'bitbucket-coexistence-installed: %s\n' "$*" >&2; exit 2; }
[[ "${RAN_BOOSTER_BITBUCKET_TEST_DISPOSABLE:-}" == 1 ]] || fail 'Explicit disposable-site consent is required.'
wordpress=${RAN_BOOSTER_WORDPRESS_PATH:?Set the exact disposable site path.}
archive=${RAN_BOOSTER_BITBUCKET_ARCHIVE:?Set the immutable beta17 ZIP path.}
php_bin=${PHP_BIN:?Set PHP.}
wp_cli=${WP_CLI_BIN:?Set WP-CLI.}
root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)"
# shellcheck source=scripts/verify-release.sh
source "$root/scripts/verify-release.sh"
proof="$root/tests/WordPress/bitbucket-coexistence-installed-smoke.php"
extension="$wordpress/wp-content/plugins/ran-booster-github-provider-extension"
claimant="$wordpress/wp-content/plugins/ran-booster-github-supersede-failing"
target="$wordpress/wp-content/plugins/ran-booster-bitbucket"
marker="$wordpress/.ran-booster-disposable-test-site"
for directory in "$wordpress" "$wordpress/wp-content" "$wordpress/wp-content/plugins" "$extension" "$claimant"; do
 [[ -d "$directory" && ! -L "$directory" && "$(cd "$directory" && pwd -P)" == "$directory" ]] || fail 'Exact non-symlinked site and installed claimant roots are required.'
done
[[ -f "$marker" && ! -L "$marker" && "$(<"$marker")" == 'RAN Booster disposable test site' ]] || fail 'Exact disposable-site marker required.'
[[ -f "$proof" ]] || fail 'Bitbucket proof is required.'
[[ ! -e "$target" && ! -L "$target" ]] || fail 'Refusing an existing Bitbucket target.'
[[ -f "$archive" && ! -L "$archive" ]] || fail 'Immutable archive must be a regular local file.'
archive_hash=$(sha256_file "$archive")
[[ "$archive_hash" == b7963f335c624cf08124040a24a9d51d80c26640faee46e75334783f0547bc5a ]] || fail 'Immutable Bitbucket beta17 ZIP digest mismatch.'
unzip -tqq "$archive"
diff -qr --exclude=vendor "$root/tests/fixtures/ran-booster-github-provider-extension" "$extension"
diff -qr "$root/tests/fixtures/ran-booster-github-supersede-failing" "$claimant"
wp() { "$php_bin" "$wp_cli" "$@" --user=admin --path="$wordpress"; }
baseline_active="$(wp option get active_plugins --format=json)"
# The preceding supersede proof must leave both claimants inactive.
jq -e 'all(.[]; (startswith("ran-booster-github-provider-extension/") or startswith("ran-booster-github-supersede-failing/")) | not)' <<< "$baseline_active" >/dev/null || fail 'GitHub claimants must start inactive.'
temporary="$(mktemp -d "${RUNNER_TEMP:-${TMPDIR:-/tmp}}/ran-bitbucket-coexistence.XXXXXXXX")"
owned_target=0
claim_changed=0
restore_bundled() {
 local plugin
 for plugin in ran-booster-github-provider-extension ran-booster-github-supersede-failing; do
  if wp plugin is-active "$plugin"; then wp plugin deactivate "$plugin" >/dev/null || return 1; fi
 done
}
restore_active_plugins() {
 local current_active current_members baseline_members
 current_active="$(wp option get active_plugins --format=json)" || return 1
 current_members="$(jq -ce '[.[]] | sort' <<< "$current_active")" || return 1
 baseline_members="$(jq -ce '[.[]] | sort' <<< "$baseline_active")" || return 1
 if [[ "$current_members" != "$baseline_members" ]]; then
  printf 'Active plugin membership changed; refusing baseline restoration.\n' >&2
  return 1
 fi
 # Native activation/deactivation can sort or leave sparse keys. Restore the
 # exact snapshot only after ruling out added, removed or duplicate entries.
 wp option update active_plugins "$baseline_active" --format=json >/dev/null || return 1
 current_active="$(wp option get active_plugins --format=json)" || return 1
 if [[ "$current_active" != "$baseline_active" ]]; then
  printf 'Exact active plugin baseline restoration failed.\n' >&2
  return 1
 fi
}
cleanup() {
 local status=$?
 trap - EXIT INT TERM
 set +e
 if (( claim_changed )); then restore_bundled || status=1; fi
 if (( owned_target )); then
  wp plugin deactivate ran-booster-bitbucket >/dev/null || status=1
  if [[ -d "$target" && ! -L "$target" ]] && diff -qr "$temporary/ran-booster-bitbucket" "$target"; then
   rm -rf -- "$target" || status=1
  else
   printf 'Owned Bitbucket target changed; retaining it for inspection: %s\n' "$target" >&2
   status=1
  fi
 fi
 restore_active_plugins || status=1
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
check_bitbucket bundled
claim_changed=1
wp plugin activate ran-booster-github-provider-extension >/dev/null
check_bitbucket ran-booster-github-provider-extension
wp plugin deactivate ran-booster-github-provider-extension >/dev/null
wp plugin activate ran-booster-github-supersede-failing >/dev/null
check_bitbucket supersede_failed
restore_bundled
claim_changed=0
check_bitbucket bundled
printf 'Immutable Bitbucket lookup passed in fresh requests while GitHub was bundled, superseded and unavailable.\n'
# Cleanup restores the original active list and removes only byte-identical owned files.
