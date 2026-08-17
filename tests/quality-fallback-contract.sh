#!/usr/bin/env bash
set -euo pipefail

repo_root=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
selector="$repo_root/scripts/select-merged-release-pr.sh"
fixture="$repo_root/tests/fixtures/release-state/merged-prs.json"
main_commit=3333333333333333333333333333333333333333
repository=RocketsAreNostalgic/ran-booster
branch=release-please--branches--main--components--ran-booster

selected=$(bash "$selector" "$main_commit" "$repository" "$branch" < "$fixture")
jq -e \
	--arg base 1111111111111111111111111111111111111111 \
	--arg head 2222222222222222222222222222222222222222 \
	'.number == 43 and .base.sha == $base and .head.sha == $head' \
	<<< "$selected" >/dev/null

for wrong in \
	4444444444444444444444444444444444444444 \
	cccccccccccccccccccccccccccccccccccccccc; do
	if bash "$selector" "$wrong" "$repository" "$branch" < "$fixture" >/dev/null 2>&1; then
		printf 'non-release main commit %s was accepted\n' "$wrong" >&2
		exit 1
	fi
done

duplicate=$(jq '.[0] += [.[0][0]]' "$fixture")
if bash "$selector" "$main_commit" "$repository" "$branch" <<< "$duplicate" >/dev/null 2>&1; then
	printf 'ambiguous merged Release Please identity was accepted\n' >&2
	exit 1
fi

printf 'Merged Release Please fallback selection passed.\n'
