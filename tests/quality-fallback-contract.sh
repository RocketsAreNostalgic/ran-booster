#!/usr/bin/env bash
set -euo pipefail

repo_root=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
selector="$repo_root/scripts/select-merged-release-pr.sh"
validator="$repo_root/scripts/validate-release-candidate.sh"
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

work_root=$(mktemp -d "${TMPDIR:-/tmp}/ran-quality-lifecycle-test.XXXXXX")
trap 'rm -rf "$work_root"' EXIT
workflow="$repo_root/.github/workflows/quality.yml"
lifecycle="$work_root/lifecycle.sh"

awk '
  $0 == "        id: lifecycle" { lifecycle = 1; next }
  lifecycle && $0 == "        run: |" { block = 1; next }
  block && /^      - / { exit }
  block {
    sub(/^          /, "")
    print
    emitted = 1
  }
  END { if (!lifecycle || !block || !emitted) exit 1 }
' "$workflow" > "$lifecycle" || {
	printf 'Quality lifecycle step is unavailable.\n' >&2
	exit 1
}

mock_bin="$work_root/bin"
mkdir -p "$mock_bin"
cat > "$mock_bin/gh" <<'EOF'
#!/usr/bin/env bash
set -euo pipefail
case "$*" in
  *'/actions/workflows/quality.yml') printf '{"id":123}\n' ;;
  *'/runs?head_sha='*)
    if [[ "${RAN_MOCK_TRUSTED_RUN:-}" == true ]]; then
      printf '{"workflow_runs":[{"workflow_id":123,"path":".github/workflows/quality.yml","event":"workflow_dispatch","head_branch":"release-please--branches--main--components--ran-booster","head_sha":"%s","head_repository":{"full_name":"RocketsAreNostalgic/ran-booster"},"repository":{"full_name":"RocketsAreNostalgic/ran-booster"},"actor":{"login":"%s"},"triggering_actor":{"login":"github-actions[bot]"},"status":"%s","conclusion":"%s"}]}\n' "${RAN_MOCK_RUN_HEAD_SHA:-$RAN_MOCK_HEAD_SHA}" "${RAN_MOCK_RUN_ACTOR:-github-actions[bot]}" "${RAN_MOCK_RUN_STATUS:-completed}" "${RAN_MOCK_RUN_CONCLUSION:-success}"
    else
      printf '{"workflow_runs":[]}\n'
    fi
    ;;
  *) printf 'unexpected gh call: %s\n' "$*" >&2; exit 1 ;;
esac
EOF
chmod +x "$mock_bin/gh"

prepare_updated_candidate() {
	case_dir="$work_root/case-$1"
	mkdir -p "$case_dir"
	git -C "$case_dir" init --quiet
	git -C "$case_dir" config user.name 'Quality Fixture'
	git -C "$case_dir" config user.email 'quality@example.invalid'
	printf '{".":"1.2.3"}\n' > "$case_dir/.release-please-manifest.json"
	printf '# Changelog\n\n## [1.2.3](https://example.invalid/compare/v1.2.2...v1.2.3) (2026-01-01)\n\nAccepted history.\n' > "$case_dir/CHANGELOG.md"
	printf '<?php\n/**\n * Plugin Name: Quality Fixture\n * Version: 1.2.3\n */\n' > "$case_dir/ran-booster.php"
	printf '=== Quality Fixture ===\nStable tag: 1.2.3\n\nAccepted readme.\n' > "$case_dir/readme.txt"
	git -C "$case_dir" add .
	git -C "$case_dir" commit --quiet -m 'chore: seed release fixture'
	shared_sha=$(git -C "$case_dir" rev-parse HEAD)
	git -C "$case_dir" checkout --quiet -b "$branch"
	printf '{".":"1.2.4"}\n' > "$case_dir/.release-please-manifest.json"
	sed -i.bak -E 's/Version: 1\.2\.3/Version: 1.2.4/' "$case_dir/ran-booster.php"
	rm -f "$case_dir/ran-booster.php.bak"
	sed -i.bak -E 's/Stable tag: 1\.2\.3/Stable tag: 1.2.4/' "$case_dir/readme.txt"
	rm -f "$case_dir/readme.txt.bak"
	printf '# Changelog\n\n## [1.2.4](https://example.invalid/compare/v1.2.3...v1.2.4) (2026-01-02)\n\nGenerated release.\n\n## [1.2.3](https://example.invalid/compare/v1.2.2...v1.2.3) (2026-01-01)\n\nAccepted history.\n' > "$case_dir/CHANGELOG.md"
	git -C "$case_dir" add .
	git -C "$case_dir" commit --quiet -m 'chore(main): release 1.2.4'
	git -C "$case_dir" checkout --quiet -B main "$shared_sha"
	printf '<?php // Main advance.\n' > "$case_dir/main-advance.php"
	git -C "$case_dir" add main-advance.php
	git -C "$case_dir" commit --quiet -m 'feat: advance main'
	base_sha=$(git -C "$case_dir" rev-parse HEAD)
	git -C "$case_dir" checkout --quiet "$branch"
	git -C "$case_dir" merge --quiet --no-ff main -m 'Merge branch main into release candidate'
	head_sha=$(git -C "$case_dir" rev-parse HEAD)
	mkdir -p "$case_dir/scripts"
	cp "$validator" "$case_dir/scripts/validate-release-candidate.sh"
	cp "$repo_root/scripts/has-trusted-release-candidate-run.sh" "$case_dir/scripts/has-trusted-release-candidate-run.sh"
}

run_lifecycle() {
	local actor=$1
	local output=$2
	local trusted_run=${3:-true}
	local run_actor=${4:-github-actions[bot]}
	local run_head_sha=${5:-$head_sha}
	local run_status=${6:-completed}
	local run_conclusion=${7:-success}
	(
		cd "$case_dir"
		PATH="$mock_bin:$PATH" \
		GH_TOKEN=fixture-token \
		GITHUB_ACTOR="$actor" \
		GITHUB_EVENT_NAME=pull_request \
		GITHUB_OUTPUT="$output" \
		GITHUB_REPOSITORY="$repository" \
		GITHUB_SHA="$head_sha" \
		GITHUB_TRIGGERING_ACTOR="$actor" \
		RAN_DISPATCH_RELEASE_PR='' \
		RAN_DISPATCH_RELEASE_SHA='' \
		RAN_PR_AUTHOR='github-actions[bot]' \
		RAN_PR_BASE=main \
		RAN_PR_BASE_SHA="$base_sha" \
		RAN_PR_HEAD="$branch" \
		RAN_PR_HEAD_REPOSITORY="$repository" \
		RAN_PR_HEAD_SHA="$head_sha" \
		RAN_PR_NUMBER=100 \
		RAN_MOCK_HEAD_SHA="$head_sha" \
		RAN_MOCK_RUN_ACTOR="$run_actor" \
		RAN_MOCK_RUN_HEAD_SHA="$run_head_sha" \
		RAN_MOCK_RUN_STATUS="$run_status" \
		RAN_MOCK_RUN_CONCLUSION="$run_conclusion" \
		RAN_MOCK_TRUSTED_RUN="$trusted_run" \
		bash "$lifecycle"
	)
}

prepare_updated_candidate human
human_output="$work_root/human-output"
run_lifecycle bnjmnrsh "$human_output"
grep -Fx 'lane=full' "$human_output" >/dev/null

if run_lifecycle bnjmnrsh "$work_root/missing-proof-output" false >/dev/null 2>&1; then
	printf 'human-triggered Release Please pull request without trusted Quality proof was accepted\n' >&2
	exit 1
fi

if run_lifecycle bnjmnrsh "$work_root/forged-actor-output" true attacker >/dev/null 2>&1; then
	printf 'forged trusted Quality actor was accepted\n' >&2
	exit 1
fi

if run_lifecycle bnjmnrsh "$work_root/stale-head-output" true 'github-actions[bot]' 0000000000000000000000000000000000000000 >/dev/null 2>&1; then
	printf 'stale trusted Quality head was accepted\n' >&2
	exit 1
fi

if run_lifecycle bnjmnrsh "$work_root/in-progress-output" true 'github-actions[bot]' "$head_sha" in_progress '' >/dev/null 2>&1; then
	printf 'in-progress trusted Quality run was accepted\n' >&2
	exit 1
fi

if run_lifecycle bnjmnrsh "$work_root/failed-output" true 'github-actions[bot]' "$head_sha" completed failure >/dev/null 2>&1; then
	printf 'failed trusted Quality run was accepted\n' >&2
	exit 1
fi

printf '<?php // Unauthorized runtime change.\n' > "$case_dir/main-advance.php"
git -C "$case_dir" add main-advance.php
git -C "$case_dir" commit --quiet --amend --no-edit
head_sha=$(git -C "$case_dir" rev-parse HEAD)
if run_lifecycle bnjmnrsh "$work_root/malformed-output" >/dev/null 2>&1; then
	printf 'human-triggered malformed Release Please pull request was accepted\n' >&2
	exit 1
fi

prepare_updated_candidate bot
bot_output="$work_root/bot-output"
run_lifecycle 'github-actions[bot]' "$bot_output"
grep -Fx 'lane=release-candidate' "$bot_output" >/dev/null

printf 'Merged Release Please fallback selection and executable lifecycle classification passed.\n'
