#!/usr/bin/env bash
set -euo pipefail

fail() {
	printf 'merged release pull request: %s\n' "$*" >&2
	exit 1
}

[[ $# -eq 3 ]] || fail 'expected <main-commit> <repository> <release-branch>.'
main_commit=$1
repository=$2
release_branch=$3
[[ "$main_commit" =~ ^[0-9a-f]{40}$ ]] || fail 'invalid main commit.'
[[ "$repository" =~ ^[^/[:space:]]+/[^/[:space:]]+$ ]] || fail 'invalid repository.'
[[ -n "$release_branch" && "$release_branch" != *[[:space:]]* ]] || fail 'invalid release branch.'

jq -cer \
	--arg base main \
	--arg bot 'github-actions[bot]' \
	--arg head "$release_branch" \
	--arg merge "$main_commit" \
	--arg repository "$repository" \
	'add
	| [.[] | select(
		.merged_at != null
		and .base.ref == $base
		and .head.ref == $head
		and .head.repo.full_name == $repository
		and .merge_commit_sha == $merge
		and .user.login == $bot
	)]
	| if length == 1 then .[0] else error("Expected exactly one merged Release Please pull request for the main commit.") end'
