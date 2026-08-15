<?php

declare( strict_types = 1 );

namespace Tests\Booster\GitHub\WebhookManagement;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use RAN\AddOn\WebhookAssistance\WebhookAssistanceFacade;
use RAN\Admin\Interaction\AdminInteractionFacade;
use RAN\Booster\GitHub\WebhookManagement\GitHubWebhookManagement;

require_once __DIR__ . '/GitHubWebhookManagementWordPressFunctions.php';

final class GitHubWebhookManagementTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['ran_booster_github_webhook_management_actions']      = array();
		$GLOBALS['ran_booster_github_webhook_management_filters']      = array();
		$GLOBALS['ran_booster_github_webhook_management_styles']       = array();
		$GLOBALS['ran_booster_github_webhook_management_capabilities'] = array();
		$_GET = array();
	}

	public function testItRegistersTheFixedGitHubPresentationAndRequestBoundaryOnce(): void {
		$management = $this->management();
		$management->register();

		self::assertSame(
			array(
				'ran_booster_admin_provider_repository_assistance_active',
				'ran_booster_admin_provider_repository_rows',
				'ran_booster_documentation_sections_after_provider_gh',
			),
			array_keys( $GLOBALS['ran_booster_github_webhook_management_filters'] )
		);
		self::assertSame(
			array(
				'ran_booster_admin_provider_repository_panel',
				'admin_post_ran_booster_github_webhook_management_operation',
				'admin_enqueue_scripts',
			),
			array_keys( $GLOBALS['ran_booster_github_webhook_management_actions'] )
		);
		foreach ( array_merge( $GLOBALS['ran_booster_github_webhook_management_actions'], $GLOBALS['ran_booster_github_webhook_management_filters'] ) as $registrations ) {
			self::assertCount( 1, $registrations );
		}
	}

	public function testItLoadsItsScopedStylesOnlyOnTheGitHubProviderScreen(): void {
		$management = $this->management();
		$_GET       = array(
			'page' => 'ran-booster',
			'tab'  => 'gh',
		);

		$management->enqueueAdminAssets( 'settings_page_unrelated' );
		self::assertSame( array(), $GLOBALS['ran_booster_github_webhook_management_styles'] );

		$management->enqueueAdminAssets( 'toplevel_page_ran-booster' );
		self::assertSame(
			array(
				'handle'       => 'ran-booster-github-webhook-management',
				'source'       => 'https://example.test/wp-content/plugins/ran-booster/assets/ran-booster-github-webhook-management.css',
				'dependencies' => array( 'ran-booster-styles' ),
				'version'      => (string) filemtime( dirname( __DIR__, 4 ) . '/assets/ran-booster-github-webhook-management.css' ),
			),
			$GLOBALS['ran_booster_github_webhook_management_styles'][0]
		);
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function testLegacyAddOnOwnsTheFeatureAndGetsOneCapabilityGatedStopNotice(): void {
		require dirname( __DIR__, 4 ) . '/tests/fixtures/LegacyAssistedHooksPlugin.php';
		self::assertTrue( GitHubWebhookManagement::legacyAddOnIsActive() );

		GitHubWebhookManagement::registerLegacyAddOnNotice();
		self::assertCount( 1, $GLOBALS['ran_booster_github_webhook_management_actions']['admin_notices'] );
		$notice = $GLOBALS['ran_booster_github_webhook_management_actions']['admin_notices'][0]['callback'];

		ob_start();
		$notice();
		self::assertSame( '', ob_get_clean() );

		$GLOBALS['ran_booster_github_webhook_management_capabilities']['activate_plugins'] = true;
		ob_start();
		$notice();
		$output = (string) ob_get_clean();
		self::assertStringContainsString( 'pre-retirement RAN Booster Assisted Hooks', $output );
		self::assertStringContainsString( 'Deactivate that add-on', $output );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function testExactRetirementBridgeMakesTheLoadedAddOnInertToCore(): void {
		require dirname( __DIR__, 4 ) . '/tests/fixtures/LegacyAssistedHooksPlugin.php';
		define( 'RAN_BOOSTER_ASSISTED_HOOKS_RETIREMENT_BRIDGE_VERSION', 1 );

		self::assertFalse( GitHubWebhookManagement::legacyAddOnIsActive() );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function testUnknownRetirementMarkerFailsClosedAsLegacy(): void {
		require dirname( __DIR__, 4 ) . '/tests/fixtures/LegacyAssistedHooksPlugin.php';
		define( 'RAN_BOOSTER_ASSISTED_HOOKS_RETIREMENT_BRIDGE_VERSION', 2 );

		self::assertTrue( GitHubWebhookManagement::legacyAddOnIsActive() );
	}

	private function management(): GitHubWebhookManagement {
		return new GitHubWebhookManagement(
			$this->createMock( WebhookAssistanceFacade::class ),
			$this->createMock( AdminInteractionFacade::class ),
			dirname( __DIR__, 4 ) . '/',
			'https://example.test/wp-content/plugins/ran-booster/'
		);
	}
}
