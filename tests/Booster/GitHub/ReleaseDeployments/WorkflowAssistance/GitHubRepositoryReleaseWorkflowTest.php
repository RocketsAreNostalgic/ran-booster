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

	public function testStatusUsesStoredRecordIdentityWhenRecordOccupiesRepository(): void {
		$records = new SetupRecordStore();
		self::assertTrue(
			$records->save(
				$this->record(
					array(
						'repo_id'            => '101',
						'package_type'       => 'theme',
						'package_identifier' => 'foreign-plugin/foreign-plugin.php',
						'source_revision'    => 5,
					)
				)
			)
		);

		$workflow = $this->workflow( new WorkflowCredentialStore() );
		$status   = ( new D23ReleaseFacade() )->status( 'plugin', 'example-plugin/example-plugin.php' );
		$result   = $workflow->status( $status );

		self::assertSame( 'theme', $result->packageType() );
		self::assertSame( 'foreign-plugin/foreign-plugin.php', $result->packageIdentifier() );
		self::assertSame( 5, $result->sourceRevision() );
		self::assertFalse( $result->recordExact() );
		self::assertTrue( $result->recordOccupied() );
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

	private function record( array $overrides = array() ): array {
		return array_replace(
			array(
				'schema_version'        => 2,
				'operation'             => 'bootstrap',
				'repo_id'               => '101',
				'repository'            => 'RocketsAreNostalgic/example-plugin',
				'package_type'          => 'plugin',
				'package_identifier'    => 'example-plugin/example-plugin.php',
				'source_revision'       => 3,
				'default_branch'        => 'main',
				'base_sha'              => str_repeat( 'a', 40 ),
				'setup_branch'          => 'ran-booster/release-setup-v2-aaaaaaaaaaaa-deadbeef',
				'head_sha'              => str_repeat( 'b', 40 ),
				'pr_number'             => 42,
				'profile_id'            => 'source-ready-wordpress-plugin/2',
				'template_repo_name'    => 'RocketsAreNostalgic/ran-booster-release-bootstrap-templates',
				'template_repo_id'      => '1322743261',
				'template_release_id'   => 41,
				'template_tag'          => 'v1.2.3',
				'template_commit'       => str_repeat( 'c', 40 ),
				'template_asset_id'     => 73,
				'template_asset_name'   => 'ran-booster-release-bootstrap-templates.zip',
				'template_asset_size'   => 1000,
				'template_asset_digest' => str_repeat( 'd', 64 ),
				'manifest_digest'       => str_repeat( 'e', 64 ),
				'receipt_digest'        => str_repeat( 'f', 64 ),
				'consumer_api'          => 2,
				'pack_version'          => '1.2.3',
				'bundle_hash'           => str_repeat( '1', 64 ),
				'changed_path_hash'     => str_repeat( '2', 64 ),
			),
			$overrides
		);
	}
}
