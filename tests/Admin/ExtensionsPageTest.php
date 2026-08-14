<?php

declare(strict_types=1);

namespace Tests\Admin;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use RAN\Booster;
use RAN\Dashboard;
use RAN\Internal\CoreContainer;

require_once dirname( __DIR__ ) . '/Support/ProviderCredentialDispatcherWordPressFunctions.php';
require_once __DIR__ . '/AdminViewWordPressFunctions.php';
require_once __DIR__ . '/DashboardRoutingWordPressFunctions.php';
require_once __DIR__ . '/BoosterAssetsWordPressFunctions.php';
require_once __DIR__ . '/ExtensionsPageWordPressFunctions.php';

final class ExtensionsPageTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['ran_booster_dashboard_test_multisite']          = false;
		$GLOBALS['ran_booster_dashboard_test_actions']            = array();
		$GLOBALS['ran_booster_extensions_page_menus']             = array();
		$GLOBALS['ran_booster_extensions_page_submenus']          = array();
		$GLOBALS['ran_booster_test_capabilities']                 = array( 'manage_options' => true );
		$GLOBALS['ran_booster_test_capability_checks']            = array();
		$GLOBALS['ran_booster_extensions_plugins']                = array();
		$GLOBALS['ran_booster_extensions_active_plugins']         = array();
		$GLOBALS['ran_booster_extensions_network_active_plugins'] = array();
		$GLOBALS['ran_booster_extensions_plugins_failure']        = null;
	}

	protected function tearDown(): void {
		unset(
			$GLOBALS['ran_booster_dashboard_test_multisite'],
			$GLOBALS['ran_booster_dashboard_test_actions'],
			$GLOBALS['ran_booster_extensions_page_menus'],
			$GLOBALS['ran_booster_extensions_page_submenus'],
			$GLOBALS['ran_booster_test_capabilities'],
			$GLOBALS['ran_booster_test_capability_checks'],
			$GLOBALS['ran_booster_extensions_plugins'],
			$GLOBALS['ran_booster_extensions_active_plugins'],
			$GLOBALS['ran_booster_extensions_network_active_plugins'],
			$GLOBALS['ran_booster_extensions_plugins_failure']
		);
	}

	public function testRegistersExtensionsAfterTheExistingFixedSubmenus(): void {
		$booster = $this->booster();
		$booster->adminMenu();

		self::assertSame(
			array(
				'ran-booster-plugins-create',
				'ran-booster-plugins',
				'ran-booster-themes-create',
				'ran-booster-themes',
				'ran-booster-extensions',
			),
			array_column( $GLOBALS['ran_booster_extensions_page_submenus'], 'menu_slug' )
		);
		self::assertSame(
			array(
				'parent_slug' => 'ran-booster',
				'page_title'  => 'Extensions',
				'menu_title'  => 'Extensions',
				'capability'  => 'manage_options',
				'menu_slug'   => 'ran-booster-extensions',
			),
			array_diff_key( $GLOBALS['ran_booster_extensions_page_submenus'][4], array( 'callback' => true ) )
		);
		self::assertSame( array( $booster, 'renderExtensionsPage' ), $GLOBALS['ran_booster_extensions_page_submenus'][4]['callback'] );
	}

	public function testDashboardRoutesExtensionsThroughTheSharedPageFrame(): void {
		$dashboard = new class() extends Dashboard {
			/** @var array<string, mixed> */
			public array $captured = array();

			public function __construct() {}

			protected function render( $view, $data = array() ) {
				$this->captured = array(
					'view' => $view,
					'data' => $data,
				);

				return true;
			}
		};

		self::assertTrue( $dashboard->getExtensions( array( array( 'id' => 'example' ) ), '/plugins.php' ) );
		self::assertSame( 'extensions', $dashboard->captured['view'] );
		self::assertSame( array(), $dashboard->captured['data']['tabs'] );
		self::assertSame( '/plugins.php', $dashboard->captured['data']['pluginsUrl'] );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function testRendersFourOfflineCardsWithTruthfulUnavailableControls(): void {
		$this->defineCompatibleApis();

		$output = $this->render();

		self::assertSame( 4, substr_count( $output, 'class="plugin-card ran-booster-extension-card"' ) );
		self::assertStringContainsString( 'class="ran-booster-page-heading__title">Extensions</h2>', $output );
		self::assertStringContainsString( 'class="ran-booster-page-heading__description">Add focused capabilities', $output );
		self::assertStringNotContainsString( '<h1', $output );
		self::assertSame( 4, substr_count( $output, '>Free<' ) );
		self::assertSame( 4, substr_count( $output, '>Sponsor<' ) );
		self::assertSame( 8, substr_count( $output, '>Beta<' ) );
		self::assertSame( 2, substr_count( $output, '>Sponsor install<' ) );
		self::assertSame( 2, substr_count( $output, '>Install unavailable<' ) );
		self::assertSame( 4, substr_count( $output, ' disabled aria-disabled="true"' ) );
		self::assertSame( 8, substr_count( $output, 'Compatible with your version of Booster' ) );
		self::assertSame( 4, substr_count( $output, '>More Details</a>' ) );
		self::assertSame( 4, substr_count( $output, 'class="thickbox ran-booster-extension-details-link"' ) );
		self::assertSame( 4, substr_count( $output, 'aria-label="More details about ' ) );
		self::assertSame( 4, substr_count( $output, 'class="ran-booster-extension-details"' ) );
		self::assertSame( 4, substr_count( $output, '>About this extension<' ) );
		self::assertStringContainsString( '#TB_inline?width=772', $output );
		self::assertStringContainsString( 'inlineId=ran-booster-extension-details-ran-booster-assisted-hooks', $output );
		self::assertStringContainsString( 'Set up, check, reconfigure, or remove one GitHub repository webhook', $output );
		self::assertStringContainsString( 'Review and adopt supported packages from an inactive WP Pusher 3.0.13 installation.', $output );
		self::assertStringContainsString( '/assets/extensions/bitbucket-cloud.svg', $output );
		self::assertStringContainsString( '/assets/extensions/release-deployments.svg', $output );
		self::assertStringNotContainsString( 'placehold.co', $output );
		self::assertStringNotContainsString( 'install-now', $output );
		self::assertStringNotContainsString( 'plugin-information?', $output );
		self::assertStringNotContainsString( '<iframe', $output );
		self::assertStringNotContainsString( '<form', $output );
		self::assertStringNotContainsString( 'RAN_BOOSTER_', $output );
		self::assertLessThan( strpos( $output, 'WP Pusher Migrator' ), strpos( $output, 'Bitbucket Cloud' ) );
		self::assertLessThan( strpos( $output, 'Assisted Hooks' ), strpos( $output, 'WP Pusher Migrator' ) );
		self::assertLessThan( strpos( $output, 'Release Deployments' ), strpos( $output, 'Assisted Hooks' ) );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function testInstalledStateComesOnlyFromLocalWordPressPluginState(): void {
		$this->defineCompatibleApis();
		$GLOBALS['ran_booster_extensions_plugins']                = array(
			'ran-booster-bitbucket/ran-booster-bitbucket.php' => array( 'Name' => 'Bitbucket' ),
			'ran-booster-assisted-hooks/ran-booster-assisted-hooks.php' => array( 'Name' => 'Hooks' ),
			'ran-booster-release-deployments/ran-booster-release-deployments.php' => array( 'Name' => 'Releases' ),
		);
		$GLOBALS['ran_booster_extensions_active_plugins']         = array( 'ran-booster-bitbucket/ran-booster-bitbucket.php' );
		$GLOBALS['ran_booster_extensions_network_active_plugins'] = array( 'ran-booster-release-deployments/ran-booster-release-deployments.php' );

		$output = $this->render();

		self::assertSame( 6, substr_count( $output, '>Active<' ) );
		self::assertSame( 3, substr_count( $output, '>Installed, inactive<' ) );
		self::assertSame( 1, substr_count( $output, 'https://example.test/wp-admin/plugins.php' ) );
		self::assertSame( 4, substr_count( $output, '>More Details</a>' ) );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function testMismatchedRequiredApiMarksTheCardIncompatible(): void {
		define( 'RAN_BOOSTER_PROVIDER_API_VERSION', 8 );
		define( 'RAN_BOOSTER_ADDON_API_VERSION', 15 );
		define( 'RAN_BOOSTER_PORTABILITY_API_VERSION', 2 );
		define( 'RAN_BOOSTER_ADMIN_INTERACTION_API_VERSION', 2 );
		$GLOBALS['ran_booster_extensions_plugins']['ran-booster-bitbucket/ran-booster-bitbucket.php'] = array( 'Name' => 'Bitbucket' );

		$output = $this->render();

		self::assertSame( 3, substr_count( $output, '>Incompatible<' ) );
		self::assertStringContainsString( 'ran-booster-badge--error', $output );
		self::assertStringContainsString( 'Requires a different version of Booster', $output );
		self::assertStringNotContainsString( '>Active<', $output );
	}

	public function testDeniedRequestRendersNothing(): void {
		$GLOBALS['ran_booster_test_capabilities']['manage_options'] = false;

		self::assertSame( '', $this->render() );
		self::assertSame( array( 'manage_options' ), $GLOBALS['ran_booster_test_capability_checks'] );
	}

	public function testPluginInventoryFailureRendersOnlyTheSafeShell(): void {
		$GLOBALS['ran_booster_extensions_plugins_failure'] = new \RuntimeException( 'private path' );

		$output = $this->render();

		self::assertStringContainsString( 'Extensions are temporarily unavailable.', $output );
		self::assertStringNotContainsString( 'private path', $output );
		self::assertStringNotContainsString( 'plugin-card', $output );
	}

	private function defineCompatibleApis(): void {
		define( 'RAN_BOOSTER_PROVIDER_API_VERSION', 9 );
		define( 'RAN_BOOSTER_ADDON_API_VERSION', 15 );
		define( 'RAN_BOOSTER_PORTABILITY_API_VERSION', 2 );
		define( 'RAN_BOOSTER_ADMIN_INTERACTION_API_VERSION', 2 );
	}

	private function render(): string {
		ob_start();
		$this->booster()->renderExtensionsPage();

		return (string) ob_get_clean();
	}

	private function booster(): Booster {
		$container = new CoreContainer();
		$container->bind(
			'RAN\\Dashboard',
			new class() {
				public function getIndex(): void {}
				public function getPluginsCreate(): void {}
				public function getPlugins(): void {}
				public function getThemesCreate(): void {}
				public function getThemes(): void {}
				/** @param list<array<string, mixed>> $extensions */
				public function getExtensions( array $extensions, string $pluginsUrl ): void {
					require dirname( __DIR__, 2 ) . '/views/extensions.php';
				}
			}
		);
		$booster              = new Booster( $container );
		$booster->boosterPath = dirname( __DIR__, 2 );
		$booster->boosterUrl  = 'https://example.test/wp-content/plugins/ran-booster';

		return $booster;
	}
}
