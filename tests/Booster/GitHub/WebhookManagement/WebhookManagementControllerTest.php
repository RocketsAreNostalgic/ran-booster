<?php

declare( strict_types = 1 );

namespace Tests\Booster\GitHub\WebhookManagement;

use PHPUnit\Framework\TestCase;
use RAN\AddOn\WebhookAssistance\AssistanceReadiness;
use RAN\AddOn\WebhookAssistance\AssistanceTarget;
use RAN\AddOn\WebhookAssistance\WebhookAssistanceFacade;
use RAN\AddOn\WebhookAssistance\WebhookProfileMetadata;
use RAN\Admin\Interaction\AdminInteractionFacade;
use RAN\Admin\Interaction\AdminInteractionOutcome;
use RAN\Admin\Interaction\AdminInteractionRequest;
use RAN\Booster\GitHub\WebhookManagement\Admin\WebhookManagementController;
use RAN\Booster\GitHub\WebhookManagement\Display\WebhookDisplayModel;
use RAN\Booster\GitHub\WebhookManagement\Installation\InstallationRecord;
use RAN\Booster\GitHub\WebhookManagement\Installation\InstallationStore;
use RAN\Booster\GitHub\WebhookManagement\Operation\WebhookOperationCoordinator;
use RAN\RepositoryProvider\RepositoryWebhookFitnessResult;
use RAN\RepositoryProvider\RepositoryWebhookOperationResult;

require_once dirname( __DIR__, 4 ) . '/tests/Support/PackageViewWordPressFunctions.php';

final class WebhookManagementControllerTest extends TestCase {
	public function testItEnrichesOnlyTheReservedCoreGitHubAction(): void {
		$store         = new OperationStoreFixture();
		$store->record = $this->record();
		$display       = $this->display( store: $store );
		$rows          = array(
			'1234' => array(
				'details' => array(
					array(
						'label' => 'Core detail',
						'value' => 'kept',
					),
				),
				'actions' => array(
					'core:manual'             => array(
						'key'   => 'core:manual',
						'label' => 'Manual',
						'url'   => 'https://example.test/manual',
					),
					'core:webhook-management' => array(
						'key'          => 'core:webhook-management',
						'label'        => 'GitHub webhook management',
						'url'          => '',
						'disabled'     => true,
						'described_by' => 'premium-reason',
					),
				),
			),
		);

		$result = $display->enrichRows(
			$rows,
			'gh',
			array( '1234' => $this->repositoryProjection() ),
			'https://site.example/wp-admin/admin.php?page=ran-booster&tab=gh'
		);

		self::assertSame( 'kept', $result['1234']['details'][0]['value'] );
		self::assertSame( $rows['1234']['actions']['core:manual'], $result['1234']['actions']['core:manual'] );
		self::assertFalse( $result['1234']['actions']['core:webhook-management']['disabled'] );
		self::assertStringContainsString( 'repository=1234', $result['1234']['actions']['core:webhook-management']['url'] );
		self::assertSame( $rows, $display->enrichRows( $rows, 'bb', array(), 'https://site.example/' ) );
	}

	public function testMalformedOrUnavailableCoreReadinessLeavesRowsInert(): void {
		$rows      = array(
			'1234' => array(
				'actions' => array(
					'core:webhook-management' => array(
						'disabled' => true,
						'url'      => '',
					),
				),
			),
		);
		$malformed = new AssistanceReadiness(
			array(),
			'https://hooks.example.test/webhook',
			array(
				array(
					'provider_code' => 'gh',
					'repository_id' => '1234',
					'eligible'      => 'yes',
				),
			)
		);
		$gateway   = new OperationGatewayFixture( $malformed, $this->target(), $this->operationResult() );
		self::assertSame( $rows, $this->display( $gateway )->enrichRows( $rows, 'gh', array( '1234' => $this->repositoryProjection() ), 'https://site.example/' ) );

		$blocked = new AssistanceReadiness(
			array( 'database_unavailable' ),
			'https://hooks.example.test/webhook',
			array(
				array(
					'provider_code' => 'gh',
					'repository_id' => '1234',
					'eligible'      => true,
				),
			)
		);
		self::assertSame( $rows, $this->display( new OperationGatewayFixture( $blocked, $this->target(), $this->operationResult() ) )->enrichRows( $rows, 'gh', array( '1234' => $this->repositoryProjection() ), 'https://site.example/' ) );

		$gateway->throwOnReadiness = true;
		self::assertSame( $rows, $this->display( $gateway )->enrichRows( $rows, 'gh', array( '1234' => $this->repositoryProjection() ), 'https://site.example/' ) );
	}

	public function testPanelRendersSavedIdentityAndRequestOnlyInputWithoutFetchingSecrets(): void {
		$gateway = $this->gateway();
		$html    = $this->renderPanel( gateway: $gateway );

		self::assertStringContainsString( 'name="booster_credential_id"', $html );
		self::assertStringContainsString( 'name="github_pat"', $html );
		self::assertStringContainsString( 'Used by Core for this fixed operation only', $html );
		self::assertStringContainsString( 'not exposed to the admin presentation layer', $html );
		self::assertStringNotContainsString( 'synthetic-request-credential', $html );
		self::assertSame( array(), $gateway->calls, 'Rendering must not assess or execute a provider operation.' );
	}

	public function testRequestOnlySetupPassesCredentialOnceAndStoresOnlySafeRecoveryHistory(): void {
		$gateway    = $this->gateway();
		$store      = new OperationStoreFixture();
		$controller = $this->controller( gateway: $gateway, store: $store );
		$redirect   = $controller->handleAdminPost( $this->request(), 'valid' );

		self::assertSame(
			array(
				array( 'setup', null, true, 'valid' ),
			),
			$gateway->calls
		);
		self::assertStringNotContainsString( 'synthetic-request-credential', serialize( $gateway->calls ) );
		self::assertCount( 1, $gateway->assessmentCalls, 'Core performs one authoritative assessment inside the fixed operation.' );
		self::assertStringNotContainsString( 'synthetic-request-credential', $redirect );
		self::assertStringContainsString( 'webhook_management_result=configured_pending_delivery', $redirect );
		self::assertSame( 'needs_verification', $store->record?->status() );
		self::assertStringNotContainsString( 'synthetic-request-credential', serialize( $store->record?->toArray() ) );
	}

	public function testSavedSetupPassesOnlyTheDisplaySafeProfileId(): void {
		$gateway = $this->gateway();
		$store   = new OperationStoreFixture();
		$this->controller( gateway: $gateway, store: $store )->handleAdminPost(
			$this->request(
				array(
					'github_pat'            => '',
					'booster_credential_id' => 'credential_1',
				)
			),
			'valid'
		);

		self::assertSame(
			array(
				array( 'setup', 'credential_1', false, 'valid' ),
			),
			$gateway->calls
		);
		self::assertCount( 1, $gateway->assessmentCalls, 'Saved credentials use the same one-assessment Core operation path.' );
		self::assertSame( 'wh_0123456789abcdef01234567', $store->record?->webhookProfileId() );
	}

	public function testPartialSetupRetainsOrphanRecoveryStateWithoutReportingSuccess(): void {
		$gateway         = $this->gateway();
		$gateway->result = $this->operationResult( 'partial', 'setup_compensation_incomplete', '77' );
		$store           = new OperationStoreFixture();
		$redirect        = $this->controller( gateway: $gateway, store: $store )->handleAdminPost( $this->request(), 'valid' );

		self::assertSame( 'orphaned', $store->record?->status() );
		self::assertStringContainsString( 'webhook_management_result=setup_compensation_incomplete', $redirect );
		self::assertStringNotContainsString( 'webhook_management_result=configured_pending_delivery', $redirect );
	}

	public function testNullHookAmbiguityPersistsTargetScopedRecoveryAndSuppressesBlindSetup(): void {
		$gateway         = $this->gateway();
		$gateway->result = $this->operationResult( 'ambiguous', 'setup_response_invalid', null );
		$store           = new OperationStoreFixture();
		$controller      = $this->controller( gateway: $gateway, store: $store );

		$redirect = $controller->handleAdminPost( $this->request(), 'valid' );

		self::assertStringContainsString( 'webhook_management_result=setup_response_invalid', $redirect );
		self::assertTrue( $store->record?->requiresHookIdentification() );
		self::assertSame( 'orphaned', $store->record?->status() );
		self::assertSame( 'wh_0123456789abcdef01234567', $store->record?->webhookProfileId() );
		self::assertSame( 1, count( $gateway->mutationCalls ) );

		$secondRedirect = $controller->handleAdminPost( $this->request(), 'valid' );
		self::assertStringContainsString( 'webhook_management_result=manual_recovery_required', $secondRedirect );
		self::assertSame( 1, count( $gateway->mutationCalls ) );

		$html = $this->renderPanel( $gateway, $store );
		self::assertStringContainsString( 'without a stable hook ID', $html );
		self::assertStringNotContainsString( 'value="setup"', $html );
	}

	public function testSetupSaveFailureFallsBackToDurableOrphanEvidence(): void {
		$gateway                      = $this->gateway();
		$store                        = new OperationStoreFixture();
		$store->saveFailuresRemaining = 1;

		$redirect = $this->controller( gateway: $gateway, store: $store )->handleAdminPost( $this->request(), 'valid' );

		self::assertSame( 2, $store->saveAttempts );
		self::assertSame( '77', $store->record?->hookId() );
		self::assertSame( 'wh_0123456789abcdef01234567', $store->record?->webhookProfileId() );
		self::assertSame( 'orphaned', $store->record?->status() );
		self::assertStringContainsString( 'webhook_management_result=orphaned', $redirect );
	}

	public function testRepeatedSetupSaveFailureReturnsBoundedRecoveryReferencesAndSuppressesRetryView(): void {
		$gateway                      = $this->gateway();
		$store                        = new OperationStoreFixture();
		$store->saveFailuresRemaining = 2;
		$controller                   = $this->controller( gateway: $gateway, store: $store );

		$redirect = $controller->handleAdminPost( $this->request(), 'valid' );

		self::assertNull( $store->record );
		self::assertStringContainsString( 'webhook_management_result=recovery_record_failed', $redirect );
		self::assertStringContainsString( 'recovery_hook=77', $redirect );
		self::assertStringContainsString( 'recovery_profile=wh_0123456789abcdef01234567', $redirect );
		self::assertStringNotContainsString( 'synthetic-request-credential', $redirect );

		parse_str( (string) parse_url( $redirect, PHP_URL_QUERY ), $_GET );
		$html = $this->renderPanel( $gateway, $store );
		self::assertStringContainsString( 'GitHub hook reference 77', $html );
		self::assertStringContainsString( 'Core signing profile wh_0123456789abcdef01234567', $html );
		self::assertStringNotContainsString( 'value="setup"', $html );
	}

	public function testCoreAuthoritativeFitnessBlockMakesNoRemoteMutationAndLeavesRecordsUnchanged(): void {
		$blocked = array(
			'insufficient' => $this->fitnessResult( suitability: 'insufficient', evidence: 'observed' ),
			'unavailable'  => $this->fitnessResult( evidence: 'assessment_unavailable' ),
			'stale'        => $this->fitnessResult( evidence: 'stale' ),
			'unsupported'  => $this->fitnessResult( support: 'unsupported' ),
		);
		foreach ( $blocked as $fitnessLabel => $fitness ) {
			foreach ( array( 'setup', 'check', 'reconfigure', 'remove' ) as $operation ) {
				$gateway          = $this->gateway();
				$gateway->fitness = $fitness;
				$store            = new OperationStoreFixture();
				if ( 'setup' !== $operation ) {
					$store->record = $this->record();
				}
				$before   = $store->record?->toArray();
				$redirect = $this->controller( gateway: $gateway, store: $store )->handleAdminPost(
					$this->request( array( 'github_webhook_management_operation' => $operation ) ),
					'valid'
				);

				self::assertSame( array(), $gateway->mutationCalls, $operation . ' must not mutate after ' . $fitnessLabel );
				self::assertSame( $before, $store->record?->toArray() );
				self::assertSame( 0, $store->saveAttempts );
				self::assertStringContainsString( 'webhook_management_result=repository_identity_unconfirmed', $redirect );
				self::assertCount( 1, $gateway->calls );
				self::assertCount( 1, $gateway->assessmentCalls );
				self::assertSame( $operation, $gateway->assessmentCalls[0][0] );
				self::assertSame( array( $gateway->calls[0] ), $gateway->assessmentCalls );
			}
		}
	}

	public function testConcurrentSetupCannotOverwriteARecordThatChangedAfterCoreExecutionStarted(): void {
		$gateway                       = $this->gateway();
		$gateway->result               = $this->operationResult( 'ambiguous', 'setup_response_invalid', null );
		$store                         = new OperationStoreFixture();
		$current                       = $this->record();
		$store->beforeConditionalWrite = static function ( OperationStoreFixture $interleaved ) use ( $current ): void {
			$interleaved->record = $current;
		};

		$redirect = $this->controller( gateway: $gateway, store: $store )->handleAdminPost( $this->request(), 'valid' );

		self::assertSame( $current->toArray(), $store->record?->toArray() );
		self::assertFalse( $store->record?->requiresHookIdentification() );
		self::assertSame( 1, $store->saveAttempts );
		self::assertStringContainsString( 'webhook_management_result=record_conflict', $redirect );
		self::assertStringContainsString( 'recovery_hook=recovery%3Ahook-identity-unavailable', $redirect );
		self::assertStringContainsString( 'recovery_profile=wh_0123456789abcdef01234567', $redirect );
	}

	public function testItRejectsMissingMixedOrUnauthorizedCredentialsBeforeCoreExecution(): void {
		foreach ( array(
			array(
				'github_pat'            => '',
				'booster_credential_id' => '',
			),
			array(
				'github_pat'            => 'one-request',
				'booster_credential_id' => 'credential_1',
			),
		) as $changes ) {
			$gateway  = $this->gateway();
			$redirect = $this->controller( gateway: $gateway )->handleAdminPost( $this->request( $changes ), 'valid' );

			self::assertSame( array(), $gateway->calls );
			self::assertStringContainsString( 'webhook_management_result=invalid_token', $redirect );
		}

		$gateway = $this->gateway();
		$this->controller( gateway: $gateway )->handleAdminPost( $this->request(), 'wrong' );
		self::assertSame( array(), $gateway->calls );
	}

	public function testCheckRecordsConfigurationWithoutClaimingSignedDelivery(): void {
		$gateway         = $this->gateway();
		$gateway->result = $this->operationResult( 'succeeded', 'configured_pending_delivery', '77' );
		$store           = new OperationStoreFixture();
		$store->record   = $this->record( status: 'needs_verification' );
		$redirect        = $this->controller( gateway: $gateway, store: $store )->handleAdminPost(
			$this->request( array( 'github_webhook_management_operation' => 'check' ) ),
			'valid'
		);

		self::assertSame( array( array( 'check', null, true, '77', 'wh_0123456789abcdef01234567', 1, 'valid' ) ), $gateway->mutationCalls );
		self::assertSame( 'needs_verification', $store->record?->status() );
		self::assertStringContainsString( 'webhook_management_result=configured_pending_delivery', $redirect );
	}

	public function testAmbiguousRemovalRetainsRecoveryEvidenceAndNeverRetries(): void {
		$gateway         = $this->gateway();
		$gateway->result = $this->operationResult( 'ambiguous', 'remove_outcome_unknown', '77' );
		$store           = new OperationStoreFixture();
		$store->record   = $this->record();

		$controller = $this->controller( gateway: $gateway, store: $store );
		$redirect   = $controller->handleAdminPost(
			$this->request( array( 'github_webhook_management_operation' => 'remove' ) ),
			'valid'
		);

		self::assertSame( array( array( 'remove', null, true, '77', 'wh_0123456789abcdef01234567', 1, 'valid' ) ), $gateway->mutationCalls );
		self::assertSame( 'removal_pending', $store->record?->status() );
		self::assertStringContainsString( 'webhook_management_result=remove_outcome_unknown', $redirect );

		parse_str( (string) parse_url( $redirect, PHP_URL_QUERY ), $_GET );
		$html = $this->renderPanel( $gateway, $store );

		self::assertStringContainsString( 'could not confirm whether the remote hook was removed', $html );
		self::assertStringContainsString( 'value="check"', $html );
		self::assertStringNotContainsString( 'value="remove"', $html );
	}

	public function testConfirmedAbsenceDeletesOnlyTheLocalRecoveryRecord(): void {
		$gateway         = $this->gateway();
		$gateway->result = $this->operationResult( 'succeeded', 'absent', '77', false );
		$store           = new OperationStoreFixture();
		$store->record   = $this->record();

		$redirect = $this->controller( gateway: $gateway, store: $store )->handleAdminPost(
			$this->request( array( 'github_webhook_management_operation' => 'remove' ) ),
			'valid'
		);

		self::assertNull( $store->record );
		self::assertStringContainsString( 'webhook_management_result=removed', $redirect );
	}

	public function testConfirmedAbsenceCannotDeleteARecordChangedWhileCoreWasRunning(): void {
		$gateway                       = $this->gateway();
		$gateway->result               = $this->operationResult( 'succeeded', 'absent', '77', false );
		$store                         = new OperationStoreFixture();
		$store->record                 = $this->record();
		$current                       = $this->record( status: 'profile_revision_stale' );
		$store->beforeConditionalWrite = static function ( OperationStoreFixture $interleaved ) use ( $current ): void {
			$interleaved->record = $current;
		};

		$redirect = $this->controller( gateway: $gateway, store: $store )->handleAdminPost(
			$this->request( array( 'github_webhook_management_operation' => 'remove' ) ),
			'valid'
		);

		self::assertSame( $current->toArray(), $store->record?->toArray() );
		self::assertStringContainsString( 'webhook_management_result=record_conflict', $redirect );
	}

	public function testFailedReconfigureWithAuthoritativeAbsenceRetainsRemoteMissingHistory(): void {
		$gateway         = $this->gateway();
		$gateway->result = $this->operationResult( 'failed', 'hook_absent', '77' );
		$store           = new OperationStoreFixture();
		$store->record   = $this->record();

		$redirect = $this->controller( gateway: $gateway, store: $store )->handleAdminPost(
			$this->request( array( 'github_webhook_management_operation' => 'reconfigure' ) ),
			'valid'
		);

		self::assertSame( 'remote_missing', $store->record?->status() );
		self::assertStringContainsString( 'webhook_management_result=remote_missing', $redirect );
	}

	public function testAmbiguousReconfigureRequiresCheckBeforeAnotherUpdate(): void {
		$gateway         = $this->gateway();
		$gateway->result = $this->operationResult( 'ambiguous', 'reconfigure_readback_unavailable', '77' );
		$store           = new OperationStoreFixture();
		$store->record   = $this->record( endpoint: 'https://hooks.example.test/previous' );
		$interaction     = new CapturingAdminInteractionFacade();
		$controller      = $this->controller( gateway: $gateway, store: $store, adminInteraction: $interaction );

		$redirect = $controller->handleAdminPost(
			$this->request( array( 'github_webhook_management_operation' => 'reconfigure' ) ),
			'valid'
		);

		self::assertSame( 'needs_verification', $store->record?->status() );
		self::assertSame( 'https://hooks.example.test/previous', $store->record?->endpoint() );
		self::assertNull( $interaction->outcome, 'Uncertain mutations must retain the refresh path so the persisted state is rendered.' );
		self::assertStringContainsString( 'webhook_management_result=reconfigure_readback_unavailable', $redirect );

		parse_str( (string) parse_url( $redirect, PHP_URL_QUERY ), $_GET );
		$html = $this->renderPanel( $gateway, $store );

		self::assertStringContainsString( 'notice notice-error inline ran-booster-github-webhook-management__notice', $html );
		self::assertStringContainsString( 'Run Check or inspect the hook in GitHub before retrying an update', $html );
		self::assertStringContainsString( 'value="check"', $html );
		self::assertStringNotContainsString( 'value="reconfigure"', $html );
	}

	public function testAuthoritativeCheckMismatchPersistsDriftAndOffersReconfigure(): void {
		$gateway         = $this->gateway();
		$gateway->result = $this->operationResult(
			'succeeded',
			'configuration_drift',
			'77',
			true,
			array(
				'endpoint'     => 'mismatched',
				'events'       => 'matched',
				'content_type' => 'matched',
				'active'       => 'matched',
			)
		);
		$store           = new OperationStoreFixture();
		$store->record   = $this->record( status: 'needs_verification' );
		$controller      = $this->controller( gateway: $gateway, store: $store );

		$redirect = $controller->handleAdminPost(
			$this->request( array( 'github_webhook_management_operation' => 'check' ) ),
			'valid'
		);

		self::assertSame( 'configuration_drift', $store->record?->status() );
		self::assertStringContainsString( 'webhook_management_result=configuration_drift', $redirect );

		$html = $this->renderPanel( $gateway, $store );

		self::assertStringContainsString( 'value="reconfigure"', $html );
		self::assertStringContainsString( 'value="check"', $html );
	}

	public function testPartialReconfigureRequiresCheckBeforeAnotherUpdate(): void {
		$gateway         = $this->gateway();
		$gateway->result = $this->operationResult( 'partial', 'operation_lock_release_failed', '77' );
		$store           = new OperationStoreFixture();
		$store->record   = $this->record();
		$controller      = $this->controller( gateway: $gateway, store: $store );

		$redirect = $controller->handleAdminPost(
			$this->request( array( 'github_webhook_management_operation' => 'reconfigure' ) ),
			'valid'
		);

		self::assertSame( 'needs_verification', $store->record?->status() );
		self::assertStringContainsString( 'webhook_management_result=operation_lock_release_failed', $redirect );

		parse_str( (string) parse_url( $redirect, PHP_URL_QUERY ), $_GET );
		$html = $this->renderPanel( $gateway, $store );

		self::assertStringContainsString( 'then run Check before retrying', $html );
		self::assertStringContainsString( 'value="check"', $html );
		self::assertStringNotContainsString( 'value="reconfigure"', $html );
	}

	public function testVerifiedResultCopyDoesNotClaimThatCheckProvedSignedDelivery(): void {
		$_GET = array( 'webhook_management_result' => 'verified' );
		$html = $this->renderPanel();

		self::assertStringContainsString( 'Provider request ID in Booster Activity', $html );
		self::assertStringNotContainsString( 'confirmed the recorded remote configuration and signed delivery state', $html );
	}

	public function testFailedSetupUsesTheSharedInlineFailureResponse(): void {
		$gateway         = $this->gateway();
		$gateway->result = $this->operationResult( 'failed', 'setup_failed', null, false );
		$interaction     = new CapturingAdminInteractionFacade();

		try {
			$this->controller( gateway: $gateway, adminInteraction: $interaction )->handleAdminPost( $this->request(), 'valid' );
			self::fail( 'The shared administration interaction must terminate after responding.' );
		} catch ( AdminInteractionResponded ) {
			self::assertInstanceOf( AdminInteractionOutcome::class, $interaction->outcome );
		}

		self::assertSame( AdminInteractionOutcome::VALIDATION_FAILURE, $interaction->outcome?->kind() );
		self::assertSame( 422, $interaction->outcome?->status() );
		self::assertStringContainsString( 'No remote hook was established', $interaction->outcome?->message() ?? '' );
	}

	public function testAmbiguousSetupKeepsTheRefreshPathForRecoveryState(): void {
		$gateway         = $this->gateway();
		$gateway->result = $this->operationResult( 'ambiguous', 'setup_response_invalid', null );
		$interaction     = new CapturingAdminInteractionFacade();

		$redirect = $this->controller( gateway: $gateway, adminInteraction: $interaction )->handleAdminPost( $this->request(), 'valid' );

		self::assertNull( $interaction->outcome );
		self::assertStringContainsString( 'webhook_management_result=setup_response_invalid', $redirect );
	}

	public function testFailedResultRendersAsAnExplicitErrorNotice(): void {
		$_GET = array( 'webhook_management_result' => 'setup_failed' );
		$html = $this->renderPanel();

		self::assertStringContainsString( 'notice notice-error inline ran-booster-github-webhook-management__notice', $html );
		self::assertStringContainsString( 'No remote hook was established', $html );
		self::assertStringNotContainsString( 'GitHub webhook management completed the request', $html );
	}

	protected function tearDown(): void {
		$_GET = array();
	}

	/** @param array<string, mixed> $changes @return array<string, mixed> */
	private function request( array $changes = array() ): array {
		return array_merge(
			array(
				'github_webhook_management_operation' => 'setup',
				'provider_code'                       => 'gh',
				'repository_id'                       => '1234',
				'github_pat'                          => 'synthetic-request-credential',
			),
			$changes
		);
	}

	/** @return array<string, mixed> */
	private function repositoryProjection(): array {
		return array(
			'provider_code'         => 'gh',
			'repository_id'         => '1234',
			'repository'            => 'owner/repository',
			'label'                 => 'Repository',
			'package_references'    => array( 'plugin/example.php' ),
			'deployment_policies'   => array(
				'automatic' => 1,
				'manual'    => 0,
				'disabled'  => 0,
			),
			'endpoint'              => 'https://hooks.example.test/webhook',
			'eligible'              => true,
			'status'                => 'ready',
			'reason_codes'          => array(),
			'local_secret_coverage' => 'repository',
		);
	}

	private function readiness(): AssistanceReadiness {
		$projection = $this->repositoryProjection();
		$repository = array(
			'provider_code'         => $projection['provider_code'],
			'repository_id'         => $projection['repository_id'],
			'repository'            => $projection['repository'],
			'label'                 => $projection['label'],
			'package_references'    => $projection['package_references'],
			'deployment_policies'   => $projection['deployment_policies'],
			'status'                => $projection['status'],
			'reason_codes'          => $projection['reason_codes'],
			'local_secret_coverage' => $projection['local_secret_coverage'],
			'eligible'              => $projection['eligible'],
		);
		return new AssistanceReadiness( array(), 'https://hooks.example.test/webhook', array( $repository ) );
	}

	private function target(): AssistanceTarget {
		return new AssistanceTarget(
			'gh',
			'1234',
			'owner/repository',
			'Repository',
			array( 'plugin/example.php' ),
			array(
				'automatic' => 1,
				'manual'    => 0,
				'disabled'  => 0,
			),
			'https://hooks.example.test/webhook'
		);
	}

	private function record( string $status = 'configured', string $endpoint = 'https://hooks.example.test/webhook' ): InstallationRecord {
		return new InstallationRecord( 'gh', '1234', 'owner/repository', '77', 'wh_0123456789abcdef01234567', 'repository', 1, 'created', $endpoint, $status, '2026-07-23T16:00:00Z', '2026-07-23T17:00:00Z' );
	}

	/** @param array<string, string>|null $configuration */
	private function operationResult( string $state = 'succeeded', string $code = 'configured_pending_delivery', ?string $hookId = '77', bool $withProfile = true, ?array $configuration = null ): RepositoryWebhookOperationResult {
		$delivery = match ( $code ) {
			'verified' => 'verified',
			'absent', 'hook_absent' => 'absent',
			default => 'succeeded' === $state ? 'configured_pending_delivery' : 'unknown',
		};

		return new RepositoryWebhookOperationResult(
			$state,
			$code,
			'2026-08-02T20:00:00Z',
			$hookId,
			$configuration ?? array(
				'endpoint'     => 'matched',
				'events'       => 'matched',
				'content_type' => 'matched',
				'active'       => 'matched',
			),
			$delivery,
			'Review the bounded operation result.',
			$withProfile ? new WebhookProfileMetadata( 'wh_0123456789abcdef01234567', 'gh', 'repository', 'owner/repository', '1234', 1, 'created', 'file', false ) : null
		);
	}

	private function fitnessResult(
		string $support = 'supported',
		string $suitability = 'unknown',
		string $evidence = 'unknown_by_design'
	): RepositoryWebhookFitnessResult {
		return new RepositoryWebhookFitnessResult( $support, $suitability, 'unknown', $evidence, 'fitness_result', '2026-08-02T20:00:00Z', 'Review the bounded assessment.' );
	}

	private function gateway(): OperationGatewayFixture {
		return new OperationGatewayFixture( $this->readiness(), $this->target(), $this->operationResult() );
	}

	private function display( ?OperationGatewayFixture $gateway = null, ?OperationStoreFixture $store = null ): WebhookDisplayModel {
		return new WebhookDisplayModel( $gateway ?? $this->gateway(), $store ?? new OperationStoreFixture() );
	}

	private function renderPanel( ?OperationGatewayFixture $gateway = null, ?OperationStoreFixture $store = null ): string {
		$gateway  ??= $this->gateway();
		$store    ??= new OperationStoreFixture();
		$display    = $this->display( $gateway, $store );
		$controller = $this->controller( $gateway, $store );
		$context    = $controller->panelContext();
		$model      = $display->panel( 'gh', '1234', 'https://site.example/wp-admin/admin.php?page=ran-booster&tab=gh', $context['result'], $context['recovery'], true );
		self::assertIsArray( $model );
		$formAttributes = '';
		ob_start();
		require dirname( __DIR__, 4 ) . '/RAN/Booster/GitHub/WebhookManagement/views/panel.php';

		return (string) ob_get_clean();
	}

	private function controller( ?OperationGatewayFixture $gateway = null, ?OperationStoreFixture $store = null, ?AdminInteractionFacade $adminInteraction = null ): WebhookManagementController {
		$gateway  ??= $this->gateway();
		$store    ??= new OperationStoreFixture();
		$controller = new WebhookManagementController(
			new WebhookOperationCoordinator( $gateway, $store ),
			$this->display( $gateway, $store ),
			static fn (): bool => true,
			static fn ( string $nonce, string $action ): bool => 'valid' === $nonce && in_array(
				$action,
				array(
					'ran_booster_repository_webhook_setup_gh_1234',
					'ran_booster_repository_webhook_check_gh_1234',
					'ran_booster_repository_webhook_reconfigure_gh_1234',
					'ran_booster_repository_webhook_remove_gh_1234',
				),
				true
			)
		);
		if ( null !== $adminInteraction ) {
			$controller->useAdminInteractionFacade( $adminInteraction );
		}

		return $controller;
	}
}

final class OperationStoreFixture implements InstallationStore {
	public ?InstallationRecord $record = null;
	public int $saveAttempts           = 0;
	public int $saveFailuresRemaining  = 0;
	/** @var (\Closure(self): void)|null */
	public ?\Closure $beforeConditionalWrite = null;

	public function all(): array {
		return null === $this->record ? array() : array( $this->record->storageKey() => $this->record );
	}

	public function find( string $providerCode, string $repositoryId ): ?InstallationRecord {
		return null !== $this->record && hash_equals( $providerCode, $this->record->providerCode() ) && hash_equals( $repositoryId, $this->record->repositoryId() )
			? $this->record
			: null;
	}

	public function saveIfCurrent( InstallationRecord $record, ?InstallationRecord $expected ): string {
		++$this->saveAttempts;
		if ( null !== $this->beforeConditionalWrite ) {
			$interleave                   = $this->beforeConditionalWrite;
			$this->beforeConditionalWrite = null;
			$interleave( $this );
		}
		if ( $this->same( $this->record, $record ) ) {
			return self::WRITE_UNCHANGED;
		}
		if ( ! $this->same( $this->record, $expected ) ) {
			return self::WRITE_CONFLICT;
		}
		if ( 0 < $this->saveFailuresRemaining ) {
			--$this->saveFailuresRemaining;

			return self::WRITE_FAILED;
		}
		$this->record = $record;

		return self::WRITE_APPLIED;
	}

	public function deleteIfCurrent( string $providerCode, string $repositoryId, ?InstallationRecord $expected ): string {
		unset( $providerCode, $repositoryId );
		++$this->saveAttempts;
		if ( null !== $this->beforeConditionalWrite ) {
			$interleave                   = $this->beforeConditionalWrite;
			$this->beforeConditionalWrite = null;
			$interleave( $this );
		}
		if ( ! $this->same( $this->record, $expected ) ) {
			return self::WRITE_CONFLICT;
		}
		$this->record = null;

		return self::WRITE_APPLIED;
	}

	private function same( ?InstallationRecord $left, ?InstallationRecord $right ): bool {
		return null === $left || null === $right
			? $left === $right
			: $left->toArray() === $right->toArray();
	}
}

final class OperationGatewayFixture implements WebhookAssistanceFacade {
	/** @var list<array<mixed>> */
	public array $calls = array();
	/** @var list<array<mixed>> */
	public array $assessmentCalls = array();
	/** @var list<array<mixed>> */
	public array $mutationCalls = array();
	public RepositoryWebhookFitnessResult $fitness;
	public bool $throwOnReadiness = false;

	public function __construct(
		private readonly AssistanceReadiness $readinessResult,
		private readonly AssistanceTarget $targetResult,
		public RepositoryWebhookOperationResult $result
	) {
		$this->fitness = $this->fitnessResult();
	}

	public function readiness( string $providerCode ): AssistanceReadiness {
		unset( $providerCode );
		if ( $this->throwOnReadiness ) {
			throw new \RuntimeException( 'Readiness unavailable.' );
		}

		return $this->readinessResult;
	}

	public function target( string $providerCode, string $repositoryId ): ?AssistanceTarget {
		return 'gh' === $providerCode && hash_equals( $repositoryId, $this->targetResult->repositoryId() ) ? $this->targetResult : null;
	}

	public function credentialChoices( string $providerCode ): array {
		return 'gh' === $providerCode ? array(
			array(
				'id'         => 'credential_1',
				'label'      => 'Temporary',
				'kind'       => 'fine-grained',
				'destroy_on' => null,
			),
		) : array();
	}

	public function profile( string $providerCode, string $repositoryId, string $profileId ): ?WebhookProfileMetadata {
		unset( $providerCode, $repositoryId );

		return 'wh_0123456789abcdef01234567' === $profileId
			? new WebhookProfileMetadata( 'wh_0123456789abcdef01234567', 'gh', 'repository', 'owner/repository', '1234', 1, 'created', 'file', false )
			: null;
	}

	public function assessSetup( AssistanceTarget $target, ?string $credentialProfileId, string $nonce, #[\SensitiveParameter] ?string $requestCredential = null ): RepositoryWebhookFitnessResult {
		unset( $target );
		$call                    = array( 'assessSetup', $credentialProfileId, null !== $requestCredential, $nonce );
		$this->calls[]           = $call;
		$this->assessmentCalls[] = $call;

		return $this->fitness;
	}

	public function assessCheck( AssistanceTarget $target, ?string $credentialProfileId, string $hookId, string $webhookProfileId, int $profileRevision, string $nonce, #[\SensitiveParameter] ?string $requestCredential = null ): RepositoryWebhookFitnessResult {
		unset( $target );
		$call                    = array( 'assessCheck', $credentialProfileId, null !== $requestCredential, $hookId, $webhookProfileId, $profileRevision, $nonce );
		$this->calls[]           = $call;
		$this->assessmentCalls[] = $call;

		return $this->fitness;
	}

	public function assessReconfigure( AssistanceTarget $target, ?string $credentialProfileId, string $hookId, string $webhookProfileId, int $profileRevision, string $nonce, #[\SensitiveParameter] ?string $requestCredential = null ): RepositoryWebhookFitnessResult {
		unset( $target );
		$call                    = array( 'assessReconfigure', $credentialProfileId, null !== $requestCredential, $hookId, $webhookProfileId, $profileRevision, $nonce );
		$this->calls[]           = $call;
		$this->assessmentCalls[] = $call;

		return $this->fitness;
	}

	public function assessRemove( AssistanceTarget $target, ?string $credentialProfileId, string $hookId, string $webhookProfileId, int $profileRevision, string $nonce, #[\SensitiveParameter] ?string $requestCredential = null ): RepositoryWebhookFitnessResult {
		unset( $target );
		$call                    = array( 'assessRemove', $credentialProfileId, null !== $requestCredential, $hookId, $webhookProfileId, $profileRevision, $nonce );
		$this->calls[]           = $call;
		$this->assessmentCalls[] = $call;

		return $this->fitness;
	}

	public function setup( AssistanceTarget $target, ?string $credentialProfileId, string $nonce, #[\SensitiveParameter] ?string $requestCredential = null ): RepositoryWebhookOperationResult {
		unset( $target );
		$call          = array( 'setup', $credentialProfileId, null !== $requestCredential, $nonce );
		$this->calls[] = $call;

		return $this->authoritativeOperation( $call );
	}

	public function check( AssistanceTarget $target, ?string $credentialProfileId, string $hookId, string $webhookProfileId, int $profileRevision, string $nonce, #[\SensitiveParameter] ?string $requestCredential = null ): RepositoryWebhookOperationResult {
		unset( $target );
		$call          = array( 'check', $credentialProfileId, null !== $requestCredential, $hookId, $webhookProfileId, $profileRevision, $nonce );
		$this->calls[] = $call;

		return $this->authoritativeOperation( $call );
	}

	public function reconfigure( AssistanceTarget $target, ?string $credentialProfileId, string $hookId, string $webhookProfileId, int $profileRevision, string $nonce, #[\SensitiveParameter] ?string $requestCredential = null ): RepositoryWebhookOperationResult {
		unset( $target );
		$call          = array( 'reconfigure', $credentialProfileId, null !== $requestCredential, $hookId, $webhookProfileId, $profileRevision, $nonce );
		$this->calls[] = $call;

		return $this->authoritativeOperation( $call );
	}

	public function remove( AssistanceTarget $target, ?string $credentialProfileId, string $hookId, string $webhookProfileId, int $profileRevision, string $nonce, #[\SensitiveParameter] ?string $requestCredential = null ): RepositoryWebhookOperationResult {
		unset( $target );
		$call          = array( 'remove', $credentialProfileId, null !== $requestCredential, $hookId, $webhookProfileId, $profileRevision, $nonce );
		$this->calls[] = $call;

		return $this->authoritativeOperation( $call );
	}

	/** @param array<mixed> $call */
	private function authoritativeOperation( array $call ): RepositoryWebhookOperationResult {
		$this->assessmentCalls[] = $call;
		$projection              = $this->fitness->toArray();
		if ( 'supported' !== $projection['support']
			|| ! in_array( $projection['suitability'], array( 'suitable', 'unknown' ), true )
			|| ! in_array( $projection['evidence'], array( 'observed', 'inferred', 'unknown_by_design' ), true ) ) {
			return new RepositoryWebhookOperationResult(
				'failed',
				'repository_identity_unconfirmed',
				'2026-08-02T20:00:00Z',
				null,
				array(
					'endpoint'     => 'unknown',
					'events'       => 'unknown',
					'content_type' => 'unknown',
					'active'       => 'unknown',
				),
				'unknown',
				'Review the current target, profile, credential and provider capability.'
			);
		}
		$this->mutationCalls[] = $call;

		return $this->result;
	}

	private function fitnessResult(): RepositoryWebhookFitnessResult {
		return new RepositoryWebhookFitnessResult( 'supported', 'unknown', 'unknown', 'unknown_by_design', 'fitness_unknown', '2026-08-02T20:00:00Z', 'Review the bounded assessment.' );
	}
}

final class AdminInteractionResponded extends \RuntimeException {
}

final class CapturingAdminInteractionFacade implements AdminInteractionFacade {
	public ?AdminInteractionOutcome $outcome = null;

	public function renderFormAttributes( AdminInteractionRequest $request ): void {
		unset( $request );
	}

	public function isEnhancedRequest( AdminInteractionRequest $request ): bool {
		unset( $request );

		return true;
	}

	public function respond( AdminInteractionOutcome $outcome ): never {
		$this->outcome = $outcome;

		throw new AdminInteractionResponded();
	}
}
