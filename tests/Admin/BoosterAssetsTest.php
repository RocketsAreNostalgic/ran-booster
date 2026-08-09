<?php

declare(strict_types=1);

namespace Tests\Admin;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RAN\Admin\BackgroundDeploymentFailureNotice;
use RAN\Admin\BackgroundDeploymentFailureNoticeController;
use RAN\Admin\CredentialExpiryNotice;
use RAN\Admin\CredentialExpiryNoticeController;
use RAN\Admin\DevelopmentSafetyNoticeController;
use RAN\Admin\PackageUpdateProgressController;
use RAN\Booster;
use RAN\Internal\CoreContainer;
use RAN\RepositoryProvider\ProviderRegistry;

require_once dirname( __DIR__ ) . '/Support/ProviderCredentialDispatcherWordPressFunctions.php';
require_once __DIR__ . '/DashboardRoutingWordPressFunctions.php';
require_once __DIR__ . '/BoosterAssetsWordPressFunctions.php';

final class BoosterAssetsTest extends TestCase {

	protected function setUp(): void {
		$_GET = array();
		$GLOBALS['ran_booster_asset_test_registered_styles']  = array();
		$GLOBALS['ran_booster_asset_test_enqueued_styles']    = array();
		$GLOBALS['ran_booster_asset_test_registered_scripts'] = array();
		$GLOBALS['ran_booster_asset_test_enqueued_scripts']   = array();
		$GLOBALS['ran_booster_asset_test_localized_scripts']  = array();
	}

	protected function tearDown(): void {
		$_GET = array();
		unset(
			$GLOBALS['ran_booster_asset_test_registered_styles'],
			$GLOBALS['ran_booster_asset_test_enqueued_styles'],
			$GLOBALS['ran_booster_asset_test_registered_scripts'],
			$GLOBALS['ran_booster_asset_test_enqueued_scripts'],
			$GLOBALS['ran_booster_asset_test_localized_scripts']
		);
	}

	/** @return list<array{string}> */
	public static function unrelatedAdminHookProvider(): array {
		return array(
			array( 'index.php' ),
			array( 'plugins.php' ),
			array( 'themes.php' ),
			array( 'settings_page_ran-booster' ),
			array( 'ran-booster_page_ran-booster-pro' ),
		);
	}

	#[DataProvider( 'unrelatedAdminHookProvider' )]
	public function testUnrelatedAdminPagesReceiveNoBoosterAssets( string $hook ): void {
		$this->booster()->loadScripts( $hook );

		self::assertSame( array(), $GLOBALS['ran_booster_asset_test_registered_styles'] );
		self::assertSame( array(), $GLOBALS['ran_booster_asset_test_enqueued_styles'] );
		self::assertSame( array(), $GLOBALS['ran_booster_asset_test_registered_scripts'] );
		self::assertSame( array(), $GLOBALS['ran_booster_asset_test_enqueued_scripts'] );
		self::assertSame( array(), $GLOBALS['ran_booster_asset_test_localized_scripts'] );
	}

	public function testExpiryNoticeLoadsItsSmallAssetOnAnyAdminScreenOnlyWhenVisible(): void {
		$booster = $this->booster( true );

		$booster->loadCredentialExpiryNoticeScript( 'plugins.php' );

		self::assertSame(
			array( 'ran-booster-credential-expiry-notice' ),
			$GLOBALS['ran_booster_asset_test_enqueued_scripts']
		);
		self::assertSame(
			array(
				'ajaxUrl' => 'https://example.test/wp-admin/admin-ajax.php',
				'action'  => CredentialExpiryNoticeController::AJAX_ACTION,
				'nonce'   => 'nonce-for-' . hash( 'sha256', CredentialExpiryNoticeController::NONCE_ACTION ),
			),
			$GLOBALS['ran_booster_asset_test_localized_scripts']['ran-booster-credential-expiry-notice']['ranBoosterCredentialExpiryNotice']
		);
	}

	public function testExpiryNoticeAssetIsAbsentWithoutAVisibleReminder(): void {
		$this->booster()->loadCredentialExpiryNoticeScript( 'plugins.php' );

		self::assertSame( array(), $GLOBALS['ran_booster_asset_test_registered_scripts'] );
		self::assertSame( array(), $GLOBALS['ran_booster_asset_test_enqueued_scripts'] );
	}

	public function testExpiryNoticeAssetIsAbsentForThePersistentStorageFailureNotice(): void {
		$this->booster( true, false, false )->loadCredentialExpiryNoticeScript( 'plugins.php' );

		self::assertSame( array(), $GLOBALS['ran_booster_asset_test_registered_scripts'] );
		self::assertSame( array(), $GLOBALS['ran_booster_asset_test_enqueued_scripts'] );
	}

	public function testBackgroundFailureNoticeLoadsItsSmallAssetOnAnyAdminScreenOnlyWhenVisible(): void {
		$booster = $this->booster( false, true );

		$booster->loadBackgroundDeploymentFailureNoticeScript( 'plugins.php' );

		self::assertSame(
			array( 'ran-booster-background-deployment-failure-notice' ),
			$GLOBALS['ran_booster_asset_test_enqueued_scripts']
		);
		self::assertSame(
			array(
				'ajaxUrl' => 'https://example.test/wp-admin/admin-ajax.php',
				'action'  => BackgroundDeploymentFailureNoticeController::AJAX_ACTION,
				'nonce'   => 'nonce-for-' . hash( 'sha256', BackgroundDeploymentFailureNoticeController::NONCE_ACTION ),
			),
			$GLOBALS['ran_booster_asset_test_localized_scripts']['ran-booster-background-deployment-failure-notice']['ranBoosterBackgroundFailureNotice']
		);
	}

	public function testBackgroundFailureNoticeAssetIsAbsentWithoutAVisibleFailure(): void {
		$this->booster()->loadBackgroundDeploymentFailureNoticeScript( 'plugins.php' );

		self::assertSame( array(), $GLOBALS['ran_booster_asset_test_registered_scripts'] );
		self::assertSame( array(), $GLOBALS['ran_booster_asset_test_enqueued_scripts'] );
	}

	/** @return list<array{string}> */
	public static function packageAdminHookProvider(): array {
		return array(
			array( 'ran-booster_page_ran-booster-plugins-create' ),
			array( 'ran-booster_page_ran-booster-plugins' ),
			array( 'ran-booster_page_ran-booster-themes-create' ),
			array( 'ran-booster_page_ran-booster-themes' ),
		);
	}

	#[DataProvider( 'packageAdminHookProvider' )]
	public function testPackagePagesReceiveCommonAssetsAndPickerLocalization( string $hook ): void {
		$_GET['page'] = 'untrusted-mismatch';

		$this->booster()->loadScripts( $hook );

		self::assertSame( array( 'ran-booster-styles' ), $GLOBALS['ran_booster_asset_test_enqueued_styles'] );
		self::assertSame(
			array( 'ran-booster-htmx', 'ran-booster-js', 'ran-booster-secure-inputs', 'ran-booster-enhanced-mutations', 'ran-booster-packages', 'ran-booster-repository-picker' ),
			$GLOBALS['ran_booster_asset_test_enqueued_scripts']
		);
		self::assertSame(
			array( 'ran-booster-htmx' ),
			$GLOBALS['ran_booster_asset_test_registered_scripts']['ran-booster-js']['dependencies']
		);
		self::assertSame(
			array(),
			$GLOBALS['ran_booster_asset_test_registered_scripts']['ran-booster-htmx']['dependencies']
		);
		self::assertStringEndsWith(
			'/assets/lib/htmx/htmx.min.js',
			$GLOBALS['ran_booster_asset_test_registered_scripts']['ran-booster-htmx']['source']
		);
		self::assertTrue( $GLOBALS['ran_booster_asset_test_registered_scripts']['ran-booster-htmx']['footer'] );
		self::assertSame(
			array( 'ran-booster-js' ),
			$GLOBALS['ran_booster_asset_test_registered_scripts']['ran-booster-secure-inputs']['dependencies']
		);
		self::assertStringEndsWith(
			'/assets/ran-booster-secure-inputs.js',
			$GLOBALS['ran_booster_asset_test_registered_scripts']['ran-booster-secure-inputs']['source']
		);
		self::assertTrue( $GLOBALS['ran_booster_asset_test_registered_scripts']['ran-booster-secure-inputs']['footer'] );
		self::assertSame(
			array( 'ran-booster-js', 'wp-a11y' ),
			$GLOBALS['ran_booster_asset_test_registered_scripts']['ran-booster-enhanced-mutations']['dependencies']
		);
		self::assertStringEndsWith(
			'/assets/ran-booster-enhanced-mutations.js',
			$GLOBALS['ran_booster_asset_test_registered_scripts']['ran-booster-enhanced-mutations']['source']
		);
		self::assertTrue( $GLOBALS['ran_booster_asset_test_registered_scripts']['ran-booster-enhanced-mutations']['footer'] );
		self::assertSame(
			array( 'ran-booster-enhanced-mutations' ),
			$GLOBALS['ran_booster_asset_test_registered_scripts']['ran-booster-packages']['dependencies']
		);
		self::assertStringEndsWith(
			'/assets/ran-booster-packages.js',
			$GLOBALS['ran_booster_asset_test_registered_scripts']['ran-booster-packages']['source']
		);
		self::assertTrue( $GLOBALS['ran_booster_asset_test_registered_scripts']['ran-booster-packages']['footer'] );
		self::assertSame(
			array( 'ran-booster-js' ),
			$GLOBALS['ran_booster_asset_test_registered_scripts']['ran-booster-repository-picker']['dependencies']
		);
		self::assertStringEndsWith(
			'/assets/ran-booster-repository-picker.js',
			$GLOBALS['ran_booster_asset_test_registered_scripts']['ran-booster-repository-picker']['source']
		);
		self::assertTrue( $GLOBALS['ran_booster_asset_test_registered_scripts']['ran-booster-repository-picker']['footer'] );
		self::assertArrayNotHasKey(
			'ran-booster-js',
			$GLOBALS['ran_booster_asset_test_localized_scripts']
		);
		self::assertArrayNotHasKey(
			'ranBoosterRepoPicker',
			$GLOBALS['ran_booster_asset_test_localized_scripts']['ran-booster-packages']
		);
		self::assertSame(
			array(
				'ajaxUrl' => 'https://example.test/wp-admin/admin-ajax.php',
				'action'  => DevelopmentSafetyNoticeController::AJAX_ACTION,
				'nonce'   => 'nonce-for-' . hash( 'sha256', DevelopmentSafetyNoticeController::NONCE_ACTION ),
			),
			$GLOBALS['ran_booster_asset_test_localized_scripts']['ran-booster-packages']['ranBoosterDevelopmentSafetyNotice']
		);
		self::assertSame(
			'gh',
			$GLOBALS['ran_booster_asset_test_localized_scripts']['ran-booster-repository-picker']['ranBoosterRepoPicker']['defaultProvider']
		);
		if ( str_ends_with( $hook, '-plugins' ) || str_ends_with( $hook, '-themes' ) ) {
			self::assertSame(
				PackageUpdateProgressController::AJAX_ACTION,
				$GLOBALS['ran_booster_asset_test_localized_scripts']['ran-booster-packages']['ranBoosterPackageProgress']['action']
			);
			self::assertSame(
				'nonce-for-' . hash( 'sha256', PackageUpdateProgressController::NONCE_ACTION ),
				$GLOBALS['ran_booster_asset_test_localized_scripts']['ran-booster-packages']['ranBoosterPackageProgress']['nonce']
			);
		} else {
			self::assertArrayNotHasKey(
				'ranBoosterPackageProgress',
				$GLOBALS['ran_booster_asset_test_localized_scripts']['ran-booster-packages']
			);
		}
	}

	public function testCommonStylesheetsPreserveCascadeWithPerComponentVersions(): void {
		$this->booster()->loadScripts( 'ran-booster_page_ran-booster-themes' );

		$expectedStyles = array(
			'ran-booster-00-foundations'                  => '00-foundations.css',
			'ran-booster-10-buttons'                      => '10-buttons.css',
			'ran-booster-15-enhanced-mutations'           => '15-enhanced-mutations.css',
			'ran-booster-20-repository-picker'            => '20-repository-picker.css',
			'ran-booster-25-admin-primitives'             => '25-admin-primitives.css',
			'ran-booster-30-provider-cards'               => '30-provider-cards.css',
			'ran-booster-35-status-utilities'             => '35-status-utilities.css',
			'ran-booster-40-tables-and-pills'             => '40-tables-and-pills.css',
			'ran-booster-50-troubleshooting-and-activity' => '50-troubleshooting-and-activity.css',
			'ran-booster-60-packages'                     => '60-packages.css',
			'ran-booster-65-package-settings'             => '65-package-settings.css',
			'ran-booster-70-credential-dialog'            => '70-credential-dialog.css',
			'ran-booster-styles'                          => '80-responsive.css',
		);
		$previousHandle = null;

		self::assertSame( array_keys( $expectedStyles ), array_keys( $GLOBALS['ran_booster_asset_test_registered_styles'] ) );
		foreach ( $expectedStyles as $handle => $file ) {
			$registeredStyle = $GLOBALS['ran_booster_asset_test_registered_styles'][ $handle ];

			self::assertStringEndsWith( '/assets/ran-booster/' . $file, $registeredStyle['source'] );
			self::assertIsInt( $registeredStyle['version'] );
			self::assertSame( null === $previousHandle ? array() : array( $previousHandle ), $registeredStyle['dependencies'] );
			$previousHandle = $handle;
		}
	}

	public function testDocumentationTabReceivesTopLevelAndPageSpecificStyles(): void {
		$_GET['tab'] = 'documentation';

		$this->booster()->loadScripts( 'toplevel_page_ran-booster' );

		self::assertSame(
			array( 'ran-booster-styles', 'ran-booster-onboarding', 'ran-booster-documentation' ),
			$GLOBALS['ran_booster_asset_test_enqueued_styles']
		);
		self::assertArrayHasKey( 'ran-booster-documentation', $GLOBALS['ran_booster_asset_test_registered_styles'] );
		self::assertArrayHasKey( 'ran-booster-onboarding', $GLOBALS['ran_booster_asset_test_registered_styles'] );
		self::assertSame( array(), $GLOBALS['ran_booster_asset_test_localized_scripts'] );
	}

	public function testPortabilityTabReceivesItsNarrowAjaxConfiguration(): void {
		$_GET['tab'] = 'portability';

		$this->booster()->loadScripts( 'toplevel_page_ran-booster' );

		self::assertSame(
			array( 'ran-booster-htmx', 'ran-booster-js', 'ran-booster-secure-inputs', 'ran-booster-enhanced-mutations', 'ran-booster-portability' ),
			$GLOBALS['ran_booster_asset_test_enqueued_scripts']
		);
		self::assertSame(
			array( 'ran-booster-htmx' ),
			$GLOBALS['ran_booster_asset_test_registered_scripts']['ran-booster-js']['dependencies']
		);
		self::assertSame(
			array( 'ran-booster-secure-inputs', 'ran-booster-enhanced-mutations' ),
			$GLOBALS['ran_booster_asset_test_registered_scripts']['ran-booster-portability']['dependencies']
		);
		self::assertStringEndsWith(
			'/assets/ran-booster-portability.js',
			$GLOBALS['ran_booster_asset_test_registered_scripts']['ran-booster-portability']['source']
		);
		self::assertTrue( $GLOBALS['ran_booster_asset_test_registered_scripts']['ran-booster-portability']['footer'] );
		self::assertArrayHasKey( 'ranBoosterPortability', $GLOBALS['ran_booster_asset_test_localized_scripts']['ran-booster-portability'] );
		self::assertSame(
			'https://example.test/wp-admin/admin-ajax.php',
			$GLOBALS['ran_booster_asset_test_localized_scripts']['ran-booster-portability']['ranBoosterPortability']['ajaxUrl']
		);
	}

	public function testProviderTabDetectionUsesRegisteredAdministrationMetadata(): void {
		$container = new CoreContainer();
		$container->bind(
			ProviderRegistry::class,
			new class() {

				/** @return list<object> */
				public function administrationMetadata(): array {
					return array(
						(object) array(
							'code' => \RAN\RepositoryProvider\ProviderCode::parse( 'gh' ),
						),
					);
				}
			}
		);
		$booster = new class( $container ) extends Booster {

			public function providerTab( ?string $tab ): bool {
				return $this->isProviderAdminTab( $tab );
			}
		};

		self::assertTrue( $booster->providerTab( 'gh' ) );
		self::assertFalse( $booster->providerTab( 'unknown' ) );
		self::assertFalse( $booster->providerTab( 'documentation' ) );
		self::assertFalse( $booster->providerTab( null ) );
	}

	/** @return list<array{string}> */
	public static function providerTabProvider(): array {
		return array(
			array( 'gh' ),
			array( 'bb' ),
		);
	}

	#[DataProvider( 'providerTabProvider' )]
	public function testProviderTabsReceiveBoundedHtmxAlongsideCommonAssets( string $tab ): void {
		$_GET['tab'] = $tab;

		$this->booster()->loadScripts( 'toplevel_page_ran-booster' );

		self::assertSame(
			array( 'ran-booster-styles', 'ran-booster-onboarding' ),
			$GLOBALS['ran_booster_asset_test_enqueued_styles']
		);
		self::assertSame(
			array( 'ran-booster-htmx', 'ran-booster-js', 'ran-booster-secure-inputs', 'ran-booster-enhanced-mutations' ),
			$GLOBALS['ran_booster_asset_test_enqueued_scripts']
		);
		self::assertSame(
			array( 'ran-booster-htmx' ),
			$GLOBALS['ran_booster_asset_test_registered_scripts']['ran-booster-js']['dependencies']
		);
		self::assertSame(
			array( 'ran-booster-js', 'wp-a11y' ),
			$GLOBALS['ran_booster_asset_test_registered_scripts']['ran-booster-enhanced-mutations']['dependencies']
		);
		self::assertStringEndsWith(
			'/assets/lib/htmx/htmx.min.js',
			$GLOBALS['ran_booster_asset_test_registered_scripts']['ran-booster-htmx']['source']
		);
		self::assertTrue( $GLOBALS['ran_booster_asset_test_registered_scripts']['ran-booster-htmx']['footer'] );
		self::assertSame( array(), $GLOBALS['ran_booster_asset_test_localized_scripts'] );
	}

	/** @return list<array{mixed}> */
	public static function fallbackTabProvider(): array {
		return array(
			array( null ),
			array( 'unknown' ),
			array( array( 'gh' ) ),
		);
	}

	#[DataProvider( 'fallbackTabProvider' )]
	public function testFallbackTabsDoNotLoadHtmx( mixed $tab ): void {
		if ( null !== $tab ) {
			$_GET['tab'] = $tab;
		}

		$this->booster()->loadScripts( 'toplevel_page_ran-booster' );

		self::assertSame(
			array( 'ran-booster-styles', 'ran-booster-onboarding' ),
			$GLOBALS['ran_booster_asset_test_enqueued_styles']
		);
		self::assertArrayHasKey( 'ran-booster-onboarding', $GLOBALS['ran_booster_asset_test_registered_styles'] );
		self::assertArrayNotHasKey( 'ran-booster-documentation', $GLOBALS['ran_booster_asset_test_registered_styles'] );
		self::assertSame(
			array( 'ran-booster-js', 'ran-booster-secure-inputs', 'ran-booster-enhanced-mutations' ),
			$GLOBALS['ran_booster_asset_test_enqueued_scripts']
		);
		self::assertSame(
			array(),
			$GLOBALS['ran_booster_asset_test_registered_scripts']['ran-booster-js']['dependencies']
		);
		self::assertSame(
			array( 'ran-booster-js', 'wp-a11y' ),
			$GLOBALS['ran_booster_asset_test_registered_scripts']['ran-booster-enhanced-mutations']['dependencies']
		);
		self::assertArrayNotHasKey( 'ran-booster-htmx', $GLOBALS['ran_booster_asset_test_registered_scripts'] );
		self::assertSame( array(), $GLOBALS['ran_booster_asset_test_localized_scripts'] );
	}

	/** @return list<array{string}> */
	public static function staticTabProvider(): array {
		return array(
			array( 'troubleshooting' ),
		);
	}

	#[DataProvider( 'staticTabProvider' )]
	public function testStaticTabsReceiveTopLevelStyleAlongsideCommonAssets( string $tab ): void {
		$_GET['tab'] = $tab;

		$this->booster()->loadScripts( 'toplevel_page_ran-booster' );

		self::assertSame( array( 'ran-booster-styles', 'ran-booster-onboarding' ), $GLOBALS['ran_booster_asset_test_enqueued_styles'] );
		self::assertSame(
			array( 'ran-booster-htmx', 'ran-booster-js', 'ran-booster-secure-inputs', 'ran-booster-enhanced-mutations' ),
			$GLOBALS['ran_booster_asset_test_enqueued_scripts']
		);
		self::assertSame(
			array( 'ran-booster-htmx' ),
			$GLOBALS['ran_booster_asset_test_registered_scripts']['ran-booster-js']['dependencies']
		);
		self::assertSame(
			array( 'ran-booster-js', 'wp-a11y' ),
			$GLOBALS['ran_booster_asset_test_registered_scripts']['ran-booster-enhanced-mutations']['dependencies']
		);
		self::assertSame( array(), $GLOBALS['ran_booster_asset_test_localized_scripts'] );
	}

	private function booster(
		bool $expiryNoticeVisible = false,
		bool $backgroundFailureNoticeVisible = false,
		bool $expiryNoticeDismissible = true
	): Booster {
		$container = new CoreContainer();
		$booster   = new class( $container ) extends Booster {

			public bool $expiryNoticeVisible            = false;
			public bool $expiryNoticeDismissible        = true;
			public bool $backgroundFailureNoticeVisible = false;

			protected function isProviderAdminTab( ?string $tab ): bool {
				return in_array( $tab, array( 'gh', 'bb' ), true );
			}
		};
		$container->bind(
			CredentialExpiryNotice::class,
			static fn (): object => new class( $booster->expiryNoticeVisible, $booster->expiryNoticeDismissible ) {
				public function __construct( private bool $visible, private bool $dismissible ) {
				}

				public function shouldLoadDismissalScript(): bool {
					return $this->visible && $this->dismissible;
				}
			}
		);
		$container->bind(
			BackgroundDeploymentFailureNotice::class,
			static fn (): object => new class( $booster->backgroundFailureNoticeVisible ) {
				public function __construct( private bool $visible ) {
				}

				public function shouldRender(): bool {
					return $this->visible;
				}
			}
		);
		$container->bind(
			'RAN\\Admin\\ProviderSettingsPresenter',
			new class() {
				/** @return array{default_provider: string, providers: array<string, mixed>} */
				public function buildPackageForm(): array {
					return array(
						'default_provider' => 'gh',
						'providers'        => array( 'gh' => array( 'label' => 'GitHub' ) ),
					);
				}
			}
		);
		$booster->boosterPath                    = dirname( __DIR__, 2 );
		$booster->boosterUrl                     = 'https://example.test/wp-content/plugins/ran-booster';
		$booster->expiryNoticeVisible            = $expiryNoticeVisible;
		$booster->expiryNoticeDismissible        = $expiryNoticeDismissible;
		$booster->backgroundFailureNoticeVisible = $backgroundFailureNoticeVisible;

		return $booster;
	}
}
