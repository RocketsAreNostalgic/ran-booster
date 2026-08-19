#!/usr/bin/env bash
set -euo pipefail

repo_root=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
reconciler="$repo_root/scripts/reconcile-release-candidate-marker.sh"
base_sha=1111111111111111111111111111111111111111
old_head=2222222222222222222222222222222222222222
new_head=3333333333333333333333333333333333333333
old_marker="<!-- ran-booster-release-candidate: ${old_head} -->"
new_marker="<!-- ran-booster-release-candidate: ${new_head} -->"

fixture=$(jq -nc \
	--arg base "$base_sha" \
	--arg bot 'github-actions[bot]' \
	--arg bot_email '41898282+github-actions[bot]@users.noreply.github.com' \
	--arg committer_email 'noreply@github.com' \
	--arg head "$new_head" \
	--arg old_marker "$old_marker" \
	'{
		comments: [{id: 91, user: {login: $bot}, body: $old_marker}],
		identity: {
			data: {
				repository: {
					pullRequest: {
						commits: {
							nodes: [{
								commit: {
									oid: $head,
									parents: {nodes: [{oid: $base}]},
									signature: {isValid: true, state: "VALID", signer: {login: "web-flow"}},
									author: {email: $bot_email, user: {login: $bot}},
									committer: {email: $committer_email}
								}
							}]
						}
					}
				}
			}
		}
	}')

transition=$("$reconciler" false "$base_sha" "$new_head" <<< "$fixture")
jq -e \
	--arg marker "$new_marker" \
	'.operation == "patch" and .comment_id == 91 and .marker == $marker' \
	<<< "$transition" >/dev/null

transition=$("$reconciler" true "$base_sha" "$new_head" <<< "$fixture")
jq -e \
	--arg marker "$new_marker" \
	'.operation == "patch" and .comment_id == 91 and .marker == $marker' \
	<<< "$transition" >/dev/null

invalid_identity=$(jq '.identity.data.repository.pullRequest.commits.nodes[0].commit.signature.isValid = false' <<< "$fixture")
if "$reconciler" false "$base_sha" "$new_head" <<< "$invalid_identity" >/dev/null 2>&1; then
	printf 'stale marker accepted an invalid bot signature\n' >&2
	exit 1
fi
if "$reconciler" true "$base_sha" "$new_head" <<< "$invalid_identity" >/dev/null 2>&1; then
	printf 'action output accepted an invalid bot signature\n' >&2
	exit 1
fi

duplicate=$(jq '.comments += [.comments[0]]' <<< "$fixture")
if "$reconciler" false "$base_sha" "$new_head" <<< "$duplicate" >/dev/null 2>&1; then
	printf 'duplicate bot markers were accepted\n' >&2
	exit 1
fi

current=$(jq --arg marker "$new_marker" '.comments[0].body = $marker' <<< "$fixture")
transition=$("$reconciler" false "$base_sha" "$new_head" <<< "$current")
jq -e '.operation == "none"' <<< "$transition" >/dev/null

current_without_identity=$(jq '.identity = null' <<< "$current")
if "$reconciler" false "$base_sha" "$new_head" <<< "$current_without_identity" >/dev/null 2>&1; then
	printf 'current marker bypassed non-action bot identity proof\n' >&2
	exit 1
fi

printf 'Release candidate marker transitions passed.\n'
