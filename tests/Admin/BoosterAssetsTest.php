<?php

declare(strict_types=1);

namespace Tests\Admin;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RAN\Admin\DeploymentAdminController;
use RAN\Admin\DeploymentAdminPresenter;
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
		$GLOBALS['ran_booster_asset_test_registered_styles']   = array();
		$GLOBALS['ran_booster_asset_test_enqueued_styles']     = array();
		$GLOBALS['ran_booster_asset_test_registered_scripts']  = array();
		$GLOBALS['ran_booster_asset_test_enqueued_scripts']    = array();
		$GLOBALS['ran_booster_asset_test_localized_scripts']   = array();
		$GLOBALS['ran_booster_asset_test_script_events']       = array();
		$GLOBALS['ran_booster_asset_test_script_translations'] = array();
	}

	protected function tearDown(): void {
		$_GET = array();
		unset(
			$GLOBALS['ran_booster_asset_test_registered_styles'],
			$GLOBALS['ran_booster_asset_test_enqueued_styles'],
			$GLOBALS['ran_booster_asset_test_registered_scripts'],
			$GLOBALS['ran_booster_asset_test_enqueued_scripts'],
			$GLOBALS['ran_booster_asset_test_localized_scripts'],
			$GLOBALS['ran_booster_asset_test_script_events'],
			$GLOBALS['ran_booster_asset_test_script_translations']
		);
	}

	/** @return list<array{string}> */
	public static function unrelated_admin_hook_provider(): array {
		return array(
			array( 'index.php' ),
			array( 'plugins.php' ),
			array( 'themes.php' ),
			array( 'settings_page_ran-booster' ),
			array( 'ran-booster_page_unrelated' ),
		);
	}

	public function test_extensions_page_receives_the_common_and_native_modal_assets(): void {
		$this->booster()->load_scripts( 'ran-booster_page_ran-booster-extensions' );

		self::assertSame( array( 'ran-booster-admin-shell', 'ran-booster-styles', 'thickbox' ), $GLOBALS['ran_booster_asset_test_enqueued_styles'] );
		self::assertSame( array( 'thickbox', 'ran-booster-extension-details' ), $GLOBALS['ran_booster_asset_test_enqueued_scripts'] );
		self::assertSame( array( 'ran-booster-extension-details' ), array_keys( $GLOBALS['ran_booster_asset_test_registered_scripts'] ) );
		self::assertSame(
			array( 'jquery', 'thickbox' ),
			$GLOBALS['ran_booster_asset_test_registered_scripts']['ran-booster-extension-details']['dependencies']
		);
		self::assertArrayHasKey( 'ran-booster-55-extensions', $GLOBALS['ran_booster_asset_test_registered_styles'] );
		self::assertSame( array(), $GLOBALS['ran_booster_asset_test_localized_scripts'] );
	}

	#[DataProvider( 'unrelated_admin_hook_provider' )]
	public function test_unrelated_admin_pages_receive_no_booster_assets( string $hook ): void {
		$this->booster()->load_scripts( $hook );

		self::assertSame( array(), $GLOBALS['ran_booster_asset_test_registered_styles'] );
		self::assertSame( array(), $GLOBALS['ran_booster_asset_test_enqueued_styles'] );
		self::assertSame( array(), $GLOBALS['ran_booster_asset_test_registered_scripts'] );
		self::assertSame( array(), $GLOBALS['ran_booster_asset_test_enqueued_scripts'] );
		self::assertSame( array(), $GLOBALS['ran_booster_asset_test_localized_scripts'] );
	}

	public function test_expiry_notice_loads_its_small_asset_on_any_admin_screen_only_when_visible(): void {
		$booster = $this->booster( true );

		$booster->load_credential_expiry_notice_script( 'plugins.php' );

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

	public function test_expiry_notice_asset_is_absent_without_avisible_reminder(): void {
		$this->booster()->load_credential_expiry_notice_script( 'plugins.php' );

		self::assertSame( array(), $GLOBALS['ran_booster_asset_test_registered_scripts'] );
		self::assertSame( array(), $GLOBALS['ran_booster_asset_test_enqueued_scripts'] );
	}

	public function test_expiry_notice_asset_is_absent_for_the_persistent_storage_failure_notice(): void {
		$this->booster( true, false, false )->load_credential_expiry_notice_script( 'plugins.php' );

		self::assertSame( array(), $GLOBALS['ran_booster_asset_test_registered_scripts'] );
		self::assertSame( array(), $GLOBALS['ran_booster_asset_test_enqueued_scripts'] );
	}

	public function test_background_failure_notice_loads_its_small_asset_on_any_admin_screen_only_when_visible(): void {
		$booster = $this->booster( false, true );

		$booster->load_background_deployment_failure_notice_script( 'plugins.php' );

		self::assertSame(
			array( 'ran-booster-background-deployment-failure-notice' ),
			$GLOBALS['ran_booster_asset_test_enqueued_scripts']
		);
		self::assertSame(
			array(
				'ajaxUrl' => 'https://example.test/wp-admin/admin-ajax.php',
				'action'  => DeploymentAdminController::AJAX_ACTION,
				'nonce'   => 'nonce-for-' . hash( 'sha256', DeploymentAdminController::NONCE_ACTION ),
			),
			$GLOBALS['ran_booster_asset_test_localized_scripts']['ran-booster-background-deployment-failure-notice']['ranBoosterBackgroundFailureNotice']
		);
	}

	public function test_background_failure_notice_asset_is_absent_without_avisible_failure(): void {
		$this->booster()->load_background_deployment_failure_notice_script( 'plugins.php' );

		self::assertSame( array(), $GLOBALS['ran_booster_asset_test_registered_scripts'] );
		self::assertSame( array(), $GLOBALS['ran_booster_asset_test_enqueued_scripts'] );
	}

	/** @return list<array{string}> */
	public static function package_admin_hook_provider(): array {
		return array(
			array( 'ran-booster_page_ran-booster-plugins-create' ),
			array( 'ran-booster_page_ran-booster-plugins' ),
			array( 'ran-booster_page_ran-booster-themes-create' ),
			array( 'ran-booster_page_ran-booster-themes' ),
		);
	}

	#[DataProvider( 'package_admin_hook_provider' )]
	public function test_package_pages_receive_common_assets_and_picker_localization( string $hook ): void {
		$_GET['page'] = 'untrusted-mismatch';

		$this->booster()->load_scripts( $hook );

		self::assertSame( array( 'ran-booster-admin-shell', 'ran-booster-styles' ), $GLOBALS['ran_booster_asset_test_enqueued_styles'] );
		self::assertSame(
			array( 'ran-booster-htmx', 'ran-booster-js', 'ran-booster-secure-inputs', 'ran-booster-enhanced-mutations', 'ran-booster-packages', 'ran-booster-repository-picker' ),
			$GLOBALS['ran_booster_asset_test_enqueued_scripts']
		);
		self::assertSame(
			array( 'ran-booster-htmx', 'wp-i18n' ),
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
			array( 'ran-booster-js', 'wp-i18n' ),
			$GLOBALS['ran_booster_asset_test_registered_scripts']['ran-booster-secure-inputs']['dependencies']
		);
		self::assertStringEndsWith(
			'/assets/ran-booster-secure-inputs.js',
			$GLOBALS['ran_booster_asset_test_registered_scripts']['ran-booster-secure-inputs']['source']
		);
		self::assertTrue( $GLOBALS['ran_booster_asset_test_registered_scripts']['ran-booster-secure-inputs']['footer'] );
		self::assertSame(
			array( 'ran-booster-js', 'wp-a11y', 'wp-i18n' ),
			$GLOBALS['ran_booster_asset_test_registered_scripts']['ran-booster-enhanced-mutations']['dependencies']
		);
		self::assertStringEndsWith(
			'/assets/ran-booster-enhanced-mutations.js',
			$GLOBALS['ran_booster_asset_test_registered_scripts']['ran-booster-enhanced-mutations']['source']
		);
		self::assertTrue( $GLOBALS['ran_booster_asset_test_registered_scripts']['ran-booster-enhanced-mutations']['footer'] );
		self::assertSame(
			array(
				array(
					'handle' => 'ran-booster-js',
					'domain' => 'ran-booster',
					'path'   => dirname( __DIR__, 2 ) . '/languages',
				),
				array(
					'handle' => 'ran-booster-secure-inputs',
					'domain' => 'ran-booster',
					'path'   => dirname( __DIR__, 2 ) . '/languages',
				),
				array(
					'handle' => 'ran-booster-enhanced-mutations',
					'domain' => 'ran-booster',
					'path'   => dirname( __DIR__, 2 ) . '/languages',
				),
				array(
					'handle' => 'ran-booster-packages',
					'domain' => 'ran-booster',
					'path'   => dirname( __DIR__, 2 ) . '/languages',
				),
				array(
					'handle' => 'ran-booster-repository-picker',
					'domain' => 'ran-booster',
					'path'   => dirname( __DIR__, 2 ) . '/languages',
				),
			),
			$GLOBALS['ran_booster_asset_test_script_translations']
		);
		foreach ( array_column( $GLOBALS['ran_booster_asset_test_script_translations'], 'handle' ) as $handle ) {
			$registered_at = array_search(
				array(
					'function' => 'wp_register_script',
					'handle'   => $handle,
				),
				$GLOBALS['ran_booster_asset_test_script_events'],
				true
			);
			$translated_at = array_search(
				array(
					'function' => 'wp_set_script_translations',
					'handle'   => $handle,
				),
				$GLOBALS['ran_booster_asset_test_script_events'],
				true
			);
			self::assertIsInt( $registered_at );
			self::assertIsInt( $translated_at );
			self::assertLessThan( $translated_at, $registered_at );
		}
		self::assertSame(
			array( 'ran-booster-enhanced-mutations', 'wp-i18n' ),
			$GLOBALS['ran_booster_asset_test_registered_scripts']['ran-booster-packages']['dependencies']
		);
		self::assertStringEndsWith(
			'/assets/ran-booster-packages.js',
			$GLOBALS['ran_booster_asset_test_registered_scripts']['ran-booster-packages']['source']
		);
		self::assertTrue( $GLOBALS['ran_booster_asset_test_registered_scripts']['ran-booster-packages']['footer'] );
		self::assertSame(
			array( 'ran-booster-js', 'wp-i18n' ),
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

	public function test_common_stylesheets_preserve_cascade_with_per_component_versions(): void {
		$this->booster()->load_scripts( 'ran-booster_page_ran-booster-themes' );

		$expected_styles = array(
			'ran-booster-admin-shell'                     => 'ran-admin-shell.css',
			'ran-booster-00-foundations'                  => '00-foundations.css',
			'ran-booster-10-buttons'                      => '10-buttons.css',
			'ran-booster-15-enhanced-mutations'           => '15-enhanced-mutations.css',
			'ran-booster-20-repository-picker'            => '20-repository-picker.css',
			'ran-booster-25-admin-primitives'             => '25-admin-primitives.css',
			'ran-booster-30-provider-cards'               => '30-provider-cards.css',
			'ran-booster-35-status-utilities'             => '35-status-utilities.css',
			'ran-booster-40-tables-and-pills'             => '40-tables-and-pills.css',
			'ran-booster-50-troubleshooting-and-activity' => '50-troubleshooting-and-activity.css',
			'ran-booster-55-extensions'                   => '55-extensions.css',
			'ran-booster-60-packages'                     => '60-packages.css',
			'ran-booster-65-package-settings'             => '65-package-settings.css',
			'ran-booster-70-credential-dialog'            => '70-credential-dialog.css',
			'ran-booster-styles'                          => '80-responsive.css',
		);
		$previous_handle = null;

		self::assertSame( array_keys( $expected_styles ), array_keys( $GLOBALS['ran_booster_asset_test_registered_styles'] ) );
		foreach ( $expected_styles as $handle => $file ) {
			$registered_style = $GLOBALS['ran_booster_asset_test_registered_styles'][ $handle ];

			self::assertStringEndsWith(
				'ran-booster-admin-shell' === $handle ? '/assets/' . $file : '/assets/ran-booster/' . $file,
				$registered_style['source']
			);
			self::assertIsInt( $registered_style['version'] );
			self::assertSame( null === $previous_handle ? array() : array( $previous_handle ), $registered_style['dependencies'] );
			$previous_handle = $handle;
		}
	}

	public function test_documentation_tab_receives_top_level_and_page_specific_styles(): void {
		$_GET['tab'] = 'documentation';

		$this->booster()->load_scripts( 'toplevel_page_ran-booster' );

		self::assertSame(
			array( 'ran-booster-admin-shell', 'ran-booster-styles', 'ran-booster-onboarding', 'ran-booster-documentation' ),
			$GLOBALS['ran_booster_asset_test_enqueued_styles']
		);
		self::assertArrayHasKey( 'ran-booster-documentation', $GLOBALS['ran_booster_asset_test_registered_styles'] );
		self::assertArrayHasKey( 'ran-booster-onboarding', $GLOBALS['ran_booster_asset_test_registered_styles'] );
		self::assertSame( array(), $GLOBALS['ran_booster_asset_test_localized_scripts'] );
	}

	public function test_portability_tab_receives_its_narrow_ajax_configuration(): void {
		$_GET['tab'] = 'portability';

		$this->booster()->load_scripts( 'toplevel_page_ran-booster' );

		self::assertSame(
			array( 'ran-booster-htmx', 'ran-booster-js', 'ran-booster-secure-inputs', 'ran-booster-enhanced-mutations', 'ran-booster-portability' ),
			$GLOBALS['ran_booster_asset_test_enqueued_scripts']
		);
		self::assertSame(
			array( 'ran-booster-htmx', 'wp-i18n' ),
			$GLOBALS['ran_booster_asset_test_registered_scripts']['ran-booster-js']['dependencies']
		);
		self::assertSame(
			array( 'ran-booster-secure-inputs', 'ran-booster-enhanced-mutations', 'wp-i18n' ),
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

	public function test_native_transporter_route_receives_the_canonical_portability_assets(): void {
		$this->booster()->load_scripts( 'ran-booster_page_ran-booster-transporter' );

		self::assertSame(
			array( 'ran-booster-admin-shell', 'ran-booster-styles', 'ran-booster-onboarding' ),
			$GLOBALS['ran_booster_asset_test_enqueued_styles']
		);
		self::assertSame(
			array( 'ran-booster-htmx', 'ran-booster-js', 'ran-booster-secure-inputs', 'ran-booster-enhanced-mutations', 'ran-booster-portability' ),
			$GLOBALS['ran_booster_asset_test_enqueued_scripts']
		);
		self::assertSame(
			'https://example.test/wp-admin/admin-ajax.php',
			$GLOBALS['ran_booster_asset_test_localized_scripts']['ran-booster-portability']['ranBoosterPortability']['ajaxUrl']
		);
	}

	public function test_provider_tab_detection_uses_registered_administration_metadata(): void {
		$container = new CoreContainer();
		$container->bind(
			ProviderRegistry::class,
			new class() {

				/** @return list<object> */
				public function administration_metadata(): array {
					return array(
						(object) array(
							'code' => \RAN\RepositoryProvider\ProviderCode::parse( 'gh' ),
						),
					);
				}
			}
		);
		$booster = new class( $container ) extends Booster {

			public function provider_tab( ?string $tab ): bool {
				return $this->is_provider_admin_tab( $tab );
			}
		};

		self::assertTrue( $booster->provider_tab( 'gh' ) );
		self::assertFalse( $booster->provider_tab( 'unknown' ) );
		self::assertFalse( $booster->provider_tab( 'documentation' ) );
		self::assertFalse( $booster->provider_tab( null ) );
	}

	/** @return list<array{string}> */
	public static function provider_tab_provider(): array {
		return array(
			array( 'gh' ),
			array( 'bb' ),
		);
	}

	#[DataProvider( 'provider_tab_provider' )]
	public function test_provider_tabs_receive_bounded_htmx_alongside_common_assets( string $tab ): void {
		$_GET['tab'] = $tab;

		$this->booster()->load_scripts( 'toplevel_page_ran-booster' );

		self::assertSame(
			array( 'ran-booster-admin-shell', 'ran-booster-styles', 'ran-booster-onboarding' ),
			$GLOBALS['ran_booster_asset_test_enqueued_styles']
		);
		self::assertSame(
			array( 'ran-booster-htmx', 'ran-booster-js', 'ran-booster-secure-inputs', 'ran-booster-enhanced-mutations' ),
			$GLOBALS['ran_booster_asset_test_enqueued_scripts']
		);
		self::assertSame(
			array( 'ran-booster-htmx', 'wp-i18n' ),
			$GLOBALS['ran_booster_asset_test_registered_scripts']['ran-booster-js']['dependencies']
		);
		self::assertSame(
			array( 'ran-booster-js', 'wp-a11y', 'wp-i18n' ),
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
	public static function fallback_tab_provider(): array {
		return array(
			array( null ),
			array( 'unknown' ),
			array( array( 'gh' ) ),
		);
	}

	#[DataProvider( 'fallback_tab_provider' )]
	public function test_fallback_tabs_do_not_load_htmx( mixed $tab ): void {
		if ( null !== $tab ) {
			$_GET['tab'] = $tab;
		}

		$this->booster()->load_scripts( 'toplevel_page_ran-booster' );

		self::assertSame(
			array( 'ran-booster-admin-shell', 'ran-booster-styles', 'ran-booster-onboarding' ),
			$GLOBALS['ran_booster_asset_test_enqueued_styles']
		);
		self::assertArrayHasKey( 'ran-booster-onboarding', $GLOBALS['ran_booster_asset_test_registered_styles'] );
		self::assertArrayNotHasKey( 'ran-booster-documentation', $GLOBALS['ran_booster_asset_test_registered_styles'] );
		self::assertSame(
			array( 'ran-booster-js', 'ran-booster-secure-inputs', 'ran-booster-enhanced-mutations' ),
			$GLOBALS['ran_booster_asset_test_enqueued_scripts']
		);
		self::assertSame(
			array( 'wp-i18n' ),
			$GLOBALS['ran_booster_asset_test_registered_scripts']['ran-booster-js']['dependencies']
		);
		self::assertSame(
			array( 'ran-booster-js', 'wp-a11y', 'wp-i18n' ),
			$GLOBALS['ran_booster_asset_test_registered_scripts']['ran-booster-enhanced-mutations']['dependencies']
		);
		self::assertArrayNotHasKey( 'ran-booster-htmx', $GLOBALS['ran_booster_asset_test_registered_scripts'] );
		self::assertSame( array(), $GLOBALS['ran_booster_asset_test_localized_scripts'] );
	}

	/** @return list<array{string}> */
	public static function static_tab_provider(): array {
		return array(
			array( 'troubleshooting' ),
		);
	}

	#[DataProvider( 'static_tab_provider' )]
	public function test_static_tabs_receive_top_level_style_alongside_common_assets( string $tab ): void {
		$_GET['tab'] = $tab;

		$this->booster()->load_scripts( 'toplevel_page_ran-booster' );

		self::assertSame( array( 'ran-booster-admin-shell', 'ran-booster-styles', 'ran-booster-onboarding' ), $GLOBALS['ran_booster_asset_test_enqueued_styles'] );
		self::assertSame(
			array( 'ran-booster-htmx', 'ran-booster-js', 'ran-booster-secure-inputs', 'ran-booster-enhanced-mutations' ),
			$GLOBALS['ran_booster_asset_test_enqueued_scripts']
		);
		self::assertSame(
			array( 'ran-booster-htmx', 'wp-i18n' ),
			$GLOBALS['ran_booster_asset_test_registered_scripts']['ran-booster-js']['dependencies']
		);
		self::assertSame(
			array( 'ran-booster-js', 'wp-a11y', 'wp-i18n' ),
			$GLOBALS['ran_booster_asset_test_registered_scripts']['ran-booster-enhanced-mutations']['dependencies']
		);
		self::assertSame( array(), $GLOBALS['ran_booster_asset_test_localized_scripts'] );
	}

	private function booster(
		bool $expiry_notice_visible = false,
		bool $background_failure_notice_visible = false,
		bool $expiry_notice_dismissible = true
	): Booster {
		$container = new CoreContainer();
		$booster   = new class( $container ) extends Booster {

			public bool $expiry_notice_visible             = false;
			public bool $expiry_notice_dismissible         = true;
			public bool $background_failure_notice_visible = false;

			protected function is_provider_admin_tab( ?string $tab ): bool {
				return in_array( $tab, array( 'gh', 'bb' ), true );
			}
		};
		$container->bind(
			CredentialExpiryNotice::class,
			static fn (): object => new class( $booster->expiry_notice_visible, $booster->expiry_notice_dismissible ) {
				public function __construct( private bool $visible, private bool $dismissible ) {
				}

				public function should_load_dismissal_script(): bool {
					return $this->visible && $this->dismissible;
				}
			}
		);
		$container->bind(
			DeploymentAdminPresenter::class,
			static fn (): object => new class( $booster->background_failure_notice_visible ) {
				public function __construct( private bool $visible ) {
				}

				public function should_render(): bool {
					return $this->visible;
				}
			}
		);
		$container->bind(
			'RAN\\Admin\\ProviderSettingsPresenter',
			new class() {
				/** @return array{default_provider: string, providers: array<string, mixed>} */
				public function build_package_form(): array {
					return array(
						'default_provider' => 'gh',
						'providers'        => array( 'gh' => array( 'label' => 'GitHub' ) ),
					);
				}
			}
		);
		$booster->booster_path                      = dirname( __DIR__, 2 );
		$booster->booster_url                       = 'https://example.test/wp-content/plugins/ran-booster';
		$booster->expiry_notice_visible             = $expiry_notice_visible;
		$booster->expiry_notice_dismissible         = $expiry_notice_dismissible;
		$booster->background_failure_notice_visible = $background_failure_notice_visible;

		return $booster;
	}
}
