#!/usr/bin/env bash
set -euo pipefail

repo_root=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
fixtures="$repo_root/tests/fixtures/release-state"
work_root=$(mktemp -d "${TMPDIR:-/tmp}/ran-bitbucket-release-state-test.XXXXXX")
trap 'rm -rf "$work_root"' EXIT

mock_bin="$work_root/bin"
mkdir -p "$mock_bin"
cat > "$mock_bin/gh" <<'EOF'
#!/usr/bin/env bash
set -euo pipefail
[[ "$1" == api && $# -eq 2 ]]
endpoint=$2
case "${TAG_SCENARIO}:${endpoint}" in
	lightweight:*/git/ref/tags/v1.2.3)
		file=tag-lightweight-ref.json
		;;
	annotated:*/git/ref/tags/v1.2.3|annotated-shadow:*/git/ref/tags/v1.2.3)
		file=tag-annotated-ref.json
		;;
	annotated:*/git/tags/bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb)
		file=tag-annotated-object.json
		;;
	annotated-shadow:*/git/tags/bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb)
		file=tag-annotated-shadow-object.json
		;;
	shadow:*/git/ref/tags/v1.2.3)
		file=tag-shadow-ref.json
		;;
	*)
		exit 1
		;;
esac
cat "$FIXTURE_DIR/$file"
EOF
chmod +x "$mock_bin/gh"

tag_verifier="$repo_root/scripts/verify-release-tag-target.sh"
expected_commit=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa
for scenario in lightweight annotated; do
	PATH="$mock_bin:$PATH" FIXTURE_DIR="$fixtures" TAG_SCENARIO="$scenario" \
		"$tag_verifier" RocketsAreNostalgic/ran-booster v1.2.3 "$expected_commit" >/dev/null
done
for scenario in shadow annotated-shadow; do
	if PATH="$mock_bin:$PATH" FIXTURE_DIR="$fixtures" TAG_SCENARIO="$scenario" \
		"$tag_verifier" RocketsAreNostalgic/ran-booster v1.2.3 "$expected_commit" >/dev/null 2>&1; then
		printf 'shadow tag scenario %s was accepted\n' "$scenario" >&2
		exit 1
	fi
done

quality="$work_root/quality"
remote="$work_root/remote"
mkdir -p "$quality" "$remote"
archive=ran-booster-1.2.3.zip
checksum="${archive}.sha256"
printf 'verified archive bytes\n' > "$quality/$archive"
printf 'verified checksum bytes\n' > "$quality/$checksum"
cp "$quality/$archive" "$remote/$archive"
cp "$quality/$checksum" "$remote/$checksum"
asset_verifier="$repo_root/scripts/verify-immutable-release-assets.sh"
"$asset_verifier" "$quality/$archive" "$quality/$checksum" "$remote" \
	< "$fixtures/immutable-assets-exact.json" >/dev/null
printf 'replaced published bytes\n' > "$remote/$archive"
if "$asset_verifier" "$quality/$archive" "$quality/$checksum" "$remote" \
	< "$fixtures/immutable-assets-exact.json" >/dev/null 2>&1; then
	printf 'replaced published archive bytes were accepted\n' >&2
	exit 1
fi
cp "$quality/$archive" "$remote/$archive"
if "$asset_verifier" "$quality/$archive" "$quality/$checksum" "$remote" \
	< "$fixtures/immutable-assets-extra.json" >/dev/null 2>&1; then
	printf 'published release with an extra asset was accepted\n' >&2
	exit 1
fi

run_fixture="$fixtures/retry-runs.json"
run_selector="$repo_root/scripts/has-trusted-release-candidate-run.sh"
branch=release-please--branches--main--components--ran-booster
head_sha=dddddddddddddddddddddddddddddddddddddddd
repository=RocketsAreNostalgic/ran-booster
select_run() {
	jq --argjson id "$1" '{workflow_runs: [.workflow_runs[] | select(.id == $id)]}' "$run_fixture"
}
for id in 1 2; do
	select_run "$id" | "$run_selector" 42 "$branch" "$head_sha" "$repository" \
		|| { printf 'trusted active/successful run %s did not suppress retry\n' "$id" >&2; exit 1; }
done
for id in 3 4 5 6; do
	if select_run "$id" | "$run_selector" 42 "$branch" "$head_sha" "$repository"; then
		printf 'failed, cancelled, or non-bot run %s suppressed retry\n' "$id" >&2
		exit 1
	fi
done

release_workflow="$repo_root/.github/workflows/release-please.yml"
action_parser="$work_root/release-action-parser.sh"
{
	printf '%s\n' '#!/usr/bin/env bash' 'set -euo pipefail'
	printf '%s\n' "release_branch='release-please--branches--main--components--ran-booster'"
	awk '
		/^          expected_files=/ { capture = 1 }
		capture {
			end = ( $0 == "          fi" )
			sub( /^          /, "" )
			print
			if ( end ) {
				exit
			}
		}
	' "$release_workflow"
	printf '%s\n' 'test "$action_pr_number" = 54'
} > "$action_parser"

release_action_pr() {
	jq -nc --argjson files "$1" '[{
		number: 54,
		baseBranchName: "main",
		headBranchName: "release-please--branches--main--components--ran-booster",
		files: $files
	}]'
}

assert_action_payload_accepted() {
	RAN_RELEASE_PRS_CREATED=true \
		RAN_RELEASE_PRS="$1" \
		bash "$action_parser"
}

assert_action_files_rejected() {
	if assert_action_payload_accepted "$(release_action_pr "$1")" >/dev/null 2>&1; then
		printf 'invalid Release Please action files were accepted: %s\n' "$1" >&2
		exit 1
	fi
}

assert_action_payload_accepted "$(release_action_pr '[]')"
assert_action_payload_accepted "$(release_action_pr '[{"filename":"readme.txt"},"CHANGELOG.md",{"path":"ran-booster.php"},".release-please-manifest.json"]')"
assert_action_files_rejected '["CHANGELOG.md"]'
assert_action_files_rejected '[".release-please-manifest.json","CHANGELOG.md","ran-booster.php","readme.txt","arbitrary.txt"]'
assert_action_files_rejected '[".release-please-manifest.json","CHANGELOG.md","ran-booster.php","readme.txt","readme.txt"]'
assert_action_files_rejected '["arbitrary.txt"]'
assert_action_files_rejected 'null'
missing_files=$(jq -nc '[{
	number: 54,
	baseBranchName: "main",
	headBranchName: "release-please--branches--main--components--ran-booster"
}]')
if assert_action_payload_accepted "$missing_files" >/dev/null 2>&1; then
	printf 'Release Please action output without files was accepted\n' >&2
	exit 1
fi

printf 'Release tag, immutable asset, and retry actor fixtures passed.\n'
