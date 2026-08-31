<?php

declare(strict_types=1);

namespace Tests\Booster\GitHub\ReleaseDeployments\WorkflowAssistance;

use PHPUnit\Framework\TestCase;
use RAN\AddOn\ReleaseTracking\ReleaseTrackingEligibility;
use RAN\AddOn\ReleaseTracking\ReleaseTrackingFacade;
use RAN\AddOn\ReleaseTracking\ReleaseTrackingPreflight;
use RAN\AddOn\ReleaseTracking\ReleaseTrackingResult;
use RAN\AddOn\ReleaseTracking\ReleaseTrackingStatus;
use RAN\Booster\GitHub\ReleaseDeployments\WorkflowAssistance\GitHubRepositoryClient;
use RAN\Booster\GitHub\ReleaseDeployments\WorkflowAssistance\SetupRecordStore;
use RAN\Booster\GitHub\ReleaseDeployments\WorkflowAssistance\SourceReadyAssessor;
use RAN\Booster\GitHub\ReleaseDeployments\WorkflowAssistance\TemplatePackRepositoryClient;
use RAN\Booster\GitHub\ReleaseDeployments\WorkflowAssistance\WorkflowApplicationCoordinator;
use ReflectionMethod;
use Tests\Booster\GitHub\ReleaseDeployments\WorkflowAssistance\Support\TemplatePackApi2Fixture;
use function RAN\Booster\GitHub\ReleaseDeployments\WorkflowAssistance\wp_json_encode;

require_once __DIR__ . '/WorkflowAssistanceTestBootstrap.php';
require_once __DIR__ . '/Support/TemplatePackApi2Fixture.php';

final class WorkflowApplicationCoordinatorTest extends TestCase {
	public function testNullPreflightIsReportedAsAContractAvailabilityFailure(): void {
		$facade      = new D23ReleaseFacade();
		$coordinator = $this->coordinator( $facade, new D23ApplicationTransport(), new SetupRecordStore() );
		$status      = $facade->status( 'plugin', 'example-plugin/example-plugin.php' );

		$facade->preflightContractUnavailable = true;
		$result                               = $coordinator->inspect( $status, 'stable', new ReleaseTrackingPreflight( ReleaseTrackingPreflight::PREFLIGHT_UNAVAILABLE, 'example-plugin' ), 'token' );
		self::assertSame( 'workflow_preflight_unavailable', $result['code'] );
		self::assertSame( 'provider_unavailable', $result['diagnostic_code'] );
	}

	public function testReleaseAssetInspectionUsesTheAssessmentPreflightContract(): void {
		$facade      = new D23ReleaseFacade( 'release_asset' );
		$coordinator = $this->coordinator( $facade, new D23ApplicationTransport(), new SetupRecordStore() );
		$status      = $facade->status( 'plugin', 'example-plugin/example-plugin.php' );

		$result = $coordinator->inspect( $status, 'stable', new ReleaseTrackingPreflight( ReleaseTrackingPreflight::RELEASE_UNAVAILABLE, 'example-plugin' ), 'token' );

		self::assertSame( 'workflow_inspected', $result['code'] );
		self::assertSame( array(), $facade->calls );
	}

	public function testReturnedUnavailablePreflightUsesItsSafeReasonCode(): void {
		$facade                    = new D23ReleaseFacade();
		$coordinator               = $this->coordinator( $facade, new D23ApplicationTransport(), new SetupRecordStore() );
		$status                    = $facade->status( 'plugin', 'example-plugin/example-plugin.php' );
		$facade->preflightResponse = new ReleaseTrackingPreflight(
			ReleaseTrackingPreflight::PREFLIGHT_UNAVAILABLE,
			'example-plugin',
			reasonCode: 'provider_unavailable'
		);
		$result                    = $coordinator->inspect( $status, 'stable', $facade->preflightResponse, 'token' );
		self::assertSame( 'workflow_preflight_unavailable', $result['code'] );
		self::assertSame( 'provider_unavailable', $result['diagnostic_code'] );
	}

	public function testRejectedPreflightRetainsTheReleasePreflightDiagnosticForInspectionAndSetup(): void {
		$facade                    = new D23ReleaseFacade();
		$coordinator               = $this->coordinator( $facade, new D23ApplicationTransport(), new SetupRecordStore() );
		$status                    = $facade->status( 'plugin', 'example-plugin/example-plugin.php' );
		$facade->preflightResponse = new ReleaseTrackingPreflight( 'invalid_release_assets', 'example-plugin', reasonCode: 'invalid_release' );

		$inspection = $coordinator->inspect( $status, 'stable', $facade->preflightResponse, 'token' );
		self::assertSame( 'release_preflight', $inspection['failure_stage'] );
		self::assertSame( 'invalid_release', $inspection['diagnostic_code'] );

		$facade->preflightResponse = new ReleaseTrackingPreflight( ReleaseTrackingPreflight::READY, 'example-plugin' );
		$preview                   = $coordinator->inspect( $status, 'stable', $facade->preflightResponse, 'token' );
		$facade->preflightResponse = new ReleaseTrackingPreflight( 'invalid_release_assets', 'example-plugin', reasonCode: 'invalid_release' );
		$setup                     = $coordinator->setup( $status, $preview['preview_key'], 'owner/example-plugin', $facade->preflightResponse, 'token' );
		self::assertSame( 'release_preflight', $setup['failure_stage'] );
		self::assertSame( 'invalid_release', $setup['diagnostic_code'] );
	}

	public function testFailureStagesMapOnlyRemoteAuthTemplateAndStorageFaults(): void {
		$status      = ( new D23ReleaseFacade() )->status( 'plugin', 'example-plugin/example-plugin.php' );
		$coordinator = $this->coordinator( new D23ReleaseFacade(), new D23ApplicationTransport(), new SetupRecordStore() );
		$result      = new ReflectionMethod( $coordinator, 'result' );
		$cases       = array(
			'unauthorised'              => 'credential_authorisation',
			'preflight_unavailable'     => 'release_preflight',
			'remote_unavailable'        => 'repository_snapshot',
			'template_pack_unavailable' => 'template_pack',
			'target_changed'            => '',
			'template_superseded'       => '',
			'invalid_request'           => '',
		);

		foreach ( $cases as $code => $stage ) {
			$outcome = $result->invoke( $coordinator, $status, $code, false, '' );
			self::assertSame( $stage, $outcome['failure_stage'], $code );
		}
		self::assertSame(
			'preview_storage',
			$result->invoke( $coordinator, $status, 'remote_unavailable', false, '', 'preview_storage' )['failure_stage']
		);
		self::assertSame(
			'unexpected',
			$result->invoke( $coordinator, $status, 'remote_unavailable', false, '', 'unexpected' )['failure_stage']
		);
		self::assertSame(
			'repository_mutation',
			$result->invoke( $coordinator, $status, 'partial', false, '', 'repository_mutation' )['failure_stage']
		);
	}

	public function testTemplateUpdateSetupPreservesOperationalRemoteFailureClassification(): void {
		foreach ( array(
			401 => array( 'workflow_unauthorised', 'credential_authorisation' ),
			500 => array( 'workflow_remote_unavailable', 'repository_snapshot' ),
		) as $httpStatus => $expected ) {
			$GLOBALS['ran_booster_release_deployments_test_options']    = array();
			$GLOBALS['ran_booster_release_deployments_test_transients'] = array();
			$transport   = new D23ApplicationTransport();
			$facade      = new D23ReleaseFacade();
			$records     = new SetupRecordStore();
			$coordinator = $this->coordinator( $facade, $transport, $records );
			$status      = $facade->status( 'plugin', 'example-plugin/example-plugin.php' );
			$inspect     = $coordinator->inspect( $status, 'stable', $this->readyPreflight(), 'secret-token' );

			self::assertTrue( $coordinator->setup( $status, $inspect['preview_key'], 'owner/example-plugin', $this->readyPreflight(), 'secret-token' )['successful'] );
			$transport->mergePull();
			$transport->offerTemplateUpdate();
			$update = $coordinator->inspectUpdate( $status, 'secret-token' );
			self::assertTrue( $update['successful'] );

			$transport->failRepositoryRead( $httpStatus );
			$outcome = $coordinator->setupUpdate( $status, $update['preview_key'], 'owner/example-plugin', 'secret-token' );
			self::assertSame( $expected[0], $outcome['code'], (string) $httpStatus );
			self::assertSame( $expected[1], $outcome['failure_stage'], (string) $httpStatus );
		}
	}

	protected function setUp(): void {
		$GLOBALS['ran_booster_release_deployments_test_options']    = array();
		$GLOBALS['ran_booster_release_deployments_test_transients'] = array();
	}

	public function testCompleteApiTwoBootstrapUsesExactPreviewGitObjectsReadbackAndSchemaTwo(): void {
		$transport   = new D23ApplicationTransport();
		$facade      = new D23ReleaseFacade();
		$records     = new SetupRecordStore();
		$coordinator = $this->coordinator( $facade, $transport, $records );
		$status      = $facade->status( 'plugin', 'example-plugin/example-plugin.php' );
		$inspect     = $coordinator->inspect( $status, 'stable', $this->readyPreflight(), 'secret-token' );

		self::assertSame( 'workflow_inspected', $inspect['code'] );
		self::assertTrue( $inspect['successful'] );
		$preview = $coordinator->preview( $inspect['preview_key'], $status );
		self::assertNotNull( $preview );
		self::assertSame( 2, $preview['schema_version'] );
		self::assertSame( 'source-ready-wordpress-plugin/2', $preview['profile_id'] );
		self::assertSame( 20, count( $preview ) );
		self::assertCount( 5, array_filter( $preview['changes'], static fn ( array $change ): bool => in_array( $change['path'], array( '.github/workflows/release-please.yml', 'release-please-config.json', 'scripts/build-release.sh', 'scripts/verify-release.sh', 'scripts/upload-release-assets.sh' ), true ) ) );
		self::assertStringNotContainsString( 'secret-token', (string) wp_json_encode( $preview ) );

		$setup = $coordinator->setup( $status, $inspect['preview_key'], 'owner/example-plugin', $this->readyPreflight(), 'secret-token' );
		self::assertSame( 'workflow_setup_open', $setup['code'] );
		self::assertTrue( $setup['successful'] );
		$record = $records->find( '101' );
		self::assertNotNull( $record );
		self::assertSame( 2, $record['schema_version'] );
		self::assertSame( 2, $record['consumer_api'] );
		self::assertSame( 'bootstrap', $record['operation'] );
		self::assertSame( 28, count( $record ) );
		self::assertNull( $coordinator->preview( $inspect['preview_key'], $status ) );
		self::assertSame( 'workflow_pr_open', $coordinator->outcome( $status, 'secret-token' )['code'] );
		$transport->mergePull();
		self::assertSame( 'workflow_pr_merged', $coordinator->outcome( $status, 'secret-token' )['code'] );
		self::assertSame( 'workflow_template_current', $coordinator->inspectUpdate( $status, 'secret-token' )['code'] );
		self::assertGreaterThanOrEqual( 5, $transport->writeCounts['blob'] );
		self::assertSame( 1, $transport->writeCounts['tree'] );
		self::assertSame( 1, $transport->writeCounts['commit'] );
		self::assertSame( 1, $transport->writeCounts['ref'] );
		self::assertSame( 1, $transport->writeCounts['pull'] );
		self::assertNotContains( 'PATCH', array_column( $transport->requests, 'method' ) );
		self::assertNotContains( 'DELETE', array_column( $transport->requests, 'method' ) );
		self::assertStringNotContainsString( 'secret-token', (string) wp_json_encode( $GLOBALS['ran_booster_release_deployments_test_options'] ) );
		foreach ( $transport->requests as $request ) {
			if ( str_contains( (string) ( $request['args']['headers']['Authorization'] ?? '' ), 'secret-token' ) ) {
				continue;
			}
			self::assertStringNotContainsString( 'secret-token', (string) wp_json_encode( $request ) );
		}
	}

	public function testInspectAdoptsOnlyAnExactCanonicalManagedSetupWithoutWritingState(): void {
		$transport   = new D23ApplicationTransport();
		$facade      = new D23ReleaseFacade();
		$status      = $facade->status( 'plugin', 'example-plugin/example-plugin.php' );
		$established = $this->coordinator( $facade, $transport, new SetupRecordStore() );
		$preview     = $established->inspect( $status, 'stable', $this->readyPreflight(), 'selected-token' );
		self::assertSame( 'workflow_setup_open', $established->setup( $status, $preview['preview_key'], 'owner/example-plugin', $this->readyPreflight(), 'selected-token' )['code'] );
		$transport->mergePull();
		$writes = $transport->writeCounts;
		$GLOBALS['ran_booster_release_deployments_test_options'] = array();

		$result = $this->coordinator( $facade, $transport, new SetupRecordStore() )->inspect( $status, 'stable', $this->readyPreflight(), 'selected-token' );

		self::assertSame( 'workflow_release_automation_present', $result['code'] );
		self::assertTrue( $result['successful'] );
		self::assertSame( '', $result['preview_key'] );
		self::assertSame( $writes, $transport->writeCounts );
		self::assertSame( array(), $GLOBALS['ran_booster_release_deployments_test_options'] );
	}

	public function testInspectRefusesModifiedManagedFilesAndMismatchedReceiptInputs(): void {
		foreach ( array( 'modified', 'mismatched' ) as $scenario ) {
			$GLOBALS['ran_booster_release_deployments_test_options']    = array();
			$GLOBALS['ran_booster_release_deployments_test_transients'] = array();
			$transport   = new D23ApplicationTransport();
			$facade      = new D23ReleaseFacade();
			$status      = $facade->status( 'plugin', 'example-plugin/example-plugin.php' );
			$established = $this->coordinator( $facade, $transport, new SetupRecordStore() );
			$preview     = $established->inspect( $status, 'stable', $this->readyPreflight(), 'token' );
			self::assertSame( 'workflow_setup_open', $established->setup( $status, $preview['preview_key'], 'owner/example-plugin', $this->readyPreflight(), 'token' )['code'] );
			$transport->mergePull();
			if ( 'modified' === $scenario ) {
				$transport->mutateDefaultDocument( 'scripts/verify-release.sh', "#!/bin/sh\nprintf hostile\n" );
			} else {
				$transport->mutateReceipt(
					static function ( array $receipt ): array {
						$receipt['inputs']['update_uri'] = 'https://github.com/owner/other';
						return $receipt;
					}
				);
			}
			$writes = $transport->writeCounts;
			$GLOBALS['ran_booster_release_deployments_test_options'] = array();

			$result = $this->coordinator( $facade, $transport, new SetupRecordStore() )->inspect( $status, 'stable', $this->readyPreflight(), 'token' );

			self::assertSame( 'workflow_profile_modified', $result['code'], $scenario );
			self::assertFalse( $result['successful'], $scenario );
			self::assertSame( '', $result['preview_key'], $scenario );
			self::assertSame( $writes, $transport->writeCounts, $scenario );
		}
	}

	public function testInspectRejectsAdditionalReleaseAutomationBesideAnExactCanonicalSetup(): void {
		$transport   = new D23ApplicationTransport();
		$facade      = new D23ReleaseFacade();
		$status      = $facade->status( 'plugin', 'example-plugin/example-plugin.php' );
		$established = $this->coordinator( $facade, $transport, new SetupRecordStore() );
		$preview     = $established->inspect( $status, 'stable', $this->readyPreflight(), 'token' );
		self::assertSame( 'workflow_setup_open', $established->setup( $status, $preview['preview_key'], 'owner/example-plugin', $this->readyPreflight(), 'token' )['code'] );
		$transport->mergePull();
		$transport->mutateDefaultDocument( '.github/workflows/publish-release.yml', "steps:\n  - uses: softprops/action-gh-release@v2\n" );
		$writes = $transport->writeCounts;
		$GLOBALS['ran_booster_release_deployments_test_options'] = array();

		$result = $this->coordinator( $facade, $transport, new SetupRecordStore() )->inspect( $status, 'stable', $this->readyPreflight(), 'token' );

		self::assertSame( 'workflow_release_automation_conflict', $result['code'] );
		self::assertFalse( $result['successful'] );
		self::assertSame( 'repository_snapshot', $result['failure_stage'] );
		self::assertSame( 'release_automation_detected', $result['diagnostic_code'] );
		self::assertSame( '', $result['preview_key'] );
		self::assertSame( $writes, $transport->writeCounts );
		self::assertSame( array(), $GLOBALS['ran_booster_release_deployments_test_options'] );
	}

	public function testHealthyPublishedReleaseCanInspectPreviewAndOpenAnExactSetupDraft(): void {
		$transport             = new D23ApplicationTransport();
		$facade                = new D23ReleaseFacade( 'release_asset' );
		$facade->preflightCode = ReleaseTrackingPreflight::READY;
		$records               = new SetupRecordStore();
		$coordinator           = $this->coordinator( $facade, $transport, $records );
		$status                = $facade->status( 'plugin', 'example-plugin/example-plugin.php' );

		$inspect = $coordinator->inspect( $status, 'stable', $this->readyPreflight(), 'token' );
		self::assertSame( 'workflow_inspected', $inspect['code'] );
		self::assertNotNull( $coordinator->preview( $inspect['preview_key'], $status ) );
		self::assertSame(
			'workflow_setup_open',
			$coordinator->setup( $status, $inspect['preview_key'], 'owner/example-plugin', $this->readyPreflight(), 'token' )['code']
		);
		self::assertSame( 17, $records->find( '101' )['pr_number'] );
	}

	public function testAvailableTemplateUpdateUsesPinnedOldAndNewIdentityAndASecondAtomicDraft(): void {
		$transport   = new D23ApplicationTransport();
		$facade      = new D23ReleaseFacade();
		$records     = new SetupRecordStore();
		$coordinator = $this->coordinator( $facade, $transport, $records );
		$status      = $facade->status( 'plugin', 'example-plugin/example-plugin.php' );
		$inspect     = $coordinator->inspect( $status, 'stable', $this->readyPreflight(), 'token' );
		self::assertSame( 'workflow_setup_open', $coordinator->setup( $status, $inspect['preview_key'], 'owner/example-plugin', $this->readyPreflight(), 'token' )['code'] );
		$transport->mergePull();
		$transport->offerTemplateUpdate();

		$available = $coordinator->inspectUpdate( $status, 'token' );
		self::assertSame( 'workflow_template_update_available', $available['code'] );
		$preview = $coordinator->preview( $available['preview_key'], $status );
		self::assertNotNull( $preview );
		self::assertSame( 'template_update', $preview['kind'] );
		self::assertSame( 'v1.2.3', $preview['old_template_identity']['release_tag'] );
		self::assertSame( 'v1.2.4', $preview['new_template_identity']['release_tag'] );
		self::assertSame( '', $preview['preflight_channel'] );

		$result = $coordinator->setupUpdate( $status, $available['preview_key'], 'owner/example-plugin', 'token' );
		self::assertSame( 'workflow_setup_open', $result['code'] );
		$record = $records->find( '101' );
		self::assertSame( 'template_update', $record['operation'] );
		self::assertSame( '1.2.4', $record['pack_version'] );
		self::assertSame( 2, $transport->writeCounts['tree'] );
		self::assertSame( 2, $transport->writeCounts['commit'] );
		self::assertSame( 2, $transport->writeCounts['ref'] );
		self::assertSame( 2, $transport->writeCounts['pull'] );
	}

	public function testUsesTheOperationTokenForEveryTemplatePackReadAcrossBootstrapAndUpdate(): void {
		$transport   = new D23ApplicationTransport();
		$facade      = new D23ReleaseFacade();
		$records     = new SetupRecordStore();
		$coordinator = $this->coordinator( $facade, $transport, $records );
		$status      = $facade->status( 'plugin', 'example-plugin/example-plugin.php' );

		$inspect = $coordinator->inspect( $status, 'stable', $this->readyPreflight(), 'operation-token' );
		self::assertSame( 'workflow_setup_open', $coordinator->setup( $status, $inspect['preview_key'], 'owner/example-plugin', $this->readyPreflight(), 'operation-token' )['code'] );
		$transport->mergePull();
		$transport->offerTemplateUpdate();
		$available = $coordinator->inspectUpdate( $status, 'operation-token' );
		self::assertSame( 'workflow_template_update_available', $available['code'] );
		self::assertSame( 'workflow_setup_open', $coordinator->setupUpdate( $status, $available['preview_key'], 'owner/example-plugin', 'operation-token' )['code'] );

		$templateRequests = array_filter(
			$transport->requests,
			static fn ( array $request ): bool => str_contains( $request['url'], '/ran-booster-release-bootstrap-templates' )
		);
		self::assertNotEmpty( $templateRequests );
		foreach ( $templateRequests as $request ) {
			self::assertSame( 'Bearer operation-token', $request['args']['headers']['Authorization'] );
		}
	}

	public function testSetupRecordFollowsExactPackageAcrossMonotonicCoreSourceRevisions(): void {
		$transport   = new D23ApplicationTransport();
		$facade      = new D23ReleaseFacade();
		$records     = new SetupRecordStore();
		$coordinator = $this->coordinator( $facade, $transport, $records );
		$status      = $facade->status( 'plugin', 'example-plugin/example-plugin.php' );
		$inspect     = $coordinator->inspect( $status, 'stable', $this->readyPreflight(), 'token' );
		self::assertSame( 'workflow_setup_open', $coordinator->setup( $status, $inspect['preview_key'], 'owner/example-plugin', $this->readyPreflight(), 'token' )['code'] );
		$transport->mergePull();

		$published = $this->statusAtRevision( $status, 4 );
		self::assertSame( 'workflow_pr_merged', $coordinator->outcome( $published, 'token' )['code'] );
		self::assertSame( 4, $records->find( '101' )['source_revision'] );
		self::assertSame( 'workflow_template_current', $coordinator->inspectUpdate( $published, 'token' )['code'] );

		$older = $this->statusAtRevision( $status, 3 );
		self::assertSame( 'workflow_invalid_request', $coordinator->outcome( $older, 'token' )['code'] );
		self::assertSame( 4, $records->find( '101' )['source_revision'] );
	}

	public function testThemeBootstrapUsesTheThemeProfileAndCompleteAtomicBundle(): void {
		$transport   = new D23ApplicationTransport( false, 'theme' );
		$facade      = new D23ReleaseFacade();
		$records     = new SetupRecordStore();
		$coordinator = $this->coordinator( $facade, $transport, $records );
		$status      = $facade->status( 'theme', 'example-theme' );
		$inspect     = $coordinator->inspect( $status, 'stable', $this->readyPreflight(), 'theme-token' );
		$preview     = $coordinator->preview( $inspect['preview_key'], $status );

		self::assertSame( 'workflow_inspected', $inspect['code'] );
		self::assertNotNull( $preview );
		self::assertSame( 'source-ready-wordpress-theme/2', $preview['profile_id'] );
		self::assertSame( 'workflow_setup_open', $coordinator->setup( $status, $inspect['preview_key'], 'owner/example-plugin', $this->readyPreflight(), 'theme-token' )['code'] );
		self::assertSame( 'theme', $records->find( '101' )['package_type'] );
		self::assertGreaterThanOrEqual( 5, $transport->writeCounts['blob'] );
	}

	public function testCompetingReleaseAutomationRefusesInspectionBeforeAnyRemoteMutation(): void {
		$transport = new D23ApplicationTransport();
		$transport->mutateDefaultDocument(
			'.github/workflows/publish.yml',
			"name: Publish\njobs:\n  release:\n    steps:\n      - uses: softprops/action-gh-release@v2\n"
		);
		$facade      = new D23ReleaseFacade();
		$coordinator = $this->coordinator( $facade, $transport, new SetupRecordStore() );
		$status      = $facade->status( 'plugin', 'example-plugin/example-plugin.php' );
		$result      = $coordinator->inspect( $status, 'stable', $this->readyPreflight(), 'token' );

		self::assertSame( 'workflow_release_automation_conflict', $result['code'] );
		self::assertFalse( $result['successful'] );
		self::assertSame( 'repository_snapshot', $result['failure_stage'] );
		self::assertSame( 'release_automation_detected', $result['diagnostic_code'] );
		self::assertSame( '', $result['preview_key'] );
		self::assertSame(
			array(
				'blob'   => 0,
				'tree'   => 0,
				'commit' => 0,
				'ref'    => 0,
				'pull'   => 0,
			),
			$transport->writeCounts
		);
		self::assertSame( array(), array_values( array_intersect( array( 'POST', 'PATCH', 'DELETE' ), array_column( $transport->requests, 'method' ) ) ) );
	}

	public function testOutcomeKeepsClosedUnmergedAndMergedDriftDistinct(): void {
		$transport   = new D23ApplicationTransport();
		$facade      = new D23ReleaseFacade();
		$records     = new SetupRecordStore();
		$coordinator = $this->coordinator( $facade, $transport, $records );
		$status      = $facade->status( 'plugin', 'example-plugin/example-plugin.php' );
		$inspect     = $coordinator->inspect( $status, 'stable', $this->readyPreflight(), 'token' );
		self::assertSame( 'workflow_setup_open', $coordinator->setup( $status, $inspect['preview_key'], 'owner/example-plugin', $this->readyPreflight(), 'token' )['code'] );

		$transport->closePull();
		self::assertSame( 'workflow_pr_closed', $coordinator->outcome( $status, 'token' )['code'] );
		$transport->reopenPull();
		$transport->driftPullBase();
		self::assertSame( 'workflow_target_changed', $coordinator->outcome( $status, 'token' )['code'] );
	}

	public function testMergedOutcomeRejectsDirtyManagedDocument(): void {
		$transport   = new D23ApplicationTransport();
		$facade      = new D23ReleaseFacade();
		$records     = new SetupRecordStore();
		$coordinator = $this->coordinator( $facade, $transport, $records );
		$status      = $facade->status( 'plugin', 'example-plugin/example-plugin.php' );
		$inspect     = $coordinator->inspect( $status, 'stable', $this->readyPreflight(), 'token' );
		self::assertSame( 'workflow_setup_open', $coordinator->setup( $status, $inspect['preview_key'], 'owner/example-plugin', $this->readyPreflight(), 'token' )['code'] );
		$transport->mergePull();
		$transport->mutateDefaultDocument( 'scripts/verify-release.sh', "#!/bin/sh\nprintf hostile\n" );

		self::assertSame( 'workflow_target_changed', $coordinator->outcome( $status, 'token' )['code'] );
	}

	public function testMergedOutcomeRejectsMissingManagedDocumentReceiptSetAndRecordIdentityDrift(): void {
		foreach ( array( 'missing_document', 'managed_set', 'identity' ) as $scenario ) {
			$GLOBALS['ran_booster_release_deployments_test_options']    = array();
			$GLOBALS['ran_booster_release_deployments_test_transients'] = array();
			$transport   = new D23ApplicationTransport();
			$facade      = new D23ReleaseFacade();
			$records     = new SetupRecordStore();
			$coordinator = $this->coordinator( $facade, $transport, $records );
			$status      = $facade->status( 'plugin', 'example-plugin/example-plugin.php' );
			$inspect     = $coordinator->inspect( $status, 'stable', $this->readyPreflight(), 'token' );
			self::assertSame( 'workflow_setup_open', $coordinator->setup( $status, $inspect['preview_key'], 'owner/example-plugin', $this->readyPreflight(), 'token' )['code'] );
			$transport->mergePull();
			if ( 'missing_document' === $scenario ) {
				$transport->removeDefaultDocument( 'scripts/build-release.sh' );
			} elseif ( 'managed_set' === $scenario ) {
				$bytes = $transport->mutateReceipt(
					static function ( array $receipt ): array {
						unset( $receipt['managed_files']['scripts/build-release.sh'] );
						return $receipt;
					}
				);
				$GLOBALS['ran_booster_release_deployments_test_options']['ran_booster_release_deployments_setup_records']['101']['receipt_digest'] = hash( 'sha256', $bytes );
			} else {
				$GLOBALS['ran_booster_release_deployments_test_options']['ran_booster_release_deployments_setup_records']['101']['template_release_id'] = 999;
			}
			self::assertSame( 'workflow_target_changed', $coordinator->outcome( $status, 'token' )['code'], $scenario );
		}
	}

	public function testFreshReadyPreflightCanContinueAfterARejectedConfirmation(): void {
		$transport   = new D23ApplicationTransport();
		$facade      = new D23ReleaseFacade();
		$coordinator = $this->coordinator( $facade, $transport, new SetupRecordStore() );
		$status      = $facade->status( 'plugin', 'example-plugin/example-plugin.php' );
		$inspect     = $coordinator->inspect( $status, 'stable', $this->readyPreflight(), 'token' );
		$writes      = $transport->writeCounts;
		self::assertSame( 'workflow_invalid_request', $coordinator->setup( $status, $inspect['preview_key'], 'owner/wrong', $this->readyPreflight(), 'token' )['code'] );
		self::assertSame( $writes, $transport->writeCounts );
		$facade->preflightCode = ReleaseTrackingPreflight::READY;
		self::assertSame( 'workflow_setup_open', $coordinator->setup( $status, $inspect['preview_key'], 'owner/example-plugin', $this->readyPreflight(), 'token' )['code'] );
		self::assertGreaterThan( $writes['pull'], $transport->writeCounts['pull'] );
	}

	public function testPreviewRejectsEveryScalarIdentityKindAndChangeDriftWithoutWrites(): void {
		$transport   = new D23ApplicationTransport();
		$facade      = new D23ReleaseFacade();
		$coordinator = $this->coordinator( $facade, $transport, new SetupRecordStore() );
		$status      = $facade->status( 'plugin', 'example-plugin/example-plugin.php' );
		$inspect     = $coordinator->inspect( $status, 'stable', $this->readyPreflight(), 'token' );
		$transient   = 'ran_booster_github_release_workflow_preview_' . $inspect['preview_key'];
		$valid       = $GLOBALS['ran_booster_release_deployments_test_transients'][ $transient ];
		$cases       = array();
		foreach ( array(
			'user_id'           => '1',
			'revision'          => '3',
			'repo_id'           => 'zero',
			'repository'        => '../bad',
			'default_branch'    => 'bad branch',
			'base_sha'          => 'HEAD',
			'profile_id'        => 'source-ready-wordpress-plugin/1',
			'pack_version'      => 'next',
			'manifest_hash'     => 'bad',
			'bundle_hash'       => 'bad',
			'changed_path_hash' => 'bad',
			'allowlist_hash'    => 'bad',
		) as $field => $value ) {
			$case           = $valid;
			$case[ $field ] = $value;
			$cases[]        = $case;
		}
		$case                          = $valid;
		$case['old_template_identity'] = array( 'asset_id' => 1 );
		$cases[]                       = $case;
		$case                          = $valid;
		$case['new_template_identity']['repository_id'] = '1';
		$cases[]                                        = $case;
		$case = $valid;
		$case['new_template_identity']['release_target'] = str_repeat( 'f', 40 );
		$cases[] = $case;
		$case    = $valid;
		$case['new_template_identity']['release_draft'] = true;
		$cases[]                                        = $case;
		$case                                        = $valid;
		$case['new_template_identity']['asset_size'] = 2097153;
		$cases[]                                     = $case;
		$case                                        = $valid;
		$case['changes'][0]['path']                  = '../unsafe';
		$cases[]                                     = $case;
		$case                                        = $valid;
		$case['changes'][1]                          = $case['changes'][0];
		$cases[]                                     = $case;
		$case                                        = $valid;
		$case['changes']                             = array_reverse( $case['changes'] );
		$cases[]                                     = $case;
		foreach ( $cases as $case ) {
			$GLOBALS['ran_booster_release_deployments_test_transients'][ $transient ] = $case;
			self::assertNull( $coordinator->preview( $inspect['preview_key'], $status ) );
		}
		self::assertSame(
			array(
				'blob'   => 0,
				'tree'   => 0,
				'commit' => 0,
				'ref'    => 0,
				'pull'   => 0,
			),
			$transport->writeCounts
		);
	}

	public function testLostRefAndPullAcknowledgementsRecoverByExactReadbackWithoutDuplicateWrites(): void {
		$transport   = new D23ApplicationTransport( true );
		$facade      = new D23ReleaseFacade();
		$records     = new SetupRecordStore();
		$coordinator = $this->coordinator( $facade, $transport, $records );
		$status      = $facade->status( 'plugin', 'example-plugin/example-plugin.php' );
		$inspect     = $coordinator->inspect( $status, 'stable', $this->readyPreflight(), 'lost-ack-token' );
		$result      = $coordinator->setup( $status, $inspect['preview_key'], 'owner/example-plugin', $this->readyPreflight(), 'lost-ack-token' );
		self::assertSame( 'workflow_setup_recovered', $result['code'] );
		self::assertSame( 1, $transport->writeCounts['ref'] );
		self::assertSame( 1, $transport->writeCounts['pull'] );
		self::assertNotNull( $records->find( '101' ) );
	}

	public function testClosedWrongBaseAndDuplicateDeterministicPullsStopBeforeObjectWrites(): void {
		foreach ( array( 'closed', 'wrong_base', 'duplicate' ) as $scenario ) {
			$GLOBALS['ran_booster_release_deployments_test_options']    = array();
			$GLOBALS['ran_booster_release_deployments_test_transients'] = array();
			$transport = new D23ApplicationTransport();
			$transport->seedPullScenario( $scenario );
			$facade      = new D23ReleaseFacade();
			$coordinator = $this->coordinator( $facade, $transport, new SetupRecordStore() );
			$status      = $facade->status( 'plugin', 'example-plugin/example-plugin.php' );
			$inspect     = $coordinator->inspect( $status, 'stable', $this->readyPreflight(), 'token' );
			$result      = $coordinator->setup( $status, $inspect['preview_key'], 'owner/example-plugin', $this->readyPreflight(), 'token' );
			self::assertSame( 'workflow_invalid_response', $result['code'], $scenario );
			self::assertSame(
				array(
					'blob'   => 0,
					'tree'   => 0,
					'commit' => 0,
					'ref'    => 0,
					'pull'   => 0,
				),
				$transport->writeCounts,
				$scenario
			);
			self::assertNull( $coordinator->preview( $inspect['preview_key'], $status ), $scenario );
		}
	}

	public function testUncertainBlobTreeAndCommitWritesConsumePreviewAndCannotReplay(): void {
		foreach ( array( 'blob', 'tree', 'commit' ) as $operation ) {
			$GLOBALS['ran_booster_release_deployments_test_options']    = array();
			$GLOBALS['ran_booster_release_deployments_test_transients'] = array();
			$transport = new D23ApplicationTransport();
			$transport->failWriteAcknowledgement( $operation );
			$facade      = new D23ReleaseFacade();
			$coordinator = $this->coordinator( $facade, $transport, new SetupRecordStore() );
			$status      = $facade->status( 'plugin', 'example-plugin/example-plugin.php' );
			$inspect     = $coordinator->inspect( $status, 'stable', $this->readyPreflight(), 'token' );
			$first       = $coordinator->setup( $status, $inspect['preview_key'], 'owner/example-plugin', $this->readyPreflight(), 'token' );
			self::assertSame( 'workflow_partial', $first['code'], $operation );
			self::assertNull( $coordinator->preview( $inspect['preview_key'], $status ), $operation );
			$counts = $transport->writeCounts;
			$retry  = $coordinator->setup( $status, $inspect['preview_key'], 'owner/example-plugin', $this->readyPreflight(), 'token' );
			self::assertSame( 'workflow_invalid_request', $retry['code'], $operation );
			self::assertSame( $counts, $transport->writeCounts, $operation );
		}
	}


	public function testAnyExistingSetupRowBlocksFreshInspectionWithoutRemoteAccess(): void {
		foreach ( array(
			'legacy'    => array(
				'repo_id'            => '101',
				'repository'         => 'owner/example-plugin',
				'package_type'       => 'plugin',
				'package_identifier' => 'example-plugin/example-plugin.php',
				'source_revision'    => 3,
				'default_branch'     => 'main',
				'setup_branch'       => 'ran-booster/release-setup-v1-aaaaaaaaaaaa-deadbeef',
				'head_sha'           => str_repeat( 'b', 40 ),
				'pr_number'          => 17,
			),
			'unknown'   => array( 'future_schema' => 3 ),
			'non_array' => 'occupied',
		) as $name => $existing ) {
			$GLOBALS['ran_booster_release_deployments_test_options']['ran_booster_release_deployments_setup_records'] = array( '101' => $existing );
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Exact raw scalar value bytes are the compatibility subject under test.
			$before      = serialize( $GLOBALS['ran_booster_release_deployments_test_options']['ran_booster_release_deployments_setup_records'] );
			$transport   = new D23ApplicationTransport();
			$facade      = new D23ReleaseFacade();
			$coordinator = $this->coordinator( $facade, $transport, new SetupRecordStore() );
			$status      = $facade->status( 'plugin', 'example-plugin/example-plugin.php' );

			self::assertSame( 'workflow_invalid_request', $coordinator->inspect( $status, 'stable', $this->readyPreflight(), 'request-only-token' )['code'], $name );
			self::assertSame( array(), $transport->requests, $name );
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Exact raw scalar value bytes are the compatibility subject under test.
			self::assertSame( $before, serialize( $GLOBALS['ran_booster_release_deployments_test_options']['ran_booster_release_deployments_setup_records'] ), $name );
		}
	}

	public function testDoesNotAdoptStandaloneBetaEightPreviewKeys(): void {
		$transport   = new D23ApplicationTransport();
		$facade      = new D23ReleaseFacade();
		$coordinator = $this->coordinator( $facade, $transport, new SetupRecordStore() );
		$status      = $facade->status( 'plugin', 'example-plugin/example-plugin.php' );
		$inspect     = $coordinator->inspect( $status, 'stable', $this->readyPreflight(), 'request-only-token' );
		$key         = $inspect['preview_key'];
		$newKey      = 'ran_booster_github_release_workflow_preview_' . $key;
		$preview     = $GLOBALS['ran_booster_release_deployments_test_transients'][ $newKey ];

		unset( $GLOBALS['ran_booster_release_deployments_test_transients'][ $newKey ] );
		$GLOBALS['ran_booster_release_deployments_test_transients'][ 'ran_booster_release_workflow_preview_' . $key ] = $preview;

		self::assertNull( $coordinator->preview( $key, $status ) );
		self::assertArrayHasKey( 'ran_booster_release_workflow_preview_' . $key, $GLOBALS['ran_booster_release_deployments_test_transients'] );
	}

	private function coordinator( D23ReleaseFacade $facade, D23ApplicationTransport $transport, SetupRecordStore $records ): WorkflowApplicationCoordinator {
		unset( $facade );
		return new WorkflowApplicationCoordinator( new GitHubRepositoryClient( $transport ), new TemplatePackRepositoryClient( $transport ), new SourceReadyAssessor(), $records );
	}

	private function readyPreflight(): ReleaseTrackingPreflight {
		return new ReleaseTrackingPreflight( ReleaseTrackingPreflight::RELEASE_UNAVAILABLE, 'example-plugin' );
	}

	private function statusAtRevision( ReleaseTrackingStatus $status, int $revision ): ReleaseTrackingStatus {
		return new ReleaseTrackingStatus(
			$status->type(),
			$status->identifier(),
			'release_asset',
			$revision,
			$status->providerRepositoryId(),
			$status->deploymentPolicy(),
			$status->eligibility(),
			$status->preflight(),
			$status->packageRoot(),
			$status->installedVersion(),
			$status->latestVersion(),
			$status->updateAvailable(),
			$status->lastCheckedAt(),
			$status->cooldownUntil(),
			$status->failureCode(),
			$status->channel()
		);
	}
}
