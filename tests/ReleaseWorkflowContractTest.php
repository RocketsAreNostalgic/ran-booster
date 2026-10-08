<?php

declare(strict_types=1);

namespace RAN\Tests;

use PHPUnit\Framework\TestCase;

final class ReleaseWorkflowContractTest extends TestCase {
	public function test_release_workflow_is_thin_pinned_shared_profile_bcaller(): void {
		$workflow = $this->workflow( 'release-please.yml' );

		self::assertStringContainsString(
			'uses: RocketsAreNostalgic/.github/.github/workflows/release-profile-b.yml@593768db30a0101e940e85b9a084b2c773322785',
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

	public function test_quality_uses_inputless_exact_release_candidate_qualification(): void {
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

	public function test_quality_builds_profile_bpromotion_manifest_and_keeps_core_proofs(): void {
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

	public function test_terminal_quality_fans_in_full_and_candidate_product_evidence(): void {
		$workflow = $this->workflow( 'quality.yml' );

		self::assertStringContainsString( '  repository-quality:', $workflow );
		self::assertStringContainsString( 'name: Repository quality', $workflow );
		self::assertStringContainsString( '  quality:', $workflow );
		self::assertStringContainsString( 'name: Quality', $workflow );
		self::assertStringContainsString( '- repository-quality', $workflow );
		self::assertStringContainsString( '- wordpress-release-candidate', $workflow );
		self::assertStringContainsString( '- wordpress', $workflow ); // phpcs:ignore WordPress.WP.CapitalPDangit.MisspelledInText -- YAML job key is lowercase.
		self::assertStringContainsString( 'test "$WORDPRESS_RESULT" = success', $workflow );
		self::assertStringContainsString( 'test "$RELEASE_CANDIDATE_RESULT" = success', $workflow );
	}

	public function test_terminal_quality_rejects_missing_or_failed_evidence(): void {
		$workflow = $this->workflow( 'quality.yml' );
		$matches  = array();
		self::assertSame( 1, preg_match( '/^  quality:\n(?:(?!^  [a-z]).)*?^        run: \|\n((?:^          .*\n|^\n)+)/ms', $workflow, $matches ) );
		$script = preg_replace( '/^          /m', '', $matches[1] );
		self::assertIsString( $script );

		$valid_lanes = array(
			'full'              => array( 'success', 'success', 'skipped', 'success' ),
			'release-candidate' => array( 'success', 'skipped', 'success', 'skipped' ),
		);
		$keys        = array( 'RUNTIME_ARCHIVE_RESULT', 'REPOSITORY_QUALITY_RESULT', 'RELEASE_CANDIDATE_RESULT', 'WORDPRESS_RESULT' );
		$cases       = array();
		foreach ( $valid_lanes as $lane => $valid_results ) {
			$cases[] = array( $lane, $valid_results, true );
			foreach ( $valid_results as $index => $expected_result ) {
				foreach ( array( 'success', 'failure', 'cancelled', 'skipped' ) as $result ) {
					if ( $expected_result === $result ) {
						continue;
					}
					$changed           = $valid_results;
					$changed[ $index ] = $result;
					$cases[]           = array( $lane, $changed, false );
				}
			}
		}
		$cases[] = array( '', $valid_lanes['full'], false );
		$cases[] = array( 'unknown', $valid_lanes['full'], false );

		foreach ( $cases as [ $lane, $results, $should_pass ] ) {
			$environment         = array_combine( $keys, $results );
			$environment['LANE'] = $lane;
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- Execute the actual terminal gate to prove failure, cancellation and skipped-evidence behavior.
			$process = proc_open(
				array( '/bin/bash', '-c', $script ),
				array(
					1 => array( 'pipe', 'w' ),
					2 => array( 'pipe', 'w' ),
				),
				$pipes,
				null,
				$environment
			);
			self::assertIsResource( $process );
			$output = stream_get_contents( $pipes[1] );
			$errors = stream_get_contents( $pipes[2] );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the isolated gate subprocess output pipe.
			fclose( $pipes[1] );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the isolated gate subprocess error pipe.
			fclose( $pipes[2] );
			$exit_code = proc_close( $process );
			self::assertSame( $should_pass, 0 === $exit_code, $lane . ': ' . implode( ', ', $results ) . '\n' . $output . $errors );
		}
	}

	public function test_quality_keeps_neutral_updater_runtime_readback(): void {
		$workflow = $this->workflow( 'quality.yml' );

		self::assertStringContainsString( 'Read back the neutral updater runtime contract', $workflow );
		self::assertStringContainsString( 'php "$verifier_file" --packaging "$lock_file" "$policy_file"', $workflow );
		self::assertStringContainsString( 'runtime_version=${package_version#v}', $workflow );
		self::assertStringContainsString( '.extra["ran-updater-runtime-protocol"]', $workflow );
		self::assertStringContainsString( 'cmp -s "$expected_runtime_copy" "$runtime_copy"', $workflow );
	}

	public function test_candidate_behavior_invokes_the_validator_through_bash(): void {
		$contract = file_get_contents( __DIR__ . '/release-candidate-contract.sh' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local workflow contract.

		self::assertIsString( $contract );
		self::assertSame( 2, substr_count( $contract, 'bash "$validator" "$base_sha" "$head_sha"' ) );
		self::assertSame( 0, preg_match( '/^\s*"\$validator"/m', $contract ) );
	}

	public function test_workflow_actions_are_pinned_to_immutable_commits(): void {
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
