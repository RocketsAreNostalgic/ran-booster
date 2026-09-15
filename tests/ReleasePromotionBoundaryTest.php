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
		$quality    = $this->readText( '.github/workflows/quality.yml' );
		$releaseDoc = $this->readText( 'RELEASE.md' );
		$ciDoc      = $this->readText( 'docs/ci-architecture.md' );
		$trustPaths = $this->trustPathBlock( $quality );

		foreach ( array_merge( self::EVIDENCE_INPUT_PATHS, self::RELEASE_CONTROL_PATHS ) as $path ) {
			self::assertStringContainsString( $path, $trustPaths, $path . ' is missing from the Quality freshness classifier.' );
			self::assertStringContainsString( '`' . $path . '`', $releaseDoc, $path . ' is missing from the documented inventory.' );
		}

		self::assertStringContainsString( '`RELEASE.md` is the canonical human-readable inventory', $ciDoc );
		self::assertStringNotContainsString( 'for trust_path in \\', $this->readText( '.github/workflows/release-please.yml' ) );
	}

	public function testPrivilegedMutationRemainsBoundToQualifiedMainEvidence(): void {
		$workflow = $this->readText( '.github/workflows/release-please.yml' );
		$jobGate  = $this->releaseJobGate( $workflow );

		foreach ( array(
			"github.event.workflow_run.event == 'push'",
			"github.event.workflow_run.conclusion == 'success'",
			"github.event.workflow_run.head_branch == 'main'",
			'github.event.workflow_run.head_repository.full_name == github.repository',
		) as $requiredPredicate ) {
			self::assertStringContainsString( $requiredPredicate, $jobGate );
		}

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

		$repository = 'RocketsAreNostalgic/ran-booster';
		foreach ( array(
			array( 'pull_request', 'success', 'main', $repository ),
			array( 'push', 'failure', 'main', $repository ),
			array( 'push', 'success', 'feature', $repository ),
			array( 'push', 'success', 'main', 'someone/else' ),
		) as $unqualified ) {
			self::assertFalse( $this->workflowRunQualifies( ...$unqualified ) );
		}
		self::assertTrue( $this->workflowRunQualifies( 'push', 'success', 'main', $repository ) );
	}

	private function workflowRunQualifies(
		string $event,
		string $conclusion,
		string $branch,
		string $headRepository
	): bool {
		return 'push' === $event
			&& 'success' === $conclusion
			&& 'main' === $branch
			&& 'RocketsAreNostalgic/ran-booster' === $headRepository;
	}

	private function releaseJobGate( string $workflow ): string {
		$start = strpos( $workflow, "    if: >-\n" );
		self::assertIsInt( $start );
		$end = strpos( $workflow, "\n    runs-on:", $start );
		self::assertIsInt( $end );

		return substr( $workflow, $start, $end - $start );
	}

	private function assertStepGate( string $workflow, string $stepName, string $expectedGate ): void {
		$marker = '      - name: ' . $stepName;
		$start  = strpos( $workflow, $marker );
		self::assertIsInt( $start, $stepName . ' step is missing.' );
		$end = strpos( $workflow, "\n      - name:", $start + strlen( $marker ) );
		if ( false === $end ) {
			$end = strlen( $workflow );
		}
		$step = substr( $workflow, $start, $end - $start );
		self::assertStringContainsString( $expectedGate, $step, $stepName . ' is not bound to release admission.' );
	}

	private function trustPathBlock( string $workflow ): string {
		$start = strpos( $workflow, 'for trust_path in \\' );
		self::assertIsInt( $start );
		$end = strpos( $workflow, '; do', $start );
		self::assertIsInt( $end );

		return substr( $workflow, $start, $end - $start );
	}

	private function readText( string $path ): string {
		$content = file_get_contents( dirname( __DIR__ ) . '/' . $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local contract fixture.
		self::assertIsString( $content );

		return $content;
	}
}
