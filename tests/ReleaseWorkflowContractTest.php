<?php

declare(strict_types=1);

namespace RANTests;

use PHPUnit\Framework\TestCase;

final class ReleaseWorkflowContractTest extends TestCase {
	public function testQualityBuildsOneArchiveAndNarrowsTheAutomatedReleaseCandidate(): void {
		$workflow = $this->workflow( 'quality.yml' );

		self::assertStringContainsString( 'pull_request:', $workflow );
		self::assertStringContainsString( 'push:', $workflow );
		self::assertStringContainsString( 'release-please--branches--main--components--ran-booster', $workflow );
		self::assertStringContainsString( 'github-actions[bot]', $workflow );
		self::assertStringContainsString( 'RAN_PR_HEAD_REPOSITORY', $workflow );
		self::assertStringContainsString( 'wordpress_matrix=', $workflow );
		self::assertStringContainsString( '"7.0.3","database":"MySQL 8.4"', $workflow );
		self::assertStringContainsString( '"7.0","database":"MySQL 8.4"', $workflow );
		self::assertStringNotContainsString( sprintf( '"%s":"7.0.1"', strtolower( 'WordPress' ) ), $workflow );
		self::assertStringNotContainsString( sprintf( '"%s":"7.0.2"', strtolower( 'WordPress' ) ), $workflow );
		self::assertStringContainsString( '"database":"MariaDB 10.11"', $workflow );
		self::assertStringContainsString( '"database":"MySQL 8.0 floor"', $workflow );
		self::assertStringContainsString( 'matrix: ${{ fromJSON(needs.runtime-archive.outputs.wordpress-matrix) }}', $workflow );
		self::assertStringContainsString( "needs:\n            - runtime-archive\n            - quality", $workflow );
		self::assertStringContainsString( "needs.quality.result == 'success'", $workflow );
		self::assertStringContainsString( "needs.quality.result == 'skipped'", $workflow );
		self::assertSame( 1, substr_count( $workflow, 'bash scripts/build-release.sh' ) );
		self::assertStringNotContainsString( 'bash scripts/verify-release.sh', $workflow );
	}

	public function testQualitySharesTheVerifiedArchiveAcrossEveryConsumer(): void {
		$workflow = $this->workflow( 'quality.yml' );

		self::assertStringContainsString( 'schema: "ran-booster-ci-runtime"', $workflow );
		self::assertStringContainsString( 'quality_commit: $quality_commit', $workflow );
		self::assertStringContainsString( 'source_commit: $source_commit', $workflow );
		self::assertStringContainsString( 'ran-booster-runtime-${GITHUB_RUN_ID}-${GITHUB_RUN_ATTEMPT}', $workflow );
		self::assertStringContainsString( 'actions/upload-artifact@043fb46d1a93c77aae656e7c1c64a875d1fc6a0a', $workflow );
		self::assertStringContainsString( 'actions/download-artifact@3e5f45b2cfb9172054b4087a40e8e0b5a5461e7c', $workflow );
		self::assertStringContainsString( 'needs.runtime-archive.outputs.archive-sha256', $workflow );
		self::assertStringContainsString( 'sha256sum --check --strict', $workflow );
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

	public function testReleaseWaitsForSuccessfulMainPushQualityAndUsesItsArtifact(): void {
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
		self::assertStringContainsString( 'release-required=true\n\' >> "$GITHUB_OUTPUT"', $workflow );
		self::assertStringContainsString( 'skip-github-release: true', $workflow );
		self::assertStringNotContainsString( 'package-release:', $workflow );
		self::assertStringNotContainsString( 'bash scripts/build-release.sh', $workflow );
	}

	public function testReleaseProvesTheExactMergedPullRequestBeforePublishing(): void {
		$workflow = $this->workflow( 'release-please.yml' );

		self::assertStringContainsString( 'git rev-parse "${RAN_QUALITY_COMMIT}^2"', $workflow );
		self::assertStringContainsString( 'commits/${release_commit}/pulls', $workflow );
		self::assertStringContainsString( '.head.sha == $release', $workflow );
		self::assertStringContainsString( '.merge_commit_sha == $quality', $workflow );
		self::assertStringContainsString( '.head.ref == $head', $workflow );
		self::assertStringContainsString( '.head.repo.full_name == $repository', $workflow );
		self::assertStringContainsString( '.user.login == $bot', $workflow );
		self::assertStringContainsString( 'autorelease: pending', $workflow );
		self::assertStringContainsString( 'autorelease: tagged', $workflow );
		self::assertStringContainsString( '--target "$RAN_RELEASE_COMMIT"', $workflow );
		self::assertStringContainsString( '.target_commitish == $commit', $workflow );
		self::assertStringContainsString( '.object.type == "commit" and .object.sha == $commit', $workflow );
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
