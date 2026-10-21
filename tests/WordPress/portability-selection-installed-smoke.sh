#!/usr/bin/env bash
set -euo pipefail
# Requires github-replacement-installed-smoke.sh first and a real localhost HTTP
# server serving the same disposable site. This is not browser/manual acceptance.
wordpress="${RAN_BOOSTER_WORDPRESS_PATH:?Set the disposable WordPress path.}"
php_bin="${PHP_BIN:?Set PHP.}"
wp_cli="${WP_CLI_BIN:?Set WP-CLI.}"
root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)"
proof="$root/tests/WordPress/portability-selection-installed-smoke.php"
replacement="$root/tests/WordPress/github-replacement-installed-smoke.php"
run_phase() { "$php_bin" "$wp_cli" eval-file "$proof" "$@" --use-include --user=fixture-admin --path="$wordpress"; }
switch_phase() { "$php_bin" "$wp_cli" eval-file "$replacement" "$@" --user=fixture-admin --path="$wordpress"; }
# Explicit separate mode: never silently skip missing Migrator prerequisites.
if [[ "${1:-core}" == migrator ]]; then
  migrator_source="${RAN_MIGRATOR_SOURCE_PATH:?Set exact source helper checkout.}"
  : "${RAN_MIGRATOR_ARCHIVE:?Set verified Migrator source80 (ef225f1) ZIP.}"
  : "${RAN_MIGRATOR_WP_PUSHER_ARCHIVE:?Set verified official WP Pusher 3.0.18 ZIP.}"
  : "${RAN_MIGRATOR_WP_CLI_BIN:?Set executable WP-CLI launcher for satellite helper.}"
  [[ "$(git -C "$migrator_source" rev-parse HEAD)" == 58019d52775a12455ccdce160abfcb7802ec8802 ]] || { echo 'Exact reviewed Migrator helper source required.' >&2; exit 2; }
  git -C "$migrator_source" diff --quiet HEAD -- tests/installed-candidate scripts
  export RAN_MIGRATOR_PROOF_DISPOSABLE=1
  export RAN_MIGRATOR_WORDPRESS_PATH="$wordpress"
  RAN_MIGRATOR_EXPECTED_SITE_URL="$(switch_phase expected-origin)"
  export RAN_MIGRATOR_EXPECTED_SITE_URL
  export RAN_MIGRATOR_WP_USER=fixture-admin
  helper="$migrator_source/tests/installed-candidate/current-source-fixture.sh"
  run_phase migrator-preflight
  umask 077
  migrator_state="$(mktemp /tmp/ran-migrator-http-XXXXXXXX)"
  migrator_readback="$(mktemp /tmp/ran-migrator-readback-XXXXXXXX)"
  export RAN_BOOSTER_MIGRATOR_HTTP_STATE="$migrator_state"
  export RAN_BOOSTER_MIGRATOR_READBACK="$migrator_readback"
  trap 'rm -f -- "$migrator_state" "$migrator_readback"' EXIT
  source_phase() { bash "$helper" "$1" | tail -n 1 > "$migrator_readback"; }
  source_phase setup
  mkdir -m 0755 "$wordpress/wp-content/plugins/ran-migrator-fixture"
  printf '%s\n' '<?php' '/* Plugin Name: Migrator fixture' 'Version: 1.0.0' '*/' > "$wordpress/wp-content/plugins/ran-migrator-fixture/plugin.php"
  chmod 0644 "$wordpress/wp-content/plugins/ran-migrator-fixture/plugin.php"
  source_phase seed
  switch_phase activate-a
  switch_phase select
  run_phase migrator-session
  run_phase migrator-review
  switch_phase activate-b
  switch_phase select
  switch_phase reset-counters
  run_phase migrator-stale
  source_phase readback
  run_phase migrator-retained
  run_phase migrator-review
  switch_phase reset-counters
  run_phase migrator-apply
  source_phase readback
  run_phase migrator-complete
  source_phase cleanup
  echo 'Installed Migrator real HTTP stale refusal, fresh Disabled adoption and exact source cleanup passed.'
  exit 0
fi
[[ "${1:-core}" == core ]] || { echo 'Expected core or migrator proof mode.' >&2; exit 2; }
# Fixture::guard validates exact marker, ABSPATH, content/plugins roots and URL
# before creating this one previously absent inactive fixture target.
run_phase setup
umask 077
consumer_archive="$(mktemp /tmp/ran-consumer-XXXXXXXX.zip)"
export RAN_BOOSTER_CONSUMER_ARCHIVE="$consumer_archive"
trap 'rm -f -- "$consumer_archive"' EXIT
mkdir -m 0755 "$wordpress/wp-content/plugins/portability-consumer-proof"
printf '%s\n' '<?php' '/* Plugin Name: Portability consumer proof' 'Version: 1.0.0' '*/' > "$wordpress/wp-content/plugins/portability-consumer-proof/plugin.php"
chmod 0644 "$wordpress/wp-content/plugins/portability-consumer-proof/plugin.php"
switch_phase activate-a
switch_phase select
run_phase seed-credential
run_phase api-review
run_phase dashboard-review
switch_phase activate-b
switch_phase select
switch_phase reset-counters
run_phase api-stale
switch_phase reset-counters
run_phase dashboard-stale
# Both real consumers must be reviewable again against B before explicit adoption.
run_phase api-review
run_phase dashboard-review
switch_phase reset-counters
run_phase api-adopt
run_phase adoption-readback
# A distinct target keeps successful HTTP adoption independent of API adoption.
run_phase dashboard-success-setup
dashboard_archive="$(mktemp /tmp/ran-dashboard-XXXXXXXX.zip)"
export RAN_BOOSTER_CONSUMER_ARCHIVE="$dashboard_archive"
trap 'rm -f -- "$consumer_archive" "$dashboard_archive"' EXIT
mkdir -m 0755 "$wordpress/wp-content/plugins/portability-dashboard-proof"
printf '%s\n' '<?php' '/* Plugin Name: Portability dashboard proof' 'Version: 1.0.0' '*/' > "$wordpress/wp-content/plugins/portability-dashboard-proof/plugin.php"
chmod 0644 "$wordpress/wp-content/plugins/portability-dashboard-proof/plugin.php"
run_phase dashboard-success-review
switch_phase reset-counters
run_phase dashboard-success-no-consent
switch_phase reset-counters
run_phase dashboard-success-apply
run_phase dashboard-success-readback
echo 'Installed public API and HTTP dashboard refusal, consent and Disabled adoption proofs passed.'
echo 'Migrator controller, Bitbucket and browser acceptance are separate proofs.'
