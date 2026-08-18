<?php

declare( strict_types = 1 );

namespace Tests\Booster\GitHub\WebhookManagement;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use RAN\Booster\GitHub\GitHubProvider;

require_once __DIR__ . '/GitHubProviderLegacyWordPressFunctions.php';

final class GitHubLegacyAssistedHooksRetirementTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['ran_booster_github_webhook_management_actions']      = array();
		$GLOBALS['ran_booster_github_webhook_management_capabilities'] = array();
		$_GET = array();
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function testLegacyAddOnOwnsTheFeatureAndGetsOneCapabilityGatedStopNotice(): void {
		require dirname( __DIR__, 4 ) . '/tests/fixtures/LegacyAssistedHooksPlugin.php';
		self::assertTrue( GitHubProvider::legacyAssistedHooksAddOnIsActive() );

		GitHubProvider::registerLegacyAssistedHooksAddOnNotice();
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

		self::assertFalse( GitHubProvider::legacyAssistedHooksAddOnIsActive() );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function testUnknownRetirementMarkerFailsClosedAsLegacy(): void {
		require dirname( __DIR__, 4 ) . '/tests/fixtures/LegacyAssistedHooksPlugin.php';
		define( 'RAN_BOOSTER_ASSISTED_HOOKS_RETIREMENT_BRIDGE_VERSION', 2 );

		self::assertTrue( GitHubProvider::legacyAssistedHooksAddOnIsActive() );
	}
}
