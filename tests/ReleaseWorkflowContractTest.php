<?php

declare(strict_types=1);

namespace RANTests;

use PHPUnit\Framework\TestCase;

final class ReleaseWorkflowContractTest extends TestCase {
	public function testQualityReusesOnlyExactEvidenceAndNarrowsAValidatedReleaseCandidate(): void {
		$workflow = $this->workflow( 'quality.yml' );

		self::assertStringContainsString( 'pull_request:', $workflow );
		self::assertStringContainsString( 'push:', $workflow );
		self::assertStringContainsString( 'release-please--branches--main--components--ran-booster', $workflow );
		self::assertStringContainsString( 'github-actions[bot]', $workflow );
		self::assertStringContainsString( '"$GITHUB_ACTOR" == \'github-actions[bot]\'', $workflow );
		self::assertStringContainsString( '.actor.login == $bot', $workflow );
		self::assertStringContainsString( 'RAN_PR_HEAD_SHA: ${{ github.event.pull_request.head.sha }}', $workflow );
		self::assertStringContainsString( 'source_commit="$RAN_PR_HEAD_SHA"', $workflow );
		self::assertStringContainsString( 'bash scripts/validate-release-candidate.sh "$RAN_PR_BASE_SHA" "$RAN_PR_HEAD_SHA"', $workflow );
		self::assertStringContainsString( 'repos/${GITHUB_REPOSITORY}/pulls?state=closed&base=main&per_page=100', $workflow );
		self::assertStringNotContainsString( 'any(.pull_requests[]?', $workflow );
		self::assertStringContainsString( 'and .merge_commit_sha == $merge', $workflow );
		self::assertStringContainsString( 'test "$head_tree" = "$merge_tree"', $workflow );
		self::assertStringContainsString( '.pull_request.tested_tree == $merge_tree', $workflow );
		self::assertStringContainsString( 'git diff --quiet "$pr_base_sha" "$pr_head_sha" -- "$trust_path"', $workflow );
		self::assertStringContainsString( 'Exact prior PR evidence was unavailable; running the full fallback lane.', $workflow );
		self::assertStringContainsString( 'wordpress-release-candidate:', $workflow );
		self::assertStringContainsString( 'Release candidate install readback', $workflow );
		self::assertStringContainsString( 'wordpress_matrix=', $workflow );
		self::assertStringContainsString( '"7.0.3","database":"MySQL 8.4"', $workflow );
		self::assertStringContainsString( '"7.0","database":"MySQL 8.4"', $workflow );
		self::assertStringNotContainsString( sprintf( '"%s":"7.0.1"', strtolower( 'WordPress' ) ), $workflow );
		self::assertStringNotContainsString( sprintf( '"%s":"7.0.2"', strtolower( 'WordPress' ) ), $workflow );
		self::assertStringContainsString( '"database":"MariaDB 10.11"', $workflow );
		self::assertStringContainsString( '"database":"MySQL 8.0 floor"', $workflow );
		self::assertStringContainsString( 'matrix: ${{ fromJSON(needs.runtime-archive.outputs.wordpress-matrix) }}', $workflow );
		self::assertStringContainsString( "needs:\n            - runtime-archive\n            - quality", $workflow );
		self::assertStringContainsString( 'run: composer check', $workflow );
		self::assertStringNotContainsString( "\n              run: composer test\n", $workflow );
		self::assertStringNotContainsString( "\n              run: composer lint:php\n", $workflow );
		self::assertSame( 1, substr_count( $workflow, 'bash scripts/build-release.sh' ) );
		self::assertStringNotContainsString( 'bash scripts/verify-release.sh', $workflow );

		$validator = strpos( $workflow, 'bash scripts/validate-release-candidate.sh' );
		$candidate = strpos( $workflow, 'lane=release-candidate' );
		$builder   = strpos( $workflow, 'bash scripts/build-release.sh' );
		self::assertIsInt( $validator );
		self::assertIsInt( $candidate );
		self::assertIsInt( $builder );
		self::assertTrue( $validator < $candidate );
		self::assertTrue( $candidate < $builder );
	}

	public function testQualitySharesTheVerifiedArchiveAcrossEveryConsumer(): void {
		$workflow = $this->workflow( 'quality.yml' );

		self::assertStringContainsString( 'schema: "ran-booster-ci-runtime"', $workflow );
		self::assertStringContainsString( 'schema_version: 2', $workflow );
		self::assertStringContainsString( 'mode: "built"', $workflow );
		self::assertStringContainsString( 'lane: $lane', $workflow );
		self::assertStringContainsString( 'quality_commit: $quality_commit', $workflow );
		self::assertStringContainsString( 'source_commit: $source_commit', $workflow );
		self::assertStringContainsString( 'source_tree: $source_tree', $workflow );
		self::assertStringContainsString( 'workflow_ref: $workflow_ref', $workflow );
		self::assertStringContainsString( 'workflow_sha: $workflow_sha', $workflow );
		self::assertStringContainsString( 'pull_request:', $workflow );
		self::assertStringContainsString( 'original_run_id: $original_run_id', $workflow );
		self::assertStringContainsString( 'printf \'artifact-name=ran-booster-runtime-%s-%s\n\' "$GITHUB_RUN_ID" "$GITHUB_RUN_ATTEMPT"', $workflow );
		self::assertStringContainsString( 'actions/upload-artifact@043fb46d1a93c77aae656e7c1c64a875d1fc6a0a', $workflow );
		self::assertStringContainsString( 'actions/download-artifact@3e5f45b2cfb9172054b4087a40e8e0b5a5461e7c', $workflow );
		self::assertStringContainsString( 'needs.runtime-archive.outputs.archive-sha256', $workflow );
		self::assertStringContainsString( 'sha256sum --check --strict', $workflow );
		self::assertStringContainsString( 'retention-days: 30', $workflow );
		self::assertStringContainsString( 'wp plugin install "$GITHUB_WORKSPACE/build/ran-booster-${{ needs.runtime-archive.outputs.version }}.zip"', $workflow );
		self::assertStringContainsString( 'wp plugin get ran-booster --field=version', $workflow );
		self::assertStringContainsString( '"$plugin_root/ran-booster-release.json"', $workflow );
		self::assertStringContainsString( 'diff -qr', $workflow );
		self::assertStringContainsString( '"$plugin_root/vendor/ran/wp-github-release-updater"', $workflow );
		self::assertStringContainsString(
			'wp eval-file "$GITHUB_WORKSPACE/tests/WordPress/github-provider-installed-readback.php" --path=build/wordpress',
			$workflow
		);

		$installation = strpos( $workflow, '- name: Install and activate runtime archive' );
		$identity     = strpos( $workflow, '- name: Read back the installed release identity' );
		$provider     = strpos( $workflow, '- name: Read back the installed GitHub provider contract' );
		self::assertIsInt( $installation );
		self::assertIsInt( $identity );
		self::assertIsInt( $provider );
		self::assertTrue( $installation < $identity );
		self::assertTrue( $identity < $provider );
	}

	public function testReleaseWaitsForSuccessfulMainQualityAndUsesItsCurrentRunArtifact(): void {
		$workflow = $this->workflow( 'release-please.yml' );

		self::assertStringContainsString( 'workflow_run:', $workflow );
		self::assertStringContainsString( '- Quality', $workflow );
		self::assertStringContainsString( "github.event.workflow_run.event == 'push'", $workflow );
		self::assertStringContainsString( "github.event.workflow_run.conclusion == 'success'", $workflow );
		self::assertStringContainsString( "github.event.workflow_run.head_branch == 'main'", $workflow );
		self::assertStringContainsString( 'github.event.workflow_run.head_repository.full_name == github.repository', $workflow );
		self::assertStringContainsString( 'actions: read', $workflow );
		self::assertStringContainsString( 'ref: ${{ github.event.workflow_run.head_sha }}', $workflow );
		self::assertStringContainsString( 'run-id: ${{ github.event.workflow_run.id }}', $workflow );
		self::assertStringContainsString( 'github-token: ${{ secrets.GITHUB_TOKEN }}', $workflow );
		self::assertStringContainsString( 'ran-booster-runtime-${{ github.event.workflow_run.id }}-${{ github.event.workflow_run.run_attempt }}', $workflow );
		self::assertStringContainsString( '.mode == "admitted"', $workflow );
		self::assertStringContainsString( '.lane == "release-candidate"', $workflow );
		self::assertStringContainsString( '.mode == "built"', $workflow );
		self::assertStringContainsString( '.lane == "full"', $workflow );
		self::assertStringContainsString( '.run.id == $current_run_id', $workflow );
		self::assertStringContainsString( 'release-required=true\n\' >> "$GITHUB_OUTPUT"', $workflow );
		self::assertStringContainsString( 'skip-github-release: true', $workflow );
		self::assertStringNotContainsString( 'package-release:', $workflow );
		self::assertStringNotContainsString( 'bash scripts/build-release.sh', $workflow );
	}

	public function testReleaseProvesTheExactMergedPullRequestBeforePublishing(): void {
		$workflow = $this->workflow( 'release-please.yml' );

		self::assertStringContainsString( 'git rev-parse "${RAN_QUALITY_COMMIT}^2"', $workflow );
		self::assertStringContainsString( 'pulls?state=closed&base=main&per_page=100', $workflow );
		self::assertStringContainsString( 'git diff --name-status --no-renames "$base_commit" "$release_commit"', $workflow );
		self::assertStringContainsString( 'bash scripts/validate-release-candidate.sh "$base_commit" "$release_commit"', $workflow );
		self::assertStringContainsString( 'current_main="$(gh api "repos/${GITHUB_REPOSITORY}/git/ref/heads/main"', $workflow );
		self::assertStringContainsString( '.head.sha == $release', $workflow );
		self::assertStringContainsString( '.base.sha == $base_commit', $workflow );
		self::assertStringContainsString( '.merge_commit_sha == $quality', $workflow );
		self::assertStringContainsString( '.head.ref == $head', $workflow );
		self::assertStringContainsString( '.head.repo.full_name == $repository', $workflow );
		self::assertStringContainsString( '.user.login == $bot', $workflow );
		self::assertStringContainsString( 'autorelease: pending', $workflow );
		self::assertStringContainsString( 'autorelease: tagged', $workflow );
		self::assertStringContainsString( '--target "$RAN_RELEASE_COMMIT"', $workflow );
		self::assertStringContainsString( '.target_commitish == $commit', $workflow );
		self::assertStringContainsString( '.object.type == "commit" and .object.sha == $commit', $workflow );
		self::assertStringContainsString( '.admission.merge_commit == $admission_merge', $workflow );
		self::assertStringContainsString( '.actor.login == $bot', $workflow );
		self::assertStringContainsString( 'runs?event=pull_request&head_sha=${RAN_RELEASE_COMMIT}&status=completed', $workflow );
		self::assertStringContainsString( 'actions/runs/${original_run_id}/attempts/${original_run_attempt}', $workflow );
		self::assertStringContainsString( 'RAN_IMMUTABLE_RELEASES_ENABLED', $workflow );
		self::assertStringContainsString( 'for delay in 0 2 2 2 2', $workflow );

		$preflight = strpos( $workflow, '- name: Prove an exact merged Release Please candidate before publication' );
		$download  = strpos( $workflow, '- name: Download the exact archive tested by Quality' );
		$draft     = strpos( $workflow, '- name: Create or reuse the draft and attach verified assets' );
		$publish   = strpos( $workflow, '- name: Publish only under the immutable-release contract' );
		$readback  = strpos( $workflow, '- name: Read back the immutable release and reconcile its exact PR' );

		self::assertIsInt( $preflight );
		self::assertIsInt( $download );
		self::assertIsInt( $draft );
		self::assertIsInt( $publish );
		self::assertIsInt( $readback );
		self::assertTrue( $preflight < $download );
		self::assertTrue( $download < $draft );
		self::assertTrue( $draft < $publish );
		self::assertTrue( $publish < $readback );
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

	public function testWordPressSmokeGatesAcceptTheExactDeclaredFloor(): void {
		foreach ( array( 'core-updater-proof.sh', 'managed-theme-registration-smoke.sh' ) as $scriptName ) {
			$script = file_get_contents( __DIR__ . '/WordPress/' . $scriptName ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local CI contract.
			self::assertIsString( $script );
			self::assertStringContainsString( '7.0|7.0.*) ;;', $script );
		}
	}

	private function workflow( string $name ): string {
		$workflow = file_get_contents( dirname( __DIR__ ) . '/.github/workflows/' . $name ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local workflow contract.
		self::assertIsString( $workflow );

		return $workflow;
	}
}
