<?php

declare(strict_types=1);

namespace Tests\Admin\ReleaseManagement\GitHub;

require_once __DIR__ . '/../Support/ReleaseManagementWordPressFunctions.php';
require_once __DIR__ . '/Support/GitHubReleaseWorkflowWordPressFunctions.php';

use PHPUnit\Framework\TestCase;
use RAN\Admin\ReleaseManagement\GitHub\GitHubReleaseWorkflowDisplay;
use ReflectionMethod;

final class GitHubReleaseWorkflowDisplayTest extends TestCase {
	public function testPreviewAndFormValuesAreEscapedWithoutLeakingRawMarkup(): void {
		$html = ( new GitHubReleaseWorkflowDisplay() )->workflow(
			array(
				'result_code'       => 'workflow_inspected',
				'result_successful' => true,
				'preview'           => array(
					'kind'                  => 'bootstrap',
					'repository'            => 'owner/<script>alert(1)</script>',
					'default_branch'        => 'main"><img src=x onerror=alert(1)>',
					'base_sha'              => str_repeat( 'a', 40 ),
					'pack_version'          => '1.2.3<script>',
					'new_template_identity' => array( 'asset_sha256' => str_repeat( 'b', 64 ) ),
					'changes'               => array(
						array(
							'path'      => '<script>path</script>',
							'operation' => 'added"><img src=x>',
							'sha256'    => str_repeat( 'c', 64 ),
						),
					),
				),
				'record'            => null,
				'legacy'            => null,
				'forms'             => array(
					'setup' => $this->form( 'setup', '<script>confirm</script>' ),
				),
			)
		);

		self::assertStringNotContainsString( '<script>', $html );
		self::assertStringNotContainsString( '<img ', $html );
		self::assertStringContainsString( '&lt;script&gt;path&lt;/script&gt;', $html );
		self::assertStringContainsString( '&lt;script&gt;confirm&lt;/script&gt;', $html );
		self::assertStringContainsString( 'data-ran-booster-package-success', $html );
		self::assertStringContainsString( 'name="github_token"', $html );
		self::assertStringContainsString( 'name="github_token" autocomplete="off" class="regular-text" required', $html );
	}

	public function testSchemaTwoRecordRendersOnlyCurrentOutcomeAndUpdateControls(): void {
		$html = ( new GitHubReleaseWorkflowDisplay() )->workflow(
			array(
				'result_code'       => '',
				'result_successful' => false,
				'preview'           => null,
				'record'            => array(
					'schema_version' => 2,
					'repository'     => 'owner/example',
					'pr_number'      => 17,
				),
				'legacy'            => null,
				'forms'             => array(
					'outcome'        => $this->form( 'outcome' ),
					'update_inspect' => $this->form( 'update_inspect' ),
				),
			)
		);

		self::assertStringContainsString( 'https://github.com/owner/example/pull/17', $html );
		self::assertStringContainsString( 'Check pull request outcome', $html );
		self::assertStringContainsString( 'Check for template updates', $html );
		self::assertStringNotContainsString( 'Assess source-ready release setup', $html );
		self::assertStringNotContainsString( 'Legacy, unverified', $html );
	}

	public function testUnavailableRendersReadOnlyAssessPromptWithReasonAndNoFormInputs(): void {
		$reason = 'A temporary upstream limitation prevents direct assessment right now.';
		$html   = ( new GitHubReleaseWorkflowDisplay() )->workflow(
			array(
				'unavailable'        => true,
				'unavailable_reason' => $reason,
				'forms'              => array(
					'inspect' => $this->form( 'inspect' ),
				),
			)
		);

		self::assertStringContainsString( 'Release automation', $html );
		self::assertStringContainsString( 'Release automation cannot be assessed with the current package settings.', $html );
		self::assertStringContainsString( '<details class="ran-booster-release-workflow" open>', $html );
		self::assertStringContainsString( 'Assess source-ready release setup', $html );
		self::assertStringContainsString( $reason, $html );
		self::assertStringContainsString( '<button type="submit" class="button" disabled>', $html );
		self::assertStringNotContainsString( '<form', $html );
		self::assertStringNotContainsString( 'name="github_token"', $html );
		self::assertStringNotContainsString( 'type="hidden"', $html );
		self::assertStringNotContainsString( 'type="password"', $html );
	}

	public function testLegacyAndUnknownEvidenceRemainDisplayOnlyAndEscaped(): void {
		$display = new GitHubReleaseWorkflowDisplay();
		$legacy  = $display->workflow(
			array(
				'legacy' => array(
					'schema_version' => 1,
					'repository'     => 'owner/example',
					'setup_branch'   => 'ran-booster/release-setup-v1-aaaa"><script>branch</script>',
					'pr_number'      => 17,
				),
			)
		);
		self::assertStringContainsString( 'Legacy, unverified manual-reconciliation evidence.', $legacy );
		self::assertStringContainsString( 'https://github.com/owner/example/pull/17', $legacy );
		self::assertStringContainsString( '&lt;script&gt;branch&lt;/script&gt;', $legacy );
		self::assertStringNotContainsString( '<script>', $legacy );
		self::assertStringNotContainsString( '<form', $legacy );

		$unknown = $display->workflow(
			array(
				'legacy' => array(
					'schema_version' => 1,
					'unsupported'    => 1,
					'opaque'         => '<script>do-not-render</script>',
				),
			)
		);
		self::assertStringContainsString( 'not authoritative for this package', $unknown );
		self::assertStringNotContainsString( 'do-not-render', $unknown );
		self::assertStringNotContainsString( '<form', $unknown );
	}

	public function testAllFiveFormKindsUseNewActionsAndExpectedCredentialRequirements(): void {
		$display = new GitHubReleaseWorkflowDisplay();
		$method  = new ReflectionMethod( $display, 'form' );
		foreach ( array( 'inspect', 'setup', 'outcome', 'update_inspect', 'update_setup' ) as $operation ) {
			$html = $method->invoke( $display, $this->form( $operation, str_contains( $operation, 'setup' ) ? 'owner/example' : '' ) );
			self::assertStringContainsString( 'value="ran_booster_github_release_workflow_' . $operation . '"', $html, $operation );
			self::assertStringNotContainsString( 'release_deployments', $html, $operation );
			self::assertStringNotContainsString( 'workflow_workflow', $html, $operation );
			if ( in_array( $operation, array( 'setup', 'update_setup' ), true ) ) {
				self::assertStringContainsString( 'name="github_token" autocomplete="off" class="regular-text" required', $html, $operation );
			} else {
				self::assertStringNotContainsString( 'class="regular-text" required', $html, $operation );
			}
		}
	}

	public function testAdapterSourcesContainNoRetiredRoutesProductTextOrTextDomain(): void {
		$root = dirname( __DIR__, 4 ) . '/RAN/Admin/ReleaseManagement/GitHub/';
		foreach ( array( 'GitHubReleaseWorkflowControls.php', 'GitHubReleaseWorkflowDisplay.php' ) as $file ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Static local source boundary under test.
			$source = file_get_contents( $root . $file );
			self::assertIsString( $source );
			foreach ( array(
				'ran_booster_release_deployments',
				'ran-booster-release-deployments',
				"'ran-booster-release-deployments'",
				'Release Deployments add-on',
			) as $retired ) {
				self::assertStringNotContainsString( $retired, $source, $file );
			}
		}
	}

	/** @return array<string,mixed> */
	private function form( string $operation, string $confirmation = '' ): array {
		return array(
			'operation' => $operation,
			'action'    => 'https://example.test/wp-admin/admin-post.php',
			'fields'    => array(
				'action'      => 'ran_booster_github_release_workflow_' . $operation,
				'_wpnonce'    => 'nonce-for-' . $operation,
				'hostile_key' => '"><script>hidden</script>',
			),
			'confirm'   => $confirmation,
		);
	}
}
