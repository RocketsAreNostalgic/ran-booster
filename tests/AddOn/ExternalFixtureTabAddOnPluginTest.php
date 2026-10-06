<?php

declare(strict_types=1);

namespace RAN\Tests\AddOn;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use RAN\Admin\AdminAddOnRegistry;
use RAN\Admin\AdminAddOnTab;

final class ExternalFixtureTabAddOnPluginTest extends TestCase {
	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit lifecycle override requires this method name.
	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this exact lifecycle override name.
	protected function setUp(): void {
		require_once __DIR__ . '/../Support/ExternalFixtureAddOnWordPressFunctions.php';
		require_once __DIR__ . '/../Support/ExternalFixtureTabAddOnWordPressFunctions.php';
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_plugin_loaded_before_core_registers_and_renders_one_tab(): void {
		$GLOBALS['ran_booster_external_fixture_addon_translations'] = array(
			'ran-booster-fixture-tab-addon' => array( 'Fixture Tab' => 'Onglet témoin' ),
		);
		$this->load_fixture_plugin();
		self::assertFalse( defined( 'RAN_BOOSTER_ADDON_API_VERSION' ) );
		define( 'RAN_BOOSTER_ADDON_API_VERSION', 17 );

		$registry = $this->register();
		$tab      = $registry->get( 'fixture-tab' );

		self::assertInstanceOf( AdminAddOnTab::class, $tab );
		self::assertSame( 'ran-booster-fixture-tab-addon', $tab->add_on_slug() );
		self::assertSame( 'fixture-tab', $tab->key() );
		self::assertSame( 'Onglet témoin', $tab->label() );
		self::assertSame( array( $tab ), $registry->all() );
		self::assertSame(
			'<div id="ran-booster-fixture-tab" data-scope="site" data-url="https://example.test/wp-admin/admin.php?page=ran-booster&amp;tab=fixture-tab">Onglet témoin</div>',
			$this->render( $registry, $tab )
		);
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_plugin_loaded_after_core_uses_the_same_tab_contract(): void {
		define( 'RAN_BOOSTER_ADDON_API_VERSION', 17 );
		$this->load_fixture_plugin();

		$registry = $this->register();

		self::assertInstanceOf( AdminAddOnTab::class, $registry->get( 'fixture-tab' ) );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_plugin_is_harmless_when_core_or_its_api_version_is_unavailable(): void {
		$this->load_fixture_plugin();
		$this->run_hook( 'plugins_loaded' );
		self::assertArrayNotHasKey( 'ran_booster_register_admin_tabs', $GLOBALS['ran_booster_external_fixture_addon_actions'] );

		$GLOBALS['ran_booster_external_fixture_addon_actions'] = array();
		define( 'RAN_BOOSTER_ADDON_API_VERSION', 15 );
		$this->load_fixture_plugin();
		$this->run_hook( 'plugins_loaded' );
		self::assertArrayNotHasKey( 'ran_booster_register_admin_tabs', $GLOBALS['ran_booster_external_fixture_addon_actions'] );
	}

	private function load_fixture_plugin(): void {
		$GLOBALS['ran_booster_external_fixture_addon_actions'] = array();
		$GLOBALS['ran_booster_external_fixture_addon_admin']   = true;
		require dirname( __DIR__ ) . '/fixtures/ran-booster-fixture-tab-addon/ran-booster-fixture-tab-addon.php';
	}

	private function register(): AdminAddOnRegistry {
		$this->run_hook( 'plugins_loaded' );
		$registry = new AdminAddOnRegistry( array(), 7, 7 );
		$this->run_hook( 'ran_booster_register_admin_tabs', $registry );
		$registry->seal();

		return $registry;
	}

	private function run_hook( string $hook, mixed ...$arguments ): void {
		$callbacks = $GLOBALS['ran_booster_external_fixture_addon_actions'][ $hook ] ?? array();
		self::assertCount( 1, $callbacks, sprintf( 'The %s callback must be registered once.', $hook ) );
		$callbacks[0]( ...$arguments );
	}

	private function render( AdminAddOnRegistry $registry, AdminAddOnTab $tab ): string {
		ob_start();
		$tab->render(
			$registry->context_for(
				$tab,
				'https://example.test/wp-admin/admin.php?page=ran-booster&tab=fixture-tab',
				'site'
			)
		);

		return (string) ob_get_clean();
	}
}
