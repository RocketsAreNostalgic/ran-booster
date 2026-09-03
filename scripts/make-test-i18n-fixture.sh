#!/usr/bin/env bash

# Generate, or verify, the test-only French gettext and Jed fixtures.
#
# Usage: make-test-i18n-fixture.sh [--check]

set -euo pipefail

fail() {
	printf 'make-test-i18n-fixture: %s\n' "$*" >&2
	exit 1
}

script_dir=$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd)
repo_root=$(git -C "$script_dir" rev-parse --show-toplevel 2>/dev/null) \
	|| fail 'run this script from a Git checkout.'
cd "$repo_root"

mode=${1:-}
[[ -z "$mode" || "$mode" == '--check' ]] \
	|| fail 'usage: make-test-i18n-fixture.sh [--check]'

command -v php >/dev/null 2>&1 || fail 'php is required.'
wp_cli=$(command -v wp 2>/dev/null) || fail 'WP-CLI is required.'
fixture_dir='tests/fixtures/i18n'
fixture_po="$fixture_dir/ran-booster-fr_FR.po"
fixture_mo="$fixture_dir/ran-booster-fr_FR.mo"
temporary_dir=$(mktemp -d "${TMPDIR:-/tmp}/ran-booster-i18n.XXXXXX") \
	|| fail 'could not create a temporary fixture directory.'
trap 'rm -rf "$temporary_dir"' EXIT HUP INT TERM

php -n "$wp_cli" i18n make-mo "$fixture_po" "$temporary_dir/ran-booster-fr_FR.mo"
php -n "$wp_cli" i18n make-json "$fixture_po" "$temporary_dir" --no-purge
chmod 0644 "$temporary_dir"/*
shopt -s nullglob
temporary_json=( "$temporary_dir"/*.json )
fixture_json=( "$fixture_dir"/ran-booster-fr_FR-*.json )
shopt -u nullglob

if [[ "$mode" == '--check' ]]; then
	cmp -s "$temporary_dir/ran-booster-fr_FR.mo" "$fixture_mo" \
		|| fail 'the MO fixture is stale; run scripts/make-test-i18n-fixture.sh.'
	[[ ${#temporary_json[@]} -eq ${#fixture_json[@]} ]] \
		|| fail 'the Jed JSON fixture set is stale; run scripts/make-test-i18n-fixture.sh.'
	for generated_json in "${temporary_json[@]}"; do
		cmp -s "$generated_json" "$fixture_dir/$(basename "$generated_json")" \
			|| fail 'a Jed JSON fixture is stale; run scripts/make-test-i18n-fixture.sh.'
	done
	exit 0
fi

mv "$temporary_dir/ran-booster-fr_FR.mo" "$fixture_mo"
for existing_json in "${fixture_json[@]}"; do
	rm -f -- "$existing_json"
done
mv "${temporary_json[@]}" "$fixture_dir/"
