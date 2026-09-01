<?php

declare( strict_types = 1 );

namespace Tests\Admin\WebhookManagement;

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- Closely coupled operation fixtures keep this focused integration suite readable.

use PHPUnit\Framework\TestCase;
use RAN\AddOn\WebhookAssistance\AssistanceReadiness;
use RAN\AddOn\WebhookAssistance\AssistanceTarget;
use RAN\AddOn\WebhookAssistance\WebhookAssistanceFacade;
use RAN\AddOn\WebhookAssistance\WebhookProfileMetadata;
use RAN\Admin\Interaction\AdminInteractionFacade;
use RAN\Admin\Interaction\AdminInteractionOutcome;
use RAN\Admin\Interaction\AdminInteractionRequest;
use RAN\Admin\ManagedPackageWebhookAuthorityResolver;
use RAN\Admin\WebhookManagement\Display\WebhookDisplayModel;
use RAN\Admin\WebhookManagement\Installation\InstallationRecord;
use RAN\Admin\WebhookManagement\Installation\InstallationStore;
use RAN\Admin\WebhookManagement\Operation\WebhookOperationCoordinator;
use RAN\Admin\WebhookManagement\WebhookManagementController;
use RAN\RepositoryProvider\ProviderRegistry;
use RAN\RepositoryProvider\RepositoryWebhookFitnessResult;
use RAN\RepositoryProvider\RepositoryWebhookOperationResult;
use RAN\Package;
use RAN\PackageSource;
use RAN\Storage\PluginRepository;
use RAN\Storage\ThemeRepository;
use Tests\Support\CompleteWebhookManagementCapabilityProvider;
use Tests\Support\FitnessOnlyWebhookManagementCapabilityProvider;

require_once dirname( __DIR__, 3 ) . '/tests/Support/PackageViewWordPressFunctions.php';
require_once dirname( __DIR__, 2 ) . '/Support/WebhookManagementCapabilityProviders.php';

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__, 2 ) . '/fixtures/wordpress/' );
}

final class WebhookManagementControllerTest extends TestCase {
	public function testItEnrichesOnlyTheReservedCoreProviderAction(): void {
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
			'GitHub',
			'https://github.com/',
			array( '1234' => $this->repositoryProjection() ),
			'https://site.example/wp-admin/admin.php?page=ran-booster&tab=gh'
		);

		self::assertSame( 'kept', $result['1234']['details'][0]['value'] );
		self::assertSame( $rows['1234']['actions']['core:manual'], $result['1234']['actions']['core:manual'] );
		self::assertFalse( $result['1234']['actions']['core:webhook-management']['disabled'] );
		self::assertStringContainsString( 'repository=1234', $result['1234']['actions']['core:webhook-management']['url'] );
		self::assertSame( $rows, $display->enrichRows( $rows, 'bb', 'Bitbucket', 'https://bitbucket.org/', array(), 'https://site.example/' ) );
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
		self::assertSame( $rows, $this->display( $gateway )->enrichRows( $rows, 'gh', 'GitHub', 'https://github.com/', array( '1234' => $this->repositoryProjection() ), 'https://site.example/' ) );

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
		self::assertSame( $rows, $this->display( new OperationGatewayFixture( $blocked, $this->target(), $this->operationResult() ) )->enrichRows( $rows, 'gh', 'GitHub', 'https://github.com/', array( '1234' => $this->repositoryProjection() ), 'https://site.example/' ) );

		$gateway->throwOnReadiness = true;
		self::assertSame( $rows, $this->display( $gateway )->enrichRows( $rows, 'gh', 'GitHub', 'https://github.com/', array( '1234' => $this->repositoryProjection() ), 'https://site.example/' ) );
	}

	public function testBrowserOnlyProfileWarningDoesNotReplaceTheRecordedHistoricalObservation(): void {
		$gateway                = $this->gateway();
		$gateway->profileAbsent = true;
		$store                  = new OperationStoreFixture();
		$store->record          = $this->record( 'needs_verification' );
		$rows                   = array(
			'1234' => array(
				'details' => array(),
				'actions' => array(),
			),
		);

		$result = $this->display( $gateway, $store )->enrichRows(
			$rows,
			'gh',
			'GitHub',
			'https://github.com/',
			array( '1234' => $this->repositoryProjection() ),
			'https://site.example/'
		);

		self::assertSame( 'Needs attention: Needs Verification at last check', $result['1234']['details'][0]['value'] );
		self::assertSame( '2026-07-23T17:00:00Z', $result['1234']['details'][4]['value'] );
		self::assertSame( 'Current local warning', $result['1234']['details'][2]['label'] );
		self::assertSame( 'Secret needs attention', $result['1234']['details'][2]['value'] );
		self::assertSame(
			array(
				'core:webhook-recorded-status',
				'core:webhook-observation',
				'core:webhook-current-warning',
				'core:webhook-recorded-profile',
				'core:webhook-last-checked',
			),
			array_column( $result['1234']['details'], 'key' )
		);
	}

	public function testUnavailableManagementHistoryOmitsProfileAndCurrentWarningDetails(): void {
		$facade = $this->createMock( WebhookAssistanceFacade::class );
		$facade->expects( self::never() )->method( 'profile' );
		$store         = new OperationStoreFixture();
		$store->record = $this->record( 'needs_verification' );
		$result        = ( new WebhookDisplayModel( $facade, $store ) )->enrichHistoricalRows(
			array(
				'1234' => array(
					'details' => array(),
					'actions' => array(),
				),
			),
			'gh',
			array( '1234' => $this->repositoryProjection() )
		);

		self::assertSame( array( 'Recorded hook status', 'Observation', 'Last checked' ), array_column( $result['1234']['details'], 'label' ) );
		self::assertSame( 'Needs attention: Needs Verification at last check', $result['1234']['details'][0]['value'] );
		self::assertSame( '2026-07-23T17:00:00Z', $result['1234']['details'][2]['value'] );
		self::assertSame(
			array(
				'core:webhook-recorded-status',
				'core:webhook-observation',
				'core:webhook-last-checked',
			),
			array_column( $result['1234']['details'], 'key' )
		);
		self::assertSame( array(), $result['1234']['actions'] );
	}

	public function testUnavailableManagementAddsLocalHistoryToReleaseRowsWithoutProviderWork(): void {
		$facade = $this->createMock( WebhookAssistanceFacade::class );
		$facade->expects( self::never() )->method( 'readiness' );
		$facade->expects( self::never() )->method( 'target' );
		$facade->expects( self::never() )->method( 'credentialChoices' );
		$facade->expects( self::never() )->method( 'profile' );
		$store         = new OperationStoreFixture();
		$store->record = $this->record( 'needs_verification' );
		$result        = ( new WebhookDisplayModel( $facade, $store ) )->enrichHistoricalRows(
			array(
				'1234' => array(
					'source_key'    => 'release_asset',
					'repository_id' => '1234',
					'details'       => array(
						array(
							'label' => 'Core detail',
							'value' => 'kept',
						),
					),
					'actions'       => array(),
				),
			),
			'gh',
			array()
		);

		self::assertSame( 'kept', $result['1234']['details'][0]['value'] );
		self::assertSame( array( 'Core detail', 'Recorded hook status', 'Observation', 'Last checked' ), array_column( $result['1234']['details'], 'label' ) );
		self::assertSame( 'Needs attention: Needs Verification at last check', $result['1234']['details'][1]['value'] );
		self::assertSame( '2026-07-23T17:00:00Z', $result['1234']['details'][3]['value'] );
		self::assertSame( array(), $result['1234']['actions'] );
	}

	public function testUnavailableManagementRowsUseOneCachedLookupForProjectionAndReleaseRowsAndPreserveWhitespaceRepositoryId(): void {
		$facade = $this->createMock( WebhookAssistanceFacade::class );
		$facade->expects( self::never() )->method( 'readiness' );
		$facade->expects( self::never() )->method( 'target' );
		$facade->expects( self::never() )->method( 'credentialChoices' );
		$facade->expects( self::never() )->method( 'profile' );
		$projectionRecord = new InstallationRecord(
			'gh',
			'projection-id',
			'owner/repository',
			'77',
			'wh_0123456789abcdef01234567',
			'repository',
			1,
			'created',
			'https://hooks.example.test/webhook',
			'configured',
			'2026-07-23T16:00:00Z',
			'2026-07-23T17:00:00Z'
		);
		$releaseRecord    = new InstallationRecord(
			'gh',
			' spaced-release-id ',
			'owner/repository',
			'78',
			'wh_89abcdef0123456789abcdef',
			'owner',
			1,
			'reused',
			'https://hooks.example.test/webhook',
			'needs_verification',
			'2026-07-23T16:00:00Z',
			'2026-07-23T17:00:00Z'
		);
		$store            = new OperationStoreFixture();
		$store->records   = array(
			$projectionRecord->storageKey() => $projectionRecord,
			$releaseRecord->storageKey()    => $releaseRecord,
		);
		$result           = ( new WebhookDisplayModel( $facade, $store ) )->enrichHistoricalRows(
			array(
				'projection-row' => array(
					'details' => array(),
					'actions' => array(),
				),
				'release-row'    => array(
					'source_key'    => 'release_asset',
					'repository_id' => ' spaced-release-id ',
					'details'       => array(),
					'actions'       => array(),
				),
			),
			'gh',
			array(
				'projection-row' => array(
					'provider_code' => 'gh',
					'repository_id' => 'projection-id',
				),
			)
		);

		self::assertSame( 1, $store->allAttempts );
		self::assertSame( 0, $store->findAttempts );
		self::assertSame( array( 'Recorded hook status', 'Observation', 'Last checked' ), array_column( $result['projection-row']['details'], 'label' ) );
		self::assertSame( 'Configured at last check', $result['projection-row']['details'][0]['value'] );
		self::assertSame( array( 'Recorded hook status', 'Observation', 'Last checked' ), array_column( $result['release-row']['details'], 'label' ) );
		self::assertSame( 'Needs attention: Needs Verification at last check', $result['release-row']['details'][0]['value'] );
		self::assertSame( array(), $result['projection-row']['actions'] );
		self::assertSame( array(), $result['release-row']['actions'] );
	}

	public function testPanelRendersSavedIdentityAndRequestOnlyInputWithoutFetchingSecrets(): void {
		$gateway = $this->gateway();
		$html    = $this->renderPanel( gateway: $gateway );

		self::assertStringContainsString( 'name="booster_credential_id"', $html );
		self::assertStringContainsString( 'name="request_credential"', $html );
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
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Test-only scalar inspection; no serialized input is consumed.
		self::assertStringNotContainsString( 'synthetic-request-credential', serialize( $gateway->calls ) );
		self::assertCount( 1, $gateway->assessmentCalls, 'Core performs one authoritative assessment inside the fixed operation.' );
		self::assertStringNotContainsString( 'synthetic-request-credential', $redirect );
		self::assertStringContainsString( 'webhook_management_result=configured_pending_delivery', $redirect );
		self::assertSame( 'needs_verification', $store->record?->status() );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Test-only scalar inspection; no serialized input is consumed.
		self::assertStringNotContainsString( 'synthetic-request-credential', serialize( $store->record?->toArray() ) );
	}

	public function testPackageInitiatedOperationReturnsToTheAllowlistedPackageSettingsRoute(): void {
		$GLOBALS['ran_booster_package_view_multisite'] = true;
		$interaction                                   = new CapturingAdminInteractionFacade();
		$redirect                                      = $this->controller( adminInteraction: $interaction )->handleAdminPost(
			$this->request(
				array(
					'return_url' => 'https://example.test/wp-admin/admin.php?page=ran-booster-plugins&package=example%2Fexample.php&source_view=branch&ran_booster_open_advanced=1&unsafe=discarded',
				)
			),
			'valid'
		);

		self::assertStringContainsString( 'page=ran-booster-plugins', $redirect );
		self::assertStringStartsWith( 'https://example.test/wp-admin/network/admin.php?', $redirect );
		self::assertStringContainsString( 'package=example%2Fexample.php', $redirect );
		self::assertStringContainsString( 'source_view=branch', $redirect );
		self::assertStringContainsString( 'ran_booster_open_advanced=1', $redirect );
		self::assertStringContainsString( 'webhook_management_result=', $redirect );
		self::assertStringNotContainsString( 'unsafe=', $redirect );
		self::assertStringNotContainsString( 'panel=repositories', $redirect );
		self::assertStringNotContainsString( '#ran-booster-', $redirect );
		self::assertNull( $interaction->outcome, 'Package settings use the ordinary redirect because the provider repository HTMX target is absent.' );
	}

	public function testPackageReturnMustBelongToTheOperatedProviderRepository(): void {
		$matching = $this->packageAuthorities(
			array(
				'example/example.php' => array( 'gh', '1234' ),
				'other/other.php'     => array( 'gh', 'other' ),
			),
			array(
				'example-theme' => array( 'gh', '1234' ),
				'other-theme'   => array( 'gh', 'other' ),
			)
		);

		$controller = $this->controller( authorities: $matching );
		$plugin     = $controller->handleAdminPost(
			$this->request( array( 'return_url' => 'https://example.test/wp-admin/admin.php?page=ran-booster-plugins&package=example%2Fexample.php' ) ),
			'valid'
		);
		$theme      = $controller->handleAdminPost(
			$this->request( array( 'return_url' => 'https://example.test/wp-admin/admin.php?page=ran-booster-themes&package=example-theme' ) ),
			'valid'
		);
		$unrelated  = $controller->handleAdminPost(
			$this->request( array( 'return_url' => 'https://example.test/wp-admin/admin.php?page=ran-booster-plugins&package=other%2Fother.php' ) ),
			'valid'
		);
		$otherTheme = $controller->handleAdminPost(
			$this->request( array( 'return_url' => 'https://example.test/wp-admin/admin.php?page=ran-booster-themes&package=other-theme' ) ),
			'valid'
		);

		self::assertStringContainsString( 'page=ran-booster-plugins', $plugin );
		self::assertStringContainsString( 'package=example%2Fexample.php', $plugin );
		self::assertStringContainsString( 'page=ran-booster-themes', $theme );
		self::assertStringContainsString( 'package=example-theme', $theme );
		self::assertStringContainsString( 'page=ran-booster', $unrelated );
		self::assertStringContainsString( 'panel=repositories', $unrelated );
		self::assertStringNotContainsString( 'other%2Fother.php', $unrelated );
		self::assertStringContainsString( 'page=ran-booster', $otherTheme );
		self::assertStringContainsString( 'panel=repositories', $otherTheme );
		self::assertStringNotContainsString( 'package=other-theme', $otherTheme );
	}

	public function testRepositoryInitiatedOperationReturnsToItsExactRepositoryRoute(): void {
		$redirect = $this->controller()->handleAdminPost(
			$this->request(
				array(
					'return_url' => 'https://example.test/wp-admin/admin.php?page=ran-booster&tab=gh&panel=repositories&repository=1234&unsafe=discarded',
				)
			),
			'valid'
		);

		self::assertStringContainsString( 'page=ran-booster', $redirect );
		self::assertStringContainsString( 'tab=gh', $redirect );
		self::assertStringContainsString( 'panel=repositories', $redirect );
		self::assertStringContainsString( 'repository=1234', $redirect );
		self::assertStringNotContainsString( 'unsafe=', $redirect );

		$fallback = $this->controller()->handleAdminPost(
			$this->request(
				array(
					'return_url' => 'https://example.test/wp-admin/admin.php?page=ran-booster&tab=gh&panel=repositories&repository=other',
				)
			),
			'valid'
		);
		self::assertStringContainsString( 'repository=1234', $fallback );
		self::assertStringNotContainsString( 'repository=other', $fallback );
	}

	public function testCompleteNonGitHubProviderUsesTheSamePlacementAndOperationPath(): void {
		$providerCode  = 'fixture-provider';
		$providerLabel = 'Fixture Forge';
		$gateway       = new OperationGatewayFixture(
			$this->readiness( $providerCode ),
			$this->target( $providerCode ),
			$this->operationResult( providerCode: $providerCode )
		);
		$store         = new OperationStoreFixture();
		$controller    = $this->controller( $gateway, $store, providerCode: $providerCode, providerLabel: $providerLabel );
		$request       = $this->request( array( 'provider_code' => $providerCode ) );
		$redirect      = $controller->handleAdminPost( $request, 'valid' );
		$display       = $this->display( $gateway, $store );
		$repositoryRow = array(
			'fixture-repository' => array(
				'details' => array(),
				'actions' => array(
					'core:webhook-management' => array(
						'key'          => 'core:webhook-management',
						'label'        => 'Manage webhook',
						'url'          => '',
						'disabled'     => true,
						'described_by' => 'unavailable',
					),
				),
			),
		);
		$projection    = array( 'fixture-repository' => $this->repositoryProjection( $providerCode ) );
		$enriched      = $display->enrichRows( $repositoryRow, $providerCode, $providerLabel, 'https://fixture-provider.example.test/', $projection, 'https://site.example/provider' );
		$model         = $display->panel( $providerCode, $providerLabel, '1234', 'https://site.example/provider', null, null, true );

		self::assertSame( array( array( 'setup', null, true, 'valid' ) ), $gateway->mutationCalls );
		self::assertSame( $providerCode, $store->record?->providerCode() );
		self::assertStringContainsString( 'tab=fixture-provider', $redirect );
		self::assertFalse( $enriched['fixture-repository']['actions']['core:webhook-management']['disabled'] );
		self::assertSame( $providerCode, $model['provider_code'] ?? null );
		self::assertSame( $providerLabel, $model['provider_label'] ?? null );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Test-only encoding of bounded display projections.
		self::assertStringNotContainsString( 'tab=gh', serialize( array( $redirect, $enriched, $model ) ) );
	}

	public function testPartialAndMissingProvidersRejectSecretBearingRequestsBeforeFacadeOrProviderWork(): void {
		foreach ( array( 'partial-provider', 'missing-provider' ) as $providerCode ) {
			$gateway    = $this->gateway( $providerCode );
			$store      = new OperationStoreFixture();
			$provider   = new FitnessOnlyWebhookManagementCapabilityProvider( 'partial-provider', 'Partial Provider' );
			$registry   = 'partial-provider' === $providerCode ? new ProviderRegistry( array( $provider ) ) : new ProviderRegistry();
			$controller = new WebhookManagementController(
				new WebhookOperationCoordinator( $gateway, $store ),
				$this->display( $gateway, $store ),
				$registry,
				$this->packageAuthorities(),
				static fn (): bool => true,
				static fn (): bool => true
			);
			$request    = $this->request(
				array(
					'provider_code'      => $providerCode,
					'request_credential' => 'secret-canary-partial-provider',
				)
			);

			$redirect = $controller->handleAdminPost( $request, 'valid' );

			self::assertSame( array(), $gateway->calls );
			self::assertSame( array(), $gateway->assessmentCalls );
			self::assertSame( array(), $gateway->mutationCalls );
			self::assertSame( 0, $provider->providerOperationCalls );
			self::assertStringContainsString( 'webhook_management_result=invalid_request', $redirect );
			self::assertStringNotContainsString( 'secret-canary-partial-provider', $redirect );
			self::assertStringNotContainsString( 'tab=gh', $redirect );
		}
	}

	public function testSavedSetupPassesOnlyTheDisplaySafeProfileId(): void {
		$gateway = $this->gateway();
		$store   = new OperationStoreFixture();
		$this->controller( gateway: $gateway, store: $store )->handleAdminPost(
			$this->request(
				array(
					'request_credential'    => '',
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

		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url,WordPress.Security.NonceVerification.Recommended -- Test parses a local redirect into display-only query state.
		parse_str( (string) parse_url( $redirect, PHP_URL_QUERY ), $_GET );
		$html = $this->renderPanel( $gateway, $store );
		self::assertStringContainsString( 'provider hook reference 77', $html );
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
					$this->request( array( 'repository_webhook_management_operation' => $operation ) ),
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
				'request_credential'    => '',
				'booster_credential_id' => '',
			),
			array(
				'request_credential'    => 'one-request',
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
			$this->request( array( 'repository_webhook_management_operation' => 'check' ) ),
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
			$this->request( array( 'repository_webhook_management_operation' => 'remove' ) ),
			'valid'
		);

		self::assertSame( array( array( 'remove', null, true, '77', 'wh_0123456789abcdef01234567', 1, 'valid' ) ), $gateway->mutationCalls );
		self::assertSame( 'removal_pending', $store->record?->status() );
		self::assertStringContainsString( 'webhook_management_result=remove_outcome_unknown', $redirect );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url,WordPress.Security.NonceVerification.Recommended -- Test parses a local redirect into display-only query state.
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
			$this->request( array( 'repository_webhook_management_operation' => 'remove' ) ),
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
			$this->request( array( 'repository_webhook_management_operation' => 'remove' ) ),
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
		$interaction     = new CapturingAdminInteractionFacade();

		$redirect = $this->controller( gateway: $gateway, store: $store, adminInteraction: $interaction )->handleAdminPost(
			$this->request( array( 'repository_webhook_management_operation' => 'reconfigure' ) ),
			'valid'
		);

		self::assertSame( 'remote_missing', $store->record?->status() );
		self::assertNull( $interaction->outcome, 'Authoritative absence must refresh the page so the persisted remote-missing state is rendered.' );
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
			$this->request( array( 'repository_webhook_management_operation' => 'reconfigure' ) ),
			'valid'
		);

		self::assertSame( 'needs_verification', $store->record?->status() );
		self::assertSame( 'https://hooks.example.test/previous', $store->record?->endpoint() );
		self::assertNull( $interaction->outcome, 'Uncertain mutations must retain the refresh path so the persisted state is rendered.' );
		self::assertStringContainsString( 'webhook_management_result=reconfigure_readback_unavailable', $redirect );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url,WordPress.Security.NonceVerification.Recommended -- Test parses a local redirect into display-only query state.
		parse_str( (string) parse_url( $redirect, PHP_URL_QUERY ), $_GET );
		$html = $this->renderPanel( $gateway, $store );

		self::assertStringContainsString( 'notice notice-error inline ran-booster-repository-webhook-management__notice', $html );
		self::assertStringContainsString( 'Run Check or inspect the hook at the provider before retrying an update', $html );
		self::assertStringContainsString( 'value="check"', $html );
		self::assertStringNotContainsString( 'value="reconfigure"', $html );
	}

	public function testAmbiguousProviderRemediationSurvivesOnlyItsSignedRedirect(): void {
		$remediation     = 'Inspect the provider audit trail before retrying this operation.';
		$gateway         = $this->gateway();
		$gateway->result = $this->operationResult( 'ambiguous', 'fixture_reconfigure_uncertain', '77', remediation: $remediation );
		$store           = new OperationStoreFixture();
		$store->record   = $this->record( endpoint: 'https://hooks.example.test/previous' );
		$controller      = $this->controller( gateway: $gateway, store: $store, adminInteraction: new CapturingAdminInteractionFacade() );

		$redirect = $controller->handleAdminPost(
			$this->request( array( 'repository_webhook_management_operation' => 'reconfigure' ) ),
			'valid'
		);

		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url,WordPress.Security.NonceVerification.Recommended -- Test parses a local signed redirect.
		parse_str( (string) parse_url( $redirect, PHP_URL_QUERY ), $_GET );
		$html = $this->renderPanel( $gateway, $store );
		self::assertStringContainsString( $remediation, $html );

		$_GET['webhook_management_remediation'] = 'Tampered provider guidance.';
		$html                                   = $this->renderPanel( $gateway, $store );
		self::assertStringNotContainsString( 'Tampered provider guidance.', $html );
		self::assertStringContainsString( 'could not confirm that the remote webhook operation succeeded', $html );
	}

	public function testPackageRemediationCarriesItsSignedProviderAndRepositoryIdentity(): void {
		$remediation     = 'Inspect the provider audit trail before retrying this operation.';
		$gateway         = $this->gateway();
		$gateway->result = $this->operationResult( 'ambiguous', 'fixture_reconfigure_uncertain', '77', remediation: $remediation );
		$store           = new OperationStoreFixture();
		$store->record   = $this->record( endpoint: 'https://hooks.example.test/previous' );
		$controller      = $this->controller( gateway: $gateway, store: $store, adminInteraction: new CapturingAdminInteractionFacade() );

		$redirect = $controller->handleAdminPost(
			$this->request(
				array(
					'repository_webhook_management_operation' => 'reconfigure',
					'return_url' => 'https://example.test/wp-admin/admin.php?page=ran-booster-plugins&package=example%2Fexample.php',
				)
			),
			'valid'
		);

		self::assertStringContainsString( 'webhook_management_provider=gh', $redirect );
		self::assertStringContainsString( 'webhook_management_repository=1234', $redirect );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url,WordPress.Security.NonceVerification.Recommended -- Test parses a local signed redirect into display-only query state.
		parse_str( (string) parse_url( $redirect, PHP_URL_QUERY ), $_GET );
		$html = $this->renderPanel( $gateway, $store );

		self::assertStringContainsString( $remediation, $html );
		$_GET['webhook_management_repository'] = 'other';
		$html                                  = $this->renderPanel( $gateway, $store );
		self::assertStringNotContainsString( $remediation, $html );
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
			$this->request( array( 'repository_webhook_management_operation' => 'check' ) ),
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
			$this->request( array( 'repository_webhook_management_operation' => 'reconfigure' ) ),
			'valid'
		);

		self::assertSame( 'needs_verification', $store->record?->status() );
		self::assertStringContainsString( 'webhook_management_result=operation_lock_release_failed', $redirect );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url,WordPress.Security.NonceVerification.Recommended -- Test parses a local redirect into display-only query state.
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

	public function testFailedStateCannotSmuggleAVerifiedSuccessCodeIntoTheInlineResponse(): void {
		$gateway         = $this->gateway();
		$gateway->result = $this->operationResult( 'failed', 'verified' );
		$store           = new OperationStoreFixture();
		$interaction     = new CapturingAdminInteractionFacade();

		try {
			$this->controller( gateway: $gateway, store: $store, adminInteraction: $interaction )->handleAdminPost( $this->request(), 'valid' );
			self::fail( 'The shared administration interaction must terminate after responding.' );
		} catch ( AdminInteractionResponded ) {
			self::assertInstanceOf( AdminInteractionOutcome::class, $interaction->outcome );
		}

		self::assertNull( $store->record );
		self::assertSame( AdminInteractionOutcome::VALIDATION_FAILURE, $interaction->outcome?->kind() );
		self::assertStringContainsString( 'could not confirm', $interaction->outcome?->message() ?? '' );
	}

	public function testAmbiguousSetupKeepsTheRefreshPathForRecoveryState(): void {
		$gateway         = $this->gateway();
		$gateway->result = $this->operationResult( 'ambiguous', 'setup_response_invalid', null );
		$interaction     = new CapturingAdminInteractionFacade();

		$redirect = $this->controller( gateway: $gateway, adminInteraction: $interaction )->handleAdminPost( $this->request(), 'valid' );

		self::assertNull( $interaction->outcome );
		self::assertStringContainsString( 'webhook_management_result=setup_response_invalid', $redirect );
	}

	public function testAmbiguousSetupFailureCodeCannotBypassThePersistedRecoveryRefresh(): void {
		$gateway         = $this->gateway();
		$gateway->result = $this->operationResult( 'ambiguous', 'setup_failed', null );
		$store           = new OperationStoreFixture();
		$interaction     = new CapturingAdminInteractionFacade();

		$redirect = $this->controller( gateway: $gateway, store: $store, adminInteraction: $interaction )->handleAdminPost( $this->request(), 'valid' );

		self::assertSame( 'orphaned', $store->record?->status() );
		self::assertNull( $interaction->outcome );
		self::assertStringContainsString( 'webhook_management_result=setup_failed', $redirect );
	}

	public function testFailedResultRendersAsAnExplicitErrorNotice(): void {
		$_GET = array( 'webhook_management_result' => 'setup_failed' );
		$html = $this->renderPanel();

		self::assertStringContainsString( 'notice notice-error inline ran-booster-repository-webhook-management__notice', $html );
		self::assertStringContainsString( 'No remote hook was established', $html );
		self::assertStringNotContainsString( 'Webhook management completed the request', $html );
	}

	public function testProviderRemediationIsBoundedBeforePresentation(): void {
		$display  = $this->display();
		$maximum  = str_repeat( 'r', 255 );
		$fallback = 'Webhook management could not confirm that the remote webhook operation succeeded. Review the recorded status before retrying.';

		self::assertSame( $maximum, $display->notice( 'fixture_provider_failed', null, $maximum ) );
		self::assertSame( $fallback, $display->notice( 'fixture_provider_failed', null, str_repeat( 'r', 256 ) ) );
		self::assertSame( $fallback, $display->notice( 'fixture_provider_failed', null, str_repeat( 'r', 512 ) ) );
	}

	protected function tearDown(): void {
		$_GET = array();
		unset( $GLOBALS['ran_booster_package_view_multisite'] );
	}

	/** @param array<string, mixed> $changes @return array<string, mixed> */
	private function request( array $changes = array() ): array {
		return array_merge(
			array(
				'repository_webhook_management_operation' => 'setup',
				'provider_code'                           => 'gh',
				'repository_id'                           => '1234',
				'request_credential'                      => 'synthetic-request-credential',
			),
			$changes
		);
	}

	/** @return array<string, mixed> */
	private function repositoryProjection( string $providerCode = 'gh' ): array {
		return array(
			'provider_code'         => $providerCode,
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

	private function readiness( string $providerCode = 'gh' ): AssistanceReadiness {
		$projection = $this->repositoryProjection( $providerCode );
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

	private function target( string $providerCode = 'gh' ): AssistanceTarget {
		return new AssistanceTarget(
			$providerCode,
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
	private function operationResult( string $state = 'succeeded', string $code = 'configured_pending_delivery', ?string $hookId = '77', bool $withProfile = true, ?array $configuration = null, string $providerCode = 'gh', string $remediation = 'Review the bounded operation result.' ): RepositoryWebhookOperationResult {
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
			$remediation,
			$withProfile ? new WebhookProfileMetadata( 'wh_0123456789abcdef01234567', $providerCode, 'repository', 'owner/repository', '1234', 1, 'created', 'file', false ) : null
		);
	}

	private function fitnessResult(
		string $support = 'supported',
		string $suitability = 'unknown',
		string $evidence = 'unknown_by_design'
	): RepositoryWebhookFitnessResult {
		return new RepositoryWebhookFitnessResult( $support, $suitability, 'unknown', $evidence, 'fitness_result', '2026-08-02T20:00:00Z', 'Review the bounded assessment.' );
	}

	private function gateway( string $providerCode = 'gh' ): OperationGatewayFixture {
		return new OperationGatewayFixture( $this->readiness( $providerCode ), $this->target( $providerCode ), $this->operationResult( providerCode: $providerCode ) );
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
		$model      = $display->panel( 'gh', 'GitHub', '1234', 'https://site.example/wp-admin/admin.php?page=ran-booster&tab=gh', $context['result'], $context['recovery'], true, $context['remediation'] );
		self::assertIsArray( $model );
		$formAttributes = '';
		ob_start();
		require dirname( __DIR__, 3 ) . '/RAN/Admin/WebhookManagement/views/panel.php';

		return (string) ob_get_clean();
	}

	private function controller( ?OperationGatewayFixture $gateway = null, ?OperationStoreFixture $store = null, ?AdminInteractionFacade $adminInteraction = null, string $providerCode = 'gh', string $providerLabel = 'GitHub', ?ManagedPackageWebhookAuthorityResolver $authorities = null ): WebhookManagementController {
		$gateway  ??= $this->gateway();
		$store    ??= new OperationStoreFixture();
		$controller = new WebhookManagementController(
			new WebhookOperationCoordinator( $gateway, $store ),
			$this->display( $gateway, $store ),
			new ProviderRegistry( array( new CompleteWebhookManagementCapabilityProvider( $providerCode, $providerLabel ) ) ),
			$authorities ?? $this->packageAuthorities( array( 'example/example.php' => array( $providerCode, '1234' ) ) ),
			static fn (): bool => true,
			static fn ( string $nonce, string $action ): bool => ( 'valid' === $nonce && in_array(
				$action,
				array(
					'ran_booster_repository_webhook_setup_' . $providerCode . '_1234',
					'ran_booster_repository_webhook_check_' . $providerCode . '_1234',
					'ran_booster_repository_webhook_reconfigure_' . $providerCode . '_1234',
					'ran_booster_repository_webhook_remove_' . $providerCode . '_1234',
				),
				true
			) ) || ( str_starts_with( $action, 'ran_booster_repository_webhook_result_' )
				&& hash_equals( hash_hmac( 'sha256', $action, 'test-result-nonce' ), $nonce ) ),
			static fn ( string $action ): string => hash_hmac( 'sha256', $action, 'test-result-nonce' )
		);
		if ( null !== $adminInteraction ) {
			$controller->useAdminInteractionFacade( $adminInteraction );
		}

		return $controller;
	}

	/**
	 * @param array<string, array{0:string,1:string}> $plugins
	 * @param array<string, array{0:string,1:string}> $themes
	 */
	private function packageAuthorities( array $plugins = array(), array $themes = array() ): ManagedPackageWebhookAuthorityResolver {
		$pluginRepository = $this->createMock( PluginRepository::class );
		$themeRepository  = $this->createMock( ThemeRepository::class );
		$pluginRepository->method( 'boosterPluginFromFile' )->willReturnCallback( fn ( mixed $identifier ): Package => $this->returnPackage( $plugins, $identifier ) );
		$themeRepository->method( 'boosterThemeFromStylesheet' )->willReturnCallback( fn ( mixed $identifier ): Package => $this->returnPackage( $themes, $identifier ) );

		return new ManagedPackageWebhookAuthorityResolver( $pluginRepository, $themeRepository );
	}

	/** @param array<string, array{0:string,1:string}> $packages */
	private function returnPackage( array $packages, mixed $identifier ): Package {
		if ( ! is_string( $identifier ) || ! isset( $packages[ $identifier ] ) ) {
			throw new \RuntimeException( 'Package return authority did not match.' );
		}
		$package = $this->createMock( Package::class );
		$package->method( 'getSource' )->willReturn( PackageSource::BRANCH );
		$package->method( 'getProviderCode' )->willReturn( $packages[ $identifier ][0] );
		$package->method( 'getProviderRepositoryId' )->willReturn( $packages[ $identifier ][1] );

		return $package;
	}
}

final class OperationStoreFixture implements InstallationStore {
	public ?InstallationRecord $record = null;
	public int $allAttempts            = 0;
	public int $findAttempts           = 0;
	/** @var array<string, InstallationRecord> */
	public array $records             = array();
	public int $saveAttempts          = 0;
	public int $saveFailuresRemaining = 0;
	/** @var (\Closure(self): void)|null */
	public ?\Closure $beforeConditionalWrite = null;

	public function all(): array {
		++$this->allAttempts;
		$records = $this->records;
		if ( null !== $this->record ) {
			$records[ $this->record->storageKey() ] = $this->record;
		}

		return $records;
	}

	public function find( string $providerCode, string $repositoryId ): ?InstallationRecord {
		++$this->findAttempts;
		$key = InstallationRecord::key( $providerCode, $repositoryId );

		if ( isset( $this->records[ $key ] ) ) {
			return $this->records[ $key ];
		}

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
	public bool $profileAbsent    = false;

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
		return hash_equals( $this->targetResult->providerCode(), $providerCode ) && hash_equals( $repositoryId, $this->targetResult->repositoryId() ) ? $this->targetResult : null;
	}

	public function credentialChoices( string $providerCode ): array {
		return hash_equals( $this->targetResult->providerCode(), $providerCode ) ? array(
			array(
				'id'         => 'credential_1',
				'label'      => 'Temporary',
				'kind'       => 'fine-grained',
				'destroy_on' => null,
			),
		) : array();
	}

	public function profile( string $providerCode, string $repositoryId, string $profileId ): ?WebhookProfileMetadata {
		if ( $this->profileAbsent ) {
			return null;
		}

		return hash_equals( $this->targetResult->providerCode(), $providerCode )
			&& hash_equals( $this->targetResult->repositoryId(), $repositoryId )
			&& 'wh_0123456789abcdef01234567' === $profileId
			? new WebhookProfileMetadata( 'wh_0123456789abcdef01234567', $providerCode, 'repository', 'owner/repository', '1234', 1, 'created', 'file', false )
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
