<?php

declare(strict_types=1);

namespace Tests\AddOn;

require_once __DIR__ . '/../Support/ExternalFixtureAddOnWordPressFunctions.php';

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Tests\Support\NullLoggingFacade;
use RAN\Admin\AdminAddOnRegistry;
use RAN\Admin\AdminAddOnTab;

final class ExternalFixtureTabAddOnPluginTest extends TestCase {

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function testPluginLoadedBeforeCoreRegistersAndRendersOneTab(): void {
		$this->loadFixturePlugin();
		self::assertFalse( defined( 'RAN_BOOSTER_ADDON_API_VERSION' ) );
		define( 'RAN_BOOSTER_ADDON_API_VERSION', 12 );
		define( 'RAN_BOOSTER_LOGGING_API_VERSION', 1 );

		$registry = $this->register();
		$tab      = $registry->get( 'fixture-tab' );

		self::assertInstanceOf( AdminAddOnTab::class, $tab );
		self::assertSame( 'ran-booster-fixture-tab-addon', $tab->addOnSlug() );
		self::assertSame( array( $tab ), $registry->all() );
		self::assertSame(
			'<div id="ran-booster-fixture-tab" data-scope="site" data-url="https://example.test/wp-admin/admin.php?page=ran-booster&amp;tab=fixture-tab">Fixture Tab</div>',
			$this->render( $registry, $tab )
		);
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function testPluginLoadedAfterCoreUsesTheSameTabContract(): void {
		define( 'RAN_BOOSTER_ADDON_API_VERSION', 12 );
		define( 'RAN_BOOSTER_LOGGING_API_VERSION', 1 );
		$this->loadFixturePlugin();

		$registry = $this->register();

		self::assertInstanceOf( AdminAddOnTab::class, $registry->get( 'fixture-tab' ) );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function testPluginIsHarmlessWhenCoreIsAbsentOrLoggingIsUnavailable(): void {
		$this->loadFixturePlugin();
		$this->runHook( 'plugins_loaded' );
		self::assertArrayNotHasKey( 'ran_booster_register_admin_tabs', $GLOBALS['ran_booster_external_fixture_addon_actions'] );

		$GLOBALS['ran_booster_external_fixture_addon_actions'] = array();
		define( 'RAN_BOOSTER_ADDON_API_VERSION', 12 );
		$this->loadFixturePlugin();
		$this->runHook( 'plugins_loaded' );
		self::assertArrayNotHasKey( 'ran_booster_register_admin_tabs', $GLOBALS['ran_booster_external_fixture_addon_actions'] );
	}

	private function loadFixturePlugin(): void {
		$GLOBALS['ran_booster_external_fixture_addon_actions'] = array();
		$GLOBALS['ran_booster_external_fixture_addon_admin']   = true;
		require dirname( __DIR__ ) . '/fixtures/ran-booster-fixture-tab-addon/ran-booster-fixture-tab-addon.php';
	}

	private function register(): AdminAddOnRegistry {
		$this->runHook( 'plugins_loaded' );
		$registry = new AdminAddOnRegistry( new NullLoggingFacade(), array(), 7, 7 );
		$this->runHook( 'ran_booster_register_admin_tabs', $registry );
		$registry->seal();

		return $registry;
	}

	private function runHook( string $hook, mixed ...$arguments ): void {
		$callbacks = $GLOBALS['ran_booster_external_fixture_addon_actions'][ $hook ] ?? array();
		self::assertCount( 1, $callbacks, sprintf( 'The %s callback must be registered once.', $hook ) );
		$callbacks[0]( ...$arguments );
	}

	private function render( AdminAddOnRegistry $registry, AdminAddOnTab $tab ): string {
		ob_start();
		$tab->render(
			$registry->contextFor(
				$tab,
				'https://example.test/wp-admin/admin.php?page=ran-booster&tab=fixture-tab',
				'site'
			)
		);

		return (string) ob_get_clean();
	}
}
