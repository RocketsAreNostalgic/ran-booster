<?php

declare( strict_types = 1 );

namespace Tests\Admin\WebhookManagement;

use PHPUnit\Framework\TestCase;
use RAN\AddOn\WebhookAssistance\AssistanceTarget;
use RAN\AddOn\WebhookAssistance\AssistanceReadiness;
use RAN\AddOn\WebhookAssistance\WebhookAssistanceFacade;
use RAN\Admin\Interaction\AdminInteractionFacade;
use RAN\Admin\ManagedPackageWebhookAuthorityResolver;
use RAN\Admin\AdminPackageProjection;
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

	public function testItRegistersTheCompleteNonGitHubProviderPresentationAndRequestBoundaryOnce(): void {
		$provider = new CompleteWebhookManagementCapabilityProvider( 'fixture-provider', 'Fixture Forge' );
		$controls = $this->controls( $provider );
		$controls->register();

		self::assertTrue( $controls->supportsProvider( 'fixture-provider' ) );
		self::assertSame(
			array( 'ran_booster_documentation_sections_after_provider_fixture-provider' ),
			array_keys( $GLOBALS['ran_booster_repository_webhook_management_filters'] )
		);
		self::assertSame(
			array( 'admin_post_ran_booster_repository_webhook_management_operation', 'admin_enqueue_scripts', 'ran_booster_admin_package_settings_sections', 'ran_booster_admin_package_advanced_source_sections' ),
			array_keys( $GLOBALS['ran_booster_repository_webhook_management_actions'] )
		);
		foreach ( array_merge( $GLOBALS['ran_booster_repository_webhook_management_actions'], $GLOBALS['ran_booster_repository_webhook_management_filters'] ) as $registrations ) {
			self::assertCount( 1, $registrations );
		}
		self::assertSame( 0, $provider->providerOperationCalls );
	}

	public function testItLoadsScopedStylesOnCapableProviderAndPackageSettingsScreens(): void {
		$controls = $this->controls( new CompleteWebhookManagementCapabilityProvider( 'fixture-provider', 'Fixture Forge' ) );
		$controls->register();
		$_GET = array(
			'page' => 'ran-booster',
			'tab'  => 'fixture-provider',
		);

		$controls->enqueueAdminAssets( 'settings_page_unrelated' );
		self::assertSame( array(), $GLOBALS['ran_booster_repository_webhook_management_styles'] );

		$controls->enqueueAdminAssets( 'toplevel_page_ran-booster' );
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
		$controls->enqueueAdminAssets( 'ran-booster_page_ran-booster-plugins' );
		self::assertCount( 2, $GLOBALS['ran_booster_repository_webhook_management_styles'] );
	}

	public function testPartialAbsentMissingAndMalformedProvidersReceiveNoPlacementBeforeProviderWork(): void {
		$providers = array(
			new FitnessOnlyWebhookManagementCapabilityProvider( 'fitness-only', 'Fitness only' ),
			new ManagementOnlyWebhookManagementCapabilityProvider( 'management-only', 'Management only' ),
			new UnnormalizedWebhookManagementCapabilityProvider( 'no-policy', 'No signing policy' ),
			new AbsentWebhookManagementCapabilityProvider( 'absent', 'Absent' ),
		);
		$controls  = $this->controls( ...$providers );
		$controls->register();
		$rows = array( 'repository' => array( 'actions' => array() ) );

		foreach ( array( 'fitness-only', 'management-only', 'no-policy', 'absent', 'missing', "bad\0code" ) as $providerCode ) {
			self::assertFalse( $controls->supportsProvider( $providerCode ) );
			self::assertSame( $rows, $controls->enrichRepositoryRows( $rows, $providerCode, array(), 'https://example.test/' ) );
			ob_start();
			$controls->renderRepositoryPanel( $providerCode, 'repository', 'https://example.test/' );
			self::assertSame( '', ob_get_clean() );
		}

		foreach ( $providers as $provider ) {
			self::assertSame( 0, $provider->providerOperationCalls );
		}
		self::assertSame( array(), $GLOBALS['ran_booster_repository_webhook_management_filters'] );
		foreach ( array( 'fitness-only', 'management-only', 'no-policy', 'absent', 'missing' ) as $providerCode ) {
			$_GET = array(
				'page' => 'ran-booster',
				'tab'  => $providerCode,
			);
			$controls->enqueueAdminAssets( 'toplevel_page_ran-booster' );
		}
		self::assertSame( array(), $GLOBALS['ran_booster_repository_webhook_management_styles'] );
	}

	public function testBranchPackageRendersTheFixedNormalPostWebhookDisclosure(): void {
		$GLOBALS['ran_booster_repository_webhook_management_capabilities']['manage_options'] = true;
		$package = $this->createMock( Package::class );
		$package->method( 'getSource' )->willReturn( PackageSource::BRANCH );
		$package->method( 'getProviderCode' )->willReturn( 'fixture-provider' );
		$package->method( 'getProviderRepositoryId' )->willReturn( '1234' );
		$plugins = $this->createMock( PluginRepository::class );
		$plugins->expects( self::once() )->method( 'boosterPluginFromFile' )->willReturn( $package );
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
		$facade->expects( self::once() )->method( 'credentialChoices' )->with( 'fixture-provider' )->willReturn( array() );
		$controls = new RepositoryWebhookManagementControls( $facade, $this->createMock( AdminInteractionFacade::class ), new ManagedPackageWebhookAuthorityResolver( $plugins, $this->createMock( ThemeRepository::class ) ), new ProviderRegistry( array( new CompleteWebhookManagementCapabilityProvider( 'fixture-provider', 'Fixture Forge' ) ) ), dirname( __DIR__, 3 ) . '/', 'https://example.test/wp-content/plugins/ran-booster/' );
		$controls->register();

		ob_start();
		$controls->renderPackageWebhookSetup( 'edit', 'plugin', 'branch', new AdminPackageProjection( 'plugin', 'example/example.php', 'Example', 'fixture-provider', 'branch', 1, 'manual', 'https://example.test/settings' ), 'https://example.test/settings' );
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'Webhook setup', $html );
		self::assertStringContainsString( 'class="ran-booster-package-disclosure ran-booster-package-webhook-setup"', $html );
		self::assertStringContainsString( 'class="ran-booster-package-disclosure__body ran-booster-package-webhook-setup__body"', $html );
		self::assertStringContainsString( 'name="action" value="ran_booster_repository_webhook_management_operation"', $html );
		self::assertStringContainsString( 'name="repository_webhook_management_operation" value="setup"', $html );
		self::assertStringContainsString( 'does not enable Automatic updates', $html );
		self::assertStringNotContainsString( 'hx-post=', $html );
		self::assertStringNotContainsString( 'hx-target=', $html );
	}

	public function testBranchProjectionWithoutStableIdentityStaysVisibleAndInert(): void {
		$controls = $this->controls( new CompleteWebhookManagementCapabilityProvider( 'fixture-provider', 'Fixture Forge' ) );
		$controls->register();

		ob_start();
		$controls->renderPackageWebhookSetup( 'edit', 'plugin', 'branch', new AdminPackageProjection( 'plugin', 'missing/missing.php', 'Missing', 'fixture-provider', 'branch', 1, 'manual', 'https://example.test/settings' ), 'https://example.test/settings' );
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'Webhook setup', $html );
		self::assertStringContainsString( 'stable repository identity', $html );
		self::assertStringContainsString( 'disabled', $html );
	}

	public function testReleaseSourceProjectionDoesNotDuplicateThePausedWebhookDisclosure(): void {
		$controls = $this->controls( new CompleteWebhookManagementCapabilityProvider( 'fixture-provider', 'Fixture Forge' ) );
		$controls->register();

		ob_start();
		$controls->renderPackageWebhookSetup( 'edit', 'plugin', 'branch', new AdminPackageProjection( 'plugin', 'release/release.php', 'Release', 'fixture-provider', 'release_asset', 1, 'manual', 'https://example.test/settings' ), 'https://example.test/settings' );
		self::assertSame( '', (string) ob_get_clean() );
	}

	public function testLocalCallbackReadinessExplainsWhyWebhookSetupIsDisabled(): void {
		$GLOBALS['ran_booster_repository_webhook_management_capabilities']['manage_options'] = true;
		$package = $this->createMock( Package::class );
		$package->method( 'getSource' )->willReturn( PackageSource::BRANCH );
		$package->method( 'getProviderCode' )->willReturn( 'fixture-provider' );
		$package->method( 'getProviderRepositoryId' )->willReturn( '1234' );
		$plugins = $this->createMock( PluginRepository::class );
		$plugins->expects( self::once() )->method( 'boosterPluginFromFile' )->willReturn( $package );
		$facade = $this->createMock( WebhookAssistanceFacade::class );
		$facade->expects( self::once() )->method( 'target' )->with( 'fixture-provider', '1234' )->willReturn( null );
		$facade->expects( self::once() )->method( 'readiness' )->with( 'fixture-provider' )->willReturn( new AssistanceReadiness( array( 'callback_requires_public_https' ), 'http://localhost:10008/webhook', array() ) );
		$facade->expects( self::never() )->method( 'credentialChoices' );
		$controls = new RepositoryWebhookManagementControls( $facade, $this->createMock( AdminInteractionFacade::class ), new ManagedPackageWebhookAuthorityResolver( $plugins, $this->createMock( ThemeRepository::class ) ), new ProviderRegistry( array( new CompleteWebhookManagementCapabilityProvider( 'fixture-provider', 'Fixture Forge' ) ) ), dirname( __DIR__, 3 ) . '/', 'https://example.test/wp-content/plugins/ran-booster/' );
		$controls->register();

		ob_start();
		$controls->renderPackageWebhookSetup( 'edit', 'plugin', 'branch', new AdminPackageProjection( 'plugin', 'example/example.php', 'Example', 'fixture-provider', 'branch', 1, 'manual', 'https://example.test/settings' ), 'https://example.test/settings' );
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'public HTTPS URL', $html );
		self::assertStringContainsString( 'class="ran-booster-package-disclosure ran-booster-package-webhook-setup"', $html );
		self::assertStringContainsString( 'class="ran-booster-package-disclosure__body ran-booster-package-webhook-setup__body"', $html );
		self::assertStringContainsString( 'disabled', $html );
	}

	public function testRepositoryConflictReadinessMatchesTheExactPackageReference(): void {
		$GLOBALS['ran_booster_repository_webhook_management_capabilities']['manage_options'] = true;
		$package = $this->createMock( Package::class );
		$package->method( 'getSource' )->willReturn( PackageSource::BRANCH );
		$package->method( 'getProviderCode' )->willReturn( 'fixture-provider' );
		$package->method( 'getProviderRepositoryId' )->willReturn( '1234' );
		$plugins = $this->createMock( PluginRepository::class );
		$plugins->expects( self::once() )->method( 'boosterPluginFromFile' )->willReturn( $package );
		$facade = $this->createMock( WebhookAssistanceFacade::class );
		$facade->expects( self::once() )->method( 'target' )->willReturn( null );
		$facade->expects( self::once() )->method( 'readiness' )->willReturn(
			new AssistanceReadiness(
				array(),
				'https://example.test/webhook',
				array(
					array(
						'repository_id'      => null,
						'package_references' => array( 'example/example.php' ),
						'reason_codes'       => array( 'repository_identity_conflict' ),
					),
				)
			)
		);
		$facade->expects( self::never() )->method( 'credentialChoices' );
		$controls = new RepositoryWebhookManagementControls( $facade, $this->createMock( AdminInteractionFacade::class ), new ManagedPackageWebhookAuthorityResolver( $plugins, $this->createMock( ThemeRepository::class ) ), new ProviderRegistry( array( new CompleteWebhookManagementCapabilityProvider( 'fixture-provider', 'Fixture Forge' ) ) ), dirname( __DIR__, 3 ) . '/', 'https://example.test/wp-content/plugins/ran-booster/' );
		$controls->register();

		ob_start();
		$controls->renderPackageWebhookSetup( 'edit', 'plugin', 'branch', new AdminPackageProjection( 'plugin', 'example/example.php', 'Example', 'fixture-provider', 'branch', 1, 'manual', 'https://example.test/settings' ), 'https://example.test/settings' );
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'disagree about this repository identity', $html );
	}

	public function testPartialProviderKeepsReleaseRowHistoryLocalAndInert(): void {
		$provider = new FitnessOnlyWebhookManagementCapabilityProvider( 'fitness-only', 'Fitness only' );
		$GLOBALS['ran_booster_repository_webhook_management_test_options']['ran_booster_assisted_hooks_installations'] = array(
			'fitness-only:1234' => array(
				'schema_version'              => 3,
				'provider_code'               => 'fitness-only',
				'repository_id'               => '1234',
				'repository'                  => 'owner/example',
				'hook_id'                     => '77',
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

		$result = $controls->enrichRepositoryRows( $rows, 'fitness-only', array(), 'https://example.test/' );

		self::assertSame( array( 'Recorded hook status', 'Observation', 'Last checked' ), array_column( $result['1234']['details'], 'label' ) );
		self::assertSame( 'Needs attention: Needs Verification at last check', $result['1234']['details'][0]['value'] );
		self::assertSame( array(), $result['1234']['actions'] );
		self::assertSame( 0, $provider->providerOperationCalls );
	}

	public function testPackageHistoryUsesTheSharedPlainLanguageStatusAndHistoricalQualifier(): void {
		$package = $this->createMock( Package::class );
		$package->method( 'getSource' )->willReturn( PackageSource::BRANCH );
		$package->method( 'getProviderCode' )->willReturn( 'gh' );
		$package->method( 'getProviderRepositoryId' )->willReturn( '1234' );
		$plugins = $this->createMock( PluginRepository::class );
		$plugins->expects( self::once() )->method( 'boosterPluginFromFile' )->with( 'example/example.php' )->willReturn( $package );
		$themes = $this->createMock( ThemeRepository::class );
		$GLOBALS['ran_booster_repository_webhook_management_test_options']['ran_booster_assisted_hooks_installations'] = array(
			'gh:1234' => array(
				'schema_version'              => 3,
				'provider_code'               => 'gh',
				'repository_id'               => '1234',
				'repository'                  => 'owner/example',
				'hook_id'                     => '77',
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
		$controls = new RepositoryWebhookManagementControls(
			$this->createMock( WebhookAssistanceFacade::class ),
			$this->createMock( AdminInteractionFacade::class ),
			new ManagedPackageWebhookAuthorityResolver( $plugins, $themes ),
			new ProviderRegistry(),
			dirname( __DIR__, 3 ) . '/',
			'https://example.test/wp-content/plugins/ran-booster/'
		);

		ob_start();
		$controls->renderPackageHistory( new AdminPackageProjection( 'plugin', 'example/example.php', 'Example', 'gh', 'branch', 1, 'manual', 'https://example.test/settings' ), 'https://example.test/settings' );
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'Remote webhook history', $html );
		self::assertStringContainsString( 'Needs attention: Needs Verification at last check', $html );
		self::assertStringContainsString( 'Last checked by RAN Booster: 2026-08-20T01:02:03Z', $html );
		self::assertStringContainsString( 'historical record, not live readiness or a signed delivery', $html );
		self::assertStringNotContainsString( '>needs_verification<', $html );
	}

	private function controls( WebhookManagementCapabilityProvider ...$providers ): RepositoryWebhookManagementControls {
		$facade = $this->createMock( WebhookAssistanceFacade::class );
		$facade->expects( self::never() )->method( 'readiness' );
		$facade->expects( self::never() )->method( 'target' );
		$facade->expects( self::never() )->method( 'credentialChoices' );
		$facade->expects( self::never() )->method( 'profile' );

		return new RepositoryWebhookManagementControls(
			$facade,
			$this->createMock( AdminInteractionFacade::class ),
			new ManagedPackageWebhookAuthorityResolver( $this->createMock( PluginRepository::class ), $this->createMock( ThemeRepository::class ) ),
			new ProviderRegistry( $providers ),
			dirname( __DIR__, 3 ) . '/',
			'https://example.test/wp-content/plugins/ran-booster/'
		);
	}
}
