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
		self::assertStringContainsString( 'run: composer check:repository', $workflow );
		self::assertStringContainsString( 'run: composer analyze:development', $workflow );
		self::assertStringContainsString( 'run: pnpm check', $workflow );
		self::assertStringContainsString( 'fromJSON(needs.runtime-archive.outputs.wordpress-matrix)', $workflow );
		self::assertSame( 1, substr_count( $workflow, 'bash scripts/build-release.sh' ) );
		// Plugin Check runs once, on WordPress 7.0.3 and PHP 8.2, outside the slower critical database jobs.
		self::assertSame( 1, substr_count( $workflow, '"plugin_check":true' ) );
		self::assertStringContainsString( '{"wordpress":"7.0.3","database":"MySQL 8.0 floor","database_image":"mysql:8.0","suite":"floor","plugin_check":true}', $workflow ); // phpcs:ignore WordPress.WP.CapitalPDangit.MisspelledInText -- JSON matrix key is lowercase.
		// Repository, development analysis, candidate readback and WordPress each verify the archive's exact source SHA.
		self::assertSame( 4, substr_count( $workflow, 'name: Verify exact source checkout' ) );
	}

	public function test_analysis_split_uses_read_only_full_lane_exact_source_and_four_workers(): void {
		$workflow = $this->workflow( 'quality.yml' );
		$jobs     = array();
		foreach ( array( 'repository-quality', 'development-analysis' ) as $name ) {
			self::assertSame( 1, preg_match( '/^  ' . $name . ':\n(?:(?!^  [a-z]).)*/ms', $workflow, $matches ) );
			if ( ! isset( $matches[0] ) ) {
				self::fail( 'Expected the ' . $name . ' job.' );
			}
			$jobs[ $name ] = $matches[0];
			self::assertStringContainsString( 'needs: runtime-archive', $matches[0] );
			self::assertStringContainsString( "if: \${{ needs.runtime-archive.outputs.lane == 'full' }}", $matches[0] );
			self::assertStringContainsString( 'persist-credentials: false', $matches[0] );
			self::assertStringContainsString( 'ref: ${{ needs.runtime-archive.outputs.source-commit }}', $matches[0] );
			self::assertLessThan( strpos( $matches[0], 'composer install' ), strpos( $matches[0], 'name: Verify exact source checkout' ) );
		}
		$development = $jobs['development-analysis'];
		self::assertStringContainsString( "permissions:\n      contents: read\n    steps:", $development );
		self::assertStringNotContainsString( 'secrets.', $development );
		self::assertStringNotContainsString( 'continue-on-error', $development );
		self::assertStringNotContainsString( 'cache', $development );
		self::assertStringNotContainsString( 'pnpm', $development );
		self::assertStringNotContainsString( 'setup-node', $development );
		self::assertStringContainsString( "php-version: '8.2'", $development );
		self::assertStringContainsString( 'tools: composer:v2', $development );
		self::assertStringContainsString( 'composer install --no-interaction --prefer-dist --no-progress', $development );
		self::assertStringContainsString( 'RAN_SOURCE_COMMIT: ${{ needs.runtime-archive.outputs.source-commit }}', $development );
		self::assertStringContainsString( 'test "$(git rev-parse HEAD)" = "$RAN_SOURCE_COMMIT"', $development );
		self::assertStringContainsString( "PHPSTAN_DEVELOPMENT_PROCESSES: '4'", $development );
		self::assertSame( 1, substr_count( $workflow, 'PHPSTAN_DEVELOPMENT_PROCESSES:' ) );
		// Two legs cover the complete development analysis; neither may cancel the other.
		self::assertStringContainsString( "strategy:\n      fail-fast: false\n      matrix:\n        shard: ['1/2', '2/2']\n", $development );
		self::assertStringContainsString( 'PHPSTAN_DEVELOPMENT_SHARD: ${{ matrix.shard }}', $development );
		self::assertSame( 1, substr_count( $workflow, 'PHPSTAN_DEVELOPMENT_SHARD:' ) );
		self::assertStringContainsString( 'run: composer analyze:development', $development );
		self::assertStringContainsString( 'run: composer check:repository', $jobs['repository-quality'] );
		self::assertStringContainsString( 'run: composer test:published-template-pack', $jobs['repository-quality'] );
		self::assertStringContainsString( 'run: pnpm check', $jobs['repository-quality'] );
	}

	public function test_terminal_quality_fans_in_full_and_candidate_product_evidence(): void {
		$workflow = $this->workflow( 'quality.yml' );

		self::assertStringContainsString( '  repository-quality:', $workflow );
		self::assertStringContainsString( 'name: Repository quality', $workflow );
		self::assertStringContainsString( '  quality:', $workflow );
		self::assertStringContainsString( 'name: Quality', $workflow );
		self::assertStringContainsString( '- repository-quality', $workflow );
		self::assertStringContainsString( '- development-analysis', $workflow );
		self::assertStringContainsString( '- wordpress-release-candidate', $workflow );
		self::assertStringContainsString( '- wordpress', $workflow ); // phpcs:ignore WordPress.WP.CapitalPDangit.MisspelledInText -- YAML job key is lowercase.
		self::assertStringContainsString( 'test "$WORDPRESS_RESULT" = success', $workflow );
		self::assertStringContainsString( 'test "$RELEASE_CANDIDATE_RESULT" = success', $workflow );
		self::assertStringContainsString( "name: Quality\n    if: \${{ always() }}", $workflow );
		self::assertStringContainsString( 'DEVELOPMENT_ANALYSIS_RESULT: ${{ needs.development-analysis.result }}', $workflow );
	}

	public function test_terminal_quality_rejects_missing_or_failed_evidence(): void {
		$workflow = $this->workflow( 'quality.yml' );
		$matches  = array();
		self::assertSame( 1, preg_match( '/^  quality:\n(?:(?!^  [a-z]).)*?^        run: \|\n((?:^          .*\n|^\n)+)/ms', $workflow, $matches ) );
		if ( ! isset( $matches[1] ) ) {
			self::fail( 'Expected the terminal quality script.' );
		}
		$script = preg_replace( '/^          /m', '', $matches[1] );
		self::assertIsString( $script );

		$valid_lanes = array(
			'full'              => array( 'success', 'success', 'success', 'skipped', 'success' ),
			'release-candidate' => array( 'success', 'skipped', 'skipped', 'success', 'skipped' ),
		);
		$keys        = array( 'RUNTIME_ARCHIVE_RESULT', 'REPOSITORY_QUALITY_RESULT', 'DEVELOPMENT_ANALYSIS_RESULT', 'RELEASE_CANDIDATE_RESULT', 'WORDPRESS_RESULT' );
		$cases       = array();
		foreach ( $valid_lanes as $lane => $valid_results ) {
			$cases[] = array( $lane, $valid_results, true );
			foreach ( $valid_results as $index => $expected_result ) {
				foreach ( array( 'success', 'failure', 'cancelled', 'skipped', '', 'unknown', null ) as $result ) {
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
			$environment         = array_filter( array_combine( $keys, $results ), static fn( ?string $result ): bool => null !== $result );
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
			self::assertSame( $should_pass, 0 === $exit_code, $lane . ': ' . implode( ', ', $environment ) . '\n' . $output . $errors );
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

	public function test_quality_removes_branch_gated_fixture_token_and_redacts_http_failure_logs(): void {
		$workflow = $this->workflow( 'quality.yml' );

		self::assertStringNotContainsString( 'RAN_PRIVATE_MIGRATOR', $workflow );
		self::assertStringNotContainsString( 'UPSTREAM_FIXTURES_CLIENT_ID', $workflow );
		self::assertStringNotContainsString( 'UPSTREAM_FIXTURES_PRIVATE_KEY', $workflow );
		self::assertStringNotContainsString( 'actions/create-github-app-token', $workflow );
		self::assertStringNotContainsString( 'refs/heads/integration/171-github-blueprint', $workflow );
		self::assertStringNotContainsString( 'cat build/portability-http.log', $workflow );

		$matches = array();
		self::assertSame(
			1,
			preg_match( '/emit_redacted_http_log\(\)\s*\{\n(.*?)\n\s*\}/s', $workflow, $matches )
		);
		if ( ! isset( $matches[1] ) ) {
			self::fail( 'Expected the shared HTTP log redaction helper.' );
		}
		self::assertSame( 4, substr_count( $workflow, 'emit_redacted_http_log' ) );

		$temp_dir = sys_get_temp_dir() . '/ran-http-log-redact-' . bin2hex( random_bytes( 6 ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Temporary fixture directory for redaction helper verification.
		self::assertTrue( mkdir( $temp_dir . '/build', 0700, true ) );
		try {
			$sample_log = implode(
				"\n",
				array(
					'[Mon Nov 23 01:00:00 2026] 127.0.0.1:41234 [200]: GET /wp-login.php?token=secret-value-123',
					'[Mon Nov 23 01:00:01 2026] [::1]:41235 [500]: POST /wp-admin/admin-post.php?secret=do-not-leak',
					'PHP Fatal error: Authorization: Bearer top-secret-token in /var/www/wp-config.php',
				)
			) . "\n";
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write temporary HTTP log fixture.
			self::assertNotFalse( file_put_contents( $temp_dir . '/build/portability-http.log', $sample_log ) );

			$helper_script = "emit_redacted_http_log() {\n" . $matches[1] . "\n}\nemit_redacted_http_log\n";
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- Execute the workflow's exact log redaction helper.
			$process = proc_open(
				array( '/bin/bash', '-c', $helper_script ),
				array(
					1 => array( 'pipe', 'w' ),
					2 => array( 'pipe', 'w' ),
				),
				$pipes,
				$temp_dir
			);
			self::assertIsResource( $process );
			$stdout = (string) stream_get_contents( $pipes[1] );
			$stderr = (string) stream_get_contents( $pipes[2] );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close subprocess stdout pipe.
			fclose( $pipes[1] );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close subprocess stderr pipe.
			fclose( $pipes[2] );
			$exit_code = proc_close( $process );

			self::assertSame( 0, $exit_code );
			self::assertSame( '', $stdout );
			self::assertStringContainsString( 'PHP server access: method=GET status=200', $stderr );
			self::assertStringContainsString( 'PHP server access: method=POST status=500', $stderr );
			self::assertStringNotContainsString( 'secret-value-123', $stderr );
			self::assertStringNotContainsString( 'do-not-leak', $stderr );
			self::assertStringNotContainsString( 'top-secret-token', $stderr );
		} finally {
			if ( file_exists( $temp_dir . '/build/portability-http.log' ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Remove temporary fixture file.
				unlink( $temp_dir . '/build/portability-http.log' );
			}
			if ( is_dir( $temp_dir . '/build' ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Remove temporary fixture directory.
				rmdir( $temp_dir . '/build' );
			}
			if ( is_dir( $temp_dir ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Remove temporary fixture directory.
				rmdir( $temp_dir );
			}
		}
	}

	private function workflow( string $name ): string {
		$workflow = file_get_contents( dirname( __DIR__ ) . '/.github/workflows/' . $name ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local workflow contract.
		self::assertIsString( $workflow );

		return $workflow;
	}
}
