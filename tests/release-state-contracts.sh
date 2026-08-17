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

printf 'Release tag, immutable asset, and retry actor fixtures passed.\n'
