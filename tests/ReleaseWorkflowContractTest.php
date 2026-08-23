<?php

declare(strict_types=1);

namespace RANTests;

use PHPUnit\Framework\TestCase;

final class ReleaseWorkflowContractTest extends TestCase {
	public function testQualityUsesExactSecretlessCandidateDispatchAndMergeNeutralAdmission(): void {
		$workflow = $this->workflow( 'quality.yml' );

		self::assertStringContainsString( 'workflow_dispatch:', $workflow );
		self::assertStringContainsString( 'release_pr:', $workflow );
		self::assertStringContainsString( 'release_sha:', $workflow );
		self::assertStringContainsString( '"$GITHUB_ACTOR" == \'github-actions[bot]\'', $workflow );
		self::assertStringContainsString( '"$GITHUB_TRIGGERING_ACTOR" == \'github-actions[bot]\'', $workflow );
		self::assertStringContainsString( 'test "$RAN_DISPATCH_RELEASE_SHA" = "$pr_head_sha"', $workflow );
		self::assertStringContainsString( 'test "$GITHUB_SHA" = "$pr_head_sha"', $workflow );
		self::assertStringContainsString( 'bash scripts/validate-release-candidate.sh "$pr_base_sha" "$pr_head_sha"', $workflow );
		self::assertStringContainsString( 'and .merge_commit_sha == $merge', $workflow );
		self::assertStringContainsString( 'repos/${GITHUB_REPOSITORY}/git/commits/${pr_head_sha}', $workflow );
		self::assertStringContainsString( '.pull_request.tested_tree == $main_tree', $workflow );
		self::assertStringContainsString( 'expected_event=workflow_dispatch', $workflow );
		self::assertStringContainsString( '.triggering_actor.login == $bot', $workflow );
		self::assertStringContainsString( 'Exact prior PR evidence was unavailable; running the full fallback lane.', $workflow );
		self::assertStringContainsString( 'recover_release_fallback_identity()', $workflow );
		self::assertStringContainsString( 'for _ in 1 2 3', $workflow );
		self::assertStringContainsString( 'Preserved Release Please pull request ${pr_number} and head ${pr_head_sha} for the full fallback artifact.', $workflow );
		self::assertStringContainsString( 'Release Please identity could not be recovered for a main commit that changes the release manifest.', $workflow );
		self::assertStringContainsString( 'bash scripts/validate-release-candidate.sh "$pr_base_sha" "$pr_head_sha" || return 1', $workflow );
		self::assertStringContainsString( 'bash scripts/select-merged-release-pr.sh', $workflow );
		self::assertStringNotContainsString( 'GITHUB_SHA}^1', $workflow );
		self::assertStringNotContainsString( 'GITHUB_SHA}^2', $workflow );
		self::assertStringNotContainsString( 'parent_count', $workflow );
	}

	public function testQualityTreatsDependencyAndReleaseAuthorityAsAdmissionTrustPaths(): void {
		$workflow = $this->workflow( 'quality.yml' );

		foreach ( array(
			'composer.json',
			'composer.lock',
			'package.json',
			'pnpm-lock.yaml',
			'.github/workflows/quality.yml',
			'.github/workflows/release-please.yml',
			'scripts/build-release.sh',
			'scripts/verify-release.sh',
			'scripts/validate-release-candidate.sh',
			'scripts/select-merged-release-pr.sh',
			'scripts/reconcile-release-candidate-marker.sh',
			'scripts/verify-release-tag-target.sh',
			'scripts/has-trusted-release-candidate-run.sh',
			'scripts/verify-immutable-release-assets.sh',
		) as $path ) {
			self::assertStringContainsString( $path, $workflow );
		}
		self::assertStringContainsString( 'all(.[]; .filename != $path)', $workflow );
	}

	public function testQualityFetchesCandidateCommitsWithEphemeralTokenCredentials(): void {
		$workflow         = $this->workflow( 'quality.yml' );
		$credentialHelper = 'credential.helper=!f() { printf "%s\\n" "username=x-access-token" "password=$GH_TOKEN"; }; f';

		self::assertStringContainsString( 'persist-credentials: false', $workflow );
		self::assertSame( 1, substr_count( $workflow, 'test -n "$GH_TOKEN"' ) );
		self::assertSame( 1, substr_count( $workflow, $credentialHelper ) );
		self::assertSame( 2, substr_count( $workflow, 'git "${git_auth[@]}" fetch --no-tags origin' ) );
		self::assertStringNotContainsString( 'git fetch --no-tags origin', $workflow );
		self::assertStringNotContainsString( 'password=${GH_TOKEN}', $workflow );
	}

	public function testQualitySharesOneVerifiedArchiveAndKeepsTheCoreGates(): void {
		$workflow = $this->workflow( 'quality.yml' );

		self::assertStringContainsString( 'schema_version: 3', $workflow );
		self::assertStringContainsString( 'main_commit: $main_commit', $workflow );
		self::assertStringContainsString( 'original_run_id: $original_run_id', $workflow );
		self::assertStringContainsString( 'build/ran-booster-${{ steps.finalize.outputs.version }}.zip.sha256', $workflow );
		self::assertStringContainsString( 'wordpress-release-candidate:', $workflow );
		self::assertStringContainsString( 'RAN_PR_HEAD_SHA: ${{ needs.runtime-archive.outputs.pr-head-sha }}', $workflow );
		self::assertStringContainsString( '--arg event "$GITHUB_EVENT_NAME"', $workflow );
		self::assertSame( 2, substr_count( $workflow, 'and .run.event == $event' ) );
		self::assertStringNotContainsString( 'and .run.event == "workflow_dispatch"', $workflow );
		self::assertStringContainsString( 'run: composer check', $workflow );
		self::assertStringContainsString( 'run: pnpm check', $workflow );
		self::assertStringContainsString( 'matrix: ${{ fromJSON(needs.runtime-archive.outputs.wordpress-matrix) }}', $workflow );
		self::assertSame( 1, substr_count( $workflow, 'bash scripts/build-release.sh' ) );
	}

	public function testQualityMaterializesTheExactLockedUpdaterForFreshArchives(): void {
		$workflow = $this->workflow( 'quality.yml' );

		self::assertSame( 2, substr_count( $workflow, 'Check out locked neutral updater source' ) );
		self::assertStringContainsString( 'git show "${source_commit}:composer.lock"', $workflow );
		self::assertStringContainsString( '.name == "ran/wp-release-updater"', $workflow );
		self::assertStringContainsString( '.dist.url == "../ran-wp-release-updater"', $workflow );
		self::assertStringContainsString( 'updater_repository="$(dirname "$GITHUB_WORKSPACE")/ran-wp-release-updater"', $workflow );
		self::assertSame( 2, substr_count( $workflow, 'git -C "$updater_repository" fetch --quiet --no-tags --depth=1 origin "$updater_commit"' ) );
		self::assertStringContainsString( 'test "$(git -C "$updater_repository" rev-parse HEAD)" = "$updater_commit"', $workflow );
		self::assertStringContainsString( '"package_revision" => "c546309ae0a74518d64c2e05d6e703b723b0ca3b2dc328cb3d49f552f94975bc"', $workflow );
		self::assertStringNotContainsString( '0b506753fb45115f946bf01253ab76b893b3804e33d0f5b6f8a14c2026d59516', $workflow );
		self::assertStringNotContainsString( '320fb89e1a93813907419cecab7e05892b6d9419', $workflow );
	}

	public function testQualityReadbackUsesNeutralRuntimeMetadataRatherThanTheRemovedGitHubFacade(): void {
		$workflow = $this->workflow( 'quality.yml' );

		self::assertStringContainsString( 'Read back the neutral updater runtime contract', $workflow );
		self::assertStringContainsString( 'WP_PLUGIN_DIR . "/ran-booster/vendor/ran/wp-release-updater"', $workflow );
		self::assertStringContainsString( '"package_version" => "0.1.0-beta.1"', $workflow );
		self::assertStringContainsString( '"runtime_protocol" => 1', $workflow );
		self::assertStringContainsString( 'RAN\\\\\\\\WPReleaseUpdater\\\\\\\\V1\\\\\\\\WordPress\\\\\\\\NativePluginUpdater', $workflow );
		self::assertStringNotContainsString( 'ran_booster_release_updater', $workflow );
		self::assertStringNotContainsString( 'selection_fixed', $workflow );
	}

	public function testCandidateBehaviorInvokesTheValidatorThroughBash(): void {
		$contract = file_get_contents( __DIR__ . '/release-candidate-contract.sh' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local workflow contract.
		self::assertIsString( $contract );
		self::assertSame( 2, substr_count( $contract, 'bash "$validator" "$base_sha" "$head_sha"' ) );
		self::assertSame( 0, preg_match( '/^\s*"\$validator"/m', $contract ) );
	}

	public function testReleaseReconcilesActionOutputAndRecoverableBotIdentityBeforeDispatch(): void {
		$workflow = $this->workflow( 'release-please.yml' );

		self::assertStringContainsString( 'id: release-please', $workflow );
		self::assertStringContainsString( 'steps.release-please.outputs.prs', $workflow );
		self::assertStringContainsString( 'steps.release-please.outputs.prs_created', $workflow );
		self::assertStringNotContainsString( '.sha | type == "string" and test("^[0-9a-f]{40}$")', $workflow );
		self::assertStringContainsString( 'expected_files=\'[".release-please-manifest.json","CHANGELOG.md","ran-booster.php","readme.txt"]\'', $workflow );
		self::assertStringContainsString( '(.files | type) == "array"', $workflow );
		self::assertStringContainsString( '(.files | length) == 0', $workflow );
		self::assertStringContainsString( 'commits(last: 1)', $workflow );
		self::assertStringContainsString( 'signature {', $workflow );
		self::assertStringContainsString( 'bash scripts/reconcile-release-candidate-marker.sh', $workflow );
		self::assertStringContainsString( 'bash scripts/has-trusted-release-candidate-run.sh', $workflow );
		self::assertStringContainsString( 'gh workflow run quality.yml --ref "$head_ref"', $workflow );
		self::assertStringContainsString( '-f "release_pr=${pr_number}"', $workflow );
		self::assertStringContainsString( '-f "release_sha=${head_sha}"', $workflow );
	}

	public function testReleaseFetchesCandidatesWithEphemeralTokenCredentials(): void {
		$workflow         = $this->workflow( 'release-please.yml' );
		$credentialHelper = 'credential.helper=!f() { printf "%s\\n" "username=x-access-token" "password=$GH_TOKEN"; }; f';

		self::assertStringContainsString( 'persist-credentials: false', $workflow );
		self::assertSame( 2, substr_count( $workflow, 'test -n "$GH_TOKEN"' ) );
		self::assertSame( 2, substr_count( $workflow, $credentialHelper ) );
		self::assertSame( 4, substr_count( $workflow, 'git "${git_auth[@]}" fetch --no-tags origin' ) );
		self::assertStringNotContainsString( 'git fetch --no-tags origin', $workflow );
		self::assertStringNotContainsString( 'password=${GH_TOKEN}', $workflow );
	}

	public function testReleaseUsesMergedPrApiAndSeparatesHeadArtifactFromMainTarget(): void {
		$workflow = $this->workflow( 'release-please.yml' );

		self::assertStringContainsString( 'and .merge_commit_sha == $merge', $workflow );
		self::assertStringContainsString( 'RAN_RELEASE_COMMIT=%s\\n\' "$RAN_QUALITY_COMMIT"', $workflow );
		self::assertStringContainsString( 'RAN_RELEASE_HEAD_COMMIT=%s\\n\' "$release_head"', $workflow );
		self::assertStringContainsString( 'test "$main_tree" = "$head_tree"', $workflow );
		self::assertStringContainsString( '.source_commit == $head_commit', $workflow );
		self::assertStringContainsString( '.admission.main_commit == $main_commit', $workflow );
		self::assertStringContainsString( '--target "$RAN_RELEASE_COMMIT"', $workflow );
		self::assertStringNotContainsString( 'RAN_QUALITY_COMMIT}^1', $workflow );
		self::assertStringNotContainsString( 'RAN_QUALITY_COMMIT}^2', $workflow );
		self::assertStringNotContainsString( 'parent_count', $workflow );
	}

	public function testReleaseVerifiesEmbeddedMarkerAgainstMetadataSourceCommit(): void {
		$workflow    = $this->workflow( 'release-please.yml' );
		$markerCheck = strstr( $workflow, 'unzip -p "$archive" ran-booster/ran-booster-release.json' );

		self::assertStringContainsString( 'source_commit="$(jq -er \'.source_commit\' "$metadata")"', $workflow );
		self::assertStringContainsString(
			'(
                (
                  .mode == "admitted"
                  and .lane == "release-candidate"
                  and .source_commit == $head_commit',
			$workflow
		);
		self::assertStringContainsString(
			'or
                (
                  .mode == "built"
                  and .lane == "full"
                  and .source_commit == $main_commit',
			$workflow
		);
		self::assertIsString( $markerCheck );
		self::assertStringContainsString( '--arg commit "$source_commit"', $markerCheck );
		self::assertStringNotContainsString( '--arg commit "$RAN_RELEASE_HEAD_COMMIT"', $markerCheck );
	}

	public function testReleaseVerifiesTagAndExactAssetBytesBeforeAndAfterImmutability(): void {
		$workflow = $this->workflow( 'release-please.yml' );

		self::assertGreaterThanOrEqual( 3, substr_count( $workflow, 'bash scripts/verify-release-tag-target.sh' ) );
		self::assertSame( 2, substr_count( $workflow, 'bash scripts/verify-immutable-release-assets.sh' ) );
		self::assertStringContainsString( 'build/ran-booster-${RAN_RELEASE_VERSION}.zip.sha256', $workflow );
		self::assertStringContainsString( 'gh release upload "$RAN_RELEASE_TAG"', $workflow );
		self::assertStringContainsString( '--clobber', $workflow );
		self::assertStringContainsString( '.tag_name == $tag and .draft == false and .immutable == true', $workflow );

		$upload    = strpos( $workflow, 'gh release upload "$RAN_RELEASE_TAG"' );
		$precheck  = strpos( $workflow, 'bash scripts/verify-immutable-release-assets.sh', $upload );
		$publish   = strpos( $workflow, 'gh release edit "$RAN_RELEASE_TAG" --draft=false' );
		$postcheck = strrpos( $workflow, 'bash scripts/verify-immutable-release-assets.sh' );
		self::assertIsInt( $upload );
		self::assertIsInt( $precheck );
		self::assertIsInt( $publish );
		self::assertIsInt( $postcheck );
		self::assertTrue( $upload < $precheck );
		self::assertTrue( $precheck < $publish );
		self::assertTrue( $publish < $postcheck );
	}

	public function testWorkflowActionsArePinnedToImmutableCommits(): void {
		foreach ( array( 'quality.yml', 'release-please.yml' ) as $workflowName ) {
			$workflow = $this->workflow( $workflowName );
			self::assertSame( 1, preg_match_all( '/^\s*uses:\s*[^.\s][^@\s]*@([^\s#]+)/m', $workflow, $matches ) > 0 ? 1 : 0 );
			foreach ( $matches[1] as $reference ) {
				self::assertMatchesRegularExpression( '/^[0-9a-f]{40}$/', $reference, $workflowName . ' has a mutable action reference.' );
			}
		}
	}

	private function workflow( string $name ): string {
		$workflow = file_get_contents( dirname( __DIR__ ) . '/.github/workflows/' . $name ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local workflow contract.
		self::assertIsString( $workflow );

		return $workflow;
	}
}
