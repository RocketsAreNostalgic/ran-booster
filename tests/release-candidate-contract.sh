#!/usr/bin/env bash
set -euo pipefail

repo_root=$(CDPATH='' cd -- "$(dirname -- "$0")/.." && pwd)
validator="$repo_root/scripts/validate-release-candidate.sh"
test_root=$(mktemp -d "${TMPDIR:-/tmp}/ran-booster-release-candidate.XXXXXX")

cleanup() {
	case "$test_root" in
		"${TMPDIR:-/tmp}"/ran-booster-release-candidate.*) rm -rf -- "$test_root" ;;
	esac
}
trap cleanup EXIT HUP INT TERM

write_plugin() {
	local version=$1
	local description=${2:-'Repository deployment management.'}
	printf '%s\n' \
		'<?php' \
		'/**' \
		' * Plugin Name: RAN Booster' \
		" * Description: ${description}" \
		' * x-release-please-start-version' \
		" * Version: ${version}" \
		' * x-release-please-end' \
		' */' \
		'' \
		"define( 'RAN_BOOSTER_TEST_VERSION', 1 );" \
		> ran-booster.php
}

write_readme() {
	local version=$1
	printf '%s\n' \
		'=== RAN Booster ===' \
		'<!-- x-release-please-start-version -->' \
		"Stable tag: ${version}" \
		'<!-- x-release-please-end -->' \
		'' \
		'Repository deployment management.' \
		> readme.txt
}

write_manifest() {
	printf '{\n  ".": "%s"\n}\n' "$1" > .release-please-manifest.json
}

write_changelog() {
	local version=$1
	local preserve_base=${2:-true}
	printf '# Changelog\n\n## [%s](https://example.test/compare/v1.0.0...v%s)\n\n* Release entry.\n' \
		"$version" "$version" > CHANGELOG.md
	if [[ "$preserve_base" == true ]]; then
		printf '\n## [1.0.0](https://example.test/releases/v1.0.0)\n\n* Accepted history.\n' >> CHANGELOG.md
	fi
}

commit_candidate() {
	git add .release-please-manifest.json CHANGELOG.md ran-booster.php readme.txt
	git commit -q -m 'chore(main): release candidate'
}

expect_failure() {
	if "$validator" "$@" >/dev/null 2>&1; then
		printf 'Expected candidate validation to fail: %s -> %s\n' "$1" "$2" >&2
		exit 1
	fi
}

cd "$test_root"
git init -q
git config user.name 'RAN Booster Tests'
git config user.email 'tests@example.test'

write_plugin '1.0.0'
write_readme '1.0.0'
write_manifest '1.0.0'
printf '%s\n' \
	'# Changelog' \
	'' \
	'## [1.0.0](https://example.test/releases/v1.0.0)' \
	'' \
	'* Accepted history.' \
	> CHANGELOG.md
git add .
git commit -q -m 'chore(main): release 1.0.0'
base_commit=$(git rev-parse HEAD)

write_plugin '1.0.1'
write_readme '1.0.1'
write_manifest '1.0.1'
write_changelog '1.0.1'
commit_candidate
good_commit=$(git rev-parse HEAD)
"$validator" "$base_commit" "$good_commit" >/dev/null

git checkout -q -B stacked-candidate "$good_commit"
git commit -q --allow-empty -m 'unexpected stacked release commit'
expect_failure "$base_commit" HEAD

git checkout -q -B extra-path "$base_commit"
write_plugin '1.0.1'
write_readme '1.0.1'
write_manifest '1.0.1'
write_changelog '1.0.1'
printf 'unexpected\n' > unexpected.txt
git add .
git commit -q -m 'test extra path'
expect_failure "$base_commit" HEAD

git checkout -q -B changed-bootstrap "$base_commit"
write_plugin '1.0.1' 'Changed runtime description.'
write_readme '1.0.1'
write_manifest '1.0.1'
write_changelog '1.0.1'
commit_candidate
expect_failure "$base_commit" HEAD

git checkout -q -B deleted-history "$base_commit"
write_plugin '1.0.1'
write_readme '1.0.1'
write_manifest '1.0.1'
write_changelog '1.0.1' false
commit_candidate
expect_failure "$base_commit" HEAD

git checkout -q -B mismatched-version "$base_commit"
write_plugin '1.0.2'
write_readme '1.0.1'
write_manifest '1.0.1'
write_changelog '1.0.1'
commit_candidate
expect_failure "$base_commit" HEAD

printf 'Release candidate contract tests passed.\n'
