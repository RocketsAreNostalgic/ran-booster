#!/usr/bin/env bash
set -euo pipefail

repo_root=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
validator="$repo_root/scripts/validate-release-candidate.sh"
work_root=$(mktemp -d "${TMPDIR:-/tmp}/ran-bitbucket-release-candidate-test.XXXXXX")
trap 'rm -rf "$work_root"' EXIT

fail() {
	printf 'release candidate validator test: %s\n' "$*" >&2
	exit 1
}

seed="$work_root/seed"
mkdir -p "$seed"
git -C "$seed" init --quiet
git -C "$seed" config user.name 'Validator Test'
git -C "$seed" config user.email 'validator@example.invalid'

printf '{".":"1.2.3"}\n' > "$seed/.release-please-manifest.json"
cat > "$seed/CHANGELOG.md" <<'EOF'
# Changelog

## [1.2.3](https://example.invalid/compare/v1.2.2...v1.2.3) (2026-01-01)

Accepted history.
EOF
cat > "$seed/ran-booster.php" <<'EOF'
<?php
/**
 * Plugin Name: Validator Fixture
 * Version: 1.2.3
 */
EOF
cat > "$seed/readme.txt" <<'EOF'
=== Validator Fixture ===
Stable tag: 1.2.3

Accepted readme.
EOF
git -C "$seed" add .
git -C "$seed" commit --quiet -m 'chore: seed release fixture'

prepare_valid() {
	local name=$1
	case_dir="$work_root/$name"
	git clone --quiet "$seed" "$case_dir"
	git -C "$case_dir" config user.name 'Validator Test'
	git -C "$case_dir" config user.email 'validator@example.invalid'
	printf '{".":"1.2.4"}\n' > "$case_dir/.release-please-manifest.json"
	sed -i.bak -E 's/Version: 1\.2\.3/Version: 1.2.4/' "$case_dir/ran-booster.php"
	rm -f "$case_dir/ran-booster.php.bak"
	sed -i.bak -E 's/Stable tag: 1\.2\.3/Stable tag: 1.2.4/' "$case_dir/readme.txt"
	rm -f "$case_dir/readme.txt.bak"
	{
		printf '# Changelog\n\n'
		printf '## [1.2.4](https://example.invalid/compare/v1.2.3...v1.2.4) (2026-01-02)\n\n'
		printf 'Generated release.\n\n'
		git -C "$case_dir" show HEAD:CHANGELOG.md | sed -n '3,$p'
	} > "$case_dir/CHANGELOG.md"
	git -C "$case_dir" add .
	git -C "$case_dir" commit --quiet -m 'chore(main): release 1.2.4'
	base_sha=$(git -C "$case_dir" rev-parse HEAD^)
	head_sha=$(git -C "$case_dir" rev-parse HEAD)
}

amend_case() {
	git -C "$case_dir" add -A
	git -C "$case_dir" commit --quiet --amend --no-edit
	head_sha=$(git -C "$case_dir" rev-parse HEAD)
}

replace_in_case() {
	local expression=$1
	local path=$2
	sed -i.bak -E "$expression" "$case_dir/$path"
	rm -f "$case_dir/${path}.bak"
}

expect_valid() {
	local name=$1
	(
		cd "$case_dir"
		bash "$validator" "$base_sha" "$head_sha"
	) >/dev/null || fail "$name should pass"
}

expect_invalid() {
	local name=$1
	if (
		cd "$case_dir"
		bash "$validator" "$base_sha" "$head_sha"
	) >/dev/null 2>&1; then
		fail "$name should fail"
	fi
}

prepare_valid valid
expect_valid valid

prepare_valid updated-branch
generated_sha=$head_sha
git -C "$case_dir" checkout --quiet -B main "$base_sha"
printf '<?php // Main advanced.\n' > "$case_dir/main-advance.php"
git -C "$case_dir" add main-advance.php
git -C "$case_dir" commit --quiet -m 'feat: advance main'
base_sha=$(git -C "$case_dir" rev-parse HEAD)
git -C "$case_dir" checkout --quiet "$generated_sha"
git -C "$case_dir" merge --quiet --no-ff main -m 'Merge branch main into release candidate'
head_sha=$(git -C "$case_dir" rev-parse HEAD)
expect_valid updated-branch

prepare_valid updated-branch-version-mismatch
generated_sha=$head_sha
git -C "$case_dir" checkout --quiet -B main "$base_sha"
printf '<?php // Main advanced.\n' > "$case_dir/main-advance.php"
git -C "$case_dir" add main-advance.php
git -C "$case_dir" commit --quiet -m 'feat: advance main'
base_sha=$(git -C "$case_dir" rev-parse HEAD)
git -C "$case_dir" checkout --quiet "$generated_sha"
git -C "$case_dir" merge --quiet --no-ff main -m 'Merge branch main into release candidate'
replace_in_case 's/1\.2\.4/1.2.5/g' .release-please-manifest.json
replace_in_case 's/1\.2\.4/1.2.5/g' ran-booster.php
replace_in_case 's/1\.2\.4/1.2.5/g' readme.txt
replace_in_case 's/1\.2\.4/1.2.5/g' CHANGELOG.md
amend_case
expect_invalid updated-branch-version-mismatch

prepare_valid updated-branch-changelog-mismatch
generated_sha=$head_sha
git -C "$case_dir" checkout --quiet -B main "$base_sha"
printf '<?php // Main advanced.\n' > "$case_dir/main-advance.php"
git -C "$case_dir" add main-advance.php
git -C "$case_dir" commit --quiet -m 'feat: advance main'
base_sha=$(git -C "$case_dir" rev-parse HEAD)
git -C "$case_dir" checkout --quiet "$generated_sha"
git -C "$case_dir" merge --quiet --no-ff main -m 'Merge branch main into release candidate'
replace_in_case 's/Generated release\./Adjusted release notes./' CHANGELOG.md
amend_case
expect_invalid updated-branch-changelog-mismatch

prepare_valid updated-branch-plus-prefixed-changelog-mismatch
replace_in_case 's/Generated release\./+ Generated release./' CHANGELOG.md
amend_case
generated_sha=$head_sha
git -C "$case_dir" checkout --quiet -B main "$base_sha"
printf '<?php // Main advanced.\n' > "$case_dir/main-advance.php"
git -C "$case_dir" add main-advance.php
git -C "$case_dir" commit --quiet -m 'feat: advance main'
base_sha=$(git -C "$case_dir" rev-parse HEAD)
git -C "$case_dir" checkout --quiet "$generated_sha"
git -C "$case_dir" merge --quiet --no-ff main -m 'Merge branch main into release candidate'
replace_in_case 's/\+ Generated release\./+ Adjusted release notes./' CHANGELOG.md
amend_case
expect_invalid updated-branch-plus-prefixed-changelog-mismatch

prepare_valid updated-branch-runtime-edit
generated_sha=$head_sha
git -C "$case_dir" checkout --quiet -B main "$base_sha"
printf '<?php // Main advanced.\n' > "$case_dir/main-advance.php"
git -C "$case_dir" add main-advance.php
git -C "$case_dir" commit --quiet -m 'feat: advance main'
base_sha=$(git -C "$case_dir" rev-parse HEAD)
git -C "$case_dir" checkout --quiet "$generated_sha"
git -C "$case_dir" merge --quiet --no-ff main -m 'Merge branch main into release candidate'
printf '<?php // Unexpected release runtime edit.\n' > "$case_dir/main-advance.php"
git -C "$case_dir" add main-advance.php
git -C "$case_dir" commit --quiet --amend --no-edit
head_sha=$(git -C "$case_dir" rev-parse HEAD)
expect_invalid updated-branch-runtime-edit

prepare_valid updated-branch-unsupported-history
generated_sha=$head_sha
git -C "$case_dir" checkout --quiet -B main "$base_sha"
printf '<?php // Main advanced.\n' > "$case_dir/main-advance.php"
git -C "$case_dir" add main-advance.php
git -C "$case_dir" commit --quiet -m 'feat: advance main'
base_sha=$(git -C "$case_dir" rev-parse HEAD)
git -C "$case_dir" checkout --quiet "$generated_sha"
git -C "$case_dir" commit --quiet --allow-empty -m 'chore: unsupported release history'
git -C "$case_dir" merge --quiet --no-ff main -m 'Merge branch main into release candidate'
head_sha=$(git -C "$case_dir" rev-parse HEAD)
expect_invalid updated-branch-unsupported-history

prepare_valid multi-commit
git -C "$case_dir" commit --quiet --allow-empty -m 'chore: unexpected second commit'
head_sha=$(git -C "$case_dir" rev-parse HEAD)
expect_invalid multi-commit

prepare_valid extra-file
printf 'unexpected\n' > "$case_dir/unexpected.txt"
amend_case
expect_invalid extra-file

prepare_valid renamed-file
git -C "$case_dir" mv readme.txt README.txt
amend_case
expect_invalid renamed-file

prepare_valid manifest-mismatch
printf '{".":"1.2.5"}\n' > "$case_dir/.release-please-manifest.json"
amend_case
expect_invalid manifest-mismatch

prepare_valid header-mismatch
replace_in_case 's/Version: 1\.2\.4/Version: 1.2.5/' ran-booster.php
amend_case
expect_invalid header-mismatch

prepare_valid stable-tag-mismatch
replace_in_case 's/Stable tag: 1\.2\.4/Stable tag: 1.2.5/' readme.txt
amend_case
expect_invalid stable-tag-mismatch

prepare_valid plugin-non-version-edit
printf '\n// Unexpected bootstrap edit.\n' >> "$case_dir/ran-booster.php"
amend_case
expect_invalid plugin-non-version-edit

prepare_valid readme-non-version-edit
printf '\nUnexpected readme edit.\n' >> "$case_dir/readme.txt"
amend_case
expect_invalid readme-non-version-edit

prepare_valid changelog-deletion
replace_in_case '/Accepted history\./d' CHANGELOG.md
amend_case
expect_invalid changelog-deletion

prepare_valid changelog-rewrite
replace_in_case 's/Accepted history\./Rewritten history./' CHANGELOG.md
amend_case
expect_invalid changelog-rewrite

prepare_valid missing-heading
replace_in_case 's/^## \[1\.2\.4\].*/Release 1.2.4/' CHANGELOG.md
amend_case
expect_invalid missing-heading

prepare_valid wrong-heading
replace_in_case 's/1\.2\.4/1.2.5/g' CHANGELOG.md
amend_case
expect_invalid wrong-heading

printf 'Release candidate validator behavior passed (2 valid, 17 invalid cases).\n'
