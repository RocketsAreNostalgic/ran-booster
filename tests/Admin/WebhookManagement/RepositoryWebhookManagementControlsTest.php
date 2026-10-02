<?php

declare( strict_types = 1 );

namespace Tests\Admin\WebhookManagement;

use PHPUnit\Framework\TestCase;
use RAN\AddOn\WebhookAssistance\AssistanceTarget;
use RAN\AddOn\WebhookAssistance\AssistanceReadiness;
use RAN\AddOn\WebhookAssistance\WebhookAssistanceFacade;
use RAN\Admin\Interaction\AdminInteractionFacade;
use RAN\Admin\AdminPackageProjection;
use RAN\Admin\ManagedPackageWebhookAuthorityResolver;
use RAN\Admin\WebhookManagement\RepositoryWebhookManagementControls;
use RAN\Package;
use RAN\PackageSource;
use RAN\RepositoryProvider\ProviderRegistry;
use RAN\Storage\PluginRepository;
use RAN\Storage\ThemeRepository;
use Tests\Support\AbsentWebhookManagementCapabilityProvider;
use Tests\Support\CompleteWebhookManagementCapabilityProvider;
use Tests\Support\FitnessOnlyWebhookManagementCapabilityProvider;
use Tests\Support\ManagementOnlyWebhookManagementCapabilityProvider;
use Tests\Support\UnnormalizedWebhookManagementCapabilityProvider;
use Tests\Support\WebhookManagementCapabilityProvider;

require_once __DIR__ . '/RepositoryWebhookManagementControlsWordPressFunctions.php';
require_once __DIR__ . '/WordPressInstallationStoreWordPressFunctions.php';
require_once dirname( __DIR__, 2 ) . '/Support/WebhookManagementCapabilityProviders.php';
require_once dirname( __DIR__, 2 ) . '/Support/PackageViewWordPressFunctions.php';

final class RepositoryWebhookManagementControlsTest extends TestCase {
	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit lifecycle override requires this method name.
	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this exact lifecycle override name.
	protected function setUp(): void {
		if ( ! defined( 'ABSPATH' ) ) {
			define( 'ABSPATH', __DIR__ . '/' );
		}
		$GLOBALS['ran_booster_repository_webhook_management_actions']      = array();
		$GLOBALS['ran_booster_repository_webhook_management_filters']      = array();
		$GLOBALS['ran_booster_repository_webhook_management_styles']       = array();
		$GLOBALS['ran_booster_repository_webhook_management_capabilities'] = array();
		$GLOBALS['ran_booster_repository_webhook_management_test_options'] = array();
		$_GET = array();
	}

	public function test_it_registers_the_complete_non_git_hub_provider_presentation_and_request_boundary_once(): void {
		$provider = new CompleteWebhookManagementCapabilityProvider( 'fixture-provider', 'Fixture Forge' );
		$controls = $this->controls( $provider );
		$controls->register();

		self::assertTrue( $controls->supports_provider( 'fixture-provider' ) );
		self::assertSame(
			array( 'ran_booster_documentation_sections_after_provider_fixture-provider' ),
			array_keys( $GLOBALS['ran_booster_repository_webhook_management_filters'] )
		);
		self::assertSame(
			array( 'admin_post_ran_booster_repository_webhook_management_operation', 'admin_enqueue_scripts', 'ran_booster_admin_package_advanced_source_sections' ),
			array_keys( $GLOBALS['ran_booster_repository_webhook_management_actions'] )
		);
		foreach ( array_merge( $GLOBALS['ran_booster_repository_webhook_management_actions'], $GLOBALS['ran_booster_repository_webhook_management_filters'] ) as $registrations ) {
			self::assertCount( 1, $registrations );
		}
		self::assertSame( 0, $provider->provider_operation_calls );
	}

	public function test_it_loads_scoped_styles_on_capable_provider_and_package_settings_screens(): void {
		$controls = $this->controls( new CompleteWebhookManagementCapabilityProvider( 'fixture-provider', 'Fixture Forge' ) );
		$controls->register();
		$_GET = array(
			'page' => 'ran-booster',
			'tab'  => 'fixture-provider',
		);

		$controls->enqueue_admin_assets( 'settings_page_unrelated' );
		self::assertSame( array(), $GLOBALS['ran_booster_repository_webhook_management_styles'] );

		$controls->enqueue_admin_assets( 'toplevel_page_ran-booster' );
		self::assertSame(
			array(
				'handle'       => 'ran-booster-repository-webhook-management',
				'source'       => 'https://example.test/wp-content/plugins/ran-booster/assets/ran-booster-repository-webhook-management.css',
				'dependencies' => array( 'ran-booster-styles' ),
				'version'      => (string) filemtime( dirname( __DIR__, 3 ) . '/assets/ran-booster-repository-webhook-management.css' ),
			),
			$GLOBALS['ran_booster_repository_webhook_management_styles'][0]
		);

		$_GET = array(
			'page'    => 'ran-booster-plugins',
			'package' => 'example/example.php',
		);
		$controls->enqueue_admin_assets( 'ran-booster_page_ran-booster-plugins' );
		self::assertCount( 2, $GLOBALS['ran_booster_repository_webhook_management_styles'] );
	}

	public function test_partial_absent_missing_and_malformed_providers_receive_no_placement_before_provider_work(): void {
		$providers = array(
			new FitnessOnlyWebhookManagementCapabilityProvider( 'fitness-only', 'Fitness only' ),
			new ManagementOnlyWebhookManagementCapabilityProvider( 'management-only', 'Management only' ),
			new UnnormalizedWebhookManagementCapabilityProvider( 'no-policy', 'No signing policy' ),
			new AbsentWebhookManagementCapabilityProvider( 'absent', 'Absent' ),
		);
		$controls  = $this->controls( ...$providers );
		$controls->register();
		$rows = array( 'repository' => array( 'actions' => array() ) );

		foreach ( array( 'fitness-only', 'management-only', 'no-policy', 'absent', 'missing', "bad\0code" ) as $provider_code ) {
			self::assertFalse( $controls->supports_provider( $provider_code ) );
			self::assertSame( $rows, $controls->enrich_repository_rows( $rows, $provider_code, array(), 'https://example.test/' ) );
			ob_start();
			self::assertFalse( $controls->render_repository_panel( $provider_code, 'repository', 'https://example.test/' ) );
			self::assertSame( '', ob_get_clean() );
		}
		foreach ( array( 'fitness-only', 'management-only', 'no-policy' ) as $provider_code ) {
			self::assertTrue( $controls->has_management_capability( $provider_code ) );
		}
		foreach ( array( 'absent', 'missing', "bad\0code" ) as $provider_code ) {
			self::assertFalse( $controls->has_management_capability( $provider_code ) );
		}

		foreach ( $providers as $provider ) {
			self::assertSame( 0, $provider->provider_operation_calls );
		}
		self::assertSame( array(), $GLOBALS['ran_booster_repository_webhook_management_filters'] );
		foreach ( array( 'fitness-only', 'management-only', 'no-policy', 'absent', 'missing' ) as $provider_code ) {
			$_GET = array(
				'page' => 'ran-booster',
				'tab'  => $provider_code,
			);
			$controls->enqueue_admin_assets( 'toplevel_page_ran-booster' );
		}
		self::assertSame( array(), $GLOBALS['ran_booster_repository_webhook_management_styles'] );
	}

	public function test_claimed_incomplete_capability_renders_a_disabled_core_shell_without_facade_or_provider_work(): void {
		$provider = new FitnessOnlyWebhookManagementCapabilityProvider( 'fitness-only', 'Fitness only' );
		$controls = $this->controls( $provider );
		$controls->register();

		ob_start();
		$controls->render_repository_webhook_setup( 'fitness-only', '1234', 'https://example.test/repository', true, 'owner/branch' );
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'Fitness only webhook configuration is incomplete.', $html );
		self::assertStringContainsString( 'Branch packages can still be updated manually', $html );
		self::assertStringContainsString( 'ran-booster-repository-webhook-setup is-inactive', $html );
		self::assertStringContainsString( 'aria-disabled="true"', $html );
		self::assertStringNotContainsString( 'name="repository_webhook_management_operation"', $html );
		self::assertSame( 0, $provider->provider_operation_calls );
	}

	public function test_repository_panel_reports_unavailable_targets_without_rendering_markup(): void {
		$GLOBALS['ran_booster_repository_webhook_management_capabilities']['manage_options'] = true;
		foreach ( array( 'missing', 'throws' ) as $mode ) {
			$facade = $this->createMock( WebhookAssistanceFacade::class );
			$facade->expects( self::once() )->method( 'target' )->willReturnCallback(
				static function () use ( $mode ): ?AssistanceTarget {
					if ( 'throws' === $mode ) {
						throw new \RuntimeException( 'Target unavailable.' );
					}

					return null;
				}
			);
			$controls = new RepositoryWebhookManagementControls(
				$facade,
				$this->createMock( AdminInteractionFacade::class ),
				new ProviderRegistry( array( new CompleteWebhookManagementCapabilityProvider( 'fixture-provider', 'Fixture Forge' ) ) ),
				dirname( __DIR__, 3 ) . '/',
				'https://example.test/wp-content/plugins/ran-booster/',
				new ManagedPackageWebhookAuthorityResolver( $this->createMock( PluginRepository::class ), $this->createMock( ThemeRepository::class ) )
			);
			$controls->register();

			ob_start();
			self::assertFalse( $controls->render_repository_panel( 'fixture-provider', '1234', 'https://example.test/repositories' ), $mode );
			self::assertSame( '', (string) ob_get_clean(), $mode );
		}
	}

	public function test_repository_page_reuses_the_fixed_normal_post_webhook_section(): void {
		$GLOBALS['ran_booster_repository_webhook_management_capabilities']['manage_options'] = true;
		$facade = $this->createMock( WebhookAssistanceFacade::class );
		$facade->expects( self::once() )->method( 'readiness' )->with( 'fixture-provider' )->willReturn(
			new AssistanceReadiness(
				array(),
				'https://example.test/webhook',
				array(
					array(
						'provider_code'         => 'fixture-provider',
						'repository_id'         => '1234',
						'deployment_policies'   => array(
							'automatic' => 1,
							'manual'    => 0,
							'disabled'  => 0,
						),
						'local_secret_coverage' => 'repository',
					),
				)
			)
		);
		$facade->expects( self::once() )->method( 'target' )->with( 'fixture-provider', '1234' )->willReturn(
			new AssistanceTarget(
				'fixture-provider',
				'1234',
				'owner/example',
				'Example',
				array( 'example/example.php' ),
				array(
					'automatic' => 0,
					'manual'    => 1,
					'disabled'  => 0,
				),
				'https://example.test/webhook'
			)
		);
		$facade->expects( self::once() )->method( 'credential_choices' )->with( 'fixture-provider' )->willReturn(
			array(
				array(
					'id'    => 'credential-1',
					'label' => 'Repository access',
					'kind'  => 'token',
				),
			)
		);
		$controls = new RepositoryWebhookManagementControls( $facade, $this->createMock( AdminInteractionFacade::class ), new ProviderRegistry( array( new CompleteWebhookManagementCapabilityProvider( 'fixture-provider', 'Fixture Forge' ) ) ), dirname( __DIR__, 3 ) . '/', 'https://example.test/wp-content/plugins/ran-booster/', $this->package_authorities() );
		$controls->register();

		ob_start();
		$controls->render_repository_webhook_setup( 'fixture-provider', '1234', 'https://example.test/repository' );
		$html = (string) ob_get_clean();

		self::assertStringContainsString( '<h3 id="ran-booster-repository-webhook-heading">Push-to-deploy</h3>', $html );
		self::assertStringContainsString( 'Webhook setup', $html );
		self::assertStringContainsString( 'class="ran-booster-settings-section ran-booster-repository-webhook-section"', $html );
		self::assertSame( 1, substr_count( $html, 'class="ran-booster-settings-section ran-booster-repository-webhook-section"' ) );
		self::assertSame( 1, substr_count( $html, 'class="ran-booster-readiness-panel ran-booster-repository-webhook-setup"' ) );
		self::assertStringContainsString( 'class="ran-booster-readiness-panel ran-booster-repository-webhook-setup"', $html );
		self::assertStringContainsString( '<div class="ran-booster-readiness-panel__top"><div><h4 id="ran-booster-repository-webhook-setup-heading">Webhook setup</h4><p>Sets up this repository’s webhook. Automatic updates remain configured separately for each package.</p></div></div>', $html );
		self::assertStringNotContainsString( 'What this changes', $html );
		self::assertStringNotContainsString( 'Booster checks the repository before making changes and never adopts or deletes an unverified webhook.', $html );
		self::assertStringContainsString( 'class="ran-booster-repository-webhook-setup__body"', $html );
		self::assertStringContainsString( 'class="ran-booster-repository-webhook-setup__content"', $html );
		self::assertStringContainsString( 'class="ran-booster-repository-webhook-management__panel"', $html );
		self::assertStringNotContainsString( 'ran-booster-panel ran-booster-push-deploy__panel', $html );
		self::assertStringContainsString( 'class="ran-booster-settings-section__body"', $html );
		self::assertStringNotContainsString( '<details', $html );
		self::assertStringContainsString( 'name="action" value="ran_booster_repository_webhook_management_operation"', $html );
		self::assertStringContainsString( 'name="repository_webhook_management_operation" value="setup"', $html );
		self::assertStringContainsString( 'class="button" href="https://fixture-provider.example.test/owner%2Fexample/settings/hooks"', $html );
		self::assertStringContainsString( 'Open Fixture Forge webhooks', $html );
		self::assertStringContainsString( 'class="ran-booster-webhook-steps ran-booster-repository-webhook-lifecycle"', $html );
		self::assertStringContainsString( '<strong>Site receiver</strong>', $html );
		self::assertStringContainsString( '<strong>Signing secret</strong>', $html );
		self::assertStringContainsString( '<strong>Repository webhook</strong>', $html );
		self::assertStringContainsString( '<strong>Automatic packages</strong>', $html );
		self::assertStringContainsString( 'aria-hidden="true">1</span>', $html );
		self::assertStringContainsString( 'aria-hidden="true">2</span>', $html );
		self::assertStringContainsString( 'aria-hidden="true">3</span>', $html );
		self::assertStringContainsString( 'aria-hidden="true">4</span>', $html );
		self::assertStringContainsString( 'ran-booster-webhook-step is-ok', $html );
		self::assertTrue( strpos( $html, 'Repository webhook lifecycle' ) < strpos( $html, 'Webhook readiness' ) );
		self::assertStringContainsString( 'Webhook readiness', $html );
		self::assertStringContainsString( 'Branch demand', $html );
		self::assertStringContainsString( '1 Automatic Branch package uses this repository webhook.', $html );
		self::assertStringContainsString( 'Signing profile', $html );
		self::assertStringContainsString( 'A signing secret is ready for this repository.', $html );
		self::assertStringContainsString( 'Provider receiver', $html );
		self::assertStringContainsString( 'Remote hook', $html );
		self::assertStringNotContainsString( 'Bundled webhook management', $html );
		self::assertStringContainsString( 'Create a repository signing secret', $html );
		self::assertStringNotContainsString( 'request_credential', $html );
		self::assertStringContainsString( 'href="https://example.test/wp-admin/admin.php?page=ran-booster&amp;tab=fixture-provider&amp;view=credentials">Manage credentials</a>', $html );
		self::assertStringContainsString( 'href="https://example.test/wp-admin/admin.php?page=ran-booster&amp;tab=fixture-provider&amp;view=secrets">Manage signing secrets</a>', $html );
		self::assertSame( 2, substr_count( $html, 'class="ran-booster-repository-webhook-management__select-action"' ) );
		self::assertStringNotContainsString( 'hx-post=', $html );
		self::assertStringNotContainsString( 'hx-target=', $html );
	}

	public function test_repository_checklist_remains_visible_when_webhook_operations_are_unavailable(): void {
		$GLOBALS['ran_booster_repository_webhook_management_capabilities']['manage_options']                           = true;
		$GLOBALS['ran_booster_repository_webhook_management_test_options']['ran_booster_assisted_hooks_installations'] = array(
			'fixture-provider:1234' => array(
				'schema_version'              => 4,
				'provider_code'               => 'fixture-provider',
				'repository_id'               => '1234',
				'repository'                  => 'owner/example',
				'hook_id'                     => '77',
				'management_credential_id'    => 'credential_1',
				'webhook_profile_id'          => 'wh_0123456789abcdef01234567',
				'webhook_profile_scope'       => 'repository',
				'webhook_profile_revision'    => 1,
				'webhook_profile_disposition' => 'created',
				'endpoint'                    => 'https://example.test/webhook',
				'status'                      => 'configured',
				'created_at'                  => '2026-08-20T01:02:03Z',
				'checked_at'                  => '2026-08-20T01:02:03Z',
			),
		);
		$facade = $this->createMock( WebhookAssistanceFacade::class );
		$facade->expects( self::once() )->method( 'readiness' )->with( 'fixture-provider' )->willReturn(
			new AssistanceReadiness(
				array(),
				'https://example.test/webhook',
				array()
			)
		);
		$facade->expects( self::never() )->method( 'target' );
		$controls = new RepositoryWebhookManagementControls(
			$facade,
			$this->createMock( AdminInteractionFacade::class ),
			new ProviderRegistry( array( new CompleteWebhookManagementCapabilityProvider( 'fixture-provider', 'Fixture Forge' ) ) ),
			dirname( __DIR__, 3 ) . '/',
			'https://example.test/wp-content/plugins/ran-booster/',
			$this->package_authorities()
		);
		$controls->register();

		ob_start();
		$controls->render_repository_webhook_setup( 'fixture-provider', '1234', 'https://example.test/repository', false );
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'Webhook readiness', $html );
		self::assertStringContainsString( 'Published-release packages ignore pushes; no Branch package currently uses this repository webhook.', $html );
		self::assertStringContainsString( 'ran-booster-repository-webhook-readiness is-inactive', $html );
		self::assertStringContainsString( 'aria-disabled="true"', $html );
		self::assertStringContainsString( 'A signing secret was available when Booster last checked.', $html );
		self::assertStringContainsString( 'This site can receive webhook deliveries.', $html );
		self::assertStringContainsString( 'Configured at the last recorded check on 2026-08-20T01:02:03Z.', $html );
		self::assertStringContainsString( 'Webhook operations are unavailable while no eligible Branch package uses this repository.', $html );
		self::assertStringContainsString( 'class="ran-booster-readiness-panel ran-booster-repository-webhook-setup is-inactive"', $html );
		self::assertStringContainsString( 'aria-labelledby="ran-booster-repository-webhook-setup-heading" aria-disabled="true"', $html );
		self::assertStringContainsString( 'class="ran-booster-repository-webhook-management__panel"', $html );
		self::assertStringContainsString( 'name="booster_credential_id" disabled="disabled"', $html );
		self::assertStringContainsString( 'name="webhook_profile_id" disabled="disabled"', $html );
		self::assertStringContainsString( 'name="repository_webhook_management_operation" value="setup"', $html );
		self::assertStringContainsString( 'disabled="disabled" aria-disabled="true">Set up webhook</button>', $html );
		self::assertStringContainsString( 'disabled="disabled" aria-disabled="true">Test webhook</button>', $html );
		self::assertStringContainsString( 'Manage credentials</a>', $html );
		self::assertStringContainsString( 'Manage signing secrets</a>', $html );
	}

	public function test_package_webhook_disclosure_opens_for_an_operation_result_and_recovery_context(): void {
		$GLOBALS['ran_booster_repository_webhook_management_capabilities']['manage_options'] = true;
		$_GET    = array(
			'webhook_management_result' => 'operation_failed',
			'recovery_hook'             => '77',
			'recovery_profile'          => 'profile_123',
		);
		$package = $this->createMock( Package::class );
		$package->method( 'get_source' )->willReturn( PackageSource::BRANCH );
		$package->method( 'get_provider_code' )->willReturn( 'fixture-provider' );
		$package->method( 'get_provider_repository_id' )->willReturn( '1234' );
		$plugins = $this->createMock( PluginRepository::class );
		$plugins->expects( self::once() )->method( 'booster_plugin_from_file' )->willReturn( $package );
		$facade = $this->createMock( WebhookAssistanceFacade::class );
		$facade->expects( self::once() )->method( 'target' )->with( 'fixture-provider', '1234' )->willReturn(
			new AssistanceTarget(
				'fixture-provider',
				'1234',
				'owner/example',
				'Example',
				array( 'example/example.php' ),
				array(
					'automatic' => 0,
					'manual'    => 1,
					'disabled'  => 0,
				),
				'https://example.test/webhook'
			)
		);
		$facade->expects( self::once() )->method( 'credential_choices' )->with( 'fixture-provider' )->willReturn( array() );
		$controls = new RepositoryWebhookManagementControls(
			$facade,
			$this->createMock( AdminInteractionFacade::class ),
			new ProviderRegistry( array( new CompleteWebhookManagementCapabilityProvider( 'fixture-provider', 'Fixture Forge' ) ) ),
			dirname( __DIR__, 3 ) . '/',
			'https://example.test/wp-content/plugins/ran-booster/',
			new ManagedPackageWebhookAuthorityResolver( $plugins, $this->createMock( ThemeRepository::class ) )
		);
		$controls->register();

		ob_start();
		$controls->render_package_webhook_setup( 'edit', 'plugin', 'branch', new AdminPackageProjection( 'plugin', 'example/example.php', 'Example', 'fixture-provider', 'branch', 1, 'manual', 'https://example.test/settings' ), 'https://example.test/settings' );
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'data-ran-booster-package-webhook-setup open', $html );
		self::assertStringContainsString( 'could not confirm the operation outcome', $html );
		self::assertStringContainsString( 'returned recovery references', strtolower( $html ) );
	}

	public function test_repository_webhook_shell_keeps_its_child_zones_and_control_labels_across_active_and_inactive_states(): void {
		$method = new \ReflectionMethod( $this->controls(), 'render_repository_webhook_section' );
		$items  = array(
			array(
				'label'   => 'Branch demand',
				'message' => 'Known branch demand.',
				'state'   => 'is-pending',
			),
			array(
				'label'   => 'Signing profile',
				'message' => 'Known signing profile.',
				'state'   => 'is-pending',
			),
			array(
				'label'   => 'Provider receiver',
				'message' => 'Known receiver.',
				'state'   => 'is-ok',
			),
			array(
				'label'   => 'Remote hook',
				'message' => 'Known remote hook.',
				'state'   => 'is-pending',
			),
		);
		$panel  = '<div class="ran-booster-repository-webhook-management__panel"><button>Set up webhook</button><button>Test webhook</button></div>';

		ob_start();
		$method->invoke( $this->controls(), $items, $panel, true, array() );
		$active = (string) ob_get_clean();
		ob_start();
		$method->invoke(
			$this->controls(),
			$items,
			$panel,
			false,
			array(
				array(
					'class'   => 'notice-warning',
					'message' => 'Branch deployments are inactive.',
				),
			)
		);
		$inactive = (string) ob_get_clean();

		self::assertSame( $this->repository_webhook_shell_structure( $active ), $this->repository_webhook_shell_structure( $inactive ) );
		foreach ( array( 'Push-to-deploy', 'Repository webhook lifecycle', 'Webhook readiness', 'Webhook setup', 'Set up webhook', 'Test webhook' ) as $label ) {
			self::assertStringContainsString( $label, $active );
			self::assertStringContainsString( $label, $inactive );
		}
	}

	public function test_webhook_control_template_keeps_credential_secret_and_operation_identities_across_record_states(): void {
		$method = new \ReflectionMethod( $this->controls(), 'render_repository_webhook_panel_model' );
		$states = array(
			'unconfigured'       => $this->webhook_panel_model(
				false,
				false,
				array( 'setup' => false )
			),
			'configured_healthy' => $this->webhook_panel_model(
				true,
				false,
				array(
					'check'  => false,
					'test'   => false,
					'remove' => false,
				)
			),
			'drift'              => $this->webhook_panel_model(
				true,
				false,
				array(
					'check'       => false,
					'reconfigure' => false,
					'test'        => false,
					'remove'      => false,
				)
			),
			'inactive'           => $this->webhook_panel_model( true, true, array() ),
		);

		foreach ( $states as $state => $model ) {
			$html = (string) $method->invoke( $this->controls(), $model );
			self::assertSame( 1, substr_count( $html, 'name="booster_credential_id"' ), $state );
			self::assertSame( 1, substr_count( $html, 'name="webhook_profile_id"' ), $state );
			foreach ( array( 'Set up webhook', 'Check webhook', 'Update webhook', 'Test webhook', 'Remove webhook' ) as $label ) {
				self::assertStringContainsString( $label, $html, $state );
			}
		}
	}

	public function test_repository_webhook_setup_region_remains_labelled_when_the_target_is_unavailable(): void {
		$GLOBALS['ran_booster_repository_webhook_management_capabilities']['manage_options'] = true;
		$facade = $this->createMock( WebhookAssistanceFacade::class );
		$facade->expects( self::exactly( 2 ) )->method( 'readiness' )->with( 'fixture-provider' )->willReturn( new AssistanceReadiness( array( 'callback_requires_public_https' ), 'http://localhost:10008/webhook', array() ) );
		$facade->expects( self::once() )->method( 'target' )->with( 'fixture-provider', '1315521150' )->willReturn( null );
		$controls = new RepositoryWebhookManagementControls(
			$facade,
			$this->createMock( AdminInteractionFacade::class ),
			new ProviderRegistry( array( new CompleteWebhookManagementCapabilityProvider( 'fixture-provider', 'Fixture Forge' ) ) ),
			dirname( __DIR__, 3 ) . '/',
			'https://example.test/wp-content/plugins/ran-booster/',
			$this->package_authorities()
		);
		$controls->register();

		ob_start();
		$controls->render_repository_webhook_setup( 'fixture-provider', '1315521150', 'https://example.test/repository' );
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'Webhook readiness', $html );
		self::assertStringContainsString( 'Webhook setup', $html );
		self::assertStringContainsString( 'This site needs a public HTTPS URL before a provider can deliver webhooks. Local receivers cannot be reached remotely.', $html );
		self::assertLessThan(
			strpos( $html, 'Webhook readiness' ),
			strpos( $html, 'This site needs a public HTTPS URL before a provider can deliver webhooks. Local receivers cannot be reached remotely.' )
		);
		self::assertSame( 1, substr_count( $html, 'This site needs a public HTTPS URL before a provider can deliver webhooks. Local receivers cannot be reached remotely.' ) );
		self::assertStringNotContainsString( 'Bundled webhook management', $html );
		self::assertStringContainsString( 'name="booster_credential_id" disabled="disabled"', $html );
		self::assertStringContainsString( 'name="webhook_profile_id" disabled="disabled"', $html );
		self::assertStringNotContainsString( 'request_credential', $html );
		self::assertStringContainsString( 'Create a repository signing secret', $html );
		self::assertStringContainsString( 'name="repository_webhook_management_operation" value="setup"', $html );
		self::assertStringContainsString( 'disabled="disabled" aria-disabled="true">Set up webhook</button>', $html );
		self::assertStringContainsString( 'href="https://example.test/wp-admin/admin.php?page=ran-booster&amp;tab=fixture-provider&amp;view=credentials">Manage credentials</a>', $html );
		self::assertStringContainsString( 'href="https://example.test/wp-admin/admin.php?page=ran-booster&amp;tab=fixture-provider&amp;view=secrets">Manage signing secrets</a>', $html );
		self::assertDoesNotMatchRegularExpression( '/<form[^>]*ran-booster-repository-webhook-management__form[^>]*aria-disabled="true"/', $html );
		self::assertStringNotContainsString( 'Manage credentials</a> disabled', $html );
		self::assertStringNotContainsString( 'Manage signing secrets</a> disabled', $html );
	}

	public function test_partial_provider_keeps_release_row_history_local_and_inert(): void {
		$provider = new FitnessOnlyWebhookManagementCapabilityProvider( 'fitness-only', 'Fitness only' );
		$GLOBALS['ran_booster_repository_webhook_management_test_options']['ran_booster_assisted_hooks_installations'] = array(
			'fitness-only:1234' => array(
				'schema_version'              => 4,
				'provider_code'               => 'fitness-only',
				'repository_id'               => '1234',
				'repository'                  => 'owner/example',
				'hook_id'                     => '77',
				'management_credential_id'    => 'credential_1',
				'webhook_profile_id'          => 'wh_0123456789abcdef01234567',
				'webhook_profile_scope'       => 'repository',
				'webhook_profile_revision'    => 1,
				'webhook_profile_disposition' => 'created',
				'endpoint'                    => 'https://hooks.example.test/webhook',
				'status'                      => 'needs_verification',
				'created_at'                  => '2026-08-20T01:02:03Z',
				'checked_at'                  => '2026-08-20T01:02:03Z',
			),
		);
		$controls = $this->controls( $provider );
		$controls->register();
		$rows = array(
			'1234' => array(
				'source_key'    => 'release_asset',
				'repository_id' => '1234',
				'details'       => array(),
				'actions'       => array(),
			),
		);

		$result = $controls->enrich_repository_rows( $rows, 'fitness-only', array(), 'https://example.test/' );

		self::assertSame( array( 'Recorded hook status', 'Observation', 'Management credential', 'Recorded signing secret', 'Last checked' ), array_column( $result['1234']['details'], 'label' ) );
		self::assertSame( 'Needs attention: Needs Verification at last check', $result['1234']['details'][0]['value'] );
		self::assertSame( array(), $result['1234']['actions'] );
		self::assertSame( 0, $provider->provider_operation_calls );
	}

	private function controls( WebhookManagementCapabilityProvider ...$providers ): RepositoryWebhookManagementControls {
		$facade = $this->createMock( WebhookAssistanceFacade::class );
		$facade->expects( self::never() )->method( 'readiness' );
		$facade->expects( self::never() )->method( 'target' );
		$facade->expects( self::never() )->method( 'credential_choices' );
		$facade->expects( self::never() )->method( 'profile' );

		return new RepositoryWebhookManagementControls(
			$facade,
			$this->createMock( AdminInteractionFacade::class ),
			new ProviderRegistry( $providers ),
			dirname( __DIR__, 3 ) . '/',
			'https://example.test/wp-content/plugins/ran-booster/',
			$this->package_authorities()
		);
	}

	private function package_authorities(): ManagedPackageWebhookAuthorityResolver {
		return new ManagedPackageWebhookAuthorityResolver(
			$this->createMock( PluginRepository::class ),
			$this->createMock( ThemeRepository::class )
		);
	}

	/** @return list<string> */
	private function repository_webhook_shell_structure( string $html ): array {
		$zones     = array(
			'ran-booster-settings-section__header',
			'ran-booster-repository-webhook-management__notices',
			'ran-booster-repository-webhook-lifecycle',
			'ran-booster-repository-webhook-readiness',
			'ran-booster-repository-webhook-setup',
		);
		$positions = array();
		foreach ( $zones as $zone ) {
			$position = strpos( $html, $zone );
			self::assertIsInt( $position, $zone );
			$positions[ $position ] = $zone;
		}
		ksort( $positions );

		return array_values( $positions );
	}

	/** @param array<string,bool> $enabled_operations @return array<string,mixed> */
	private function webhook_panel_model( bool $recorded, bool $disabled, array $enabled_operations ): array {
		$operations = array();
		$labels     = array(
			'setup'       => 'Set up webhook',
			'check'       => 'Check webhook',
			'reconfigure' => 'Update webhook',
			'test'        => 'Test webhook',
			'remove'      => 'Remove webhook',
		);
		foreach ( $labels as $key => $label ) {
			$operations[] = array(
				'key'      => $key,
				'label'    => $label,
				'url'      => 'https://example.test/' . $key,
				'primary'  => 'setup' === $key,
				'disabled' => ! ( $enabled_operations[ $key ] ?? true ),
			);
		}

		return array(
			'disabled'                    => $disabled,
			'webhook_profile_disabled'    => $recorded || $disabled,
			'webhook_profile_placeholder' => $recorded ? 'Recorded signing secret' : 'Choose a signing secret',
			'form_action'                 => 'https://example.test/wp-admin/admin-post.php',
			'admin_action'                => 'ran_booster_repository_webhook_management_operation',
			'provider_code'               => 'fixture-provider',
			'provider_label'              => 'Fixture Forge',
			'repository_id'               => '1234',
			'return_url'                  => 'https://example.test/repository',
			'interaction_request'         => null,
			'result'                      => null,
			'recovery_warning'            => null,
			'management_credential_id'    => $recorded ? 'credential-1' : null,
			'credential_choices'          => array(
				array(
					'id'    => 'credential-1',
					'label' => 'Credential',
				),
			),
			'webhook_profile_choices'     => array(),
			'credentials_url'             => 'https://example.test/credentials',
			'secrets_url'                 => 'https://example.test/secrets',
			'operations'                  => $operations,
			'action_help'                 => null,
			'webhooks_url'                => null,
		);
	}
}
