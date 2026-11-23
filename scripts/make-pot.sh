#!/usr/bin/env bash

# Generate, or check the extraction of, the source-complete RAN Booster gettext
# catalogue. The catalogue is not committed. The release build generates it from
# the staged archive tree and the release verifier regenerates it from the
# release commit; both call this script so one flag set defines it.
#
# Usage: make-pot.sh [--check]
#        make-pot.sh [--source DIR] [--output FILE]
#
# --check checks the extraction only and writes nothing. Without options the
# checkout's catalogue is written to the git-ignored languages/ran-booster.pot.

set -euo pipefail

fail() {
	printf 'make-pot: %s\n' "$*" >&2
	exit 1
}

script_dir=$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd)
repo_root=$(git -C "$script_dir" rev-parse --show-toplevel 2>/dev/null) \
	|| fail 'run this script from a Git checkout.'
cd "$repo_root"

mode=generate
source_dir=$repo_root
pot="$repo_root/languages/ran-booster.pot"
while (( $# > 0 )); do
	case "$1" in
		--check) mode=check ;;
		--source) [[ $# -ge 2 ]] || fail 'usage: make-pot.sh [--check] [--source DIR] [--output FILE]'
			source_dir=$2; shift ;;
		--output) [[ $# -ge 2 ]] || fail 'usage: make-pot.sh [--check] [--source DIR] [--output FILE]'
			pot=$2; shift ;;
		*) fail 'usage: make-pot.sh [--check] [--source DIR] [--output FILE]' ;;
	esac
	shift
done
[[ -d "$source_dir" ]] || fail "source directory does not exist: $source_dir"
[[ "$pot" == /* ]] || fail 'the output path must be absolute.'
source_dir=$(CDPATH='' cd -- "$source_dir" && pwd)

command -v php >/dev/null 2>&1 || fail 'php is required.'
wp_cli=$(command -v wp 2>/dev/null) || fail 'WP-CLI is required.'
php_runner=( php -n )
if ! "${php_runner[@]}" "$wp_cli" --info >/dev/null 2>&1; then
	php_runner=( php )
fi
wp_cli_version="$("${php_runner[@]}" "$wp_cli" --version)" \
	|| fail 'WP-CLI 2.12.0 is required.'
grep -qx 'WP-CLI 2.12.0' <<< "$wp_cli_version" \
	|| fail "WP-CLI 2.12.0 is required; found ${wp_cli_version}."
cd "$source_dir"
temporary_dir=$(mktemp -d "${TMPDIR:-/tmp}/ran-booster-pot.XXXXXX") \
	|| fail 'could not create a temporary catalogue directory.'
trap 'rm -rf "$temporary_dir"' EXIT HUP INT TERM
temporary_pot="$temporary_dir/ran-booster.pot"
temporary_all_domains_pot="$temporary_dir/all-domains.pot"
normalised_pot="$temporary_dir/normalised.pot"
normalised_all_domains_pot="$temporary_dir/all-domains-normalised.pot"

# Prefer PHP without machine-local configuration when it can run the WP-CLI
# Phar. Otherwise, retain the configured PHP that supplies required extensions.
# The fixed memory limit keeps the runtime PHP and JavaScript source sweep bounded.
# Suppress dependency deprecations but retain warnings; block/theme metadata
# extraction is disabled because it can perform network I/O.
if ! output=$("${php_runner[@]}" -d memory_limit=512M -d 'error_reporting=E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED' "$wp_cli" i18n make-pot . "$temporary_pot" \
	--domain=ran-booster \
	--include=ran-booster.php,autoload.php,index.php,uninstall.php,RAN,views,assets \
	--exclude=assets/lib,tests,vendor,build,node_modules,ran-booster-workbench,.git,.github,.agents,.dex,scripts \
	--skip-block-json \
	--skip-theme-json \
	--package-name='RAN Booster' \
	--file-comment=$'Copyright (C) 2026 Rockets Are Nostalgic\nThis file is distributed under the GPL-2.0-only.' \
	--headers='{"Project-Id-Version":"RAN Booster","POT-Creation-Date":"2026-09-01T00:00:00+00:00","Report-Msgid-Bugs-To":"https://wordpress.org/support/plugin/ran-booster/"}' 2>&1); then
	printf '%s\n' "$output" >&2
	fail 'WP-CLI could not generate the catalogue.'
fi
printf '%s\n' "$output"

if grep -q '^Warning:' <<< "$output"; then
	fail 'WP-CLI reported gettext warnings.'
fi

if ! all_domains_output=$("${php_runner[@]}" -d memory_limit=512M -d 'error_reporting=E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED' "$wp_cli" i18n make-pot . "$temporary_all_domains_pot" \
	--ignore-domain \
	--include=ran-booster.php,autoload.php,index.php,uninstall.php,RAN,views,assets \
	--exclude=assets/lib,tests,vendor,build,node_modules,ran-booster-workbench,.git,.github,.agents,.dex,scripts \
	--skip-block-json \
	--skip-theme-json \
	--package-name='RAN Booster' \
	--file-comment=$'Copyright (C) 2026 Rockets Are Nostalgic\nThis file is distributed under the GPL-2.0-only.' \
	--headers='{"Project-Id-Version":"RAN Booster","POT-Creation-Date":"2026-09-01T00:00:00+00:00","Report-Msgid-Bugs-To":"https://wordpress.org/support/plugin/ran-booster/"}' 2>&1); then
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

[[ "$mode" == check ]] && exit 0

mkdir -p "$(dirname -- "$pot")"
chmod 0644 "$temporary_pot"
mv "$temporary_pot" "$pot"
