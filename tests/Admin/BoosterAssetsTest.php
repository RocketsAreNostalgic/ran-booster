<?php

declare(strict_types=1);

namespace RAN\Tests\Admin;

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

	private bool $had_capability_checks;

	private mixed $previous_capability_checks;

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this exact lifecycle override name.
	protected function setUp(): void {
		$_GET                             = array();
		$this->had_capability_checks      = array_key_exists( 'ran_booster_test_capability_checks', $GLOBALS );
		$this->previous_capability_checks = $GLOBALS['ran_booster_test_capability_checks'] ?? null;
		$GLOBALS['ran_booster_asset_test_registered_styles']   = array();
		$GLOBALS['ran_booster_asset_test_enqueued_styles']     = array();
		$GLOBALS['ran_booster_asset_test_registered_scripts']  = array();
		$GLOBALS['ran_booster_asset_test_enqueued_scripts']    = array();
		$GLOBALS['ran_booster_asset_test_enqueued_modules']    = array();
		$GLOBALS['ran_booster_asset_test_localized_scripts']   = array();
		$GLOBALS['ran_booster_asset_test_script_events']       = array();
		$GLOBALS['ran_booster_asset_test_script_translations'] = array();
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this exact lifecycle override name.
	protected function tearDown(): void {
		$_GET = array();
		if ( $this->had_capability_checks ) {
			$GLOBALS['ran_booster_test_capability_checks'] = $this->previous_capability_checks;
		} else {
			unset( $GLOBALS['ran_booster_test_capability_checks'] );
		}
		unset(
			$GLOBALS['ran_booster_asset_test_registered_styles'],
			$GLOBALS['ran_booster_asset_test_enqueued_styles'],
			$GLOBALS['ran_booster_asset_test_registered_scripts'],
			$GLOBALS['ran_booster_asset_test_enqueued_scripts'],
			$GLOBALS['ran_booster_asset_test_enqueued_modules'],
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

	public function test_unconfigured_locations_remain_null_after_asset_loading(): void {
		$booster = new Booster();
		self::assertNull( $booster->booster_path );
		self::assertNull( $booster->booster_url );

		$booster->load_scripts( 'ran-booster_page_ran-booster-extensions' );

		self::assertNull( $booster->booster_path );
		self::assertNull( $booster->booster_url );
		self::assertSame( '/assets/ran-admin-shell.css', $GLOBALS['ran_booster_asset_test_registered_styles']['ran-booster-admin-shell']['source'] );
		self::assertSame( '/assets/ran-booster-extension-details.js', $GLOBALS['ran_booster_asset_test_registered_scripts']['ran-booster-extension-details']['source'] );
	}

	/** @return list<array{string|null,string|null,string}> */
	public static function asset_location_provider(): array {
		return array(
			array( null, null, '/' ),
			array( '', '', '/' ),
			array( '/missing-booster-location', 'https://assets.example.test/booster', 'https://assets.example.test/booster/' ),
			array( '/missing-booster-location/', 'https://assets.example.test/booster/', 'https://assets.example.test/booster/' ),
		);
	}

	#[DataProvider( 'asset_location_provider' )]
	public function test_asset_loading_preserves_reassigned_locations( ?string $path, ?string $url, string $expected_url ): void {
		$booster               = $this->booster();
		$booster->booster_path = $path;
		$booster->booster_url  = $url;

		$booster->load_scripts( 'ran-booster_page_ran-booster-extensions' );

		self::assertSame( $path, $booster->booster_path );
		self::assertSame( $url, $booster->booster_url );
		self::assertSame( $expected_url . 'assets/ran-admin-shell.css', $GLOBALS['ran_booster_asset_test_registered_styles']['ran-booster-admin-shell']['source'] );
		self::assertSame( $expected_url . 'assets/ran-booster-extension-details.js', $GLOBALS['ran_booster_asset_test_registered_scripts']['ran-booster-extension-details']['source'] );
		self::assertNull( $GLOBALS['ran_booster_asset_test_registered_styles']['ran-booster-admin-shell']['version'] );
		self::assertNull( $GLOBALS['ran_booster_asset_test_registered_scripts']['ran-booster-extension-details']['version'] );
	}

	/** @return list<array{string}> */
	public static function invalid_asset_location_provider(): array {
		return array( array( 'booster_path' ), array( 'booster_url' ) );
	}

	#[DataProvider( 'invalid_asset_location_provider' )]
	public function test_invalid_location_arrays_are_rejected( string $property ): void {
		$booster = $this->booster();
		// Reflection preserves deliberate external misuse of these untyped public properties.
		( new \ReflectionProperty( Booster::class, $property ) )->setValue( $booster, array( 'invalid-location' ) );

		$this->expectException( \TypeError::class );
		$booster->load_scripts( 'ran-booster_page_ran-booster-extensions' );
	}

	public function test_extensions_page_receives_the_common_and_native_modal_assets(): void {
		$this->booster()->load_scripts( 'ran-booster_page_ran-booster-extensions' );

		self::assertSame( array( 'ran-booster-menu', 'ran-booster-admin-shell', 'ran-booster-styles', 'wp-components', 'thickbox' ), $GLOBALS['ran_booster_asset_test_enqueued_styles'] );
		self::assertSame( array( 'wp-element', 'react-jsx-runtime', 'ran-booster-cards', 'thickbox', 'ran-booster-extension-details' ), $GLOBALS['ran_booster_asset_test_enqueued_scripts'] );
		self::assertSame( array( 'ran-booster-cards', 'ran-booster-extension-details' ), array_keys( $GLOBALS['ran_booster_asset_test_registered_scripts'] ) );
		self::assertSame(
			array( 'jquery', 'thickbox' ),
			$GLOBALS['ran_booster_asset_test_registered_scripts']['ran-booster-extension-details']['dependencies']
		);
		self::assertSame(
			array( 'wp-element', 'react-jsx-runtime' ),
			$GLOBALS['ran_booster_asset_test_registered_scripts']['ran-booster-cards']['dependencies']
		);
		self::assertArrayHasKey( 'ran-booster-55-extensions', $GLOBALS['ran_booster_asset_test_registered_styles'] );
		self::assertSame( array(), $GLOBALS['ran_booster_asset_test_localized_scripts'] );
	}

	/** @return list<array{string, ?string, bool}> */
	public static function card_surface_provider(): array {
		return array(
			array( 'toplevel_page_ran-booster', null, true ),
			array( 'toplevel_page_ran-booster', 'overview', true ),
			array( 'toplevel_page_ran-booster', 'troubleshooting', true ),
			array( 'toplevel_page_ran-booster', 'gh', false ),
			array( 'toplevel_page_ran-booster', 'documentation', false ),
			array( 'ran-booster_page_ran-booster-extensions', null, true ),
			array( 'ran-booster_page_ran-booster-plugins', null, true ),
			array( 'ran-booster_page_ran-booster-themes', null, true ),
			array( 'ran-booster_page_ran-booster-settings', null, true ),
		);
	}

	#[DataProvider( 'card_surface_provider' )]
	public function test_card_assets_are_limited_to_selected_surfaces( string $hook, ?string $tab, bool $expected ): void {
		if ( null !== $tab ) {
			$_GET['tab'] = $tab;
		}
		$this->booster()->load_scripts( $hook );
		self::assertSame( $expected, isset( $GLOBALS['ran_booster_asset_test_registered_scripts']['ran-booster-cards'] ) );
		self::assertSame( $expected || 'ran-booster_page_ran-booster-themes' === $hook, isset( $GLOBALS['ran_booster_asset_test_enqueued_modules']['ran-booster-ui'] ) );
		if ( $expected ) {
			self::assertContains( 'wp-components', $GLOBALS['ran_booster_asset_test_enqueued_styles'] );
			self::assertSame( array( 'wp-element', 'react-jsx-runtime' ), $GLOBALS['ran_booster_asset_test_registered_scripts']['ran-booster-cards']['dependencies'] );
			self::assertTrue( $GLOBALS['ran_booster_asset_test_registered_scripts']['ran-booster-cards']['footer'] );
		}
	}

	/** @return list<array{string, ?string, bool, bool}> */
	public static function ui_module_route_provider(): array {
		return array(
			array( 'toplevel_page_ran-booster', null, true, false ),
			array( 'toplevel_page_ran-booster', 'overview', true, false ),
			array( 'toplevel_page_ran-booster', 'troubleshooting', true, false ),
			array( 'toplevel_page_ran-booster', 'gh', false, false ),
			array( 'toplevel_page_ran-booster', 'documentation', false, false ),
			array( 'ran-booster_page_ran-booster-extensions', null, true, false ),
			array( 'ran-booster_page_ran-booster-settings', null, true, false ),
			array( 'ran-booster_page_ran-booster-plugins', null, true, true ),
			array( 'ran-booster_page_ran-booster-plugins-create', null, false, true ),
			array( 'ran-booster_page_ran-booster-themes', null, true, true ),
			array( 'ran-booster_page_ran-booster-themes-create', null, false, true ),
			array( 'plugins.php', null, false, false ),
		);
	}

	#[DataProvider( 'ui_module_route_provider' )]
	public function test_ui_modules_follow_surface_and_picker_routes( string $hook, ?string $tab, bool $cards, bool $overlay ): void {
		if ( null !== $tab ) {
			$_GET['tab'] = $tab;
		}
		$this->booster()->load_scripts( $hook );
		$modules = $GLOBALS['ran_booster_asset_test_enqueued_modules'];
		foreach ( $GLOBALS['ran_booster_asset_test_registered_scripts'] as $script ) {
			self::assertNotContains( 'ran-booster-ui', $script['dependencies'] );
			self::assertNotContains( 'ran-booster-ui-overlay', $script['dependencies'] );
		}
		self::assertSame( $cards, isset( $GLOBALS['ran_booster_asset_test_registered_scripts']['ran-booster-cards'] ) );
		self::assertSame( $cards || $overlay, isset( $modules['ran-booster-ui'] ) );
		self::assertSame( $overlay, isset( $modules['ran-booster-ui-overlay'] ) );
		self::assertSame( $overlay, in_array( 'wp-i18n', $GLOBALS['ran_booster_asset_test_enqueued_scripts'], true ) );
		self::assertSame( $overlay, in_array( 'wp-a11y', $GLOBALS['ran_booster_asset_test_enqueued_scripts'], true ) );
		self::assertSame( 'plugins.php' !== $hook, in_array( 'wp-components', $GLOBALS['ran_booster_asset_test_enqueued_styles'], true ) );
		if ( $cards || $overlay ) {
			$manifest = \RAN\wp_json_file_decode( dirname( __DIR__, 2 ) . '/assets/lib/ran-booster-ui-manifest.json', array( 'associative' => true ) );
			self::assertIsArray( $manifest );
			self::assertArrayHasKey( 'foundation', $manifest );
			self::assertArrayHasKey( 'overlay', $manifest );
			self::assertIsString( $manifest['foundation'] );
			self::assertIsString( $manifest['overlay'] );
			self::assertSame(
				array(
					'source'       => 'https://example.test/wp-content/plugins/ran-booster/assets/lib/' . $manifest['foundation'],
					'dependencies' => array(),
					'version'      => null,
				),
				$modules['ran-booster-ui']
			);
			self::assertContains( 'wp-element', $GLOBALS['ran_booster_asset_test_enqueued_scripts'] );
			self::assertContains( 'react-jsx-runtime', $GLOBALS['ran_booster_asset_test_enqueued_scripts'] );
			if ( $overlay ) {
				self::assertSame(
					array(
						'source'       => 'https://example.test/wp-content/plugins/ran-booster/assets/lib/' . $manifest['overlay'],
						'dependencies' => array( 'ran-booster-ui' ),
						'version'      => null,
					),
					$modules['ran-booster-ui-overlay']
				);
			}
		} else {
			self::assertSame( array(), $modules );
		}
	}

	/** @return list<array{?string}> */
	public static function invalid_ui_manifest_provider(): array {
		return array( array( null ), array( '{invalid' ), array( '{"foundation":"../bad.js","overlay":"ran-booster-ui-overlay-valid.js"}' ), array( '{"foundation":"ran-booster-ui-foundation-valid.js"}' ) );
	}

	#[DataProvider( 'invalid_ui_manifest_provider' )]
	public function test_invalid_or_missing_ui_manifest_enqueues_no_modules( ?string $contents ): void {
		$root      = sys_get_temp_dir() . '/booster-ui-manifest-' . bin2hex( random_bytes( 8 ) );
		$directory = $root . '/assets/lib';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Create an isolated local test fixture.
		self::assertTrue( mkdir( $directory, 0700, true ) );
		try {
			if ( null !== $contents ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write the isolated local fixture only.
				file_put_contents( $directory . '/ran-booster-ui-manifest.json', $contents );
			}
			$booster               = $this->booster();
			$booster->booster_path = $root;
			$booster->load_scripts( 'ran-booster_page_ran-booster-plugins' );
			self::assertSame( array(), $GLOBALS['ran_booster_asset_test_enqueued_modules'] );
			self::assertNotContains( 'wp-element', $GLOBALS['ran_booster_asset_test_enqueued_scripts'] );
			self::assertNotContains( 'react-jsx-runtime', $GLOBALS['ran_booster_asset_test_enqueued_scripts'] );
		} finally {
			if ( is_file( $directory . '/ran-booster-ui-manifest.json' ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Remove the isolated local fixture.
				unlink( $directory . '/ran-booster-ui-manifest.json' );
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Remove the isolated local fixture directory.
			rmdir( $directory );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Remove the isolated local fixture directory.
			rmdir( $root . '/assets' );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Remove the isolated local fixture directory.
			rmdir( $root );
		}
	}

	#[DataProvider( 'unrelated_admin_hook_provider' )]
	public function test_unrelated_admin_pages_receive_only_menu_styles( string $hook ): void {
		$this->booster()->load_scripts( $hook );

		self::assertSame( array( 'ran-booster-menu' ), array_keys( $GLOBALS['ran_booster_asset_test_registered_styles'] ) );
		self::assertSame( array( 'ran-booster-menu' ), $GLOBALS['ran_booster_asset_test_enqueued_styles'] );
		$style = $GLOBALS['ran_booster_asset_test_registered_styles']['ran-booster-menu'];
		self::assertStringEndsWith( '/assets/ran-booster-menu.css', $style['source'] );
		self::assertSame( (string) filemtime( dirname( __DIR__, 2 ) . '/assets/ran-booster-menu.css' ), $style['version'] );
		self::assertSame( array(), $style['dependencies'] );
		self::assertSame( array(), $GLOBALS['ran_booster_asset_test_registered_scripts'] );
		self::assertSame( array(), $GLOBALS['ran_booster_asset_test_enqueued_scripts'] );
		self::assertSame( array(), $GLOBALS['ran_booster_asset_test_localized_scripts'] );
	}

	public function test_menu_styles_require_a_valid_hook_and_menu_capability(): void {
		$booster = $this->booster();
		$booster->load_scripts( null );
		self::assertSame( array(), $GLOBALS['ran_booster_asset_test_registered_styles'] );

		$had_capabilities                         = array_key_exists( 'ran_booster_test_capabilities', $GLOBALS );
		$previous_capabilities                    = $GLOBALS['ran_booster_test_capabilities'] ?? null;
		$GLOBALS['ran_booster_test_capabilities'] = array( 'manage_options' => false );
		try {
			$booster->load_scripts( 'plugins.php' );
			self::assertSame( array(), $GLOBALS['ran_booster_asset_test_registered_styles'] );
			self::assertSame( array(), $GLOBALS['ran_booster_asset_test_enqueued_styles'] );
		} finally {
			if ( $had_capabilities ) {
				$GLOBALS['ran_booster_test_capabilities'] = $previous_capabilities;
			} else {
				unset( $GLOBALS['ran_booster_test_capabilities'] );
			}
		}
	}

	public function test_expiry_notice_loads_its_small_asset_on_any_admin_screen_only_when_visible(): void {
		$booster = $this->booster( true );

		$booster->load_credential_expiry_notice_script( 'plugins.php' );

		self::assertSame(
			array( 'ran-booster-notice-preference' ),
			$GLOBALS['ran_booster_asset_test_enqueued_scripts']
		);
		self::assertSame(
			array(
				'ajaxUrl'      => 'https://example.test/wp-admin/admin-ajax.php',
				'action'       => CredentialExpiryNoticeController::AJAX_ACTION,
				'nonce'        => 'nonce-for-' . hash( 'sha256', CredentialExpiryNoticeController::NONCE_ACTION ),
				'errorMessage' => 'RAN Booster could not save this notice preference. Please try again.',
			),
			$GLOBALS['ran_booster_asset_test_localized_scripts']['ran-booster-notice-preference']['ranBoosterCredentialExpiryNotice']
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
			array( 'ran-booster-notice-preference' ),
			$GLOBALS['ran_booster_asset_test_enqueued_scripts']
		);
		self::assertSame(
			array(
				'ajaxUrl'      => 'https://example.test/wp-admin/admin-ajax.php',
				'action'       => DeploymentAdminController::AJAX_ACTION,
				'nonce'        => 'nonce-for-' . hash( 'sha256', DeploymentAdminController::NONCE_ACTION ),
				'errorMessage' => 'RAN Booster could not save this notice preference. Please try again.',
			),
			$GLOBALS['ran_booster_asset_test_localized_scripts']['ran-booster-notice-preference']['ranBoosterBackgroundFailureNotice']
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

		self::assertSame( array( 'ran-booster-menu', 'ran-booster-admin-shell', 'ran-booster-styles', 'wp-components' ), $GLOBALS['ran_booster_asset_test_enqueued_styles'] );
		self::assertSame(
			array_merge(
				array( 'ran-booster-htmx', 'wp-element', 'react-jsx-runtime', 'wp-i18n', 'wp-a11y' ),
				in_array( $hook, array( 'ran-booster_page_ran-booster-plugins', 'ran-booster_page_ran-booster-themes' ), true ) ? array( 'ran-booster-cards' ) : array(),
				array(
					'ran-booster-js',
					'ran-booster-secure-inputs',
					'ran-booster-enhanced-mutations',
					'ran-booster-packages',
					'ran-booster-repository-picker',
				)
			),
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
			array( 'ran-booster-js', 'wp-a11y', 'wp-i18n', 'wp-element', 'wp-components' ),
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
			$GLOBALS['ran_booster_asset_test_localized_scripts']['ran-booster-packages'] ?? array()
		);
		self::assertArrayNotHasKey( 'ranBoosterDevelopmentSafetyNotice', $GLOBALS['ran_booster_asset_test_localized_scripts']['ran-booster-notice-preference'] ?? array() );

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
				$GLOBALS['ran_booster_asset_test_localized_scripts']['ran-booster-packages'] ?? array()
			);
		}
	}

	public function test_common_stylesheets_preserve_cascade_with_per_component_versions(): void {
		$this->booster()->load_scripts( 'ran-booster_page_ran-booster-themes' );

		$registered_styles = $GLOBALS['ran_booster_asset_test_registered_styles'];
		$menu_style        = $registered_styles['ran-booster-menu'];
		unset( $registered_styles['ran-booster-menu'] );
		self::assertSame( array(), $menu_style['dependencies'] );

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

		self::assertSame( array_keys( $expected_styles ), array_keys( $registered_styles ) );
		foreach ( $expected_styles as $handle => $file ) {
			$registered_style = $GLOBALS['ran_booster_asset_test_registered_styles'][ $handle ];

			self::assertStringEndsWith(
				'ran-booster-admin-shell' === $handle ? '/assets/' . $file : '/assets/ran-booster/' . $file,
				$registered_style['source']
			);
			self::assertSame(
				(string) filemtime( dirname( __DIR__, 2 ) . '/assets/' . ( 'ran-booster-admin-shell' === $handle ? $file : 'ran-booster/' . $file ) ),
				$registered_style['version']
			);
			self::assertSame( null === $previous_handle ? array() : array( $previous_handle ), $registered_style['dependencies'] );
			$previous_handle = $handle;
		}
	}

	public function test_asset_versions_preserve_query_tokens_and_missing_file_fallback(): void {
		$booster = $this->booster();
		$version = new \ReflectionMethod( Booster::class, 'asset_version' );
		$path    = dirname( __DIR__, 2 ) . '/assets/ran-booster.js';
		$stamp   = filemtime( $path );

		self::assertIsInt( $stamp );
		self::assertSame( http_build_query( array( 'ver' => $stamp ) ), http_build_query( array( 'ver' => $version->invoke( $booster, $path ) ) ) );
		self::assertNull( $version->invoke( $booster, $path . '.missing' ) );
	}

	public function test_settings_loads_storage_styles_without_adopting_a_horizontal_tab_asset_route(): void {
		$_GET['tab'] = 'portability';
		$this->booster()->load_scripts( 'ran-booster_page_ran-booster-settings' );
		self::assertContains( 'ran-booster-onboarding', $GLOBALS['ran_booster_asset_test_enqueued_styles'] );
		self::assertContains( 'ran-booster-settings', $GLOBALS['ran_booster_asset_test_enqueued_scripts'] );
		self::assertSame( array( 'wp-i18n', 'ran-booster-cards' ), $GLOBALS['ran_booster_asset_test_registered_scripts']['ran-booster-settings']['dependencies'] );
		self::assertContains(
			array(
				'handle' => 'ran-booster-settings',
				'domain' => 'ran-booster',
				'path'   => dirname( __DIR__, 2 ) . '/languages',
			),
			$GLOBALS['ran_booster_asset_test_script_translations']
		);
		self::assertContains( 'ran-booster-htmx', $GLOBALS['ran_booster_asset_test_enqueued_scripts'] );
		self::assertNotContains( 'ran-booster-portability', $GLOBALS['ran_booster_asset_test_enqueued_scripts'] );
	}

	public function test_documentation_tab_receives_top_level_and_page_specific_styles(): void {
		$_GET['tab'] = 'documentation';

		$this->booster()->load_scripts( 'toplevel_page_ran-booster' );

		self::assertSame(
			array( 'ran-booster-menu', 'ran-booster-admin-shell', 'ran-booster-styles', 'wp-components', 'ran-booster-onboarding', 'ran-booster-documentation' ),
			$GLOBALS['ran_booster_asset_test_enqueued_styles']
		);
		self::assertArrayHasKey( 'ran-booster-documentation', $GLOBALS['ran_booster_asset_test_registered_styles'] );
		self::assertArrayHasKey( 'ran-booster-onboarding', $GLOBALS['ran_booster_asset_test_registered_styles'] );
		self::assertArrayHasKey( 'ranBoosterDevelopmentSafetyNotice', $GLOBALS['ran_booster_asset_test_localized_scripts']['ran-booster-notice-preference'] );
	}

	public function test_portability_tab_receives_its_narrow_ajax_configuration(): void {
		$_GET['tab'] = 'portability';

		$this->booster()->load_scripts( 'toplevel_page_ran-booster' );

		self::assertSame(
			array( 'ran-booster-htmx', 'ran-booster-notice-preference', 'ran-booster-js', 'ran-booster-secure-inputs', 'ran-booster-enhanced-mutations', 'ran-booster-portability' ),
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
			array( 'ran-booster-menu', 'ran-booster-admin-shell', 'ran-booster-styles', 'wp-components', 'ran-booster-onboarding' ),
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
			array( 'ran-booster-menu', 'ran-booster-admin-shell', 'ran-booster-styles', 'wp-components', 'ran-booster-onboarding' ),
			$GLOBALS['ran_booster_asset_test_enqueued_styles']
		);
		self::assertSame(
			array( 'ran-booster-htmx', 'ran-booster-notice-preference', 'ran-booster-js', 'ran-booster-secure-inputs', 'ran-booster-enhanced-mutations' ),
			$GLOBALS['ran_booster_asset_test_enqueued_scripts']
		);
		self::assertSame(
			array( 'ran-booster-htmx', 'wp-i18n' ),
			$GLOBALS['ran_booster_asset_test_registered_scripts']['ran-booster-js']['dependencies']
		);
		self::assertSame(
			array( 'ran-booster-js', 'wp-a11y', 'wp-i18n', 'wp-element', 'wp-components' ),
			$GLOBALS['ran_booster_asset_test_registered_scripts']['ran-booster-enhanced-mutations']['dependencies']
		);
		self::assertStringEndsWith(
			'/assets/lib/htmx/htmx.min.js',
			$GLOBALS['ran_booster_asset_test_registered_scripts']['ran-booster-htmx']['source']
		);
		self::assertTrue( $GLOBALS['ran_booster_asset_test_registered_scripts']['ran-booster-htmx']['footer'] );
		self::assertArrayHasKey( 'ranBoosterDevelopmentSafetyNotice', $GLOBALS['ran_booster_asset_test_localized_scripts']['ran-booster-notice-preference'] );
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
			array( 'ran-booster-menu', 'ran-booster-admin-shell', 'ran-booster-styles', 'wp-components', 'ran-booster-onboarding' ),
			$GLOBALS['ran_booster_asset_test_enqueued_styles']
		);
		self::assertArrayHasKey( 'ran-booster-onboarding', $GLOBALS['ran_booster_asset_test_registered_styles'] );
		self::assertArrayNotHasKey( 'ran-booster-documentation', $GLOBALS['ran_booster_asset_test_registered_styles'] );
		self::assertSame(
			array_merge(
				( ! is_string( $tab ) || '' === $tab ) ? array( 'wp-element', 'react-jsx-runtime', 'ran-booster-cards' ) : array(),
				array( 'ran-booster-notice-preference', 'ran-booster-js', 'ran-booster-secure-inputs', 'ran-booster-enhanced-mutations' )
			),
			$GLOBALS['ran_booster_asset_test_enqueued_scripts']
		);
		self::assertSame(
			array( 'wp-i18n' ),
			$GLOBALS['ran_booster_asset_test_registered_scripts']['ran-booster-js']['dependencies']
		);
		self::assertSame(
			array( 'ran-booster-js', 'wp-a11y', 'wp-i18n', 'wp-element', 'wp-components' ),
			$GLOBALS['ran_booster_asset_test_registered_scripts']['ran-booster-enhanced-mutations']['dependencies']
		);
		self::assertArrayNotHasKey( 'ran-booster-htmx', $GLOBALS['ran_booster_asset_test_registered_scripts'] );
		self::assertArrayHasKey( 'ranBoosterDevelopmentSafetyNotice', $GLOBALS['ran_booster_asset_test_localized_scripts']['ran-booster-notice-preference'] );
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

		self::assertSame( array( 'ran-booster-menu', 'ran-booster-admin-shell', 'ran-booster-styles', 'wp-components', 'ran-booster-onboarding' ), $GLOBALS['ran_booster_asset_test_enqueued_styles'] );
		self::assertSame(
			array( 'ran-booster-htmx', 'wp-element', 'react-jsx-runtime', 'ran-booster-cards', 'ran-booster-notice-preference', 'ran-booster-js', 'ran-booster-secure-inputs', 'ran-booster-enhanced-mutations' ),
			$GLOBALS['ran_booster_asset_test_enqueued_scripts']
		);
		self::assertSame(
			array( 'ran-booster-htmx', 'wp-i18n' ),
			$GLOBALS['ran_booster_asset_test_registered_scripts']['ran-booster-js']['dependencies']
		);
		self::assertSame(
			array( 'ran-booster-js', 'wp-a11y', 'wp-i18n', 'wp-element', 'wp-components' ),
			$GLOBALS['ran_booster_asset_test_registered_scripts']['ran-booster-enhanced-mutations']['dependencies']
		);
		self::assertArrayHasKey( 'ranBoosterDevelopmentSafetyNotice', $GLOBALS['ran_booster_asset_test_localized_scripts']['ran-booster-notice-preference'] );
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
