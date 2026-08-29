<?php

declare(strict_types=1);

namespace Tests\Booster\GitHub\ReleaseDeployments\WorkflowAssistance;

use PHPUnit\Framework\TestCase;
use RAN\AddOn\ReleaseTracking\ReleaseTrackingPreflight;
use RAN\Booster\GitHub\ReleaseDeployments\WorkflowAssistance\GitHubRepositoryClient;
use RAN\Booster\GitHub\ReleaseDeployments\WorkflowAssistance\GitHubRepositoryReleaseWorkflow;
use RAN\Booster\GitHub\ReleaseDeployments\WorkflowAssistance\SetupRecordStore;
use RAN\Booster\GitHub\ReleaseDeployments\WorkflowAssistance\SourceReadyAssessor;
use RAN\Booster\GitHub\ReleaseDeployments\WorkflowAssistance\TemplatePackRepositoryClient;
use RAN\Booster\GitHub\ReleaseDeployments\WorkflowAssistance\WorkflowApplicationCoordinator;

require_once __DIR__ . '/WorkflowAssistanceTestBootstrap.php';
require_once __DIR__ . '/Support/WorkflowCredentialStore.php';

final class GitHubRepositoryReleaseWorkflowTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['ran_booster_release_deployments_test_options']    = array();
		$GLOBALS['ran_booster_release_deployments_test_transients'] = array();
	}

	public function testPassiveStatusUsesOnlyDisplaySafeProfilesAndExactActionsUrl(): void {
		$credentials = new WorkflowCredentialStore();
		$workflow    = $this->workflow( $credentials );
		$status      = ( new D23ReleaseFacade() )->status( 'plugin', 'example-plugin/example-plugin.php' );

		$result = $workflow->status( $status );

		self::assertSame( 'https://github.com/owner/example-plugin/actions', $result->providerWorkflowUrl() );
		self::assertSame(
			array(
				array(
					'id'    => 'eligible',
					'label' => 'Repository access (classic)',
				),
			),
			$result->credentialChoices()
		);
		self::assertSame( 1, $credentials->profileReads );
		self::assertSame( array(), $credentials->materialReads );
		self::assertSame( array(), $GLOBALS['ran_booster_release_deployments_test_options'] );
	}

	public function testInvalidUpdateLifecycleRequestsDoNotReadCredentialMaterial(): void {
		$credentials = new WorkflowCredentialStore();
		$workflow    = $this->workflow( $credentials );
		$status      = ( new D23ReleaseFacade() )->status( 'plugin', 'example-plugin/example-plugin.php' );

		self::assertSame( 'workflow_invalid_request', $workflow->outcome( $status, 'eligible' )->workflowCode() );
		self::assertSame( 'workflow_invalid_request', $workflow->inspectUpdate( $status, 'eligible' )->workflowCode() );
		self::assertSame( 'workflow_invalid_request', $workflow->setupUpdate( $status, str_repeat( 'a', 32 ), 'owner/example-plugin', 'eligible' )->workflowCode() );
		self::assertSame( array(), $credentials->materialReads );
	}

	public function testCredentialChoiceLabelIsUtf8SafeAndBoundedToStatusContract(): void {
		$credentials           = new WorkflowCredentialStore();
		$credentials->profiles = array(
			'eligible' => array(
				'id'         => 'eligible',
				'label'      => str_repeat( 'あ', 100 ),
				'kind'       => 'classic',
				'source'     => 'file',
				'immutable'  => false,
				'configured' => true,
			),
		);
		$status                = ( new D23ReleaseFacade() )->status( 'plugin', 'example-plugin/example-plugin.php' );

		$choice = $this->workflow( $credentials )->status( $status )->credentialChoices()[0];

		self::assertLessThanOrEqual( 255, strlen( $choice['label'] ) );
		self::assertSame( 1, preg_match( '//u', $choice['label'] ) );
		self::assertStringEndsWith( ' (classic)', $choice['label'] );
	}

	public function testPreflightAndIneligibleSavedCredentialAreRejectedBeforeMaterialRead(): void {
		$credentials = new WorkflowCredentialStore();
		$workflow    = $this->workflow( $credentials );
		$status      = ( new D23ReleaseFacade() )->status( 'plugin', 'example-plugin/example-plugin.php' );
		$blocked     = new ReleaseTrackingPreflight( ReleaseTrackingPreflight::PREFLIGHT_UNAVAILABLE, 'example-plugin', reasonCode: 'provider_unavailable' );

		$preflightResult = $workflow->inspect( $status, 'stable', $blocked, 'eligible' );
		self::assertSame( 'workflow_preflight_unavailable', $preflightResult->workflowCode() );
		self::assertSame( '', $preflightResult->correlationReference() );
		self::assertSame( array(), $credentials->materialReads );

		$preview = $workflow->inspect( $status, 'stable', new ReleaseTrackingPreflight( ReleaseTrackingPreflight::RELEASE_UNAVAILABLE, 'example-plugin' ), null );
		self::assertTrue( $preview->successful() );
		self::assertSame( 'workflow_unauthorised', $workflow->setup( $status, $preview->previewKey(), 'owner/example-plugin', new ReleaseTrackingPreflight( ReleaseTrackingPreflight::RELEASE_UNAVAILABLE, 'example-plugin' ), 'constant' )->workflowCode() );
		self::assertSame( array(), $credentials->materialReads );
	}

	private function workflow( WorkflowCredentialStore $credentials ): GitHubRepositoryReleaseWorkflow {
		$records     = new SetupRecordStore();
		$transport   = new D23ApplicationTransport();
		$coordinator = new WorkflowApplicationCoordinator( new GitHubRepositoryClient( $transport ), new TemplatePackRepositoryClient( $transport ), new SourceReadyAssessor(), $records );
		return new GitHubRepositoryReleaseWorkflow( $credentials, $coordinator, $records );
	}
}
