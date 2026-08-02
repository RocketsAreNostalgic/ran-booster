<?php

declare(strict_types=1);

namespace Tests\Admin;

use PHPUnit\Framework\TestCase;
use RAN\Booster;

require_once dirname( __DIR__ ) . '/Support/ProviderCredentialDispatcherWordPressFunctions.php';
require_once __DIR__ . '/DashboardRoutingWordPressFunctions.php';
require_once __DIR__ . '/ProPageWordPressFunctions.php';

final class ProPageTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['ran_booster_dashboard_test_multisite'] = false;
		$GLOBALS['ran_booster_dashboard_test_actions']   = array();
		$GLOBALS['ran_booster_pro_page_menus']           = array();
		$GLOBALS['ran_booster_pro_page_submenus']        = array();
		$GLOBALS['ran_booster_test_capabilities']        = array( 'manage_options' => true );
		$GLOBALS['ran_booster_test_capability_checks']   = array();
	}

	protected function tearDown(): void {
		unset(
			$GLOBALS['ran_booster_dashboard_test_multisite'],
			$GLOBALS['ran_booster_dashboard_test_actions'],
			$GLOBALS['ran_booster_pro_page_menus'],
			$GLOBALS['ran_booster_pro_page_submenus'],
			$GLOBALS['ran_booster_test_capabilities'],
			$GLOBALS['ran_booster_test_capability_checks']
		);
	}

	public function testRegistersProAfterTheExistingFixedSubmenus(): void {
		$booster = $this->booster();
		$booster->adminMenu();

		self::assertSame(
			array(
				'ran-booster-plugins-create',
				'ran-booster-plugins',
				'ran-booster-themes-create',
				'ran-booster-themes',
				'ran-booster-pro',
			),
			array_column( $GLOBALS['ran_booster_pro_page_submenus'], 'menu_slug' )
		);
		self::assertSame(
			array(
				'parent_slug' => 'ran-booster',
				'page_title'  => 'Pro',
				'menu_title'  => 'Pro',
				'capability'  => 'manage_options',
				'menu_slug'   => 'ran-booster-pro',
			),
			array_diff_key( $GLOBALS['ran_booster_pro_page_submenus'][4], array( 'callback' => true ) )
		);
		self::assertSame( array( $booster, 'renderProPage' ), $GLOBALS['ran_booster_pro_page_submenus'][4]['callback'] );
	}

	public function testAbsentAndEmptyActionsRenderTheStaticFallback(): void {
		$booster = $this->booster();

		ob_start();
		$booster->renderProPage();
		$absent = (string) ob_get_clean();
		self::assertStringContainsString( 'Support RAN Booster on GitHub Sponsors', $absent );
		self::assertStringContainsString( 'https://example.test/wp-admin/plugins.php', $absent );

		$GLOBALS['ran_booster_dashboard_test_actions']['ran_booster_pro_page_body'] = array(
			static function (): void {
				echo " \n";
			},
		);
		ob_start();
		$booster->renderProPage();
		$empty = (string) ob_get_clean();
		self::assertStringContainsString( 'Support RAN Booster on GitHub Sponsors', $empty );
	}

	public function testCompatibleActionReceivesOnlyCanonicalUrlAndAdministrationScope(): void {
		$received = array();
		$GLOBALS['ran_booster_dashboard_test_actions']['ran_booster_pro_page_body'] = array(
			static function ( string $url, string $scope ) use ( &$received ): void {
				$received = func_get_args();
				echo '<section>Manager body</section>';
			},
		);

		ob_start();
		$this->booster()->renderProPage();
		$output = (string) ob_get_clean();

		self::assertSame(
			array( 'https://example.test/wp-admin/admin.php?page=ran-booster-pro', 'administration' ),
			$received
		);
		self::assertStringContainsString( '<section>Manager body</section>', $output );
		self::assertStringNotContainsString( 'Support RAN Booster on GitHub Sponsors', $output );
	}

	public function testNetworkAdministrationUsesTheCanonicalNetworkUrl(): void {
		$GLOBALS['ran_booster_dashboard_test_multisite'] = true;
		$received                                        = array();
		$GLOBALS['ran_booster_dashboard_test_actions']['ran_booster_pro_page_body'] = array(
			static function ( string $url, string $scope ) use ( &$received ): void {
				$received = array( $url, $scope );
			},
		);

		ob_start();
		$this->booster()->renderProPage();
		ob_end_clean();

		self::assertSame(
			array( 'https://example.test/wp-admin/network/admin.php?page=ran-booster-pro', 'administration' ),
			$received
		);
	}

	public function testDeniedRequestDoesNotInvokeTheActionOrRenderOutput(): void {
		$GLOBALS['ran_booster_test_capabilities']['manage_options'] = false;
		$called = false;
		$GLOBALS['ran_booster_dashboard_test_actions']['ran_booster_pro_page_body'] = array(
			static function () use ( &$called ): void {
				$called = true;
			},
		);

		ob_start();
		$this->booster()->renderProPage();
		$output = (string) ob_get_clean();

		self::assertFalse( $called );
		self::assertSame( '', $output );
		self::assertSame( array( 'manage_options' ), $GLOBALS['ran_booster_test_capability_checks'] );
	}

	public function testThrowingActionDiscardsPartialOutputAndRendersOnlyTheSafeUnavailableNotice(): void {
		$GLOBALS['ran_booster_dashboard_test_actions']['ran_booster_pro_page_body'] = array(
			static function (): void {
				echo 'partial-manager-output';
				throw new \RuntimeException( 'private manager failure' );
			},
		);

		ob_start();
		$this->booster()->renderProPage();
		$output = (string) ob_get_clean();

		self::assertStringNotContainsString( 'partial-manager-output', $output );
		self::assertStringNotContainsString( 'private manager failure', $output );
		self::assertStringNotContainsString( 'Support RAN Booster on GitHub Sponsors', $output );
		self::assertStringContainsString( 'The Pro page is temporarily unavailable.', $output );
	}

	private function booster(): Booster {
		return new class() extends Booster {

			public function make( $alias ) {
				if ( 'RAN\\Dashboard' === $alias ) {
					return new class() {

						public function getIndex(): void {
						}

						public function getPluginsCreate(): void {
						}

						public function getPlugins(): void {
						}

						public function getThemesCreate(): void {
						}

						public function getThemes(): void {
						}
					};
				}

				return parent::make( $alias );
			}
		};
	}
}
