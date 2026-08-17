#!/usr/bin/env bash
set -euo pipefail

fail() {
	printf 'release tag target: %s\n' "$*" >&2
	exit 1
}

[[ $# -eq 3 ]] || fail 'expected <repository> <tag> <release-commit>.'
repository=$1
tag=$2
release_commit=$3
[[ "$repository" =~ ^[^/[:space:]]+/[^/[:space:]]+$ ]] || fail 'invalid repository.'
[[ "$tag" =~ ^v[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z.-]+)?$ ]] || fail 'invalid release tag.'
[[ "$release_commit" =~ ^[0-9a-f]{40}$ ]] || fail 'invalid release commit.'

target=$(gh api "repos/${repository}/git/ref/tags/${tag}")
target_type=$(jq -er '.object.type | select(type == "string")' <<< "$target")
target_sha=$(jq -er '.object.sha | select(type == "string" and test("^[0-9a-f]{40}$"))' <<< "$target")
seen=''

for _ in {1..16}; do
	if [[ "$target_type" == commit ]]; then
		[[ "$target_sha" == "$release_commit" ]] \
			|| fail 'tag resolves to a different release commit.'
		printf 'Verified %s resolves to %s.\n' "$tag" "$release_commit"
		exit 0
	fi
	[[ "$target_type" == tag ]] || fail 'tag target is neither a commit nor an annotated tag.'
	[[ " $seen " != *" $target_sha "* ]] || fail 'annotated tag cycle detected.'
	seen="${seen} ${target_sha}"
	target=$(gh api "repos/${repository}/git/tags/${target_sha}")
	target_type=$(jq -er '.object.type | select(type == "string")' <<< "$target")
	target_sha=$(jq -er '.object.sha | select(type == "string" and test("^[0-9a-f]{40}$"))' <<< "$target")
done

fail 'annotated tag chain exceeds the supported depth.'
