<?php

declare( strict_types = 1 );

namespace RAN\Tests\Admin\WebhookManagement;

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
use RAN\Tests\Support\CompleteWebhookManagementCapabilityProvider;
use RAN\Tests\Support\FitnessOnlyWebhookManagementCapabilityProvider;

require_once dirname( __DIR__, 3 ) . '/tests/Support/PackageViewWordPressFunctions.php';
require_once dirname( __DIR__, 2 ) . '/Support/WebhookManagementCapabilityProviders.php';

if ( ! defined( 'ABSPATH' ) ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Exercise the WordPress-owned constant without changing its runtime identity.
	define( 'ABSPATH', dirname( __DIR__, 2 ) . '/fixtures/wordpress/' );
}

final class WebhookManagementControllerTest extends TestCase {
	public function test_malformed_and_missing_query_globals_cannot_supply_panel_feedback(): void {
		$original = $GLOBALS['_GET'] ?? null;
		try {
			foreach ( array( null, false, 17, 'webhook_management_result=verified' ) as $query ) {
				$GLOBALS['_GET'] = $query;
				self::assertSame(
					array(
						'result'      => null,
						'recovery'    => null,
						'remediation' => null,
					),
					$this->controller()->panel_context()
				);
			}
			unset( $GLOBALS['_GET'] );
			self::assertThat(
				$this->controller()->panel_context(),
				self::identicalTo(
					array(
						'result'      => null,
						'recovery'    => null,
						'remediation' => null,
					)
				)
			);
		} finally {
			$_GET = $original;
		}
	}

	public function test_native_return_type_failure_preserves_every_operation_failure_boundary(): void {
		foreach ( array( 'setup', 'check', 'reconfigure', 'remove', 'test' ) as $operation ) {
			$gateway = $this->createMock( WebhookAssistanceFacade::class );
			$gateway->method( 'target' )->willReturn( $this->target() );
			$gateway->expects( self::once() )->method( $operation )->willReturnCallback( static fn (): string => 'invalid provider result' );
			$store         = new OperationStoreFixture();
			$store->record = 'setup' === $operation ? null : $this->record();
			$original      = $store->record;
			$result        = ( new WebhookOperationCoordinator( $gateway, $store ) )->execute( $operation, 'gh', '1234', 'credential_1', null, 'valid' );
			self::assertSame( 'operation_failed', $result['code'] );
			self::assertFalse( $result['successful'] );
			self::assertFalse( $result['inline_safe'] );
			self::assertSame( $original, $store->record );
		}
	}

	public function test_it_enriches_only_the_reserved_core_provider_action(): void {
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

		$result = $display->enrich_rows(
			$rows,
			'gh',
			'GitHub',
			'https://github.com/',
			array( '1234' => $this->repository_projection() ),
			'https://site.example/wp-admin/admin.php?page=ran-booster&tab=gh'
		);

		self::assertSame( 'kept', $result['1234']['details'][0]['value'] );
		self::assertSame( $rows['1234']['actions']['core:manual'], $result['1234']['actions']['core:manual'] );
		self::assertFalse( $result['1234']['actions']['core:webhook-management']['disabled'] );
		self::assertStringContainsString( 'repository=1234', $result['1234']['actions']['core:webhook-management']['url'] );
		self::assertSame( $rows, $display->enrich_rows( $rows, 'bb', 'Bitbucket', 'https://bitbucket.org/', array(), 'https://site.example/' ) );
	}

	public function test_malformed_or_unavailable_core_readiness_leaves_rows_inert(): void {
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
		$gateway   = new OperationGatewayFixture( $malformed, $this->target(), $this->operation_result() );
		self::assertSame( $rows, $this->display( $gateway )->enrich_rows( $rows, 'gh', 'GitHub', 'https://github.com/', array( '1234' => $this->repository_projection() ), 'https://site.example/' ) );

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
		self::assertSame( $rows, $this->display( new OperationGatewayFixture( $blocked, $this->target(), $this->operation_result() ) )->enrich_rows( $rows, 'gh', 'GitHub', 'https://github.com/', array( '1234' => $this->repository_projection() ), 'https://site.example/' ) );

		$gateway->throw_on_readiness = true;
		self::assertSame( $rows, $this->display( $gateway )->enrich_rows( $rows, 'gh', 'GitHub', 'https://github.com/', array( '1234' => $this->repository_projection() ), 'https://site.example/' ) );
	}

	public function test_browser_only_profile_warning_does_not_replace_the_recorded_historical_observation(): void {
		$gateway                 = $this->gateway();
		$gateway->profile_absent = true;
		$store                   = new OperationStoreFixture();
		$store->record           = $this->record( 'needs_verification' );
		$rows                    = array(
			'1234' => array(
				'details' => array(),
				'actions' => array(),
			),
		);

		$result = $this->display( $gateway, $store )->enrich_rows(
			$rows,
			'gh',
			'GitHub',
			'https://github.com/',
			array( '1234' => $this->repository_projection() ),
			'https://site.example/'
		);

		self::assertSame( 'Needs attention: Needs Verification at last check', $result['1234']['details'][0]['value'] );
		self::assertSame( '2026-07-23T17:00:00Z', $result['1234']['details'][5]['value'] );
		self::assertSame( 'Current local warning', $result['1234']['details'][2]['label'] );
		self::assertSame( 'Secret needs attention', $result['1234']['details'][2]['value'] );
		self::assertSame(
			array(
				'core:webhook-recorded-status',
				'core:webhook-observation',
				'core:webhook-current-warning',
				'core:webhook-management-credential',
				'core:webhook-signing-secret',
				'core:webhook-last-checked',
			),
			array_column( $result['1234']['details'], 'key' )
		);
		self::assertSame( 'Recorded signing secret profile wh_0123456789abcdef01234567 is unavailable.', $result['1234']['details'][4]['value'] );
	}

	public function test_current_repository_history_resolves_display_safe_management_and_signing_labels(): void {
		$store         = new OperationStoreFixture();
		$store->record = $this->record();
		$result        = $this->display( $this->gateway(), $store )->enrich_rows(
			array(
				'1234' => array(
					'details' => array(),
					'actions' => array(),
				),
			),
			'gh',
			'GitHub',
			'https://github.com/',
			array( '1234' => $this->repository_projection() ),
			'https://site.example/'
		);

		self::assertSame( 'Management credential', $result['1234']['details'][2]['label'] );
		self::assertSame( 'Last managed with Temporary; provider authority has not been revalidated.', $result['1234']['details'][2]['value'] );
		self::assertSame( 'Recorded signing secret', $result['1234']['details'][3]['label'] );
		self::assertSame( 'Repository signing secret; current local profile metadata is available.', $result['1234']['details'][3]['value'] );
	}

	public function test_unavailable_management_history_omits_profile_and_current_warning_details(): void {
		$facade = $this->createMock( WebhookAssistanceFacade::class );
		$facade->expects( self::never() )->method( 'profile' );
		$store         = new OperationStoreFixture();
		$store->record = $this->record( 'needs_verification' );
		$result        = ( new WebhookDisplayModel( $facade, $store ) )->enrich_historical_rows(
			array(
				'1234' => array(
					'details' => array(),
					'actions' => array(),
				),
			),
			'gh',
			array( '1234' => $this->repository_projection() )
		);

		self::assertSame( array( 'Recorded hook status', 'Observation', 'Management credential', 'Recorded signing secret', 'Last checked' ), array_column( $result['1234']['details'], 'label' ) );
		self::assertSame(
			array(
				'core:webhook-recorded-status',
				'core:webhook-observation',
				'core:webhook-management-credential',
				'core:webhook-signing-secret',
				'core:webhook-last-checked',
			),
			array_column( $result['1234']['details'], 'key' )
		);
		self::assertSame( 'Needs attention: Needs Verification at last check', $result['1234']['details'][0]['value'] );
		self::assertSame( 'Last managed with saved credential profile credential_1; current availability was not checked.', $result['1234']['details'][2]['value'] );
		self::assertSame( '2026-07-23T17:00:00Z', $result['1234']['details'][4]['value'] );
		self::assertSame( array(), $result['1234']['actions'] );
	}

	public function test_unavailable_management_adds_local_history_to_release_rows_without_provider_work(): void {
		$facade = $this->createMock( WebhookAssistanceFacade::class );
		$facade->expects( self::never() )->method( 'readiness' );
		$facade->expects( self::never() )->method( 'target' );
		$facade->expects( self::never() )->method( 'credential_choices' );
		$facade->expects( self::never() )->method( 'profile' );
		$store         = new OperationStoreFixture();
		$store->record = $this->record( 'needs_verification' );
		$result        = ( new WebhookDisplayModel( $facade, $store ) )->enrich_historical_rows(
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
		self::assertSame( array( 'Core detail', 'Recorded hook status', 'Observation', 'Management credential', 'Recorded signing secret', 'Last checked' ), array_column( $result['1234']['details'], 'label' ) );
		self::assertSame( 'Needs attention: Needs Verification at last check', $result['1234']['details'][1]['value'] );
		self::assertSame( '2026-07-23T17:00:00Z', $result['1234']['details'][5]['value'] );
		self::assertSame( array(), $result['1234']['actions'] );
	}

	public function test_unavailable_management_rows_use_one_cached_lookup_for_projection_and_release_rows_and_preserve_whitespace_repository_id(): void {
		$facade = $this->createMock( WebhookAssistanceFacade::class );
		$facade->expects( self::never() )->method( 'readiness' );
		$facade->expects( self::never() )->method( 'target' );
		$facade->expects( self::never() )->method( 'credential_choices' );
		$facade->expects( self::never() )->method( 'profile' );
		$projection_record = new InstallationRecord(
			'gh',
			'projection-id',
			'owner/repository',
			'77',
			'credential_1',
			'wh_0123456789abcdef01234567',
			'repository',
			1,
			'created',
			'https://hooks.example.test/webhook',
			'configured',
			'2026-07-23T16:00:00Z',
			'2026-07-23T17:00:00Z'
		);
		$release_record    = new InstallationRecord(
			'gh',
			' spaced-release-id ',
			'owner/repository',
			'78',
			'credential_1',
			'wh_89abcdef0123456789abcdef',
			'owner',
			1,
			'reused',
			'https://hooks.example.test/webhook',
			'needs_verification',
			'2026-07-23T16:00:00Z',
			'2026-07-23T17:00:00Z'
		);
		$store             = new OperationStoreFixture();
		$store->records    = array(
			$projection_record->storage_key() => $projection_record,
			$release_record->storage_key()    => $release_record,
		);
		$result            = ( new WebhookDisplayModel( $facade, $store ) )->enrich_historical_rows(
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

		self::assertSame( 1, $store->all_attempts );
		self::assertSame( 0, $store->find_attempts );
		self::assertSame( array( 'Recorded hook status', 'Observation', 'Management credential', 'Recorded signing secret', 'Last checked' ), array_column( $result['projection-row']['details'], 'label' ) );
		self::assertSame( 'Configured at last check', $result['projection-row']['details'][0]['value'] );
		self::assertSame( array( 'Recorded hook status', 'Observation', 'Management credential', 'Recorded signing secret', 'Last checked' ), array_column( $result['release-row']['details'], 'label' ) );
		self::assertSame( 'Needs attention: Needs Verification at last check', $result['release-row']['details'][0]['value'] );
		self::assertSame( array(), $result['projection-row']['actions'] );
		self::assertSame( array(), $result['release-row']['actions'] );
	}

	public function test_panel_renders_saved_credential_and_signing_secret_selectors_without_fetching_secrets(): void {
		$gateway = $this->gateway();
		$html    = $this->render_panel( gateway: $gateway );

		self::assertStringContainsString( 'name="booster_credential_id"', $html );
		self::assertStringContainsString( 'name="booster_credential_id" required>', $html );
		self::assertStringContainsString( 'name="webhook_profile_id"', $html );
		self::assertStringContainsString( 'name="webhook_profile_id" required>', $html );
		self::assertStringContainsString( 'Temporary (fine-grained)', $html );
		self::assertStringContainsString( '<option value="" selected disabled>Choose a signing secret</option>', $html );
		self::assertStringContainsString( '<option value="create_repository_secret">Create a repository signing secret</option>', $html );
		self::assertStringContainsString( 'Create a repository signing secret', $html );
		self::assertStringNotContainsString( 'request_credential', $html );
		self::assertStringNotContainsString( 'presentation layer', $html );
		self::assertStringNotContainsString( 'synthetic-request-credential', $html );
		self::assertSame( array(), $gateway->calls, 'Rendering must not assess or execute a provider operation.' );
	}

	public function test_existing_webhook_record_preselects_its_management_credential_and_keeps_its_recorded_signing_secret_visible(): void {
		$store         = new OperationStoreFixture();
		$store->record = $this->record();

		$html = $this->render_panel( store: $store );

		self::assertStringContainsString( 'value="check"', $html );
		self::assertStringContainsString( 'name="booster_credential_id" required>', $html );
		self::assertStringContainsString( 'value="credential_1" selected="selected"', $html );
		self::assertStringContainsString( 'name="webhook_profile_id" disabled="disabled"', $html );
		self::assertStringContainsString( 'Recorded signing secret', $html );
		self::assertStringContainsString( 'Create a repository signing secret', $html );
	}

	public function test_successful_recorded_operations_replace_only_the_management_credential_id(): void {
		$gateway       = $this->gateway();
		$store         = new OperationStoreFixture();
		$store->record = $this->record();

		$this->controller( gateway: $gateway, store: $store )->handle_admin_post(
			$this->request(
				array(
					'repository_webhook_management_operation' => 'check',
					'booster_credential_id' => 'credential_2',
				)
			),
			'valid'
		);

		self::assertSame( 'credential_2', $store->record->management_credential_id() );
		self::assertSame( 'wh_0123456789abcdef01234567', $store->record->webhook_profile_id() );

		$this->controller( gateway: $gateway, store: $store )->handle_admin_post(
			$this->request(
				array(
					'repository_webhook_management_operation' => 'reconfigure',
					'booster_credential_id' => 'credential_3',
				)
			),
			'valid'
		);

		self::assertSame( 'credential_3', $store->record->management_credential_id() );
	}

	public function test_failed_or_ambiguous_recorded_operations_do_not_replace_the_management_credential_id(): void {
		foreach ( array( array( 'failed', 'operation_failed' ), array( 'ambiguous', 'operation_failed' ) ) as [ $state, $code ] ) {
			$gateway         = $this->gateway();
			$gateway->result = $this->operation_result( $state, $code );
			$store           = new OperationStoreFixture();
			$store->record   = $this->record();

			$this->controller( gateway: $gateway, store: $store )->handle_admin_post(
				$this->request(
					array(
						'repository_webhook_management_operation' => 'check',
						'booster_credential_id' => 'credential_2',
					)
				),
				'valid'
			);

			self::assertSame( 'credential_1', $store->record->management_credential_id() );
		}
	}

	public function test_setup_uses_only_the_saved_credential_and_stores_only_safe_recovery_history(): void {
		$gateway    = $this->gateway();
		$store      = new OperationStoreFixture();
		$controller = $this->controller( gateway: $gateway, store: $store );
		$redirect   = $controller->handle_admin_post( $this->request(), 'valid' );

		self::assertSame(
			array(
				array( 'setup', 'credential_1', null, 'valid' ),
			),
			$gateway->calls
		);
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Test-only scalar inspection; no serialized input is consumed.
		self::assertStringNotContainsString( 'synthetic-request-credential', serialize( $gateway->calls ) );
		self::assertCount( 1, $gateway->assessment_calls, 'Core performs one authoritative assessment inside the fixed operation.' );
		self::assertStringNotContainsString( 'synthetic-request-credential', $redirect );
		self::assertStringContainsString( 'webhook_management_result=configured_pending_delivery', $redirect );
		self::assertSame( 'needs_verification', $store->record?->status() );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Test-only scalar inspection; no serialized input is consumed.
		self::assertStringNotContainsString( 'synthetic-request-credential', serialize( $store->record->to_array() ) );
	}

	public function test_setup_requires_an_explicit_known_signing_secret_selection(): void {
		foreach ( array( '', 'wh_ffffffffffffffffffffffff' ) as $profile_id ) {
			$gateway  = $this->gateway();
			$redirect = $this->controller( gateway: $gateway )->handle_admin_post(
				$this->request( array( 'webhook_profile_id' => $profile_id ) ),
				'valid'
			);

			self::assertSame( array(), $gateway->calls );
			self::assertStringContainsString( 'webhook_management_result=invalid_request', $redirect );
		}
	}

	public function test_package_initiated_operation_returns_to_the_allowlisted_package_settings_route(): void {
		$GLOBALS['ran_booster_package_view_multisite'] = true;
		$interaction                                   = new CapturingAdminInteractionFacade();
		$redirect                                      = $this->controller( admin_interaction: $interaction )->handle_admin_post(
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

	public function test_package_return_must_belong_to_the_operated_provider_repository(): void {
		$matching = $this->package_authorities(
			array(
				'example/example.php' => array( 'gh', '1234' ),
				'other/other.php'     => array( 'gh', 'other' ),
			),
			array(
				'example-theme' => array( 'gh', '1234' ),
				'other-theme'   => array( 'gh', 'other' ),
			)
		);

		$controller  = $this->controller( authorities: $matching );
		$plugin      = $controller->handle_admin_post(
			$this->request( array( 'return_url' => 'https://example.test/wp-admin/admin.php?page=ran-booster-plugins&package=example%2Fexample.php' ) ),
			'valid'
		);
		$theme       = $controller->handle_admin_post(
			$this->request( array( 'return_url' => 'https://example.test/wp-admin/admin.php?page=ran-booster-themes&package=example-theme' ) ),
			'valid'
		);
		$unrelated   = $controller->handle_admin_post(
			$this->request( array( 'return_url' => 'https://example.test/wp-admin/admin.php?page=ran-booster-plugins&package=other%2Fother.php' ) ),
			'valid'
		);
		$other_theme = $controller->handle_admin_post(
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
		self::assertStringContainsString( 'page=ran-booster', $other_theme );
		self::assertStringContainsString( 'panel=repositories', $other_theme );
		self::assertStringNotContainsString( 'package=other-theme', $other_theme );
	}

	public function test_repository_initiated_operation_returns_to_its_exact_repository_route(): void {
		$redirect = $this->controller()->handle_admin_post(
			$this->request(
				array(
					'return_url' => 'https://example.test/wp-admin/admin.php?page=ran-booster&tab=gh&panel=repositories&repository=1234&repository_view=branch&unsafe=discarded',
				)
			),
			'valid'
		);

		self::assertStringContainsString( 'page=ran-booster', $redirect );
		self::assertStringContainsString( 'tab=gh', $redirect );
		self::assertStringContainsString( 'panel=repositories', $redirect );
		self::assertStringContainsString( 'repository=1234', $redirect );
		self::assertStringContainsString( 'repository_view=branch', $redirect );
		self::assertStringNotContainsString( 'unsafe=', $redirect );

		$fallback = $this->controller()->handle_admin_post(
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

	public function test_webhook_management_routes_use_network_admin_on_multisite(): void {
		$GLOBALS['ran_booster_package_view_multisite'] = true;
		$display                                       = $this->display();
		$available                                     = $display->panel(
			'gh',
			'GitHub',
			'1234',
			'https://example.test/wp-admin/network/admin.php?page=ran-booster&tab=gh',
			null,
			null,
			true
		);
		$unavailable                                   = $display->unavailable_panel(
			'gh',
			'GitHub',
			'1234',
			'owner/repository',
			'https://example.test/wp-admin/network/admin.php?page=ran-booster&tab=gh',
			'Webhook operations are unavailable.'
		);

		self::assertIsArray( $available );
		foreach ( array( $available, $unavailable ) as $model ) {
			self::assertSame( 'https://example.test/wp-admin/admin-post.php', $model['form_action'] );
			self::assertStringStartsWith( 'https://example.test/wp-admin/network/admin.php?page=ran-booster&tab=gh&view=', $model['credentials_url'] );
			self::assertStringStartsWith( 'https://example.test/wp-admin/network/admin.php?page=ran-booster&tab=gh&view=', $model['secrets_url'] );
			foreach ( $model['operations'] as $operation ) {
				self::assertStringStartsWith( 'https://example.test/wp-admin/admin-post.php?action=', $operation['url'] );
			}
		}

		$redirect = $this->controller()->handle_admin_post(
			$this->request( array( 'return_url' => 'https://untrusted.example.test/admin.php?page=ran-booster&tab=gh&panel=repositories&repository=1234' ) ),
			'valid'
		);

		self::assertStringStartsWith( 'https://example.test/wp-admin/network/admin.php?page=ran-booster&tab=gh&panel=repositories&repository=1234', $redirect );
	}

	public function test_webhook_management_routes_keep_single_site_admin_paths(): void {
		$display     = $this->display();
		$unavailable = $display->unavailable_panel(
			'gh',
			'GitHub',
			'1234',
			'owner/repository',
			'https://example.test/wp-admin/admin.php?page=ran-booster&tab=gh',
			'Webhook operations are unavailable.'
		);

		self::assertSame( 'https://example.test/wp-admin/admin-post.php', $unavailable['form_action'] );
		self::assertStringStartsWith( 'https://example.test/wp-admin/admin.php?page=ran-booster&tab=gh&view=credentials', $unavailable['credentials_url'] );
		self::assertStringStartsWith( 'https://example.test/wp-admin/admin.php?page=ran-booster&tab=gh&view=secrets', $unavailable['secrets_url'] );
		foreach ( $unavailable['operations'] as $operation ) {
			self::assertStringStartsWith( 'https://example.test/wp-admin/admin-post.php?action=', $operation['url'] );
		}
	}

	public function test_complete_non_git_hub_provider_uses_the_same_placement_and_operation_path(): void {
		$provider_code  = 'fixture-provider';
		$provider_label = 'Fixture Forge';
		$gateway        = new OperationGatewayFixture(
			$this->readiness( $provider_code ),
			$this->target( $provider_code ),
			$this->operation_result( provider_code: $provider_code )
		);
		$store          = new OperationStoreFixture();
		$controller     = $this->controller( $gateway, $store, provider_code: $provider_code, provider_label: $provider_label );
		$request        = $this->request( array( 'provider_code' => $provider_code ) );
		$redirect       = $controller->handle_admin_post( $request, 'valid' );
		$display        = $this->display( $gateway, $store );
		$repository_row = array(
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
		$projection     = array( 'fixture-repository' => $this->repository_projection( $provider_code ) );
		$enriched       = $display->enrich_rows( $repository_row, $provider_code, $provider_label, 'https://fixture-provider.example.test/', $projection, 'https://site.example/provider' );
		$model          = $display->panel( $provider_code, $provider_label, '1234', 'https://site.example/provider', null, null, true );

		self::assertSame( array( array( 'setup', 'credential_1', null, 'valid' ) ), $gateway->mutation_calls );
		self::assertSame( $provider_code, $store->record?->provider_code() );
		self::assertStringContainsString( 'tab=fixture-provider', $redirect );
		self::assertFalse( $enriched['fixture-repository']['actions']['core:webhook-management']['disabled'] );
		self::assertSame( $provider_code, $model['provider_code'] ?? null );
		self::assertSame( $provider_label, $model['provider_label'] ?? null );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Test-only encoding of bounded display projections.
		self::assertStringNotContainsString( 'tab=gh', serialize( array( $redirect, $enriched, $model ) ) );
	}

	public function test_partial_and_missing_providers_reject_secret_bearing_requests_before_facade_or_provider_work(): void {
		foreach ( array( 'partial-provider', 'missing-provider' ) as $provider_code ) {
			$gateway    = $this->gateway( $provider_code );
			$store      = new OperationStoreFixture();
			$provider   = new FitnessOnlyWebhookManagementCapabilityProvider( 'partial-provider', 'Partial Provider' );
			$registry   = 'partial-provider' === $provider_code ? new ProviderRegistry( array( $provider ) ) : new ProviderRegistry();
			$controller = new WebhookManagementController(
				new WebhookOperationCoordinator( $gateway, $store ),
				$this->display( $gateway, $store ),
				$registry,
				$this->package_authorities(),
				static fn (): bool => true,
				static fn (): bool => true
			);
			$request    = $this->request(
				array(
					'provider_code' => $provider_code,
				)
			);

			$redirect = $controller->handle_admin_post( $request, 'valid' );

			self::assertSame( array(), $gateway->calls );
			self::assertSame( array(), $gateway->assessment_calls );
			self::assertSame( array(), $gateway->mutation_calls );
			self::assertSame( 0, $provider->provider_operation_calls );
			self::assertStringContainsString( 'webhook_management_result=invalid_request', $redirect );
			self::assertStringNotContainsString( 'secret-canary-partial-provider', $redirect );
			self::assertStringNotContainsString( 'tab=gh', $redirect );
		}
	}

	public function test_saved_setup_passes_only_the_display_safe_profile_id(): void {
		$gateway = $this->gateway();
		$store   = new OperationStoreFixture();
		$this->controller( gateway: $gateway, store: $store )->handle_admin_post(
			$this->request(
				array(
					'booster_credential_id' => 'credential_1',
				)
			),
			'valid'
		);

		self::assertSame(
			array(
				array( 'setup', 'credential_1', null, 'valid' ),
			),
			$gateway->calls
		);
		self::assertCount( 1, $gateway->assessment_calls, 'Saved credentials use the same one-assessment Core operation path.' );
		self::assertSame( 'wh_0123456789abcdef01234567', $store->record?->webhook_profile_id() );
	}

	public function test_partial_setup_retains_orphan_recovery_state_without_reporting_success(): void {
		$gateway         = $this->gateway();
		$gateway->result = $this->operation_result( 'partial', 'setup_compensation_incomplete', '77' );
		$store           = new OperationStoreFixture();
		$redirect        = $this->controller( gateway: $gateway, store: $store )->handle_admin_post( $this->request(), 'valid' );

		self::assertSame( 'orphaned', $store->record?->status() );
		self::assertStringContainsString( 'webhook_management_result=setup_compensation_incomplete', $redirect );
		self::assertStringNotContainsString( 'webhook_management_result=configured_pending_delivery', $redirect );
	}

	public function test_null_hook_ambiguity_persists_target_scoped_recovery_and_disables_blind_setup(): void {
		$gateway         = $this->gateway();
		$gateway->result = $this->operation_result( 'ambiguous', 'setup_response_invalid', null );
		$store           = new OperationStoreFixture();
		$controller      = $this->controller( gateway: $gateway, store: $store );

		$redirect = $controller->handle_admin_post( $this->request(), 'valid' );

		self::assertStringContainsString( 'webhook_management_result=setup_response_invalid', $redirect );
		self::assertTrue( $store->record?->requires_hook_identification() );
		self::assertSame( 'orphaned', $store->record->status() );
		self::assertSame( 'wh_0123456789abcdef01234567', $store->record->webhook_profile_id() );
		self::assertSame( 1, count( $gateway->mutation_calls ) );

		$second_redirect = $controller->handle_admin_post( $this->request(), 'valid' );
		self::assertStringContainsString( 'webhook_management_result=manual_recovery_required', $second_redirect );
		self::assertSame( 1, count( $gateway->mutation_calls ) );

		$html = $this->render_panel( $gateway, $store );
		self::assertStringContainsString( 'without a stable hook ID', $html );
		self::assertStringContainsString( 'value="setup"', $html );
		self::assertStringContainsString( 'disabled="disabled" aria-disabled="true">Set up webhook</button>', $html );
	}

	public function test_setup_save_failure_falls_back_to_durable_orphan_evidence(): void {
		$gateway                        = $this->gateway();
		$store                          = new OperationStoreFixture();
		$store->save_failures_remaining = 1;

		$redirect = $this->controller( gateway: $gateway, store: $store )->handle_admin_post( $this->request(), 'valid' );

		self::assertSame( 2, $store->save_attempts );
		self::assertSame( '77', $store->record?->hook_id() );
		self::assertSame( 'wh_0123456789abcdef01234567', $store->record->webhook_profile_id() );
		self::assertSame( 'orphaned', $store->record->status() );
		self::assertStringContainsString( 'webhook_management_result=orphaned', $redirect );
	}

	public function test_repeated_setup_save_failure_returns_bounded_recovery_references_and_disables_retry_view(): void {
		$gateway                        = $this->gateway();
		$store                          = new OperationStoreFixture();
		$store->save_failures_remaining = 2;
		$controller                     = $this->controller( gateway: $gateway, store: $store );

		$redirect = $controller->handle_admin_post( $this->request(), 'valid' );

		self::assertNull( $store->record );
		self::assertStringContainsString( 'webhook_management_result=recovery_record_failed', $redirect );
		self::assertStringContainsString( 'recovery_hook=77', $redirect );
		self::assertStringContainsString( 'recovery_profile=wh_0123456789abcdef01234567', $redirect );
		self::assertStringNotContainsString( 'synthetic-request-credential', $redirect );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url,WordPress.Security.NonceVerification.Recommended -- Test parses a local redirect into display-only query state.
		parse_str( (string) parse_url( $redirect, PHP_URL_QUERY ), $_GET );
		$html = $this->render_panel( $gateway, $store );
		self::assertStringContainsString( 'provider hook reference 77', $html );
		self::assertStringContainsString( 'Core signing profile wh_0123456789abcdef01234567', $html );
		self::assertStringContainsString( 'value="setup"', $html );
		self::assertStringContainsString( 'disabled="disabled" aria-disabled="true">Set up webhook</button>', $html );
	}

	public function test_core_authoritative_fitness_block_makes_no_remote_mutation_and_leaves_records_unchanged(): void {
		$blocked = array(
			'insufficient' => $this->fitness_result( suitability: 'insufficient', evidence: 'observed' ),
			'unavailable'  => $this->fitness_result( evidence: 'assessment_unavailable' ),
			'stale'        => $this->fitness_result( evidence: 'stale' ),
			'unsupported'  => $this->fitness_result( support: 'unsupported' ),
		);
		foreach ( $blocked as $fitness_label => $fitness ) {
			foreach ( array( 'setup', 'check', 'reconfigure', 'remove', 'test' ) as $operation ) {
				$gateway          = $this->gateway();
				$gateway->fitness = $fitness;
				$store            = new OperationStoreFixture();
				if ( 'setup' !== $operation ) {
					$store->record = $this->record();
				}
				$before   = $store->record?->to_array();
				$redirect = $this->controller( gateway: $gateway, store: $store )->handle_admin_post(
					$this->request( array( 'repository_webhook_management_operation' => $operation ) ),
					'valid'
				);

				self::assertSame( array(), $gateway->mutation_calls, $operation . ' must not mutate after ' . $fitness_label );
				self::assertSame( $before, $store->record?->to_array() );
				self::assertSame( 0, $store->save_attempts );
				self::assertStringContainsString( 'webhook_management_result=repository_identity_unconfirmed', $redirect );
				self::assertCount( 1, $gateway->calls );
				self::assertCount( 1, $gateway->assessment_calls );
				self::assertSame( $operation, $gateway->assessment_calls[0][0] );
				self::assertSame( array( $gateway->calls[0] ), $gateway->assessment_calls );
			}
		}
	}

	public function test_concurrent_setup_cannot_overwrite_a_record_that_changed_after_core_execution_started(): void {
		$gateway                         = $this->gateway();
		$gateway->result                 = $this->operation_result( 'ambiguous', 'setup_response_invalid', null );
		$store                           = new OperationStoreFixture();
		$current                         = $this->record();
		$store->before_conditional_write = static function ( OperationStoreFixture $interleaved ) use ( $current ): void {
			$interleaved->record = $current;
		};

		$redirect = $this->controller( gateway: $gateway, store: $store )->handle_admin_post( $this->request(), 'valid' );

		self::assertSame( $current->to_array(), $store->record?->to_array() );
		self::assertFalse( $store->record->requires_hook_identification() );
		self::assertSame( 1, $store->save_attempts );
		self::assertStringContainsString( 'webhook_management_result=record_conflict', $redirect );
		self::assertStringContainsString( 'recovery_hook=recovery%3Ahook-identity-unavailable', $redirect );
		self::assertStringContainsString( 'recovery_profile=wh_0123456789abcdef01234567', $redirect );
	}

	public function test_it_rejects_missing_or_unauthorized_saved_credentials_before_core_execution(): void {
		foreach ( array(
			array( 'booster_credential_id' => '' ),
		) as $changes ) {
			$gateway  = $this->gateway();
			$redirect = $this->controller( gateway: $gateway )->handle_admin_post( $this->request( $changes ), 'valid' );

			self::assertSame( array(), $gateway->calls );
			self::assertStringContainsString( 'webhook_management_result=invalid_token', $redirect );
		}

		$gateway = $this->gateway();
		$this->controller( gateway: $gateway )->handle_admin_post( $this->request(), 'wrong' );
		self::assertSame( array(), $gateway->calls );
	}

	public function test_saved_credential_absence_keeps_every_webhook_mutation_control_visible_and_disabled(): void {
		$gateway                        = $this->gateway();
		$gateway->credentials_available = false;
		$store                          = new OperationStoreFixture();
		$store->record                  = $this->record();
		$model                          = $this->display( $gateway, $store )->panel(
			'gh',
			'GitHub',
			'1234',
			'https://site.example/wp-admin/admin.php?page=ran-booster&tab=gh',
			null,
			null,
			true
		);

		self::assertIsArray( $model );
		self::assertSame( array(), $model['credential_choices'] );
		self::assertSame(
			array( 'setup', 'check', 'reconfigure', 'test', 'remove' ),
			array_column( $model['operations'], 'key' )
		);
		self::assertSame( array( true, true, true, true, true ), array_column( $model['operations'], 'disabled' ) );

		$html = $this->render_panel( $gateway, $store );
		foreach ( array( 'Set up webhook', 'Check webhook', 'Update webhook', 'Test webhook', 'Remove webhook' ) as $label ) {
			self::assertStringContainsString( 'disabled="disabled" aria-disabled="true">' . $label . '</button>', $html );
		}
	}

	public function test_check_records_configuration_without_claiming_signed_delivery(): void {
		$gateway         = $this->gateway();
		$gateway->result = $this->operation_result( 'succeeded', 'configured_pending_delivery', '77' );
		$store           = new OperationStoreFixture();
		$store->record   = $this->record( status: 'needs_verification' );
		$redirect        = $this->controller( gateway: $gateway, store: $store )->handle_admin_post(
			$this->request( array( 'repository_webhook_management_operation' => 'check' ) ),
			'valid'
		);

		self::assertSame( array( array( 'check', 'credential_1', '77', 'wh_0123456789abcdef01234567', 1, 'valid' ) ), $gateway->mutation_calls );
		self::assertSame( 'needs_verification', $store->record->status() );
		self::assertStringContainsString( 'webhook_management_result=configured_pending_delivery', $redirect );
	}

	public function test_legacy_verified_ping_stays_needs_verification_and_is_presented_as_pending(): void {
		$gateway         = $this->gateway();
		$gateway->result = $this->operation_result( 'succeeded', 'ping_verified', '77' );
		$store           = new OperationStoreFixture();
		$store->record   = $this->record( status: 'needs_verification', management_credential_id: 'credential_old' );
		$redirect        = $this->controller( gateway: $gateway, store: $store )->handle_admin_post(
			$this->request( array( 'repository_webhook_management_operation' => 'test' ) ),
			'valid'
		);

		self::assertSame( array( array( 'test', 'credential_1', '77', 'wh_0123456789abcdef01234567', 1, 'valid' ) ), $gateway->mutation_calls );
		self::assertSame( 'needs_verification', $store->record->status() );
		self::assertSame( 'credential_1', $store->record->management_credential_id() );
		self::assertStringContainsString( 'webhook_management_result=ping_requested', $redirect );
	}

	public function test_accepted_ping_without_readback_stays_pending_and_renders_a_warning(): void {
		$gateway         = $this->gateway();
		$gateway->result = $this->operation_result( 'succeeded', 'ping_requested', '77' );
		$store           = new OperationStoreFixture();
		$store->record   = $this->record( status: 'configured' );
		$redirect        = $this->controller( gateway: $gateway, store: $store )->handle_admin_post(
			$this->request( array( 'repository_webhook_management_operation' => 'test' ) ),
			'valid'
		);

		self::assertSame( 'needs_verification', $store->record->status() );
		self::assertStringContainsString( 'webhook_management_result=ping_requested', $redirect );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url,WordPress.Security.NonceVerification.Recommended -- Test parses a local redirect into display-only query state.
		parse_str( (string) parse_url( $redirect, PHP_URL_QUERY ), $_GET );
		$html = $this->render_panel( $gateway, $store );

		self::assertStringContainsString( 'notice notice-warning inline ran-booster-repository-webhook-management__notice', $html );
		self::assertStringContainsString( 'does not prove an authenticated inbound delivery', $html );
	}

	public function test_authoritative_test_readback_persists_absent_and_configuration_drift(): void {
		foreach ( array(
			'absent' => $this->operation_result( 'succeeded', 'hook_absent', '77' ),
			'drift'  => $this->operation_result(
				'succeeded',
				'configured_pending_delivery',
				'77',
				true,
				array(
					'endpoint'     => 'mismatched',
					'events'       => 'matched',
					'content_type' => 'matched',
					'active'       => 'matched',
				)
			),
		) as $expected => $result ) {
			$gateway         = $this->gateway();
			$gateway->result = $result;
			$store           = new OperationStoreFixture();
			$store->record   = $this->record( status: 'configured' );
			$redirect        = $this->controller( gateway: $gateway, store: $store )->handle_admin_post(
				$this->request( array( 'repository_webhook_management_operation' => 'test' ) ),
				'valid'
			);

			self::assertSame( 'absent' === $expected ? 'remote_missing' : 'configuration_drift', $store->record->status() );
			self::assertStringContainsString( 'webhook_management_result=' . ( 'absent' === $expected ? 'remote_missing' : 'configuration_drift' ), $redirect );
		}
	}

	public function test_failed_ping_records_authoritative_absence_and_configuration_drift(): void {
		$gateway         = $this->gateway();
		$gateway->result = $this->operation_result(
			'failed',
			'ping_delivery_failed',
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
		$store->record   = $this->record( status: 'configured' );
		$redirect        = $this->controller( gateway: $gateway, store: $store )->handle_admin_post(
			$this->request( array( 'repository_webhook_management_operation' => 'test' ) ),
			'valid'
		);

		self::assertSame( 'configuration_drift', $store->record->status() );
		self::assertStringContainsString( 'webhook_management_result=configuration_drift', $redirect );

		$gateway->result = $this->operation_result( 'failed', 'ping_delivery_failed', '77', delivery: 'absent' );
		$store->record   = $this->record( status: 'configured' );
		$redirect        = $this->controller( gateway: $gateway, store: $store )->handle_admin_post(
			$this->request( array( 'repository_webhook_management_operation' => 'test' ) ),
			'valid'
		);

		self::assertSame( 'remote_missing', $store->record->status() );
		self::assertStringContainsString( 'webhook_management_result=remote_missing', $redirect );
	}

	public function test_ambiguous_removal_retains_recovery_evidence_and_never_retries(): void {
		$gateway         = $this->gateway();
		$gateway->result = $this->operation_result( 'ambiguous', 'remove_outcome_unknown', '77' );
		$store           = new OperationStoreFixture();
		$store->record   = $this->record();

		$controller = $this->controller( gateway: $gateway, store: $store );
		$redirect   = $controller->handle_admin_post(
			$this->request( array( 'repository_webhook_management_operation' => 'remove' ) ),
			'valid'
		);

		self::assertSame( array( array( 'remove', 'credential_1', '77', 'wh_0123456789abcdef01234567', 1, 'valid' ) ), $gateway->mutation_calls );
		self::assertSame( 'removal_pending', $store->record->status() );
		self::assertStringContainsString( 'webhook_management_result=remove_outcome_unknown', $redirect );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url,WordPress.Security.NonceVerification.Recommended -- Test parses a local redirect into display-only query state.
		parse_str( (string) parse_url( $redirect, PHP_URL_QUERY ), $_GET );
		$html = $this->render_panel( $gateway, $store );

		self::assertStringContainsString( 'could not confirm whether the remote hook was removed', $html );
		self::assertStringContainsString( 'value="check"', $html );
		self::assertStringContainsString( 'value="remove"', $html );
		self::assertStringContainsString( 'disabled="disabled" aria-disabled="true">Remove webhook</button>', $html );
	}

	public function test_confirmed_absence_deletes_only_the_local_recovery_record(): void {
		$gateway         = $this->gateway();
		$gateway->result = $this->operation_result( 'succeeded', 'absent', '77', false );
		$store           = new OperationStoreFixture();
		$store->record   = $this->record();

		$redirect = $this->controller( gateway: $gateway, store: $store )->handle_admin_post(
			$this->request( array( 'repository_webhook_management_operation' => 'remove' ) ),
			'valid'
		);

		self::assertNull( $store->record );
		self::assertStringContainsString( 'webhook_management_result=removed', $redirect );
	}

	public function test_confirmed_absence_cannot_delete_a_record_changed_while_core_was_running(): void {
		$gateway                         = $this->gateway();
		$gateway->result                 = $this->operation_result( 'succeeded', 'absent', '77', false );
		$store                           = new OperationStoreFixture();
		$store->record                   = $this->record();
		$current                         = $this->record( status: 'profile_revision_stale' );
		$store->before_conditional_write = static function ( OperationStoreFixture $interleaved ) use ( $current ): void {
			$interleaved->record = $current;
		};

		$redirect = $this->controller( gateway: $gateway, store: $store )->handle_admin_post(
			$this->request( array( 'repository_webhook_management_operation' => 'remove' ) ),
			'valid'
		);

		self::assertSame( $current->to_array(), $store->record->to_array() );
		self::assertStringContainsString( 'webhook_management_result=record_conflict', $redirect );
	}

	public function test_failed_reconfigure_with_authoritative_absence_retains_remote_missing_history(): void {
		$gateway         = $this->gateway();
		$gateway->result = $this->operation_result( 'failed', 'hook_absent', '77' );
		$store           = new OperationStoreFixture();
		$store->record   = $this->record();
		$interaction     = new CapturingAdminInteractionFacade();

		$redirect = $this->controller( gateway: $gateway, store: $store, admin_interaction: $interaction )->handle_admin_post(
			$this->request( array( 'repository_webhook_management_operation' => 'reconfigure' ) ),
			'valid'
		);

		self::assertSame( 'remote_missing', $store->record->status() );
		self::assertNull( $interaction->outcome, 'Authoritative absence must refresh the page so the persisted remote-missing state is rendered.' );
		self::assertStringContainsString( 'webhook_management_result=remote_missing', $redirect );
	}

	public function test_ambiguous_reconfigure_requires_check_before_another_update(): void {
		$gateway         = $this->gateway();
		$gateway->result = $this->operation_result( 'ambiguous', 'reconfigure_readback_unavailable', '77' );
		$store           = new OperationStoreFixture();
		$store->record   = $this->record( endpoint: 'https://hooks.example.test/previous' );
		$interaction     = new CapturingAdminInteractionFacade();
		$controller      = $this->controller( gateway: $gateway, store: $store, admin_interaction: $interaction );

		$redirect = $controller->handle_admin_post(
			$this->request( array( 'repository_webhook_management_operation' => 'reconfigure' ) ),
			'valid'
		);

		self::assertSame( 'needs_verification', $store->record->status() );
		self::assertSame( 'https://hooks.example.test/previous', $store->record->endpoint() );
		self::assertNull( $interaction->outcome, 'Uncertain mutations must retain the refresh path so the persisted state is rendered.' );
		self::assertStringContainsString( 'webhook_management_result=reconfigure_readback_unavailable', $redirect );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url,WordPress.Security.NonceVerification.Recommended -- Test parses a local redirect into display-only query state.
		parse_str( (string) parse_url( $redirect, PHP_URL_QUERY ), $_GET );
		$html = $this->render_panel( $gateway, $store );

		self::assertStringContainsString( 'notice notice-error inline ran-booster-repository-webhook-management__notice', $html );
		self::assertStringContainsString( 'Run Check or inspect the hook at the provider before retrying an update', $html );
		self::assertStringContainsString( 'value="check"', $html );
		self::assertStringContainsString( 'value="reconfigure"', $html );
		self::assertStringContainsString( 'disabled="disabled" aria-disabled="true">Update webhook</button>', $html );
	}

	public function test_ambiguous_provider_remediation_survives_only_its_signed_redirect(): void {
		$remediation     = 'Inspect the provider audit trail before retrying this operation.';
		$gateway         = $this->gateway();
		$gateway->result = $this->operation_result( 'ambiguous', 'fixture_reconfigure_uncertain', '77', remediation: $remediation );
		$store           = new OperationStoreFixture();
		$store->record   = $this->record( endpoint: 'https://hooks.example.test/previous' );
		$controller      = $this->controller( gateway: $gateway, store: $store, admin_interaction: new CapturingAdminInteractionFacade() );

		$redirect = $controller->handle_admin_post(
			$this->request( array( 'repository_webhook_management_operation' => 'reconfigure' ) ),
			'valid'
		);

		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url,WordPress.Security.NonceVerification.Recommended -- Test parses a local signed redirect.
		parse_str( (string) parse_url( $redirect, PHP_URL_QUERY ), $_GET );
		$html = $this->render_panel( $gateway, $store );
		self::assertStringContainsString( $remediation, $html );

		$_GET['webhook_management_remediation'] = 'Tampered provider guidance.';
		$html                                   = $this->render_panel( $gateway, $store );
		self::assertStringNotContainsString( 'Tampered provider guidance.', $html );
		self::assertStringContainsString( 'could not confirm that the remote webhook operation succeeded', $html );
	}

	public function test_package_remediation_carries_its_signed_provider_and_repository_identity(): void {
		$remediation     = 'Inspect the provider audit trail before retrying this operation.';
		$gateway         = $this->gateway();
		$gateway->result = $this->operation_result( 'ambiguous', 'fixture_reconfigure_uncertain', '77', remediation: $remediation );
		$store           = new OperationStoreFixture();
		$store->record   = $this->record( endpoint: 'https://hooks.example.test/previous' );
		$controller      = $this->controller( gateway: $gateway, store: $store, admin_interaction: new CapturingAdminInteractionFacade() );

		$redirect = $controller->handle_admin_post(
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
		$html = $this->render_panel( $gateway, $store );

		self::assertStringContainsString( $remediation, $html );
		$_GET['webhook_management_repository'] = 'other';
		$html                                  = $this->render_panel( $gateway, $store );
		self::assertStringNotContainsString( $remediation, $html );
	}

	public function test_authoritative_check_mismatch_persists_drift_and_offers_reconfigure(): void {
		$gateway         = $this->gateway();
		$gateway->result = $this->operation_result(
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

		$redirect = $controller->handle_admin_post(
			$this->request( array( 'repository_webhook_management_operation' => 'check' ) ),
			'valid'
		);

		self::assertSame( 'configuration_drift', $store->record->status() );
		self::assertStringContainsString( 'webhook_management_result=configuration_drift', $redirect );

		$html = $this->render_panel( $gateway, $store );

		self::assertStringContainsString( 'value="reconfigure"', $html );
		self::assertStringContainsString( 'value="check"', $html );
	}

	public function test_partial_reconfigure_requires_check_before_another_update(): void {
		$gateway         = $this->gateway();
		$gateway->result = $this->operation_result( 'partial', 'operation_lock_release_failed', '77' );
		$store           = new OperationStoreFixture();
		$store->record   = $this->record();
		$controller      = $this->controller( gateway: $gateway, store: $store );

		$redirect = $controller->handle_admin_post(
			$this->request( array( 'repository_webhook_management_operation' => 'reconfigure' ) ),
			'valid'
		);

		self::assertSame( 'needs_verification', $store->record->status() );
		self::assertStringContainsString( 'webhook_management_result=operation_lock_release_failed', $redirect );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url,WordPress.Security.NonceVerification.Recommended -- Test parses a local redirect into display-only query state.
		parse_str( (string) parse_url( $redirect, PHP_URL_QUERY ), $_GET );
		$html = $this->render_panel( $gateway, $store );

		self::assertStringContainsString( 'then run Check before retrying', $html );
		self::assertStringContainsString( 'value="check"', $html );
		self::assertStringContainsString( 'value="reconfigure"', $html );
		self::assertStringContainsString( 'disabled="disabled" aria-disabled="true">Update webhook</button>', $html );
	}

	public function test_verified_result_copy_does_not_claim_that_check_proved_signed_delivery(): void {
		$_GET = array( 'webhook_management_result' => 'verified' );
		$html = $this->render_panel();

		self::assertStringContainsString( 'Provider request ID in Booster Activity', $html );
		self::assertStringNotContainsString( 'confirmed the recorded remote configuration and signed delivery state', $html );
	}

	public function test_failed_setup_uses_the_shared_inline_failure_response(): void {
		$gateway         = $this->gateway();
		$gateway->result = $this->operation_result( 'failed', 'setup_failed', null, false );
		$interaction     = new CapturingAdminInteractionFacade();

		try {
			$this->controller( gateway: $gateway, admin_interaction: $interaction )->handle_admin_post( $this->request(), 'valid' );
			self::fail( 'The shared administration interaction must terminate after responding.' );
		} catch ( AdminInteractionResponded ) {
			self::assertInstanceOf( AdminInteractionOutcome::class, $interaction->outcome );
		}

		self::assertSame( AdminInteractionOutcome::VALIDATION_FAILURE, $interaction->outcome->kind() );
		self::assertSame( 422, $interaction->outcome->status() );
		self::assertStringContainsString( 'No remote hook was established', $interaction->outcome->message() );
	}

	public function test_failed_state_cannot_smuggle_a_verified_success_code_into_the_inline_response(): void {
		$gateway         = $this->gateway();
		$gateway->result = $this->operation_result( 'failed', 'verified' );
		$store           = new OperationStoreFixture();
		$interaction     = new CapturingAdminInteractionFacade();

		try {
			$this->controller( gateway: $gateway, store: $store, admin_interaction: $interaction )->handle_admin_post( $this->request(), 'valid' );
			self::fail( 'The shared administration interaction must terminate after responding.' );
		} catch ( AdminInteractionResponded ) {
			self::assertInstanceOf( AdminInteractionOutcome::class, $interaction->outcome );
		}

		self::assertNull( $store->record );
		self::assertSame( AdminInteractionOutcome::VALIDATION_FAILURE, $interaction->outcome->kind() );
		self::assertStringContainsString( 'could not confirm', $interaction->outcome->message() );
	}

	public function test_ambiguous_setup_keeps_the_refresh_path_for_recovery_state(): void {
		$gateway         = $this->gateway();
		$gateway->result = $this->operation_result( 'ambiguous', 'setup_response_invalid', null );
		$interaction     = new CapturingAdminInteractionFacade();

		$redirect = $this->controller( gateway: $gateway, admin_interaction: $interaction )->handle_admin_post( $this->request(), 'valid' );

		self::assertNull( $interaction->outcome );
		self::assertStringContainsString( 'webhook_management_result=setup_response_invalid', $redirect );
	}

	public function test_ambiguous_setup_failure_code_cannot_bypass_the_persisted_recovery_refresh(): void {
		$gateway         = $this->gateway();
		$gateway->result = $this->operation_result( 'ambiguous', 'setup_failed', null );
		$store           = new OperationStoreFixture();
		$interaction     = new CapturingAdminInteractionFacade();

		$redirect = $this->controller( gateway: $gateway, store: $store, admin_interaction: $interaction )->handle_admin_post( $this->request(), 'valid' );

		self::assertSame( 'orphaned', $store->record?->status() );
		self::assertNull( $interaction->outcome );
		self::assertStringContainsString( 'webhook_management_result=setup_failed', $redirect );
	}

	public function test_failed_result_renders_as_an_explicit_error_notice(): void {
		$_GET = array( 'webhook_management_result' => 'setup_failed' );
		$html = $this->render_panel();

		self::assertStringContainsString( 'notice notice-error inline ran-booster-repository-webhook-management__notice', $html );
		self::assertStringContainsString( 'No remote hook was established', $html );
		self::assertStringNotContainsString( 'Webhook management completed the request', $html );
	}

	public function test_provider_remediation_is_bounded_before_presentation(): void {
		$display  = $this->display();
		$maximum  = str_repeat( 'r', 255 );
		$fallback = 'Webhook management could not confirm that the remote webhook operation succeeded. Review the recorded status before retrying.';

		self::assertSame( $maximum, $display->notice( 'fixture_provider_failed', null, $maximum ) );
		self::assertSame( $fallback, $display->notice( 'fixture_provider_failed', null, str_repeat( 'r', 256 ) ) );
		self::assertSame( $fallback, $display->notice( 'fixture_provider_failed', null, str_repeat( 'r', 512 ) ) );
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit lifecycle override requires this method name.

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this exact lifecycle override name.
	protected function tearDown(): void {
		$_GET = array();
		unset( $GLOBALS['ran_booster_package_view_multisite'] );
	}

	/**
	 * @param array<string, mixed> $changes
	 * @return array<string, mixed>
	 */
	private function request( array $changes = array() ): array {
		return array_merge(
			array(
				'repository_webhook_management_operation' => 'setup',
				'provider_code'                           => 'gh',
				'repository_id'                           => '1234',
				'booster_credential_id'                   => 'credential_1',
				'webhook_profile_id'                      => WebhookManagementController::CREATE_REPOSITORY_SECRET,
			),
			$changes
		);
	}

	/** @return array<string, mixed> */
	private function repository_projection( string $provider_code = 'gh' ): array {
		return array(
			'provider_code'         => $provider_code,
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

	private function readiness( string $provider_code = 'gh' ): AssistanceReadiness {
		$projection = $this->repository_projection( $provider_code );
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

	private function target( string $provider_code = 'gh' ): AssistanceTarget {
		return new AssistanceTarget(
			$provider_code,
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

	private function record( string $status = 'configured', string $endpoint = 'https://hooks.example.test/webhook', string $management_credential_id = 'credential_1' ): InstallationRecord {
		return new InstallationRecord( 'gh', '1234', 'owner/repository', '77', $management_credential_id, 'wh_0123456789abcdef01234567', 'repository', 1, 'created', $endpoint, $status, '2026-07-23T16:00:00Z', '2026-07-23T17:00:00Z' );
	}

	/** @param array<string, string>|null $configuration */
	private function operation_result( string $state = 'succeeded', string $code = 'configured_pending_delivery', ?string $hook_id = '77', bool $with_profile = true, ?array $configuration = null, string $provider_code = 'gh', string $remediation = 'Review the bounded operation result.', ?string $delivery = null ): RepositoryWebhookOperationResult {
		$delivery ??= match ( $code ) {
			'verified', 'ping_verified' => 'verified',
			'ping_delivery_failed' => 'unverified',
			'ping_requested' => 'unknown',
			'absent', 'hook_absent' => 'absent',
			default => 'succeeded' === $state ? 'configured_pending_delivery' : 'unknown',
		};

		return new RepositoryWebhookOperationResult(
			$state,
			$code,
			'2026-08-02T20:00:00Z',
			$hook_id,
			$configuration ?? array(
				'endpoint'     => 'matched',
				'events'       => 'matched',
				'content_type' => 'matched',
				'active'       => 'matched',
			),
			$delivery,
			$remediation,
			$with_profile ? new WebhookProfileMetadata( 'wh_0123456789abcdef01234567', $provider_code, 'repository', 'owner/repository', '1234', 1, 'created', 'file', false ) : null
		);
	}

	private function fitness_result(
		string $support = 'supported',
		string $suitability = 'unknown',
		string $evidence = 'unknown_by_design'
	): RepositoryWebhookFitnessResult {
		return new RepositoryWebhookFitnessResult( $support, $suitability, 'unknown', $evidence, 'fitness_result', '2026-08-02T20:00:00Z', 'Review the bounded assessment.' );
	}

	private function gateway( string $provider_code = 'gh' ): OperationGatewayFixture {
		return new OperationGatewayFixture( $this->readiness( $provider_code ), $this->target( $provider_code ), $this->operation_result( provider_code: $provider_code ) );
	}

	private function display( ?OperationGatewayFixture $gateway = null, ?OperationStoreFixture $store = null ): WebhookDisplayModel {
		return new WebhookDisplayModel( $gateway ?? $this->gateway(), $store ?? new OperationStoreFixture() );
	}

	private function render_panel( ?OperationGatewayFixture $gateway = null, ?OperationStoreFixture $store = null ): string {
		$gateway  ??= $this->gateway();
		$store    ??= new OperationStoreFixture();
		$display    = $this->display( $gateway, $store );
		$controller = $this->controller( $gateway, $store );
		$context    = $controller->panel_context();
		$model      = $display->panel( 'gh', 'GitHub', '1234', 'https://site.example/wp-admin/admin.php?page=ran-booster&tab=gh', $context['result'], $context['recovery'], true, $context['remediation'] );
		self::assertIsArray( $model );
		$form_attributes = '';
		ob_start();
		require dirname( __DIR__, 3 ) . '/RAN/Admin/WebhookManagement/views/panel.php';

		return (string) ob_get_clean();
	}

	private function controller( ?OperationGatewayFixture $gateway = null, ?OperationStoreFixture $store = null, ?AdminInteractionFacade $admin_interaction = null, string $provider_code = 'gh', string $provider_label = 'GitHub', ?ManagedPackageWebhookAuthorityResolver $authorities = null ): WebhookManagementController {
		$gateway  ??= $this->gateway();
		$store    ??= new OperationStoreFixture();
		$controller = new WebhookManagementController(
			new WebhookOperationCoordinator( $gateway, $store ),
			$this->display( $gateway, $store ),
			new ProviderRegistry( array( new CompleteWebhookManagementCapabilityProvider( $provider_code, $provider_label ) ) ),
			$authorities ?? $this->package_authorities( array( 'example/example.php' => array( $provider_code, '1234' ) ) ),
			static fn (): bool => true,
			static fn ( string $nonce, string $action ): bool => ( 'valid' === $nonce && in_array(
				$action,
				array(
					'ran_booster_repository_webhook_setup_' . $provider_code . '_1234',
					'ran_booster_repository_webhook_check_' . $provider_code . '_1234',
					'ran_booster_repository_webhook_reconfigure_' . $provider_code . '_1234',
					'ran_booster_repository_webhook_remove_' . $provider_code . '_1234',
					'ran_booster_repository_webhook_test_' . $provider_code . '_1234',
				),
				true
			) ) || ( str_starts_with( $action, 'ran_booster_repository_webhook_result_' )
				&& hash_equals( hash_hmac( 'sha256', $action, 'test-result-nonce' ), $nonce ) ),
			static fn ( string $action ): string => hash_hmac( 'sha256', $action, 'test-result-nonce' )
		);
		if ( null !== $admin_interaction ) {
			$controller->use_admin_interaction_facade( $admin_interaction );
		}

		return $controller;
	}

	/**
	 * @param array<string, array{0:string,1:string}> $plugins
	 * @param array<string, array{0:string,1:string}> $themes
	 */
	private function package_authorities( array $plugins = array(), array $themes = array() ): ManagedPackageWebhookAuthorityResolver {
		$plugin_repository = $this->createMock( PluginRepository::class );
		$theme_repository  = $this->createMock( ThemeRepository::class );
		$plugin_repository->method( 'booster_plugin_from_file' )->willReturnCallback( fn ( mixed $identifier ): Package => $this->return_package( $plugins, $identifier ) );
		$theme_repository->method( 'booster_theme_from_stylesheet' )->willReturnCallback( fn ( mixed $identifier ): Package => $this->return_package( $themes, $identifier ) );

		return new ManagedPackageWebhookAuthorityResolver( $plugin_repository, $theme_repository );
	}

	/** @param array<string, array{0:string,1:string}> $packages */
	private function return_package( array $packages, mixed $identifier ): Package {
		if ( ! is_string( $identifier ) || ! isset( $packages[ $identifier ] ) ) {
			throw new \RuntimeException( 'Package return authority did not match.' );
		}
		$package = $this->createMock( Package::class );
		$package->method( 'get_source' )->willReturn( PackageSource::BRANCH );
		$package->method( 'get_provider_code' )->willReturn( $packages[ $identifier ][0] );
		$package->method( 'get_provider_repository_id' )->willReturn( $packages[ $identifier ][1] );

		return $package;
	}
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- This fixture shares the focused operation suite that owns its state and assertions.
final class OperationStoreFixture implements InstallationStore {
	public ?InstallationRecord $record = null;
	public int $all_attempts           = 0;
	public int $find_attempts          = 0;
	/** @var array<string, InstallationRecord> */
	public array $records               = array();
	public int $save_attempts           = 0;
	public int $save_failures_remaining = 0;
	/** @var (\Closure(self): void)|null */
	public ?\Closure $before_conditional_write = null;

	public function all(): array {
		++$this->all_attempts;
		$records = $this->records;
		if ( null !== $this->record ) {
			$records[ $this->record->storage_key() ] = $this->record;
		}

		return $records;
	}

	public function find( string $provider_code, string $repository_id ): ?InstallationRecord {
		++$this->find_attempts;
		$key = InstallationRecord::key( $provider_code, $repository_id );

		if ( isset( $this->records[ $key ] ) ) {
			return $this->records[ $key ];
		}

		return null !== $this->record && hash_equals( $provider_code, $this->record->provider_code() ) && hash_equals( $repository_id, $this->record->repository_id() )
			? $this->record
			: null;
	}

	public function save_if_current( InstallationRecord $record, ?InstallationRecord $expected ): string {
		++$this->save_attempts;
		if ( null !== $this->before_conditional_write ) {
			$interleave                     = $this->before_conditional_write;
			$this->before_conditional_write = null;
			$interleave( $this );
		}
		if ( $this->same( $this->record, $record ) ) {
			return self::WRITE_UNCHANGED;
		}
		if ( ! $this->same( $this->record, $expected ) ) {
			return self::WRITE_CONFLICT;
		}
		if ( 0 < $this->save_failures_remaining ) {
			--$this->save_failures_remaining;

			return self::WRITE_FAILED;
		}
		$this->record = $record;

		return self::WRITE_APPLIED;
	}

	public function delete_if_current( string $provider_code, string $repository_id, ?InstallationRecord $expected ): string {
		unset( $provider_code, $repository_id );
		++$this->save_attempts;
		if ( null !== $this->before_conditional_write ) {
			$interleave                     = $this->before_conditional_write;
			$this->before_conditional_write = null;
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
			: $left->to_array() === $right->to_array();
	}
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- This fixture shares the focused operation suite that owns its state and assertions.
final class OperationGatewayFixture implements WebhookAssistanceFacade {
	/** @var list<array<mixed>> */
	public array $calls = array();
	/** @var list<array<mixed>> */
	public array $assessment_calls = array();
	/** @var list<array<mixed>> */
	public array $mutation_calls = array();
	public RepositoryWebhookFitnessResult $fitness;
	public bool $throw_on_readiness    = false;
	public bool $profile_absent        = false;
	public bool $credentials_available = true;

	public function __construct(
		private readonly AssistanceReadiness $readiness_result,
		private readonly AssistanceTarget $target_result,
		public RepositoryWebhookOperationResult $result
	) {
		$this->fitness = $this->fitness_result();
	}

	public function readiness( string $provider_code ): AssistanceReadiness {
		unset( $provider_code );
		if ( $this->throw_on_readiness ) {
			throw new \RuntimeException( 'Readiness unavailable.' );
		}

		return $this->readiness_result;
	}

	public function target( string $provider_code, string $repository_id ): ?AssistanceTarget {
		return hash_equals( $this->target_result->provider_code(), $provider_code ) && hash_equals( $repository_id, $this->target_result->repository_id() ) ? $this->target_result : null;
	}

	public function credential_choices( string $provider_code ): array {
		return $this->credentials_available && hash_equals( $this->target_result->provider_code(), $provider_code ) ? array(
			array(
				'id'    => 'credential_1',
				'label' => 'Temporary',
				'kind'  => 'fine-grained',
			),
		) : array();
	}

	public function webhook_profile_choices( string $provider_code, string $repository_id ): array {
		return hash_equals( $this->target_result->provider_code(), $provider_code ) && hash_equals( $this->target_result->repository_id(), $repository_id ) ? array(
			array(
				'id'    => 'wh_0123456789abcdef01234567',
				'label' => 'Repository signing secret',
				'scope' => 'repository',
			),
		) : array();
	}

	public function profile( string $provider_code, string $repository_id, string $profile_id ): ?WebhookProfileMetadata {
		if ( $this->profile_absent ) {
			return null;
		}

		return hash_equals( $this->target_result->provider_code(), $provider_code )
			&& hash_equals( $this->target_result->repository_id(), $repository_id )
			&& 'wh_0123456789abcdef01234567' === $profile_id
			? new WebhookProfileMetadata( 'wh_0123456789abcdef01234567', $provider_code, 'repository', 'owner/repository', '1234', 1, 'created', 'file', false )
			: null;
	}

	public function assess_setup( AssistanceTarget $target, ?string $credential_profile_id, string $nonce ): RepositoryWebhookFitnessResult {
		unset( $target );
		$call                     = array( 'assess_setup', $credential_profile_id, $nonce );
		$this->calls[]            = $call;
		$this->assessment_calls[] = $call;

		return $this->fitness;
	}

	public function assess_check( AssistanceTarget $target, ?string $credential_profile_id, string $hook_id, string $webhook_profile_id, int $profile_revision, string $nonce ): RepositoryWebhookFitnessResult {
		unset( $target );
		$call                     = array( 'assess_check', $credential_profile_id, $hook_id, $webhook_profile_id, $profile_revision, $nonce );
		$this->calls[]            = $call;
		$this->assessment_calls[] = $call;

		return $this->fitness;
	}

	public function assess_reconfigure( AssistanceTarget $target, ?string $credential_profile_id, string $hook_id, string $webhook_profile_id, int $profile_revision, string $nonce ): RepositoryWebhookFitnessResult {
		unset( $target );
		$call                     = array( 'assess_reconfigure', $credential_profile_id, $hook_id, $webhook_profile_id, $profile_revision, $nonce );
		$this->calls[]            = $call;
		$this->assessment_calls[] = $call;

		return $this->fitness;
	}

	public function assess_remove( AssistanceTarget $target, ?string $credential_profile_id, string $hook_id, string $webhook_profile_id, int $profile_revision, string $nonce ): RepositoryWebhookFitnessResult {
		unset( $target );
		$call                     = array( 'assess_remove', $credential_profile_id, $hook_id, $webhook_profile_id, $profile_revision, $nonce );
		$this->calls[]            = $call;
		$this->assessment_calls[] = $call;

		return $this->fitness;
	}

	public function assess_test( AssistanceTarget $target, ?string $credential_profile_id, string $hook_id, string $webhook_profile_id, int $profile_revision, string $nonce ): RepositoryWebhookFitnessResult {
		unset( $target );
		$call                     = array( 'assess_test', $credential_profile_id, $hook_id, $webhook_profile_id, $profile_revision, $nonce );
		$this->calls[]            = $call;
		$this->assessment_calls[] = $call;

		return $this->fitness;
	}

	public function setup( AssistanceTarget $target, ?string $credential_profile_id, string $nonce, ?string $webhook_profile_id = null ): RepositoryWebhookOperationResult {
		unset( $target );
		$call          = array( 'setup', $credential_profile_id, $webhook_profile_id, $nonce );
		$this->calls[] = $call;

		return $this->authoritative_operation( $call );
	}

	public function check( AssistanceTarget $target, ?string $credential_profile_id, string $hook_id, string $webhook_profile_id, int $profile_revision, string $nonce ): RepositoryWebhookOperationResult {
		unset( $target );
		$call          = array( 'check', $credential_profile_id, $hook_id, $webhook_profile_id, $profile_revision, $nonce );
		$this->calls[] = $call;

		return $this->authoritative_operation( $call );
	}

	public function reconfigure( AssistanceTarget $target, ?string $credential_profile_id, string $hook_id, string $webhook_profile_id, int $profile_revision, string $nonce ): RepositoryWebhookOperationResult {
		unset( $target );
		$call          = array( 'reconfigure', $credential_profile_id, $hook_id, $webhook_profile_id, $profile_revision, $nonce );
		$this->calls[] = $call;

		return $this->authoritative_operation( $call );
	}

	public function remove( AssistanceTarget $target, ?string $credential_profile_id, string $hook_id, string $webhook_profile_id, int $profile_revision, string $nonce ): RepositoryWebhookOperationResult {
		unset( $target );
		$call          = array( 'remove', $credential_profile_id, $hook_id, $webhook_profile_id, $profile_revision, $nonce );
		$this->calls[] = $call;

		return $this->authoritative_operation( $call );
	}

	public function test( AssistanceTarget $target, ?string $credential_profile_id, string $hook_id, string $webhook_profile_id, int $profile_revision, string $nonce ): RepositoryWebhookOperationResult {
		unset( $target );
		$call          = array( 'test', $credential_profile_id, $hook_id, $webhook_profile_id, $profile_revision, $nonce );
		$this->calls[] = $call;

		return $this->authoritative_operation( $call );
	}

	/** @param array<mixed> $call */
	private function authoritative_operation( array $call ): RepositoryWebhookOperationResult {
		$this->assessment_calls[] = $call;
		$projection               = $this->fitness->to_array();
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
		$this->mutation_calls[] = $call;

		return $this->result;
	}

	private function fitness_result(): RepositoryWebhookFitnessResult {
		return new RepositoryWebhookFitnessResult( 'supported', 'unknown', 'unknown', 'unknown_by_design', 'fitness_unknown', '2026-08-02T20:00:00Z', 'Review the bounded assessment.' );
	}
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- This fixture shares the focused operation suite that owns its state and assertions.
final class AdminInteractionResponded extends \RuntimeException {
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- This fixture shares the focused operation suite that owns its state and assertions.
final class CapturingAdminInteractionFacade implements AdminInteractionFacade {
	public ?AdminInteractionOutcome $outcome = null;

	public function render_form_attributes( AdminInteractionRequest $request ): void {
		unset( $request );
	}

	public function is_enhanced_request( AdminInteractionRequest $request ): bool {
		unset( $request );

		return true;
	}

	public function respond( AdminInteractionOutcome $outcome ): never {
		$this->outcome = $outcome;

		throw new AdminInteractionResponded();
	}
}
