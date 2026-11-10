<?php

declare( strict_types = 1 );

namespace RAN\Tests\Admin\WebhookManagement;

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
use RAN\Tests\Support\AbsentWebhookManagementCapabilityProvider;
use RAN\Tests\Support\CompleteWebhookManagementCapabilityProvider;
use RAN\Tests\Support\FitnessOnlyWebhookManagementCapabilityProvider;
use RAN\Tests\Support\ManagementOnlyWebhookManagementCapabilityProvider;
use RAN\Tests\Support\UnnormalizedWebhookManagementCapabilityProvider;
use RAN\Tests\Support\WebhookManagementCapabilityProvider;

require_once __DIR__ . '/RepositoryWebhookManagementControlsWordPressFunctions.php';
require_once __DIR__ . '/WordPressInstallationStoreWordPressFunctions.php';
require_once dirname( __DIR__, 2 ) . '/Support/WebhookManagementCapabilityProviders.php';
require_once dirname( __DIR__, 2 ) . '/Support/PackageViewWordPressFunctions.php';

final class RepositoryWebhookManagementControlsTest extends TestCase {
	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit lifecycle override requires this method name.
	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this exact lifecycle override name.
	protected function setUp(): void {
		if ( ! defined( 'ABSPATH' ) ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Exercise the WordPress-owned constant without changing its runtime identity.
			define( 'ABSPATH', __DIR__ . '/' );
		}
		$GLOBALS['ran_booster_repository_webhook_management_actions']      = array();
		$GLOBALS['ran_booster_repository_webhook_management_filters']      = array();
		$GLOBALS['ran_booster_repository_webhook_management_styles']       = array();
		$GLOBALS['ran_booster_repository_webhook_management_capabilities'] = array();
		$GLOBALS['ran_booster_repository_webhook_management_test_options'] = array();
		$_GET = array();
	}

	public function test_missing_package_authority_is_rejected_at_the_controls_constructor(): void {
		$this->expectException( \ArgumentCountError::class );
		$this->expectExceptionMessage( RepositoryWebhookManagementControls::class . '::__construct()' );

		// @phpstan-ignore arguments.count (Deliberately omit the required authority to prove PHP rejects this unsupported constructor call.)
		new RepositoryWebhookManagementControls(
			$this->createMock( WebhookAssistanceFacade::class ),
			$this->createMock( AdminInteractionFacade::class ),
			new ProviderRegistry( array() ),
			'/unused/',
			'https://example.test/unused/'
		);
	}

	public function test_null_package_authority_is_rejected_before_internal_controller_composition(): void {
		$this->expectException( \TypeError::class );
		$this->expectExceptionMessage( RepositoryWebhookManagementControls::class . '::__construct(): Argument #6 ($authorities)' );

		new RepositoryWebhookManagementControls(
			$this->createMock( WebhookAssistanceFacade::class ),
			$this->createMock( AdminInteractionFacade::class ),
			new ProviderRegistry( array() ),
			'/unused/',
			'https://example.test/unused/',
			null // @phpstan-ignore argument.type (Deliberately pass null to prove the required authority cannot be bypassed.)
		);
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

		self::assertSame( 1, substr_count( $html, 'data-ran-booster-card="provider"' ) );
		self::assertStringContainsString( 'Fitness only webhook configuration is incomplete.', $html );
		self::assertStringContainsString( 'Branch packages can still be updated manually', $html );
		// Without a setup form there is nothing to disclose; the notice and the steps say why.
		self::assertStringNotContainsString( 'ran-booster-repository-webhook-setup', $html );
		self::assertStringNotContainsString( '<details', $html );
		self::assertStringContainsString( 'ran-booster-repository-webhook-lifecycle is-inactive', $html );
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
		$facade->expects( self::exactly( 2 ) )->method( 'target' )->with( 'fixture-provider', '1234' )->willReturn(
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
		$facade->expects( self::exactly( 2 ) )->method( 'credential_choices' )->with( 'fixture-provider' )->willReturn(
			array(
				array(
					'id'    => 'credential-1',
					'label' => '<script>Repository access</script>',
					'kind'  => 'token',
				),
			)
		);
		$controls = new RepositoryWebhookManagementControls( $facade, $this->createMock( AdminInteractionFacade::class ), new ProviderRegistry( array( new CompleteWebhookManagementCapabilityProvider( 'fixture-provider', 'Fixture Forge' ) ) ), dirname( __DIR__, 3 ) . '/', 'https://example.test/wp-content/plugins/ran-booster/', $this->package_authorities() );
		$controls->register();

		ob_start();
		$rendered_url = $controls->render_repository_webhook_setup( 'fixture-provider', '1234', 'https://example.test/repository' );
		$html         = (string) ob_get_clean();
		self::assertSame( 1, substr_count( $html, 'data-ran-booster-card="provider"' ) );
		self::assertStringContainsString( '&lt;script&gt;Repository access&lt;/script&gt;', $html );
		self::assertStringNotContainsString( '<script>Repository access</script>', $html );

		self::assertStringContainsString( '<h3 id="ran-booster-repository-webhook-heading">Branch publishing</h3>', $html );
		self::assertStringContainsString( 'ran-booster-repository-publishing__subheading">Push-to-deploy', $html );
		self::assertStringContainsString( 'Webhook setup', $html );
		self::assertStringContainsString( 'class="ran-booster-settings-section ran-booster-repository-webhook-section ran-booster-card" data-ran-booster-card="provider"', $html );
		self::assertSame( 1, substr_count( $html, 'class="ran-booster-settings-section ran-booster-repository-webhook-section ran-booster-card" data-ran-booster-card="provider"' ) );
		// Webhook setup is the project-standard disclosure, open because the site receiver step is ok.
		self::assertSame( 1, substr_count( $html, '<div data-ran-booster-ui-disclosure="repository-webhook-setup" data-ran-booster-ui-disclosure-density="compact">' ) );
		self::assertSame( 1, preg_match( '/<details id="ran-booster-repository-webhook-setup" data-ran-booster-ui-disclosure-fallback class="ran-booster-repository-webhook-setup ran-booster-disclosure" open>\s*<summary>Webhook setup<\/summary>\s*<div data-ran-booster-ui-disclosure-content class="ran-booster-repository-webhook-setup__body ran-booster-disclosure__body">\s*<p>Sets up this repository’s webhook\. Automatic updates remain configured separately for each package\.<\/p>/', $html ) );
		self::assertStringNotContainsString( 'ran-booster-readiness-panel', $html );
		// The only sub-heading is the Push-to-deploy tier title.
		self::assertSame( 1, substr_count( $html, '<h4' ) );
		self::assertStringNotContainsString( 'What this changes', $html );
		self::assertStringNotContainsString( 'Booster checks the repository before making changes and never adopts or deletes an unverified webhook.', $html );
		self::assertStringContainsString( 'class="ran-booster-repository-webhook-setup__content"', $html );
		// The refreshed region stays inside the disclosure content.
		self::assertLessThan( strpos( $html, 'class="ran-booster-repository-webhook-setup__content"' ), strpos( $html, 'data-ran-booster-ui-disclosure-content' ) );
		self::assertStringContainsString( 'class="ran-booster-repository-webhook-management__panel"', $html );
		self::assertStringNotContainsString( 'ran-booster-panel ran-booster-push-deploy__panel', $html );
		self::assertStringContainsString( 'class="ran-booster-settings-section__body"', $html );
		self::assertSame( 1, substr_count( $html, '<details' ) );
		self::assertStringContainsString( 'name="action" value="ran_booster_repository_webhook_management_operation"', $html );
		self::assertStringContainsString( 'name="repository_webhook_management_operation" value="setup"', $html );
		self::assertStringContainsString( '<a href="https://fixture-provider.example.test/owner%2Fexample/settings/hooks" target="_blank" rel="noopener noreferrer">', $html );
		self::assertStringContainsString( '<span aria-hidden="true">↗</span>', $html );
		self::assertStringContainsString( '<span class="screen-reader-text"> (opens in a new tab)</span>', $html );
		self::assertStringContainsString( 'Manage repo webhooks on Fixture Forge', $html );
		self::assertStringContainsString( 'class="ran-booster-webhook-steps ran-booster-repository-webhook-lifecycle"', $html );
		self::assertStringContainsString( '<strong>Site receiver</strong>', $html );
		self::assertStringContainsString( '<strong>Signing secret</strong>', $html );
		self::assertStringContainsString( '<strong>Repository webhook</strong>', $html );
		self::assertStringContainsString( '<strong>Automatic packages</strong>', $html );
		// Each step's marker is an empty aria-hidden span; its state glyph is drawn by CSS from the state class.
		self::assertSame( 4, preg_match_all( '/<span aria-hidden="true"><\/span>\s*<strong>/', $html ) );
		self::assertStringContainsString( 'ran-booster-webhook-step is-ok', $html );
		// The four steps carry the data; there is no second readiness list repeating them.
		self::assertStringNotContainsString( 'Webhook readiness', $html );
		self::assertStringNotContainsString( 'ran-booster-readiness-list', $html );
		self::assertSame( 1, substr_count( $html, '1 Automatic Branch package uses this repository webhook.' ) );
		self::assertSame( 1, substr_count( $html, 'A signing secret is ready for this repository.' ) );
		self::assertSame( 1, substr_count( $html, 'This site can receive webhook deliveries.' ) );
		self::assertStringNotContainsString( 'Bundled webhook management', $html );
		self::assertStringContainsString( 'Create a repository signing secret', $html );
		self::assertStringNotContainsString( 'request_credential', $html );
		self::assertStringContainsString( 'href="https://example.test/wp-admin/admin.php?page=ran-booster&amp;tab=fixture-provider&amp;view=credentials">Manage credentials</a>', $html );
		self::assertStringContainsString( 'href="https://example.test/wp-admin/admin.php?page=ran-booster&amp;tab=fixture-provider&amp;view=secrets">Manage signing secrets</a>', $html );
		self::assertSame( 2, substr_count( $html, 'class="ran-booster-repository-webhook-management__select-action"' ) );
		self::assertStringNotContainsString( 'hx-post=', $html );
		self::assertStringNotContainsString( 'hx-target=', $html );

		$url      = 'https://fixture-provider.example.test/owner%2Fexample/settings/hooks';
		$document = new \DOMDocument();
		$document->loadHTML( $html, LIBXML_NOERROR | LIBXML_NOWARNING );
		$xpath = new \DOMXPath( $document );
		$links = $xpath->query( '//a[@href="' . $url . '"]' );
		self::assertNotFalse( $links );
		self::assertCount( 1, $links );
		$link = $links->item( 0 );
		self::assertInstanceOf( \DOMElement::class, $link );
		self::assertSame( $url, $rendered_url );
		self::assertSame( 'Test webhook is disabled until Booster has an exact recorded hook for this repository.', $xpath->evaluate( 'normalize-space(../preceding-sibling::*[1])', $link ) );
		self::assertSame( 0.0, $xpath->evaluate( 'count(ancestor::div[contains(@class,"__actions")])', $link ) );
		self::assertSame( 5.0, $xpath->evaluate( 'count(//button[@name="repository_webhook_management_operation"])' ) );

		ob_start();
		self::assertTrue( $controls->render_repository_panel( 'fixture-provider', '1234', 'https://example.test/repository' ) );
		$standalone = (string) ob_get_clean();
		self::assertSame( 1, substr_count( $standalone, 'href="' . $url . '"' ) );
		self::assertStringContainsString( 'Manage repo webhooks on Fixture Forge <span aria-hidden="true">↗</span>', $standalone );
		$model                             = $this->webhook_panel_model( false, false, array() );
		$model['management_credential_id'] = 'credential-1';
		$model['provider_label']           = 'GitHub';
		$model['webhooks_url']             = $url;
		$form_attributes                   = '';
		ob_start();
		require dirname( __DIR__, 3 ) . '/RAN/Admin/WebhookManagement/views/panel.php';
		$no_help = (string) ob_get_clean();
		self::assertStringNotContainsString( 'Test webhook is disabled until', $no_help );
		self::assertStringContainsString( 'Manage repo webhooks on Github <span aria-hidden="true">↗</span>', $no_help );
		$document->loadHTML( $no_help, LIBXML_NOERROR | LIBXML_NOWARNING );
		$xpath = new \DOMXPath( $document );
		self::assertSame( 1.0, $xpath->evaluate( 'count(//form/p/a[@target="_blank"])' ) );
		self::assertSame( 1.0, $xpath->evaluate( 'count(//form/p[a]/preceding-sibling::*[1][self::div][contains(@class,"__actions")])' ) );
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

		self::assertStringNotContainsString( 'Webhook readiness', $html );
		self::assertSame( 1, substr_count( $html, 'Published-release packages ignore pushes; no Branch package currently uses this repository webhook.' ) );
		self::assertStringContainsString( 'ran-booster-repository-webhook-lifecycle is-inactive', $html );
		self::assertStringContainsString( 'aria-disabled="true"', $html );
		self::assertStringContainsString( 'A signing secret was available when Booster last checked.', $html );
		self::assertStringContainsString( 'This site can receive webhook deliveries.', $html );
		self::assertStringContainsString( 'Configured at the last recorded check on 2026-08-20T01:02:03Z.', $html );
		self::assertSame( 1, substr_count( $html, 'data-ran-booster-card="provider"' ) );
		self::assertStringContainsString( 'Webhook operations are unavailable while no eligible Branch package uses this repository.', $html );
		self::assertStringContainsString( 'class="ran-booster-repository-webhook-setup ran-booster-disclosure is-inactive" open>', $html );
		self::assertStringContainsString( 'class="ran-booster-repository-webhook-setup__body ran-booster-disclosure__body" aria-disabled="true"', $html );
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
				'label'   => 'Git provider receiver',
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
		foreach ( array( 'Push-to-deploy', 'Repository webhook lifecycle', 'Webhook setup', 'Set up webhook', 'Test webhook' ) as $label ) {
			self::assertStringContainsString( $label, $active );
			self::assertStringContainsString( $label, $inactive );
		}
	}

	/**
	 * Steps carry the state; setup is a standard disclosure that opens with the receiver step,
	 * and always for an operation result or validation error.
	 */
	public function test_branch_steps_carry_each_state_and_setup_disclosure_follows_the_receiver_or_an_operation_notice(): void {
		$method = new \ReflectionMethod( $this->controls(), 'render_repository_webhook_section' );
		$panel  = '<div class="ran-booster-repository-webhook-management__panel"><button>Set up webhook</button></div>';
		// Case order is the data order: demand, signing profile, receiver, remote hook; steps show receiver, signing, webhook, packages.
		$cases = array(
			'all ok'                  => array( 'is-ok', 'is-ok', 'is-ok', 'is-ok' ),
			'receiver warning'        => array( 'is-pending', 'is-ok', 'is-warning', 'is-pending' ),
			'receiver pending'        => array( 'is-pending', 'is-pending', 'is-pending', 'is-pending' ),
			'signing pending'         => array( 'is-ok', 'is-pending', 'is-ok', 'is-ok' ),
			'signing warning'         => array( 'is-pending', 'is-warning', 'is-ok', 'is-pending' ),
			'webhook ok, packages ok' => array( 'is-ok', 'is-ok', 'is-ok', 'is-ok' ),
			'webhook warning'         => array( 'is-ok', 'is-ok', 'is-ok', 'is-warning' ),
			'webhook pending'         => array( 'is-ok', 'is-ok', 'is-ok', 'is-pending' ),
			'packages pending'        => array( 'is-pending', 'is-ok', 'is-ok', 'is-ok' ),
		);
		foreach ( $cases as $name => $states ) {
			$demand   = $states[0];
			$signing  = $states[1];
			$receiver = $states[2];
			$hook     = $states[3];
			$items    = array(
				array(
					'label'   => 'Branch demand',
					'message' => 'Demand ' . $demand,
					'state'   => $demand,
				),
				array(
					'label'   => 'Signing profile',
					'message' => 'Signing ' . $signing,
					'state'   => $signing,
				),
				array(
					'label'   => 'Git provider receiver',
					'message' => 'Receiver ' . $receiver,
					'state'   => $receiver,
				),
				array(
					'label'   => 'Remote hook',
					'message' => 'Hook ' . $hook,
					'state'   => $hook,
				),
			);
			foreach ( array( false, true ) as $operation_notice ) {
				ob_start();
				$method->invoke(
					$this->controls(),
					$items,
					$panel,
					true,
					$operation_notice ? array(
						array(
							'class'   => 'notice-success',
							'message' => 'Operation done.',
						),
					) : array(),
					true,
					$operation_notice
				);
				$html     = (string) ob_get_clean();
				$document = new \DOMDocument();
				$document->loadHTML( $html, LIBXML_NOERROR | LIBXML_NOWARNING );
				$xpath = new \DOMXPath( $document );

				$classes = array();
				$nodes   = $xpath->query( '//ol[contains(@class,"ran-booster-webhook-steps")]/li' );
				self::assertNotFalse( $nodes );
				foreach ( $nodes as $node ) {
					self::assertInstanceOf( \DOMElement::class, $node );
					$classes[] = trim( str_replace( 'ran-booster-webhook-step', '', $node->getAttribute( 'class' ) ) );
				}
				self::assertSame( array( $receiver, $signing, $hook, $demand ), $classes, $name );
				self::assertSame( 0.0, $xpath->evaluate( 'count(//*[contains(@class,"ran-booster-readiness-list")])' ), $name );
				self::assertSame( 1.0, $xpath->evaluate( 'count(//details[@id="ran-booster-repository-webhook-setup"])' ), $name );
				self::assertSame( 1.0, $xpath->evaluate( 'count(//details/summary[normalize-space()="Webhook setup"])' ), $name );
				self::assertSame( 'is-ok' === $receiver || $operation_notice, 1.0 === (float) $xpath->evaluate( 'count(//details[@id="ran-booster-repository-webhook-setup"][@open])' ), $name );
				// The setup region sits inside the disclosure content.
				self::assertSame( 1.0, $xpath->evaluate( 'count(//details//div[@data-ran-booster-ui-disclosure-content]//div[contains(@class,"ran-booster-repository-webhook-setup__content")])' ), $name );
				if ( $operation_notice ) {
					// The operation result keeps its place above the steps.
					self::assertLessThan( strpos( $html, 'ran-booster-webhook-steps' ), strpos( $html, 'Operation done.' ), $name );
				} else {
					self::assertStringNotContainsString( 'ran-booster-repository-webhook-management__notices', $html, $name );
				}
			}
		}

		// Blocked: no eligible Branch package keeps the steps and setup, both marked inactive.
		ob_start();
		$method->invoke(
			$this->controls(),
			array(
				array(
					'label'   => 'Branch demand',
					'message' => 'a',
					'state'   => 'is-pending',
				),
				array(
					'label'   => 'Signing profile',
					'message' => 'b',
					'state'   => 'is-ok',
				),
				array(
					'label'   => 'Git provider receiver',
					'message' => 'c',
					'state'   => 'is-ok',
				),
				array(
					'label'   => 'Remote hook',
					'message' => 'd',
					'state'   => 'is-pending',
				),
			),
			$panel,
			false,
			array()
		);
		$blocked = (string) ob_get_clean();
		self::assertStringContainsString( 'ran-booster-repository-webhook-lifecycle is-inactive', $blocked );
		self::assertStringContainsString( 'ran-booster-repository-webhook-setup ran-booster-disclosure is-inactive', $blocked );
	}

	/**
	 * @param list<string> $states Demand, signing profile, receiver, remote hook states.
	 * @return list<array{label:string,message:string,state:string}>
	 */
	private function branch_items( array $states ): array {
		$items = array();
		foreach ( array( 'Branch demand', 'Signing profile', 'Git provider receiver', 'Remote hook' ) as $index => $label ) {
			$items[] = array(
				'label'   => $label,
				'message' => $label . ' text',
				'state'   => $states[ $index ],
			);
		}

		return $items;
	}

	/** @param array<array-key,mixed> $row */
	private function render_branch_section( array $states, bool $in_use, array $row ): string {
		$method = new \ReflectionMethod( $this->controls(), 'render_repository_webhook_section' );
		ob_start();
		$method->invoke( $this->controls(), $this->branch_items( $states ), '<div>panel</div>', $in_use, array(), true, false, $row );

		return (string) ob_get_clean();
	}

	/**
	 * Delivery badge: Active when a package uses Branch. Automation badge, from the step states
	 * (demand, signing, receiver, hook): not in use is neutral Not in use; receiver or signing not ok, or a
	 * warning hook, is amber Not ready; hook and automatic packages ok is green Active; otherwise neutral Ready.
	 */
	public function test_branch_header_and_push_to_deploy_tier_badges_follow_usage_and_step_states(): void {
		$row   = array(
			'package_summaries' => array(
				array(
					'source'       => 'branch',
					'type'         => 'plugin',
					'settings_url' => 'https://example.test/plugin?a=1&b=2',
				),
			),
		);
		$cases = array(
			'receiver warning'  => array( array( 'is-ok', 'is-ok', 'is-warning', 'is-ok' ), 'Not ready', 'medium' ),
			'receiver pending'  => array( array( 'is-ok', 'is-ok', 'is-pending', 'is-ok' ), 'Not ready', 'medium' ),
			'signing pending'   => array( array( 'is-ok', 'is-pending', 'is-ok', 'is-ok' ), 'Not ready', 'medium' ),
			'hook warning'      => array( array( 'is-ok', 'is-ok', 'is-ok', 'is-warning' ), 'Not ready', 'medium' ),
			'hook pending'      => array( array( 'is-pending', 'is-ok', 'is-ok', 'is-pending' ), 'Ready', 'none' ),
			'packages pending'  => array( array( 'is-pending', 'is-ok', 'is-ok', 'is-ok' ), 'Ready', 'none' ),
			'hook and packages' => array( array( 'is-ok', 'is-ok', 'is-ok', 'is-ok' ), 'Active', 'stable' ),
		);
		foreach ( $cases as $name => $case ) {
			$html = $this->render_branch_section( $case[0], true, $row );
			if ( 1 !== preg_match( '/<h3 id="ran-booster-repository-webhook-heading">Branch publishing<\/h3>(<span[^>]*>[^<]*<\/span>)<\/div><a href="([^"]+)">Plugin settings<\/a><\/header>/', $html, $m ) ) {
				self::fail( $name . ': header must be title, delivery badge, then the settings link.' );
			}
			self::assertStringContainsString( 'is-stable-intent', $m[1], $name );
			self::assertStringContainsString( 'Active', $m[1], $name );
			self::assertSame( 'https://example.test/plugin?a=1&amp;b=2', $m[2], $name );
			self::assertStringContainsString( 'Manual updates work without setup.', $html, $name );
			if ( 1 !== preg_match( '/<h4 id="ran-booster-repository-webhook-automation-heading"[^>]*>Push-to-deploy<span[^>]*class="[^"]*is-' . $case[2] . '-intent[^"]*"[^>]*>' . $case[1] . '<\/span><\/h4>/', $html ) ) {
				self::fail( $name . ': automation badge must read ' . $case[1] . ' (' . $case[2] . ').' );
			}
			self::assertLessThan( strpos( $html, 'ran-booster-webhook-steps' ), strpos( $html, 'ran-booster-repository-publishing__subheading' ), $name );
			self::assertLessThan( strpos( $html, 'ran-booster-repository-publishing__subheading' ), strpos( $html, 'Manual updates work without setup.' ), $name );
			self::assertStringNotContainsString( 'is-neutral"', (string) strstr( $html, '<ol' ), $name );
		}

		// Not in use: neutral delivery and automation badges, neutral steps with their text, no manual-update line, no settings link for several packages.
		$many = $this->render_branch_section( array( 'is-pending', 'is-warning', 'is-warning', 'is-pending' ), false, array( 'package_summaries' => array( array( 'source' => 'release_asset' ), array( 'source' => 'release_asset' ) ) ) );
		self::assertSame( 1, preg_match( '/Branch publishing<\/h3><span[^>]*is-none-intent[^>]*>Not in use<\/span>/', $many ) );
		self::assertSame( 1, preg_match( '/Push-to-deploy<span[^>]*is-none-intent[^>]*>Not in use<\/span><\/h4>/', $many ) );
		self::assertStringNotContainsString( 'Manual updates work without setup.', $many );
		self::assertStringNotContainsString( 'Plugin settings', $many );
		self::assertStringNotContainsString( 'Theme settings', $many );
		self::assertSame( 4, substr_count( $many, 'ran-booster-webhook-step is-neutral' ) );
		self::assertSame( 0, preg_match( '/ran-booster-webhook-step is-(?:ok|warning|pending)/', $many ) );
		self::assertStringContainsString( 'Git provider receiver text', $many );
	}

	public function test_branch_in_use_follows_package_summaries_and_the_theme_link_is_labelled(): void {
		$theme = $this->render_branch_section(
			array( 'is-ok', 'is-ok', 'is-ok', 'is-ok' ),
			false,
			array(
				'package_summaries' => array(
					array(
						'source'       => 'branch',
						'type'         => 'theme',
						'settings_url' => 'https://example.test/theme',
					),
				),
			)
		);
		// Summaries win over the flag; one package with a settings URL gets its link, labelled by type.
		self::assertSame( 1, preg_match( '/Branch publishing<\/h3><span[^>]*is-stable-intent[^>]*>Active<\/span><\/div><a href="https:\/\/example.test\/theme">Theme settings<\/a>/', $theme ) );
		// Mixed repository: a Branch package keeps Branch Active beside a Release package.
		$mixed = $this->render_branch_section( array( 'is-ok', 'is-ok', 'is-ok', 'is-ok' ), true, array( 'package_summaries' => array( array( 'source' => 'branch' ), array( 'source' => 'release_asset' ) ) ) );
		self::assertSame( 1, preg_match( '/Branch publishing<\/h3><span[^>]*is-stable-intent[^>]*>Active<\/span>/', $mixed ) );
		self::assertStringNotContainsString( 'Plugin settings', $mixed );
	}

	public function test_a_different_unavailable_reason_still_gets_its_notice_beside_the_receiver_step(): void {
		$GLOBALS['ran_booster_repository_webhook_management_capabilities']['manage_options'] = true;
		$facade = $this->createMock( WebhookAssistanceFacade::class );
		$facade->method( 'readiness' )->willReturn( new AssistanceReadiness( array( 'database_unavailable' ), 'http://localhost:10008/webhook', array() ) );
		$facade->method( 'target' )->willReturn( null );
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

		self::assertSame( 1, substr_count( $html, 'Webhook setup is unavailable because Booster database storage is unavailable.' ) );
		self::assertStringContainsString( 'ran-booster-repository-webhook-management__notices', $html );
		// An availability notice is not an operation result: the receiver step is not ok, so setup stays collapsed.
		self::assertSame( 1, preg_match( '/<details id="ran-booster-repository-webhook-setup"[^>]*ran-booster-disclosure">/', $html ) );
	}

	public function test_structured_results_are_escaped_and_rendered_in_both_surfaces(): void {
		$display      = ( new \ReflectionClass( \RAN\Admin\WebhookManagement\Display\WebhookDisplayModel::class ) )->newInstanceWithoutConstructor();
		$presentation = new \ReflectionMethod( $display, 'result_presentation' );
		$panel        = new \ReflectionMethod( $this->controls(), 'render_repository_webhook_panel_model' );
		$section      = new \ReflectionMethod( $this->controls(), 'render_repository_webhook_section' );
		foreach ( array( 'configured_pending_delivery', 'verified', 'ping_requested', 'ping_verified', 'ping_delivery_failed', 'removed', 'orphaned', 'manual_recovery_required', 'recovery_record_failed', 'record_conflict', 'record_update_failed' ) as $code ) {
			$result = $presentation->invoke(
				$display,
				$code,
				array(
					'hook_id'    => '<hook>',
					'profile_id' => '<profile>',
				),
				null
			);
			self::assertNotEmpty( $result['heading'], $code );
			if ( in_array( $code, array( 'verified', 'ping_requested', 'ping_verified' ), true ) ) {
				self::assertStringContainsString( 'signed delivery unverified', $result['heading'] );
			}
			if ( 'removed' === $code ) {
				self::assertArrayNotHasKey( 'details', $result );
			}
			$model                     = $this->webhook_panel_model( false, false, array() );
			$model['result']           = $result;
			$warning                   = array(
				'class'   => 'notice-warning',
				'heading' => 'Manual recovery',
				'message' => 'Setup is disabled on this recovery view.',
				'details' => array(),
			);
			$model['recovery_warning'] = $warning;
			ob_start();
			$panel_html = $panel->invoke( $this->controls(), $model );
			ob_end_clean();
			ob_start();
			$section->invoke( $this->controls(), array(), '', true, array( $result, $warning ) );
			$section_html = (string) ob_get_clean();
			foreach ( array( $panel_html, $section_html ) as $html ) {
				self::assertStringContainsString( $result['heading'], $html, $code );
				self::assertStringContainsString( htmlspecialchars( $result['message'], ENT_QUOTES ), $html, $code );
				self::assertStringNotContainsString( '<hook>', $html, $code );
				if ( in_array( $code, array( 'recovery_record_failed', 'record_conflict', 'record_update_failed' ), true ) ) {
					self::assertSame( 1, substr_count( $html, 'Inspect the Git provider and Core manually before leaving this view or retrying.' ), $code );
					self::assertSame( 1, substr_count( $html, 'Inspect the Git provider' ), $code );
					self::assertStringContainsString( 'record_conflict' === $code ? 'Nothing was overwritten' : 'record was not overwritten', $html, $code );
					if ( 'record_conflict' === $code ) {
						self::assertStringContainsString( 'newer webhook-management record won the persistence race', $html );
					}
					self::assertSame( 1, substr_count( $html, 'Git provider hook reference: &lt;hook&gt;' ), $code );
					self::assertSame( 1, substr_count( $html, 'Core signing profile: &lt;profile&gt;' ), $code );
					self::assertStringNotContainsString( '<profile>', $html, $code );
				}
			}
		}
		$warning                   = array(
			'class'   => 'notice-warning',
			'heading' => 'Manual recovery',
			'message' => 'Setup disabled on this recovery view.',
			'details' => array( 'Provider: <provider>', 'Hook: <hook>', 'Profile: <profile>' ),
		);
		$model                     = $this->webhook_panel_model( false, false, array() );
		$model['recovery_warning'] = $warning;
		$panel_html                = $panel->invoke( $this->controls(), $model );
		ob_start();
		$section->invoke( $this->controls(), array(), '', true, array( $warning ) );
		$section_html = (string) ob_get_clean();
		foreach ( array( $panel_html, $section_html ) as $html ) {
			foreach ( array( 'provider', 'hook', 'profile' ) as $value ) {
				self::assertStringContainsString( '&lt;' . $value . '&gt;', $html );
				self::assertStringNotContainsString( '<' . $value . '>', $html );
			}
		}
		$result          = $presentation->invoke( $display, 'unknown', null, '<script>provider</script>' );
		$model           = $this->webhook_panel_model( false, false, array() );
		$model['result'] = $result;
		self::assertStringNotContainsString( '<script>provider</script>', $panel->invoke( $this->controls(), $model ) );
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

		self::assertStringNotContainsString( 'Webhook readiness', $html );
		self::assertStringContainsString( 'Webhook setup', $html );
		// Step 1 states the missing public HTTPS URL, so the amber notice does not repeat it.
		self::assertStringNotContainsString( 'This site needs a public HTTPS URL before a Git provider can deliver webhooks.', $html );
		self::assertSame( 1, substr_count( $html, 'This site needs a public HTTPS URL to receive webhooks.' ) );
		self::assertStringNotContainsString( 'ran-booster-repository-webhook-management__notices', $html );
		// With the receiver step not ok, setup starts collapsed.
		self::assertSame( 1, preg_match( '/<details id="ran-booster-repository-webhook-setup"[^>]*ran-booster-disclosure">/', $html ) );
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

	public function test_unavailable_reason_keeps_package_reference_fallback_local_and_reads_current_state(): void {
		$GLOBALS['ran_booster_repository_webhook_management_capabilities']['manage_options'] = true;
		$package = $this->createMock( Package::class );
		$package->method( 'get_source' )->willReturn( PackageSource::BRANCH );
		$package->method( 'get_provider_code' )->willReturn( 'fixture-provider' );
		$package->method( 'get_provider_repository_id' )->willReturn( '1234' );
		$plugins = $this->createMock( PluginRepository::class );
		$plugins->method( 'booster_plugin_from_file' )->willReturn( $package );
		$unresolved = new AssistanceReadiness(
			array(),
			'https://example.test/webhook',
			array(
				array(
					'package_references' => array( 'example/example.php' ),
					'reason_codes'       => array( 'repository_identity_unavailable' ),
				),
			)
		);
		$facade     = $this->createMock( WebhookAssistanceFacade::class );
		$facade->expects( self::exactly( 3 ) )->method( 'target' )->with( 'fixture-provider', '1234' )->willReturn( null );
		$facade->expects( self::exactly( 4 ) )->method( 'readiness' )->with( 'fixture-provider' )->willReturn(
			$unresolved,
			$unresolved,
			$unresolved,
			new AssistanceReadiness( array( 'secrets_storage_unavailable' ), 'https://example.test/webhook', array() )
		);
		$controls = new RepositoryWebhookManagementControls(
			$facade,
			$this->createMock( AdminInteractionFacade::class ),
			new ProviderRegistry( array( new CompleteWebhookManagementCapabilityProvider( 'fixture-provider', 'Fixture Forge' ) ) ),
			dirname( __DIR__, 3 ) . '/',
			'https://example.test/wp-content/plugins/ran-booster/',
			new ManagedPackageWebhookAuthorityResolver( $plugins, $this->createMock( ThemeRepository::class ) )
		);
		$controls->register();
		$projection = new AdminPackageProjection( 'plugin', 'example/example.php', 'Example', 'fixture-provider', 'branch', 1, 'manual', 'https://example.test/settings' );

		ob_start();
		$controls->render_package_webhook_setup( 'edit', 'plugin', 'branch', $projection, 'https://example.test/settings' );
		$package_html = (string) ob_get_clean();
		self::assertStringContainsString( 'this repository has no stable Git provider identity', $package_html );

		ob_start();
		$controls->render_repository_webhook_setup( 'fixture-provider', '1234', 'https://example.test/repository' );
		$repository_html = (string) ob_get_clean();
		self::assertStringContainsString( 'until Booster can confirm this managed repository', $repository_html );
		self::assertStringNotContainsString( 'this repository has no stable Git provider identity', $repository_html );

		ob_start();
		$controls->render_package_webhook_setup( 'edit', 'plugin', 'branch', $projection, 'https://example.test/settings' );
		$current_html = (string) ob_get_clean();
		self::assertStringContainsString( 'encrypted signing-secret storage is unavailable', $current_html );
		self::assertStringNotContainsString( 'this repository has no stable Git provider identity', $current_html );
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
			'ran-booster-repository-webhook-lifecycle',
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

	/**
	 * @param array<string,bool> $enabled_operations
	 * @return array<string,mixed>
	 */
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
