#!/usr/bin/env bash

# Generate, or verify, the source-complete RAN Booster gettext catalogue.
#
# Usage: make-pot.sh [--check]

set -euo pipefail

fail() {
	printf 'make-pot: %s\n' "$*" >&2
	exit 1
}

script_dir=$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd)
repo_root=$(git -C "$script_dir" rev-parse --show-toplevel 2>/dev/null) \
	|| fail 'run this script from a Git checkout.'
cd "$repo_root"

mode=${1:-}
[[ -z "$mode" || "$mode" == '--check' ]] \
	|| fail 'usage: make-pot.sh [--check]'

command -v php >/dev/null 2>&1 || fail 'php is required.'
wp_cli=$(command -v wp 2>/dev/null) || fail 'WP-CLI is required.'
pot='languages/ran-booster.pot'
temporary_pot=$(mktemp "${TMPDIR:-/tmp}/ran-booster.pot.XXXXXX") \
	|| fail 'could not create a temporary catalogue.'
temporary_all_domains_pot=$(mktemp "${TMPDIR:-/tmp}/ran-booster-all-domains.pot.XXXXXX") \
	|| fail 'could not create a temporary all-domain catalogue.'
normalised_pot=$(mktemp "${TMPDIR:-/tmp}/ran-booster-normalised.pot.XXXXXX") \
	|| fail 'could not create a temporary normalised catalogue.'
normalised_all_domains_pot=$(mktemp "${TMPDIR:-/tmp}/ran-booster-all-domains-normalised.pot.XXXXXX") \
	|| fail 'could not create a temporary normalised all-domain catalogue.'
trap 'rm -f "$temporary_pot" "$temporary_all_domains_pot" "$normalised_pot" "$normalised_all_domains_pot"' EXIT HUP INT TERM

# Use PHP without machine-local extension configuration. make-pot is a parser-only
# WP-CLI command; the fixed memory limit keeps the runtime PHP and JavaScript
# source sweep bounded. Suppress dependency deprecations but retain warnings;
# block/theme metadata extraction is disabled because it can perform network I/O.
if ! output=$(php -n -d memory_limit=512M -d 'error_reporting=E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED' "$wp_cli" i18n make-pot . "$temporary_pot" \
	--domain=ran-booster \
	--include=ran-booster.php,autoload.php,index.php,uninstall.php,RAN,views,assets \
	--exclude=assets/lib,tests,vendor,build,node_modules,ran-booster-workbench,.git,.github,.agents,.dex,scripts \
	--skip-block-json \
	--skip-theme-json \
	--headers='{"POT-Creation-Date":"2026-09-01T00:00:00+00:00","Report-Msgid-Bugs-To":"https://wordpress.org/support/plugin/ran-booster/"}' 2>&1); then
	printf '%s\n' "$output" >&2
	fail 'WP-CLI could not generate the catalogue.'
fi
printf '%s\n' "$output"

if grep -q '^Warning:' <<< "$output"; then
	fail 'WP-CLI reported gettext warnings.'
fi

if ! all_domains_output=$(php -n -d memory_limit=512M -d 'error_reporting=E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED' "$wp_cli" i18n make-pot . "$temporary_all_domains_pot" \
	--ignore-domain \
	--include=ran-booster.php,autoload.php,index.php,uninstall.php,RAN,views,assets \
	--exclude=assets/lib,tests,vendor,build,node_modules,ran-booster-workbench,.git,.github,.agents,.dex,scripts \
	--skip-block-json \
	--skip-theme-json \
	--headers='{"POT-Creation-Date":"2026-09-01T00:00:00+00:00","Report-Msgid-Bugs-To":"https://wordpress.org/support/plugin/ran-booster/"}' 2>&1); then
	printf '%s\n' "$all_domains_output" >&2
	fail 'WP-CLI could not generate the all-domain catalogue.'
fi
printf '%s\n' "$all_domains_output"

if grep -q '^Warning:' <<< "$all_domains_output"; then
	fail 'WP-CLI reported gettext warnings for the all-domain catalogue.'
fi

sed '/^"X-Domain: /d' "$temporary_pot" > "$normalised_pot"
sed '/^"X-Domain: /d' "$temporary_all_domains_pot" > "$normalised_all_domains_pot"
cmp -s "$normalised_pot" "$normalised_all_domains_pot" \
	|| fail 'domain-filtered and all-domain catalogues differ; every runtime string must use ran-booster.'

if [[ "$mode" == '--check' ]]; then
	cmp -s "$temporary_pot" "$pot" \
		|| fail 'languages/ran-booster.pot is stale; run scripts/make-pot.sh.'
	exit 0
fi

mkdir -p languages
chmod 0644 "$temporary_pot"
mv "$temporary_pot" "$pot"
