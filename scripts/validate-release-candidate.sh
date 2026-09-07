#!/usr/bin/env bash
set -euo pipefail

fail() {
	printf 'validate-release-candidate: %s\n' "$*" >&2
	exit 1
}

[[ $# -eq 2 ]] || fail 'expected <base-commit> <release-commit>.'
base_commit=$(git rev-parse --verify "$1^{commit}") \
	|| fail 'base commit is unavailable.'
release_commit=$(git rev-parse --verify "$2^{commit}") \
	|| fail 'release commit is unavailable.'

git merge-base --is-ancestor "$base_commit" "$release_commit" \
	|| fail 'release commit does not descend from its pull-request base.'

release_parents=( $(git rev-list --parents -n 1 "$release_commit") )
generated_commit=''
case "${#release_parents[@]}" in
	2)
		[[ "${release_parents[1]}" == "$base_commit" ]] \
			|| fail 'linear release candidate must be the generated commit directly above its pull-request base.'
		;;
	3)
		if [[ "${release_parents[1]}" == "$base_commit" ]]; then
			generated_commit="${release_parents[2]}"
		elif [[ "${release_parents[2]}" == "$base_commit" ]]; then
			generated_commit="${release_parents[1]}"
		else
			fail 'updated release candidate merge must have its pull-request base as a parent.'
		fi
		[[ "$(git rev-list --parents -n 1 "$generated_commit" | wc -w | tr -d ' ')" == 2 ]] \
			|| fail 'updated release candidate must contain one generated commit, not merged history.'
		[[ "$(git rev-parse "${generated_commit}^")" == "$(git merge-base "$base_commit" "$generated_commit")" ]] \
			|| fail 'updated release candidate must generate directly from the branches’ common ancestor.'
		bash "$0" "$(git rev-parse "${generated_commit}^")" "$generated_commit" \
			|| fail 'updated release candidate has no exact generated release parent.'
		;;
	*)
		fail 'release candidate must be a generated commit or a controlled branch-update merge.'
		;;
esac

expected_changes=$(printf '%s\n' \
	$'M\t.release-please-manifest.json' \
	$'M\tCHANGELOG.md' \
	$'M\tran-booster.php' \
	$'M\treadme.txt')
actual_changes=$(git diff --name-status "$base_commit" "$release_commit" -- | LC_ALL=C sort -k2)
[[ "$actual_changes" == "$expected_changes" ]] \
	|| fail 'release candidate must change exactly the four generated release files.'

manifest_version() {
	git show "$1:.release-please-manifest.json" \
		| jq -er 'select(type == "object" and keys == ["."] and (.["."] | type) == "string") | .["."]'
}

plugin_version() {
	git show "$1:ran-booster.php" \
		| sed -nE 's/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*([^[:space:]]+).*/\1/p'
}

stable_tag() {
	git show "$1:readme.txt" \
		| sed -nE 's/^Stable tag:[[:space:]]*([^[:space:]]+).*/\1/p'
}

base_version=$(manifest_version "$base_commit") \
	|| fail 'base manifest has an invalid shape.'
release_version=$(manifest_version "$release_commit") \
	|| fail 'release manifest has an invalid shape.'
if [[ -n "$generated_commit" ]]; then
	generated_version=$(manifest_version "$generated_commit") \
		|| fail 'generated release parent has an invalid manifest shape.'
	[[ "$release_version" == "$generated_version" ]] \
		|| fail 'updated release candidate version must equal its generated release parent.'
fi
[[ "$release_version" =~ ^[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z.-]+)?$ ]] \
	|| fail 'release version is not valid semver.'
[[ "$release_version" != "$base_version" ]] \
	|| fail 'release version did not change.'
[[ "$(plugin_version "$base_commit")" == "$base_version" ]] \
	|| fail 'base plugin header does not match the base manifest.'
[[ "$(stable_tag "$base_commit")" == "$base_version" ]] \
	|| fail 'base Stable tag does not match the base manifest.'
[[ "$(plugin_version "$release_commit")" == "$release_version" ]] \
	|| fail 'release plugin header does not match the release manifest.'
[[ "$(stable_tag "$release_commit")" == "$release_version" ]] \
	|| fail 'release Stable tag does not match the release manifest.'

diff -u \
	<(git show "$base_commit:ran-booster.php" \
		| sed -E 's/^([[:space:]]*\*[[:space:]]*Version:).*/\1 __RELEASE_VERSION__/') \
	<(git show "$release_commit:ran-booster.php" \
		| sed -E 's/^([[:space:]]*\*[[:space:]]*Version:).*/\1 __RELEASE_VERSION__/') \
	>/dev/null \
	|| fail 'plugin bootstrap changed beyond the generated Version value.'

diff -u \
	<(git show "$base_commit:readme.txt" \
		| sed -E 's/^(Stable tag:).*/\1 __RELEASE_VERSION__/') \
	<(git show "$release_commit:readme.txt" \
		| sed -E 's/^(Stable tag:).*/\1 __RELEASE_VERSION__/') \
	>/dev/null \
	|| fail 'readme changed beyond the generated Stable tag value.'

read -r changelog_additions changelog_deletions changelog_path \
	< <(git diff --numstat "$base_commit" "$release_commit" -- CHANGELOG.md)
[[ "$changelog_path" == 'CHANGELOG.md' \
	&& "$changelog_additions" =~ ^[1-9][0-9]*$ \
	&& "$changelog_deletions" == 0 ]] \
	|| fail 'changelog must preserve all accepted history and add the new release entry.'
release_changelog=$(git show "$release_commit:CHANGELOG.md")
grep -Fq "## [${release_version}](" <<< "$release_changelog" \
	|| fail 'changelog does not contain the proposed release heading.'

release_heading_line=$(grep -nF -m1 "## [${release_version}](" <<< "$release_changelog" \
	| cut -d: -f1)
base_heading_line=$(grep -nE -m1 '^## \[[0-9]+\.[0-9]+\.[0-9]+' <<< "$release_changelog" \
	| cut -d: -f1)
[[ -n "$release_heading_line" && "$release_heading_line" == "$base_heading_line" ]] \
	|| fail 'new release entry must remain the first version section in the changelog.'

if [[ -n "$generated_commit" ]]; then
	generated_base=$(git rev-parse "${generated_commit}^")
	changelog_additions() {
		git diff --no-ext-diff --unified=0 "$1" "$2" -- CHANGELOG.md \
			| awk '/^@@ / { hunk = 1; next } hunk && /^\+/ { print substr($0, 2) }'
	}
	diff -u \
		<(changelog_additions "$generated_base" "$generated_commit") \
		<(changelog_additions "$base_commit" "$release_commit") \
		>/dev/null \
		|| fail 'updated release candidate changelog additions must equal its generated release parent.'
fi

printf 'Validated Release Please candidate %s at %s.\n' "$release_version" "$release_commit"
