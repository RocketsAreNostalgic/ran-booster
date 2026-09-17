<?php

declare(strict_types=1);

namespace RANTests;

use PHPUnit\Framework\TestCase;

final class ReleasePromotionBoundaryTest extends TestCase {
	private const EVIDENCE_INPUT_PATHS = array(
		'composer.json',
		'composer.lock',
		'runtime-packaging-policy.json',
		'package.json',
		'pnpm-lock.yaml',
	);

	private const RELEASE_CONTROL_PATHS = array(
		'.github/workflows/quality.yml',
		'.github/workflows/release-please.yml',
		'scripts/build-release.sh',
		'scripts/verify-runtime-dependencies.php',
		'scripts/verify-release.sh',
		'scripts/validate-release-candidate.sh',
		'scripts/select-merged-release-pr.sh',
		'scripts/reconcile-release-candidate-marker.sh',
		'scripts/verify-release-tag-target.sh',
		'scripts/has-trusted-release-candidate-run.sh',
		'scripts/verify-immutable-release-assets.sh',
	);

	public function testQualityFreshEvidenceClassifierMatchesTheDocumentedBoundary(): void {
		$quality         = $this->readText( '.github/workflows/quality.yml' );
		$releaseDoc      = $this->readText( 'RELEASE.md' );
		$ciDoc           = $this->readText( 'docs/ci-architecture.md' );
		$expectedPaths   = array_merge( self::EVIDENCE_INPUT_PATHS, self::RELEASE_CONTROL_PATHS );
		$executablePaths = $this->trustPaths( $quality );
		$documentedPaths = $this->documentedTrustPaths( $releaseDoc );

		sort( $expectedPaths );
		sort( $executablePaths );
		sort( $documentedPaths );

		self::assertSame( $expectedPaths, $executablePaths, 'Quality trust paths drifted from the release boundary.' );
		self::assertSame( $expectedPaths, $documentedPaths, 'RELEASE.md trust paths drifted from the release boundary.' );
		self::assertSame( $documentedPaths, $executablePaths, 'Documented and executable trust paths must match both ways.' );
		self::assertStringContainsString( '`RELEASE.md` is the canonical human-readable inventory', $ciDoc );
		self::assertStringNotContainsString( 'for trust_path in \\', $this->readText( '.github/workflows/release-please.yml' ) );
	}

	public function testPrivilegedMutationRemainsBoundToQualifiedMainEvidence(): void {
		$workflow     = $this->readText( '.github/workflows/release-please.yml' );
		$jobGate      = $this->releaseJobGate( $workflow );
		$expectedGate = implode(
			' ',
			array(
				'if: >-',
				'${{',
				"github.event.workflow_run.event == 'push'",
				'&&',
				"github.event.workflow_run.conclusion == 'success'",
				'&&',
				"github.event.workflow_run.path == '.github/workflows/quality.yml'",
				'&&',
				"github.event.workflow_run.head_branch == 'main'",
				'&&',
				'github.event.workflow_run.head_repository.full_name == github.repository',
				'}}',
			)
		);

		self::assertSame(
			$expectedGate,
			$this->normalizeWhitespace( $jobGate ),
			'The complete privileged job gate must remain the canonical all-conjunct admission expression.'
		);
		self::assertStringNotContainsString( 'github.event.workflow_run.name', $jobGate );
		self::assertStringNotContainsString( "\n  pull_request_target:", $workflow );
		self::assertStringNotContainsString( "\n  pull_request:", $workflow );
		self::assertStringContainsString( 'test "$(git rev-parse HEAD)" = "$RAN_QUALITY_COMMIT"', $workflow );
		self::assertStringContainsString( 'and .merge_commit_sha == $merge', $workflow );
		self::assertSame(
			2,
			substr_count(
				$workflow,
				'[[ "$current_main" == "$RAN_QUALITY_COMMIT" ]] && release_please_required=true'
			)
		);
	}

	public function testOrdinaryReconciliationGuardsExecuteForCurrentAndStaleMain(): void {
		$workflow = $this->readText( '.github/workflows/release-please.yml' );
		$guards   = $this->ordinaryReconciliationGuards( $workflow );
		self::assertCount( 2, $guards, 'Both ordinary reconciliation guard branches must stay contract-covered.' );

		$qualityCommit = str_repeat( 'a', 40 );
		foreach ( $guards as $guard ) {
			$current = $this->executeReconciliationGuard( $guard, $qualityCommit, $qualityCommit );
			self::assertSame( "release-required=false\nrelease-please-required=true\n", $current['github_output'] );
			self::assertSame( '', $current['stdout'] );

			$stale = $this->executeReconciliationGuard( $guard, str_repeat( 'b', 40 ), $qualityCommit );
			self::assertSame( "release-required=false\nrelease-please-required=false\n", $stale['github_output'] );
			self::assertStringContainsString( 'skipping stale reconciliation', $stale['stdout'] );
		}
	}

	public function testMergedCandidateStateTransitionFeedsLaterPrivilegedSteps(): void {
		$workflow   = $this->readText( '.github/workflows/release-please.yml' );
		$transition = $this->mergedCandidateTransition( $workflow );

		self::assertStringContainsString(
			"printf 'release-required=true\\nrelease-please-required=false\\n' >> \"\$GITHUB_OUTPUT\"",
			$transition
		);
		foreach ( array(
			"printf 'RAN_RELEASE_BASE_COMMIT=%s\\n' \"\$release_base\"",
			"printf 'RAN_RELEASE_COMMIT=%s\\n' \"\$RAN_QUALITY_COMMIT\"",
			"printf 'RAN_RELEASE_HEAD_COMMIT=%s\\n' \"\$release_head\"",
			"printf 'RAN_RELEASE_PENDING=%s\\n' \"\$release_pending\"",
			"printf 'RAN_RELEASE_PR_NUMBER=%s\\n' \"\$release_pr_number\"",
			"printf 'RAN_RELEASE_STATE=%s\\n' \"\$state\"",
			"printf 'RAN_RELEASE_TAG=%s\\n' \"\$tag\"",
			"printf 'RAN_RELEASE_TREE=%s\\n' \"\$main_tree\"",
			"printf 'RAN_RELEASE_VERSION=%s\\n' \"\$version\"",
		) as $releaseStateAssignment ) {
			self::assertStringContainsString(
				$releaseStateAssignment,
				$transition,
				$releaseStateAssignment . ' is missing from candidate state.'
			);
		}

		$this->assertStepGate(
			$workflow,
			'Open or update release pull request',
			"if: steps.release-state.outputs.release-please-required == 'true'"
		);
		$this->assertStepGate(
			$workflow,
			'Validate and dispatch exact Release Please candidate',
			"if: steps.release-state.outputs.release-please-required == 'true'"
		);
		$this->assertStepGate(
			$workflow,
			'Create or reuse draft and attach verified assets',
			"if: steps.release-state.outputs.release-required == 'true' && env.RAN_RELEASE_PENDING == 'true'"
		);
		$this->assertStepGate(
			$workflow,
			'Publish only under immutable-release contract',
			"if: steps.release-state.outputs.release-required == 'true' && env.RAN_RELEASE_PENDING == 'true'"
		);
		$this->assertStepGate(
			$workflow,
			'Read back immutable release and reconcile exact PR',
			"if: steps.release-state.outputs.release-required == 'true'"
		);
	}

	public function testFreshCandidateIdentityUsesCanonicalReleaseBranchReadback(): void {
		$workflow      = $this->readText( '.github/workflows/release-please.yml' );
		$candidateStep = $this->workflowStep( $workflow, 'Validate and dispatch exact Release Please candidate' );
		$markerScript  = $this->readText( 'scripts/reconcile-release-candidate-marker.sh' );

		self::assertStringContainsString( 'RAN_QUALITY_COMMIT: ${{ github.event.workflow_run.head_sha }}', $candidateStep );
		self::assertStringContainsString( 'base_sha="$RAN_QUALITY_COMMIT"', $candidateStep );
		self::assertStringContainsString( 'test "$(git rev-parse HEAD)" = "$base_sha"', $candidateStep );
		self::assertStringContainsString( 'git/ref/heads/${release_branch}', $candidateStep );
		self::assertStringContainsString( 'fetch --no-tags origin "refs/heads/${release_branch}"', $candidateStep );
		self::assertStringContainsString( 'object(oid: $head)', $candidateStep );
		self::assertStringContainsString( 'query($owner: String!, $name: String!, $head: GitObjectID!)', $candidateStep );
		self::assertStringNotContainsString( "jq -er '.head.sha'", $candidateStep );
		self::assertStringNotContainsString( 'refs/pull/${pr_number}/head', $candidateStep );
		self::assertStringNotContainsString( 'pullRequest(number: $number)', $candidateStep );
		self::assertStringContainsString( '.identity.data.repository.object as $head_commit', $markerScript );
		self::assertStringNotContainsString( '.identity.data.repository.pullRequest.commits.nodes', $markerScript );
	}

	/**
	 * @return list<string>
	 */
	private function ordinaryReconciliationGuards( string $workflow ): array {
		$guards      = array();
		$offset      = 0;
		$startMarker = "            release_please_required=false\n";
		$endMarker   = "            exit 0\n";

		$start = strpos( $workflow, $startMarker, $offset );
		while ( false !== $start ) {
			$end = strpos( $workflow, $endMarker, $start );
			self::assertIsInt( $end );
			$end = $end + strlen( $endMarker );

			$guard = substr( $workflow, $start, $end - $start );
			$guard = preg_replace( '/^ {12}/m', '', $guard );
			self::assertIsString( $guard );
			$guards[] = $guard;

			$offset = $end;
			$start  = strpos( $workflow, $startMarker, $offset );
		}

		return $guards;
	}

	/**
	 * @return array{github_output: string, stdout: string}
	 */
	private function executeReconciliationGuard( string $guard, string $currentMain, string $qualityCommit ): array {
		$outputFile = tempnam( sys_get_temp_dir(), 'ran-release-gate-' );
		self::assertIsString( $outputFile );
		$command = sprintf(
			'current_main=%s RAN_QUALITY_COMMIT=%s GITHUB_OUTPUT=%s bash -eu -o pipefail -c %s 2>&1',
			escapeshellarg( $currentMain ),
			escapeshellarg( $qualityCommit ),
			escapeshellarg( $outputFile ),
			escapeshellarg( $guard )
		);
		$stdout  = array();
		$status  = 0;
		exec( $command, $stdout, $status ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- Executes the checked-in release guard as a local contract fixture.
		self::assertSame( 0, $status, implode( "\n", $stdout ) );
		$githubOutput = file_get_contents( $outputFile ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local temporary contract fixture.
		self::assertIsString( $githubOutput );
		unlink( $outputFile ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Deletes only the local temporary contract fixture.

		return array(
			'github_output' => $githubOutput,
			'stdout'        => implode( "\n", $stdout ),
		);
	}

	private function mergedCandidateTransition( string $workflow ): string {
		$start = strpos( $workflow, "          printf 'release-required=true\\nrelease-please-required=false\\n'" );
		self::assertIsInt( $start );
		$end = strpos( $workflow, "\n      - name: Open or update release pull request", $start );
		self::assertIsInt( $end );

		return substr( $workflow, $start, $end - $start );
	}

	private function releaseJobGate( string $workflow ): string {
		$job = strpos( $workflow, "  release-please:\n" );
		self::assertIsInt( $job );
		$start = strpos( $workflow, "    if: >-\n", $job );
		self::assertIsInt( $start );
		$end = strpos( $workflow, "\n    runs-on:", $start );
		self::assertIsInt( $end );

		return substr( $workflow, $start, $end - $start );
	}

	private function normalizeWhitespace( string $value ): string {
		$normalized = preg_replace( '/\s+/', ' ', trim( $value ) );
		self::assertIsString( $normalized );

		return $normalized;
	}

	private function assertStepGate( string $workflow, string $stepName, string $expectedGate ): void {
		$step = $this->workflowStep( $workflow, $stepName );
		self::assertStringContainsString( $expectedGate, $step, $stepName . ' is not bound to release admission.' );
	}

	private function workflowStep( string $workflow, string $stepName ): string {
		$marker = '      - name: ' . $stepName;
		$start  = strpos( $workflow, $marker );
		self::assertIsInt( $start, $stepName . ' step is missing.' );
		$end = strpos( $workflow, "\n      - name:", $start + strlen( $marker ) );
		if ( false === $end ) {
			$end = strlen( $workflow );
		}

		return substr( $workflow, $start, $end - $start );
	}

	/**
	 * @return list<string>
	 */
	private function trustPaths( string $workflow ): array {
		$matches = array();
		$count   = preg_match_all(
			'/^\s+([.A-Za-z0-9_\/-]+)(?: \\\\|; do)$/m',
			$this->trustPathBlock( $workflow ),
			$matches
		);
		self::assertIsInt( $count );
		self::assertGreaterThan( 0, $count );

		return $matches[1];
	}

	/**
	 * @return list<string>
	 */
	private function documentedTrustPaths( string $releaseDoc ): array {
		$normalized = $this->normalizeWhitespace( $releaseDoc );
		$start      = strpos( $normalized, 'The ordinary evidence inputs are:' );
		self::assertIsInt( $start );
		$end = strpos( $normalized, '`Quality` is the executable authority', $start );
		self::assertIsInt( $end );
		$inventory = substr( $normalized, $start, $end - $start );
		$matches   = array();
		$count     = preg_match_all( '/`([^`]+)`/', $inventory, $matches );
		self::assertIsInt( $count );
		self::assertGreaterThan( 0, $count );

		return $matches[1];
	}

	private function trustPathBlock( string $workflow ): string {
		$start = strpos( $workflow, 'for trust_path in \\' );
		self::assertIsInt( $start );
		$end = strpos( $workflow, '; do', $start );
		self::assertIsInt( $end );
		$end += strlen( '; do' );

		return substr( $workflow, $start, $end - $start );
	}

	private function readText( string $path ): string {
		$content = file_get_contents( dirname( __DIR__ ) . '/' . $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local contract fixture.
		self::assertIsString( $content );

		return $content;
	}
}
