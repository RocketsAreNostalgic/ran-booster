<?php

declare(strict_types=1);

namespace Tests\Admin\ReleaseManagement;

require_once __DIR__ . '/Support/ReleaseManagementWordPressFunctions.php';
require_once __DIR__ . '/GitHub/Support/GitHubReleaseWorkflowWordPressFunctions.php';

use PHPUnit\Framework\TestCase;
use RAN\Admin\ReleaseManagement\ReleaseWorkflowDisplay;
use ReflectionMethod;

final class ReleaseWorkflowDisplayTest extends TestCase {
	public function testSourceReadyRefusalsExplainWhatMustBeReviewedBeforeSetup(): void {
		$display = new ReleaseWorkflowDisplay();
		foreach ( array(
			'workflow_release_path_conflict'    => 'One or more files Booster would manage already exist. Review and reconcile them before setting up a release workflow.',
			'workflow_package_ambiguous'        => 'Booster could not identify exactly one WordPress package header. Resolve the ambiguity before setting up a release workflow.',
			'workflow_version_mismatch'         => 'The installed version does not match the repository package header. Reconcile the versions before setting up a release workflow.',
			'workflow_version_contract_custom'  => 'Booster found version sources it cannot safely update. Review and reconcile the version contract before setting up a release workflow.',
			'workflow_runtime_paths_unknown'    => 'Booster could not safely determine the package runtime files. Review and reconcile the package layout before setting up a release workflow.',
			'workflow_prettier_contract_custom' => 'Booster found a Prettier ignore contract it cannot safely change. Review and reconcile it before setting up a release workflow.',
			'workflow_repository_unsupported'   => 'This repository does not match the supported WordPress release configuration. Review and reconcile it before setting up a release workflow.',
		) as $code => $message ) {
			$html = $display->workflow(
				array(
					'result_code'       => $code,
					'result_successful' => false,
				)
			);

			self::assertStringContainsString( $message, $html, $code );
			self::assertStringNotContainsString( 'The release workflow request was refused, changed or expired.', $html, $code );
		}
	}

	public function testExactCanonicalReleaseSetupNeedsNoSetupPullRequest(): void {
		$html = ( new ReleaseWorkflowDisplay() )->workflow(
			array(
				'result_code'       => 'workflow_release_automation_present',
				'result_successful' => true,
				'forms'             => array( 'inspect' => array_merge( $this->form( 'inspect' ), array( 'disabled' => true ) ) ),
			)
		);

		self::assertStringContainsString( 'Booster verified an exact canonical release setup in this repository. No setup pull request is needed.', $html );
		self::assertStringContainsString( 'name="booster_credential_id"', $html );
		self::assertStringContainsString( '<button type="submit" class="button" disabled aria-disabled="true">Assess release setup</button>', $html );
	}

	public function testExistingReleaseAutomationConflictIsAnInformationalObservation(): void {
		$html = ( new ReleaseWorkflowDisplay() )->workflow(
			array(
				'result_code'       => 'workflow_release_automation_conflict',
				'result_successful' => false,
				'failure_stage'     => 'repository_snapshot',
				'diagnostic_code'   => 'release_automation_detected',
			)
		);

		self::assertStringContainsString( '<div class="notice notice-info inline"', $html );
		self::assertStringContainsString( 'An existing release workflow was found. Booster will not overwrite it. Review it before using Booster setup.', $html );
		self::assertStringNotContainsString( 'Failure details', $html );
		self::assertStringNotContainsString( 'Diagnostic code:', $html );
		self::assertStringNotContainsString( 'Competing release automation', $html );
	}

	public function testRateLimitIsAnAdvisoryRatherThanACredentialFailure(): void {
		$html = ( new ReleaseWorkflowDisplay() )->workflow(
			array(
				'result_code'       => 'workflow_rate_limited',
				'result_successful' => false,
				'failure_stage'     => 'repository_snapshot',
			)
		);

		self::assertStringContainsString( '<div class="notice notice-warning inline"', $html );
		self::assertStringContainsString( 'The repository provider has temporarily rate-limited the release workflow request.', $html );
		self::assertStringNotContainsString( 'selected saved credential', $html );
	}

	public function testRequestValidationFailureDetailsExplainTheSafeAction(): void {
		$display = new ReleaseWorkflowDisplay();
		foreach ( array(
			'malformed_request'       => 'The request was incomplete or malformed. Reload the release workflow page and try again.',
			'permissions_unavailable' => 'Your current account no longer has the permissions required to manage this package. Sign in with an administrator account and try again.',
			'package_source_changed'  => 'The saved package or source changed before Booster could act. Reload the current package state and assess it again.',
			'nonce_expired'           => 'This form has expired. Reload the release workflow page and try again.',
		) as $diagnostic => $message ) {
			$html = $display->workflow(
				array(
					'result_code'       => 'workflow_invalid_request',
					'result_successful' => false,
					'failure_stage'     => 'request_validation',
					'diagnostic_code'   => $diagnostic,
				)
			);

			self::assertStringContainsString( 'Booster stopped before contacting the repository provider because this request no longer matched the current page or package.', $html, $diagnostic );
			self::assertStringContainsString( '<details><summary>Failure details</summary>', $html, $diagnostic );
			self::assertStringContainsString( $message, $html, $diagnostic );
			self::assertStringContainsString( 'Diagnostic code: <code>' . $diagnostic . '</code>', $html, $diagnostic );
		}
	}

	public function testImmediateReleasePreflightFailureIsAnErrorNoticeWithAnInlineDiagnosticDisclosure(): void {
		$display = new ReleaseWorkflowDisplay();
		$html    = $display->workflow(
			array(
				'result_code'           => 'workflow_preflight_unavailable',
				'result_successful'     => false,
				'failure_stage'         => 'release_preflight',
				'diagnostic_code'       => 'provider_unavailable',
				'diagnostic_available'  => true,
				'correlation_reference' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
				'forms'                 => array( 'inspect' => $this->form( 'inspect' ) ),
			)
		);

		self::assertStringContainsString( 'Booster could not validate the package release before continuing. No draft was opened.', $html );
		self::assertStringContainsString( '<div class="notice notice-error inline" data-ran-booster-release-workflow-result><p>', $html );
		self::assertStringContainsString( '<details><summary>Failure details</summary>', $html );
		self::assertStringContainsString( 'Booster could not read release data using the package&#039;s saved repository access. The credential selected for workflow setup is used only after this release check.', $html );
		self::assertStringContainsString( 'Diagnostic code: <code>provider_unavailable</code>', $html );
		self::assertStringContainsString( 'Failure reference: <code>aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa</code>', $html );
		self::assertStringNotContainsString( 'exception message', $html );

		$withoutReference = $display->workflow(
			array(
				'result_code'           => 'workflow_preflight_unavailable',
				'result_successful'     => false,
				'failure_stage'         => 'release_preflight',
				'diagnostic_code'       => 'provider_unavailable',
				'diagnostic_available'  => false,
				'correlation_reference' => 'cccccccccccccccccccccccccccccccc',
			)
		);
		self::assertStringContainsString( 'Diagnostic code: <code>provider_unavailable</code>', $withoutReference );
		self::assertStringNotContainsString( 'cccccccccccccccccccccccccccccccc', $withoutReference );
	}

	public function testImmediatePreflightContractFailureExplainsThatTheRequestStateMustBeReloaded(): void {
		$html = ( new ReleaseWorkflowDisplay() )->workflow(
			array(
				'result_code'       => 'workflow_preflight_unavailable',
				'result_successful' => false,
				'failure_stage'     => 'release_preflight',
				'diagnostic_code'   => 'preflight_contract_unavailable',
				'failure_history'   => array(
					array(
						'failure_stage'         => 'preview_storage',
						'diagnostic_code'       => 'provider_unavailable',
						'diagnostic_available'  => true,
						'recorded_at'           => '2026-08-27T12:34:56Z',
						'correlation_reference' => 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
					),
				),
			)
		);

		self::assertStringContainsString( 'The page or request state expired or changed. Reload the page and retry.', $html );
		self::assertStringContainsString( 'Diagnostic code: <code>preflight_contract_unavailable</code>', $html );
		self::assertStringNotContainsString( 'Earlier workflow record', $html );
		self::assertStringNotContainsString( 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb', $html );
	}

	public function testResultNoticeUsesProviderMessagesOnlyWhenNonEmptyAndFallsBackOtherwise(): void {
		$display = new ReleaseWorkflowDisplay();
		$custom  = $display->resultNotice(
			array(
				'result_code'        => 'workflow_preflight_unavailable',
				'result_successful'  => false,
				'failure_stage'      => 'release_preflight',
				'diagnostic_code'    => 'provider_unavailable',
				'result_message'     => 'Provider-specific success text.',
				'result_remediation' => 'Provider-specific remediation text.',
			)
		);

		self::assertStringContainsString( 'Provider-specific success text.', $custom );
		self::assertStringContainsString( 'Provider-specific remediation text.', $custom );
		self::assertStringNotContainsString( 'Booster could not validate the package release before continuing. No draft was opened.', $custom );
		self::assertStringNotContainsString( 'Booster could not read release data using the package&#039;s saved repository access. The credential selected for workflow setup is used only after this release check.', $custom );

		$fallback = $display->resultNotice(
			array(
				'result_code'        => 'workflow_preflight_unavailable',
				'result_successful'  => false,
				'failure_stage'      => 'release_preflight',
				'diagnostic_code'    => 'provider_unavailable',
				'result_message'     => '',
				'result_remediation' => '',
			)
		);

		self::assertStringContainsString( 'Booster could not validate the package release before continuing. No draft was opened.', $fallback );
		self::assertStringContainsString( 'Booster could not read release data using the package&#039;s saved repository access. The credential selected for workflow setup is used only after this release check.', $fallback );
		self::assertStringNotContainsString( 'Provider-specific success text.', $fallback );
		self::assertStringNotContainsString( 'Provider-specific remediation text.', $fallback );
	}

	public function testDurablePublishedReleaseDocumentationLinksRenderInEveryWorkflowState(): void {
		$display = new ReleaseWorkflowDisplay();
		foreach ( array(
			array( 'forms' => array( 'inspect' => $this->form( 'inspect' ) ) ),
			array(
				'unavailable'        => true,
				'unavailable_reason' => 'Blocked.',
			),
			array(
				'legacy' => array(
					'schema_version' => 1,
					'unsupported'    => 1,
				),
			),
		) as $view ) {
			$view['documentation_links'] = array(
				array(
					'label' => 'Fixture provider releases',
					'url'   => 'https://forge.example.test/docs/releases',
				),
			);
			$html                        = $display->workflow( $view );
			self::assertStringContainsString( 'Booster Releases docs', $html );
			self::assertStringContainsString( 'admin.php?page=ran-booster&amp;tab=documentation#ran-booster-documentation-published-releases', $html );
			self::assertStringContainsString( 'Fixture provider releases', $html );
			self::assertStringContainsString( 'https://forge.example.test/docs/releases', $html );
			self::assertStringNotContainsString( 'github.com', $html );
		}
	}

	public function testPreviewAndFormValuesAreEscapedWithoutLeakingRawMarkup(): void {
		$html = ( new ReleaseWorkflowDisplay() )->workflow(
			array(
				'result_code'       => 'workflow_inspected',
				'result_successful' => true,
				'preview'           => array(
					'kind'            => 'bootstrap',
					'repository'      => 'owner/<script>alert(1)</script>',
					'default_branch'  => 'main"><img src=x onerror=alert(1)>',
					'base_sha'        => str_repeat( 'a', 40 ),
					'pack_version'    => '1.2.3<script>',
					'template_digest' => str_repeat( 'b', 64 ),
					'changes'         => array(
						array(
							'path'      => '<script>path</script>',
							'operation' => 'added"><img src=x>',
							'digest'    => str_repeat( 'c', 64 ),
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
		self::assertStringContainsString( 'data-ran-booster-release-workflow-result', $html );
		self::assertStringContainsString( '<div class="ran-booster-release-workflow">', $html );
		self::assertStringNotContainsString( '<details', $html );
		self::assertStringContainsString( 'name="booster_credential_id"', $html );
		self::assertStringContainsString( 'name="booster_credential_id" required', $html );
		self::assertStringContainsString( 'Manage credentials', $html );
	}

	public function testSchemaTwoRecordRendersOnlyCurrentOutcomeAndUpdateControls(): void {
		$html = ( new ReleaseWorkflowDisplay() )->workflow(
			array(
				'result_code'       => '',
				'result_successful' => false,
				'preview'           => null,
				'record'            => array(
					'pull_request_url' => 'https://forge.example.test/owner/example/pull/17',
				),
				'legacy'            => null,
				'forms'             => array(
					'inspect'        => array_merge( $this->form( 'inspect' ), array( 'disabled' => true ) ),
					'outcome'        => $this->form( 'outcome' ),
					'update_inspect' => $this->form( 'update_inspect' ),
				),
			)
		);

		self::assertStringContainsString( 'https://forge.example.test/owner/example/pull/17', $html );
		self::assertStringContainsString( 'Review recorded setup pull request', $html );
		self::assertStringNotContainsString( 'github.com', $html );
		self::assertStringContainsString( 'Check pull request outcome', $html );
		self::assertStringContainsString( 'Check for template updates', $html );
		self::assertStringContainsString( 'Assess release setup', $html );
		self::assertStringNotContainsString( 'Legacy, unverified', $html );
		self::assertStringContainsString( '<div class="ran-booster-release-workflow">', $html );
		self::assertStringNotContainsString( '<details', $html );
	}

	public function testRecordedWorkflowKeepsOutcomeAndUpdateControlsWhenItsPullRequestUrlIsUnavailable(): void {
		$html = ( new ReleaseWorkflowDisplay() )->workflow(
			array(
				'record' => array( 'pull_request_url' => '' ),
				'forms'  => array(
					'inspect'        => array_merge( $this->form( 'inspect' ), array( 'disabled' => true ) ),
					'outcome'        => $this->form( 'outcome' ),
					'update_inspect' => $this->form( 'update_inspect' ),
				),
			)
		);

		self::assertStringContainsString( 'Check pull request outcome', $html );
		self::assertStringContainsString( 'Check for template updates', $html );
		self::assertStringNotContainsString( 'Review recorded setup pull request', $html );
	}

	public function testUnavailableRetainsTheAssessmentInterfaceButDisablesItsControls(): void {
		$reason = 'A temporary upstream limitation prevents direct assessment right now.';
		$html   = ( new ReleaseWorkflowDisplay() )->workflow(
			array(
				'unavailable'        => true,
				'unavailable_reason' => $reason,
				'forms'              => array(
					'inspect' => array_merge( $this->form( 'inspect' ), array( 'disabled' => true ) ),
				),
			)
		);

		self::assertStringContainsString( 'Assess this repository before preparing a release-workflow pull request.', $html );
		self::assertStringContainsString( '<div class="ran-booster-release-workflow">', $html );
		self::assertStringNotContainsString( '<details', $html );
		self::assertStringContainsString( $reason, $html );
		self::assertStringContainsString( '<form', $html );
		self::assertStringContainsString( 'name="booster_credential_id"', $html );
		self::assertStringContainsString( '<select name="booster_credential_id" disabled aria-disabled="true">', $html );
		self::assertStringContainsString( '>Manage credentials</a>', $html );
		self::assertStringContainsString( 'Inspect anonymously, or use a saved credential to avoid anonymous API limits.', $html );
		self::assertStringContainsString( '<button type="submit" class="button" disabled aria-disabled="true">Assess release setup</button>', $html );
		self::assertStringNotContainsString( 'Set up release automation', $html );
		self::assertStringNotContainsString( 'type="password"', $html );
	}

	public function testStableWorkflowShellKeepsNoticeAssessmentAndDocsZonesOrderedAcrossStates(): void {
		$inspect = $this->form( 'inspect' );
		$states  = array(
			'needs-attention'     => array(
				'result_code'       => 'workflow_invalid_request',
				'result_successful' => false,
				'forms'             => array( 'inspect' => $inspect ),
			),
			'existing-automation' => array(
				'result_code'       => 'workflow_release_automation_conflict',
				'result_successful' => false,
				'forms'             => array( 'inspect' => $inspect ),
			),
			'preview'             => array(
				'preview' => array(
					'kind'            => 'bootstrap',
					'repository'      => 'owner/example',
					'default_branch'  => 'main',
					'base_sha'        => str_repeat( 'a', 40 ),
					'pack_version'    => '1.2.3',
					'template_digest' => str_repeat( 'b', 64 ),
					'changes'         => array(),
				),
				'forms'   => array(
					'inspect' => array_merge( $inspect, array( 'disabled' => true ) ),
					'setup'   => $this->form( 'setup', 'owner/example' ),
				),
			),
			'recorded'            => array(
				'record' => array(
					'pull_request_url' => 'https://forge.example.test/owner/example/pull/17',
				),
				'forms'  => array(
					'inspect'        => array_merge( $inspect, array( 'disabled' => true ) ),
					'outcome'        => $this->form( 'outcome' ),
					'update_inspect' => $this->form( 'update_inspect' ),
				),
			),
		);

		foreach ( $states as $state => $view ) {
			$html       = ( new ReleaseWorkflowDisplay() )->workflow( $view );
			$notice     = strpos( $html, 'ran-booster-release-workflow__notices' );
			$intro      = strpos( $html, 'Assess this repository before preparing a release-workflow pull request.' );
			$credential = strpos( $html, 'name="booster_credential_id"' );
			$assess     = strpos( $html, 'Assess release setup' );
			$docs       = strpos( $html, 'Booster Releases docs' );

			self::assertSame( 1, substr_count( $html, 'ran-booster-release-workflow__notices' ), $state );
			self::assertIsInt( $notice, $state );
			self::assertIsInt( $intro, $state );
			self::assertIsInt( $credential, $state );
			self::assertIsInt( $assess, $state );
			self::assertIsInt( $docs, $state );
			self::assertTrue( $notice < $intro && $intro < $credential && $credential < $assess && $assess < $docs, $state );
		}
	}

	public function testVerifiedReleaseDoesNotClaimWorkflowOperation(): void {
		$html = ( new ReleaseWorkflowDisplay() )->workflow(
			array(
				'result_code'        => 'workflow_release_ready',
				'result_successful'  => true,
				'unavailable'        => true,
				'unavailable_reason' => 'Published releases are working, but Booster cannot tell whether a release workflow produced them.',
				'forms'              => array(
					'inspect' => array_merge( $this->form( 'inspect' ), array( 'disabled' => true ) ),
				),
			)
		);

		self::assertStringContainsString( 'Published releases are working, but Booster cannot tell whether a release workflow produced them.', $html );
		self::assertStringContainsString(
			'Published releases are available, but Booster cannot tell whether a release workflow produced them.',
			( new ReleaseWorkflowDisplay() )->workflow(
				array(
					'result_code'       => 'workflow_release_ready',
					'result_successful' => true,
				)
			)
		);
		self::assertStringContainsString( '<button type="submit" class="button" disabled aria-disabled="true">Assess release setup</button>', $html );
		self::assertStringNotContainsString( 'Set up release automation', $html );
	}

	public function testLegacyAndUnknownEvidenceRemainDisplayOnlyAndEscaped(): void {
		$display = new ReleaseWorkflowDisplay();
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
		self::assertStringContainsString( 'An earlier workflow record does not match the current package.', $legacy );
		self::assertStringNotContainsString( 'https://github.com/owner/example/pull/17', $legacy );
		self::assertStringNotContainsString( '<script>', $legacy );
		self::assertStringNotContainsString( '<form', $legacy );
		self::assertStringContainsString( '<div class="ran-booster-release-workflow">', $legacy );
		self::assertStringNotContainsString( '<details', $legacy );

		$unknown = $display->workflow(
			array(
				'legacy' => array(
					'schema_version' => 1,
					'unsupported'    => 1,
					'opaque'         => '<script>do-not-render</script>',
				),
			)
		);
		self::assertStringContainsString( 'does not match the current package', $unknown );
		self::assertStringNotContainsString( 'do-not-render', $unknown );
		self::assertStringNotContainsString( '<form', $unknown );
	}

	public function testAllFiveFormKindsUseNewActionsAndExpectedCredentialRequirements(): void {
		$display = new ReleaseWorkflowDisplay();
		$method  = new ReflectionMethod( $display, 'form' );
		foreach ( array( 'inspect', 'setup', 'outcome', 'update_inspect', 'update_setup' ) as $operation ) {
			$html = $method->invoke( $display, $this->form( $operation, str_contains( $operation, 'setup' ) ? 'owner/example' : '' ) );
			self::assertStringContainsString( 'data-ran-booster-enhanced-mutation data-ran-booster-package-mutation', $html, $operation );
			self::assertStringNotContainsString( 'data-ran-booster-relocate-rendered-error', $html, $operation );
			self::assertStringNotContainsString( 'data-ran-booster-error-target="#ran-booster-package-mutation-error"', $html, $operation );
			self::assertStringContainsString( 'hx-post="/wp-admin/admin-post.php" hx-target="#wpbody-content" hx-select="#wpbody-content" hx-swap="outerHTML show:none" hx-sync="this:drop"', $html, $operation );
			self::assertStringContainsString( 'value="ran_booster_release_workflow"', $html, $operation );
			self::assertStringContainsString( 'name="workflow_operation" value="' . $operation . '"', $html, $operation );
			self::assertStringNotContainsString( 'release_deployments', $html, $operation );
			self::assertStringNotContainsString( 'workflow_workflow', $html, $operation );
			if ( in_array( $operation, array( 'setup', 'update_setup' ), true ) ) {
				self::assertStringContainsString( 'name="booster_credential_id" required', $html, $operation );
			} else {
				self::assertStringContainsString( 'name="booster_credential_id"', $html, $operation );
			}
		}
	}

	public function testAdapterSourcesContainNoRetiredRoutesProductTextOrTextDomain(): void {
		$root = dirname( __DIR__, 3 ) . '/RAN/Admin/ReleaseManagement/';
		foreach ( array( 'ReleaseWorkflowControls.php', 'ReleaseWorkflowDisplay.php' ) as $file ) {
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
			'operation'            => $operation,
			'action'               => 'https://example.test/wp-admin/admin-post.php',
			'fields'               => array(
				'action'             => 'ran_booster_release_workflow',
				'workflow_operation' => $operation,
				'_wpnonce'           => 'nonce-for-' . $operation,
				'hostile_key'        => '"><script>hidden</script>',
			),
			'confirm'              => $confirmation,
			'credentials'          => array(
				array(
					'id'    => 'credential_1',
					'label' => 'Example credential',
				),
			),
			'anonymous_inspection' => true,
			'credentials_url'      => 'https://example.test/wp-admin/admin.php?page=ran-booster&tab=gh&view=credentials',
		);
	}
}
