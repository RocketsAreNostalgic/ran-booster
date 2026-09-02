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
		$GLOBALS['ran_booster_admin_test_translations']           = array();
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
			$GLOBALS['ran_booster_extensions_plugins_failure'],
			$GLOBALS['ran_booster_admin_test_translations']
		);
	}

	public function testRegistersTheOverviewAndTransporterRoutesBeforeExtensions(): void {
		$booster = $this->booster();
		$booster->adminMenu();

		self::assertSame(
			array(
				'ran-booster',
				'ran-booster-plugins-create',
				'ran-booster-plugins',
				'ran-booster-themes-create',
				'ran-booster-themes',
				'ran-booster-transporter',
				'ran-booster-extensions',
			),
			array_column( $GLOBALS['ran_booster_extensions_page_submenus'], 'menu_slug' )
		);
		self::assertSame(
			array(
				'parent_slug' => 'ran-booster',
				'page_title'  => 'RAN Booster',
				'menu_title'  => 'Overview',
				'capability'  => 'manage_options',
				'menu_slug'   => 'ran-booster',
			),
			array_diff_key( $GLOBALS['ran_booster_extensions_page_submenus'][0], array( 'callback' => true ) )
		);
		self::assertSame( 'getIndex', $GLOBALS['ran_booster_extensions_page_submenus'][0]['callback'][1] );
		self::assertNull( $GLOBALS['ran_booster_extensions_page_menus'][0]['callback'] );
		self::assertSame(
			array(
				'parent_slug' => 'ran-booster',
				'page_title'  => 'Transporter',
				'menu_title'  => 'Transporter',
				'capability'  => 'manage_options',
				'menu_slug'   => 'ran-booster-transporter',
			),
			array_diff_key( $GLOBALS['ran_booster_extensions_page_submenus'][5], array( 'callback' => true ) )
		);
		self::assertSame( 'getTransporter', $GLOBALS['ran_booster_extensions_page_submenus'][5]['callback'][1] );
		self::assertSame(
			array(
				'parent_slug' => 'ran-booster',
				'page_title'  => 'Extensions',
				'menu_title'  => 'Extensions',
				'capability'  => 'manage_options',
				'menu_slug'   => 'ran-booster-extensions',
			),
			array_diff_key( $GLOBALS['ran_booster_extensions_page_submenus'][6], array( 'callback' => true ) )
		);
		self::assertSame( array( $booster, 'renderExtensionsPage' ), $GLOBALS['ran_booster_extensions_page_submenus'][6]['callback'] );
	}

	public function testRegistersTranslatedMenuCopyWithoutChangingItsWordPressRouteContract(): void {
		$GLOBALS['ran_booster_admin_test_translations']['ran-booster'] = array(
			'Overview'        => 'Vue d’ensemble',
			'Install Plugin'  => 'Installer une extension',
			'Managed Plugins' => 'Extensions gérées',
			'Plugins'         => 'Extensions',
			'Install Theme'   => 'Installer une apparence',
			'Managed Themes'  => 'Apparences gérées',
			'Themes'          => 'Apparences',
			'Transporter'     => 'Transfert',
			'Extensions'      => 'Modules',
		);

		$booster = $this->booster();
		$booster->adminMenu();

		self::assertSame( 'RAN Booster', $GLOBALS['ran_booster_extensions_page_menus'][0]['page_title'] );
		self::assertSame( 'RAN Booster', $GLOBALS['ran_booster_extensions_page_menus'][0]['menu_title'] );
		self::assertSame( 'manage_options', $GLOBALS['ran_booster_extensions_page_menus'][0]['capability'] );
		self::assertSame( 'ran-booster', $GLOBALS['ran_booster_extensions_page_menus'][0]['menu_slug'] );

		self::assertSame(
			array(
				array( 'ran-booster', 'RAN Booster', 'Vue d’ensemble', 'manage_options', 'ran-booster', 'getIndex' ),
				array( 'ran-booster', 'Installer une extension', 'Installer une extension', 'manage_options', 'ran-booster-plugins-create', 'getPluginsCreate' ),
				array( 'ran-booster', 'Extensions gérées', 'Extensions', 'manage_options', 'ran-booster-plugins', 'getPlugins' ),
				array( 'ran-booster', 'Installer une apparence', 'Installer une apparence', 'manage_options', 'ran-booster-themes-create', 'getThemesCreate' ),
				array( 'ran-booster', 'Apparences gérées', 'Apparences', 'manage_options', 'ran-booster-themes', 'getThemes' ),
				array( 'ran-booster', 'Transfert', 'Transfert', 'manage_options', 'ran-booster-transporter', 'getTransporter' ),
				array( 'ran-booster', 'Modules', 'Modules', 'manage_options', 'ran-booster-extensions', 'renderExtensionsPage' ),
			),
			array_map(
				static fn ( array $submenu ): array => array(
					$submenu['parent_slug'],
					$submenu['page_title'],
					$submenu['menu_title'],
					$submenu['capability'],
					$submenu['menu_slug'],
					$submenu['callback'][1],
				),
				$GLOBALS['ran_booster_extensions_page_submenus']
			)
		);
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
		self::assertArrayNotHasKey( 'tabs', $dashboard->captured['data'] );
		self::assertSame( '/plugins.php', $dashboard->captured['data']['pluginsUrl'] );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function testRendersTwoOfflineCardsWithTruthfulUnavailableControls(): void {
		$this->defineCompatibleApis();

		$output = $this->render();

		self::assertStringContainsString( 'class="ran-booster-page-shell ran-booster-extensions plugin-install-php"', $output );
		self::assertSame( 2, substr_count( $output, 'class="plugin-card plugin-card-' ) );
		self::assertSame( 2, substr_count( $output, 'class="plugin-card-top"' ) );
		self::assertSame( 2, substr_count( $output, 'class="name column-name"' ) );
		self::assertSame( 2, substr_count( $output, 'class="desc column-description"' ) );
		self::assertSame( 2, substr_count( $output, 'class="authors"' ) );
		self::assertSame( 2, substr_count( $output, 'class="plugin-card-bottom"' ) );
		self::assertSame( 2, substr_count( $output, 'class="vers column-rating ran-booster-extension-card__metadata"' ) );
		self::assertSame( 2, substr_count( $output, 'class="column-compatibility"' ) );
		self::assertStringNotContainsString( 'ran-booster-extension-card__body', $output );
		self::assertStringContainsString( 'class="ran-booster-page-heading__title">Extensions</h2>', $output );
		self::assertStringContainsString( 'class="ran-booster-page-heading__description">Add focused capabilities', $output );
		self::assertStringNotContainsString( '<h1', $output );
		self::assertSame( 4, substr_count( $output, '>Free<' ) );
		self::assertSame( 0, substr_count( $output, '>Sponsor<' ) );
		self::assertStringNotContainsString( 'Subscriber', $output );
		self::assertStringNotContainsString( 'Get access', $output );
		self::assertStringNotContainsString( 'Sponsor packages', $output );
		self::assertSame( 4, substr_count( $output, '>Beta<' ) );
		self::assertSame( 2, substr_count( $output, '>Install<' ) );
		self::assertStringNotContainsString( '>Sponsor install<', $output );
		self::assertStringNotContainsString( '>Install unavailable<', $output );
		self::assertSame( 2, substr_count( $output, ' disabled aria-disabled="true"' ) );
		self::assertSame( 2, substr_count( $output, 'Compatible with your version of Booster' ) );
		self::assertSame( 2, substr_count( $output, 'class="compatibility-compatible"' ) );
		self::assertSame( 4, substr_count( $output, 'with your version of Booster' ) );
		self::assertSame( 2, substr_count( $output, '>More Details</a>' ) );
		self::assertSame( 2, substr_count( $output, '<ul class="plugin-action-buttons">' ) );
		self::assertSame( 4, substr_count( $output, 'class="thickbox ran-booster-extension-details-link"' ) );
		self::assertSame( 4, substr_count( $output, 'aria-label="More details about ' ) );
		self::assertSame( 2, substr_count( $output, 'class="ran-booster-extension-details"' ) );
		self::assertSame( 2, substr_count( $output, '>About this extension<' ) );
		self::assertStringContainsString( '#TB_inline?width=772', $output );
		self::assertStringNotContainsString( 'ran-booster-assisted-hooks', $output );
		self::assertStringContainsString( 'Move existing WP Pusher-managed plugins and themes into Booster without reinstalling them.', $output );
		self::assertStringContainsString( 'enabling deployment remains an explicit decision', $output );
		self::assertStringContainsString( '/assets/extensions/bitbucket-cloud.svg', $output );
		self::assertStringNotContainsString( 'Release Deployments', $output );
		self::assertStringNotContainsString( '/assets/extensions/release-deployments.svg', $output );
		self::assertStringNotContainsString( 'placehold.co', $output );
		self::assertStringNotContainsString( 'install-now', $output );
		self::assertStringNotContainsString( 'plugin-information?', $output );
		self::assertStringNotContainsString( '<iframe', $output );
		self::assertStringNotContainsString( '<form', $output );
		self::assertStringNotContainsString( 'RAN_BOOSTER_', $output );
		self::assertLessThan( strpos( $output, 'WP Pusher Migrator' ), strpos( $output, 'Bitbucket Cloud' ) );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function testRendersTranslatedCatalogueCopyAndNotInstalledStateWithoutChangingProductData(): void {
		$GLOBALS['ran_booster_admin_test_translations']['ran-booster']['Connect Booster to Bitbucket Cloud repositories for managed deployments.'] = 'Connectez Booster aux dépôts Bitbucket Cloud pour des déploiements gérés.';

		$GLOBALS['ran_booster_admin_test_translations']['ran-booster']['Add Bitbucket Cloud as a first-party repository provider while Booster continues to own credentials, webhook verification, and deployment policy.'] = 'Ajoutez Bitbucket Cloud comme fournisseur de dépôts intégré.';

		$GLOBALS['ran_booster_admin_test_translations']['ran-booster']['Connect and configure Bitbucket Cloud repositories in Booster.'] = 'Connectez et configurez les dépôts Bitbucket Cloud dans Booster.';

		$GLOBALS['ran_booster_admin_test_translations']['ran-booster']['WordPress 7.0 or later and PHP 8.2 or later.'] = 'WordPress 7.0 ou version ultérieure et PHP 8.2 ou version ultérieure.';

		$GLOBALS['ran_booster_admin_test_translations']['ran-booster']['Free'] = 'Gratuit';

		$GLOBALS['ran_booster_admin_test_translations']['ran-booster']['Not installed'] = 'Non installé';

		$output = $this->render();

		self::assertStringContainsString( 'Connectez Booster aux dépôts Bitbucket Cloud', $output );
		self::assertStringContainsString( 'Ajoutez Bitbucket Cloud comme fournisseur de dépôts intégré.', $output );
		self::assertStringContainsString( 'Connectez et configurez les dépôts Bitbucket Cloud dans Booster.', $output );
		self::assertStringContainsString( 'WordPress 7.0 ou version ultérieure et PHP 8.2 ou version ultérieure.', $output );
		self::assertSame( 4, substr_count( $output, '>Gratuit<' ) );
		self::assertSame( 4, substr_count( $output, '>Non installé<' ) );
		self::assertStringContainsString( 'Bitbucket Cloud', $output );
		self::assertStringContainsString( 'WP Pusher Migrator', $output );
		self::assertStringContainsString( 'data-extension="ran-booster-bitbucket"', $output );
		self::assertStringContainsString( 'https://github.com/RocketsAreNostalgic/ran-booster-bitbucket#readme', $output );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function testInstalledStateComesOnlyFromLocalWordPressPluginState(): void {
		$this->defineCompatibleApis();
		$GLOBALS['ran_booster_extensions_plugins']                = array(
			'ran-booster-bitbucket/ran-booster-bitbucket.php' => array( 'Name' => 'Bitbucket' ),
			'ran-booster-release-deployments/ran-booster-release-deployments.php' => array( 'Name' => 'Releases' ),
		);
		$GLOBALS['ran_booster_extensions_active_plugins']         = array( 'ran-booster-bitbucket/ran-booster-bitbucket.php' );
		$GLOBALS['ran_booster_extensions_network_active_plugins'] = array( 'ran-booster-release-deployments/ran-booster-release-deployments.php' );

		$output = $this->render();

		self::assertSame( 3, substr_count( $output, '>Active<' ) );
		self::assertSame( 0, substr_count( $output, '>Inactive<' ) );
		self::assertSame( 0, substr_count( $output, '>Installed, inactive<' ) );
		self::assertSame( 0, substr_count( $output, 'https://example.test/wp-admin/plugins.php' ) );
		self::assertSame( 2, substr_count( $output, '>More Details</a>' ) );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function testRendersTranslatedActiveAndInstalledInactiveStateLabelsWithoutChangingStateControls(): void {
		$this->defineCompatibleApis();
		$GLOBALS['ran_booster_admin_test_translations']['ran-booster'] = array(
			'Active'              => 'Actif',
			'Installed, inactive' => 'Installé, inactif',
		);

		$GLOBALS['ran_booster_extensions_plugins'] = array(
			'ran-booster-bitbucket/ran-booster-bitbucket.php'           => array( 'Name' => 'Bitbucket' ),
			'ran-booster-wp-pusher-migrator/ran-booster-wp-pusher-migrator.php' => array( 'Name' => 'WP Pusher Migrator' ),
		);

		$GLOBALS['ran_booster_extensions_active_plugins'] = array( 'ran-booster-bitbucket/ran-booster-bitbucket.php' );

		$output = $this->render();

		self::assertSame( 3, substr_count( $output, '>Actif<' ) );
		self::assertSame( 2, substr_count( $output, '>Installé, inactif<' ) );
		self::assertSame( 1, substr_count( $output, '>Inactive<' ) );
		self::assertSame( 1, substr_count( $output, 'https://example.test/wp-admin/plugins.php' ) );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function testMismatchedRequiredApiMarksTheCardIncompatible(): void {
		define( 'RAN_BOOSTER_PROVIDER_API_VERSION', 9 );
		define( 'RAN_BOOSTER_ADDON_API_VERSION', 16 );
		define( 'RAN_BOOSTER_PORTABILITY_API_VERSION', 2 );
		define( 'RAN_BOOSTER_ADMIN_INTERACTION_API_VERSION', 2 );
		$GLOBALS['ran_booster_extensions_plugins']['ran-booster-bitbucket/ran-booster-bitbucket.php'] = array( 'Name' => 'Bitbucket' );

		$output = $this->render();

		self::assertSame( 2, substr_count( $output, '>Incompatible<' ) );
		self::assertSame( 1, substr_count( $output, '>Inactive<' ) );
		self::assertStringContainsString( 'ran-booster-badge--error', $output );
		self::assertStringContainsString( 'Requires a different version of Booster', $output );
		self::assertStringNotContainsString( '>Active<', $output );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function testRendersTranslatedIncompatibleStateLabelWhileKeepingItsErrorState(): void {
		define( 'RAN_BOOSTER_PROVIDER_API_VERSION', 9 );
		define( 'RAN_BOOSTER_ADDON_API_VERSION', 16 );
		define( 'RAN_BOOSTER_PORTABILITY_API_VERSION', 2 );
		define( 'RAN_BOOSTER_ADMIN_INTERACTION_API_VERSION', 2 );
		$GLOBALS['ran_booster_admin_test_translations']['ran-booster']['Incompatible'] = 'Incompatible traduit';

		$GLOBALS['ran_booster_extensions_plugins']['ran-booster-bitbucket/ran-booster-bitbucket.php'] = array( 'Name' => 'Bitbucket' );

		$output = $this->render();

		self::assertSame( 2, substr_count( $output, '>Incompatible traduit<' ) );
		self::assertStringContainsString( 'ran-booster-badge--error', $output );
		self::assertStringContainsString( '>Inactive<', $output );
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
		define( 'RAN_BOOSTER_PROVIDER_API_VERSION', 10 );
		define( 'RAN_BOOSTER_ADDON_API_VERSION', 16 );
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
				public function getTransporter(): void {}
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
