<?php

declare(strict_types=1);

namespace RANTests;

use PHPUnit\Framework\TestCase;

final class ReleaseWorkflowContractTest extends TestCase {
	public function testReleaseWorkflowIsThinPinnedSharedProfileBCaller(): void {
		$workflow = $this->workflow( 'release-please.yml' );

		self::assertStringContainsString(
			'uses: RocketsAreNostalgic/.github/.github/workflows/release-profile-b.yml@e2fb19244a301a62f8fae2a80536898adf21fe22',
			$workflow
		);
		self::assertStringContainsString( 'expected-workflow-path: .github/workflows/quality.yml', $workflow );
		self::assertStringContainsString(
			'release-pr-head: release-please--branches--main--components--ran-booster',
			$workflow
		);
		self::assertStringContainsString( 'artifact-prefix: ran-booster-runtime', $workflow );
		self::assertStringNotContainsString( 'googleapis/release-please-action', $workflow );
		self::assertStringNotContainsString( 'gh release', $workflow );
		self::assertStringNotContainsString( '--clobber', $workflow );
		self::assertStringNotContainsString( 'pull_request:', $workflow );
	}

	public function testQualityUsesInputlessExactReleaseCandidateQualification(): void {
		$workflow = $this->workflow( 'quality.yml' );

		self::assertStringContainsString( "  workflow_dispatch:\n  pull_request:", $workflow );
		self::assertStringNotContainsString( 'release_pr:', $workflow );
		self::assertStringNotContainsString( 'release_sha:', $workflow );
		self::assertStringContainsString(
			"github.event_name == 'pull_request' && github.event.pull_request.head.sha || github.sha",
			$workflow
		);
		self::assertStringContainsString(
			"release_branch='release-please--branches--main--components--ran-booster'",
			$workflow
		);
		self::assertStringContainsString( '.user.login == $bot', $workflow );
		self::assertStringContainsString(
			'bash scripts/validate-release-candidate.sh "$RAN_PR_BASE_SHA" "$RAN_PR_HEAD_SHA"',
			$workflow
		);
		self::assertStringNotContainsString( 'triggering_actor', $workflow );
		self::assertStringNotContainsString( 'Download exact prior PR artifact', $workflow );
		self::assertStringNotContainsString( 'Exact prior PR evidence was unavailable', $workflow );
		self::assertStringNotContainsString( 'reconcile-release-candidate-marker', $workflow );
	}

	public function testQualityBuildsProfileBPromotionManifestAndKeepsCoreProofs(): void {
		$workflow = $this->workflow( 'quality.yml' );

		self::assertStringContainsString( 'name: Runtime archive', $workflow );
		self::assertStringContainsString( 'bash scripts/build-release.sh "$source_commit" "$version"', $workflow );
		self::assertStringContainsString( 'build/ran-profile-b-promotion.json', $workflow );
		self::assertStringContainsString( 'schema:"ran-profile-b-promotion"', $workflow );
		self::assertStringContainsString( 'wordpress-release-candidate:', $workflow );
		self::assertStringContainsString( 'name: Release candidate install readback', $workflow );
		self::assertStringContainsString( 'run: composer check', $workflow );
		self::assertStringContainsString( 'run: pnpm check', $workflow );
		self::assertStringContainsString( 'fromJSON(needs.runtime-archive.outputs.wordpress-matrix)', $workflow );
		self::assertSame( 1, substr_count( $workflow, 'bash scripts/build-release.sh' ) );
		self::assertSame( 3, substr_count( $workflow, 'name: Verify exact source checkout' ) );
	}

	public function testTerminalQualityFansInFullAndCandidateProductEvidence(): void {
		$workflow = $this->workflow( 'quality.yml' );

		self::assertStringContainsString( '  repository-quality:', $workflow );
		self::assertStringContainsString( 'name: Repository quality', $workflow );
		self::assertStringContainsString( '  quality:', $workflow );
		self::assertStringContainsString( 'name: Quality', $workflow );
		self::assertStringContainsString( '- repository-quality', $workflow );
		self::assertStringContainsString( '- wordpress-release-candidate', $workflow );
		self::assertStringContainsString( '- wordpress', $workflow );
		self::assertStringContainsString( 'test "$WORDPRESS_RESULT" = success', $workflow );
		self::assertStringContainsString( 'test "$RELEASE_CANDIDATE_RESULT" = success', $workflow );
	}

	public function testQualityKeepsNeutralUpdaterRuntimeReadback(): void {
		$workflow = $this->workflow( 'quality.yml' );

		self::assertStringContainsString( 'Read back the neutral updater runtime contract', $workflow );
		self::assertStringContainsString( 'php "$verifier_file" --packaging "$lock_file" "$policy_file"', $workflow );
		self::assertStringContainsString( 'runtime_version=${package_version#v}', $workflow );
		self::assertStringContainsString( '.extra["ran-updater-runtime-protocol"]', $workflow );
		self::assertStringContainsString( 'cmp -s "$expected_runtime_copy" "$runtime_copy"', $workflow );
	}

	public function testCandidateBehaviorInvokesTheValidatorThroughBash(): void {
		$contract = file_get_contents( __DIR__ . '/release-candidate-contract.sh' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local workflow contract.

		self::assertIsString( $contract );
		self::assertSame( 2, substr_count( $contract, 'bash "$validator" "$base_sha" "$head_sha"' ) );
		self::assertSame( 0, preg_match( '/^\s*"\$validator"/m', $contract ) );
	}

	public function testWorkflowActionsArePinnedToImmutableCommits(): void {
		foreach ( array( 'quality.yml', 'release-please.yml' ) as $workflow_name ) {
			$workflow = $this->workflow( $workflow_name );
			$matches  = array();

			self::assertGreaterThan(
				0,
				preg_match_all( '/^\s*uses:\s*[^.\s][^@\s]*@([^\s#]+)/m', $workflow, $matches )
			);
			foreach ( $matches[1] as $reference ) {
				self::assertMatchesRegularExpression(
					'/^[0-9a-f]{40}$/',
					$reference,
					$workflow_name . ' has a mutable action reference.'
				);
			}
		}
	}

	private function workflow( string $name ): string {
		$workflow = file_get_contents( dirname( __DIR__ ) . '/.github/workflows/' . $name ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local workflow contract.
		self::assertIsString( $workflow );

		return $workflow;
	}
}
