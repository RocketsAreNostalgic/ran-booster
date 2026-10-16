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
run_phase() { "$php_bin" "$wp_cli" eval-file "$proof" "$@" --user=fixture-admin --path="$wordpress"; }
switch_phase() { "$php_bin" "$wp_cli" eval-file "$replacement" "$@" --user=fixture-admin --path="$wordpress"; }
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
echo 'Installed public API and HTTP dashboard stale-selection refusal proof passed.'
echo 'Migrator controller, Bitbucket and browser acceptance are separate proofs.'
