#!/usr/bin/env bash
set -euo pipefail

[[ $# -eq 4 ]] || exit 2
workflow_id=$1
head_branch=$2
head_sha=$3
repository=$4
[[ "$workflow_id" =~ ^[1-9][0-9]*$ ]] || exit 2
[[ "$head_sha" =~ ^[0-9a-f]{40}$ ]] || exit 2

jq -e \
	--arg bot 'github-actions[bot]' \
	--arg branch "$head_branch" \
	--arg repository "$repository" \
	--arg sha "$head_sha" \
	--argjson workflow_id "$workflow_id" \
	'any(.workflow_runs[];
		.workflow_id == $workflow_id
		and .path == ".github/workflows/quality.yml"
		and .event == "workflow_dispatch"
		and .head_branch == $branch
		and .head_sha == $sha
		and .head_repository.full_name == $repository
		and .repository.full_name == $repository
		and .actor.login == $bot
		and .triggering_actor.login == $bot
		and (
			.status == "queued"
			or .status == "in_progress"
			or (.status == "completed" and .conclusion == "success")
		)
	)' >/dev/null
