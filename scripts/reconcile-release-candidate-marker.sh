#!/usr/bin/env bash
set -euo pipefail

fail() {
	printf 'release candidate marker: %s\n' "$*" >&2
	exit 1
}

[[ $# -eq 3 ]] || fail 'expected <action-output:true|false> <base-sha> <head-sha>.'
action_output=$1
base_sha=$2
head_sha=$3
[[ "$action_output" == true || "$action_output" == false ]] || fail 'invalid action-output state.'
[[ "$base_sha" =~ ^[0-9a-f]{40}$ ]] || fail 'invalid base SHA.'
[[ "$head_sha" =~ ^[0-9a-f]{40}$ ]] || fail 'invalid head SHA.'

input=$(cat)
jq -e '.comments | type == "array"' <<< "$input" >/dev/null \
	|| fail 'comments input must be an array.'

marker_prefix='<!-- ran-booster-release-candidate:'
expected_marker="${marker_prefix} ${head_sha} -->"
marker_comments=$(jq -cer \
	--arg bot 'github-actions[bot]' \
	--arg prefix "$marker_prefix" \
	'[.comments[] | select(
		.user.login == $bot
		and (.body | type) == "string"
		and (.body | startswith($prefix))
	)]' <<< "$input")
if [[ "$action_output" == false ]]; then
	jq -e \
		--arg base "$base_sha" \
		--arg bot 'github-actions[bot]' \
		--arg bot_email '41898282+github-actions[bot]@users.noreply.github.com' \
		--arg committer_email 'noreply@github.com' \
		--arg head "$head_sha" \
		--arg signer web-flow \
		'.identity.data.repository.pullRequest.commits.nodes
		| length == 1
		and .[0].commit.oid == $head
		and (.[0].commit.parents.nodes | length == 1 and .[0].oid == $base)
		and .[0].commit.signature.isValid == true
		and .[0].commit.signature.state == "VALID"
		and .[0].commit.signature.signer.login == $signer
		and .[0].commit.author.user.login == $bot
		and .[0].commit.author.email == $bot_email
		and .[0].commit.committer.email == $committer_email' \
		<<< "$input" >/dev/null \
		|| fail 'non-action reconciliation requires the exact verified Release Please bot commit identity.'
fi

marker_count=$(jq -er 'length' <<< "$marker_comments")
(( marker_count <= 1 )) || fail 'expected at most one bot-authored release candidate marker.'

if (( marker_count == 1 )); then
	marker_body=$(jq -er '.[0].body' <<< "$marker_comments")
	if [[ "$marker_body" == "$expected_marker" ]]; then
		jq -nc --arg marker "$expected_marker" '{operation: "none", marker: $marker}'
		exit 0
	fi
fi

if (( marker_count == 0 )); then
	jq -nc --arg marker "$expected_marker" '{operation: "post", marker: $marker}'
else
	marker_id=$(jq -er '.[0].id | select(type == "number" and . > 0)' <<< "$marker_comments") \
		|| fail 'stale marker has no valid comment id.'
	jq -nc \
		--arg marker "$expected_marker" \
		--argjson comment_id "$marker_id" \
		'{operation: "patch", comment_id: $comment_id, marker: $marker}'
fi
