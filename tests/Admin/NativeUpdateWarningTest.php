<?php

declare(strict_types=1);

namespace RAN\Tests\Admin;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use RAN\Booster;
use RAN\Internal\CoreContainer;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class NativeUpdateWarningTest extends TestCase {

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this exact lifecycle override name.
	protected function setUp(): void {
		require_once __DIR__ . '/NativeUpdateWarningWordPressFunctions.php';
		require_once __DIR__ . '/BoosterAssetsWordPressFunctions.php';
		require_once dirname( __DIR__ ) . '/Support/NativeUpdateWarningGlobalWordPressFunctions.php';
		$GLOBALS['ran_booster_native_warning_actions']           = array();
		$GLOBALS['ran_booster_native_warning_capability_checks'] = array();
		$GLOBALS['ran_booster_native_warning_capabilities']      = array(
			'update_plugins'   => true,
			'update_themes'    => false,
			'manage_options'   => true,
			'activate_plugins' => true,
		);
		$GLOBALS['ran_booster_test_capability_checks']           = array();
		$GLOBALS['ran_booster_test_capabilities']                = $GLOBALS['ran_booster_native_warning_capabilities'];
		$GLOBALS['ran_booster_native_warning_screen']            = (object) array( 'id' => 'plugins' );
		$GLOBALS['ran_booster_native_warning_multisite']         = false;
		$GLOBALS['ran_booster_asset_test_registered_scripts']    = array();
		$GLOBALS['ran_booster_asset_test_enqueued_scripts']      = array();
		$GLOBALS['ran_booster_asset_test_script_events']         = array();
		$GLOBALS['ran_booster_asset_test_script_translations']   = array();
	}

	public function test_init_registers_the_real_notice_and_authenticated_heartbeat_callbacks(): void {
		$this->booster(
			array(
				'state'     => 'recovery_required',
				'available' => false,
			)
		)->init();

		$hooks = array_map( static fn ( array $action ): string => $action['hook'], $GLOBALS['ran_booster_native_warning_actions'] );
		self::assertContains( 'admin_notices', $hooks );
		self::assertContains( 'network_admin_notices', $hooks );
		self::assertContains( 'admin_enqueue_scripts', $hooks );
		self::assertContains( 'heartbeat_received', $hooks );

		$heartbeat = $this->callback_for( 'heartbeat_received' );
		self::assertSame(
			array( 'ran_booster_native_update_warning' => true ),
			$heartbeat( array(), array( 'ran_booster_native_update_warning' => true ) )
		);
	}

	/** @return list<array{0: array<string,mixed>, 1: string}> */
	public static function state_provider(): array {
		return array(
			array(
				array(
					'state'           => 'recovery_required',
					'available'       => false,
					'native_mutation' => 'private',
				),
				'',
			),
			array(
				array(
					'state'     => 'available',
					'available' => true,
				),
				' hidden',
			),
			array(
				array(
					'state'     => 'available',
					'available' => false,
				),
				' hidden',
			),
			array(
				array(
					'state'     => 'conflict',
					'available' => false,
				),
				' hidden',
			),
		);
	}

	/** @param array<string,mixed> $status */
	#[DataProvider( 'state_provider' )]
	public function test_notice_uses_only_the_passive_tri_state_and_never_renders_diagnostics( array $status, string $hidden ): void {
		$booster = $this->booster( $status );
		ob_start();
		$this->render_warning( $booster );
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'data-ran-booster-native-update-warning' . $hidden, $html );
		self::assertStringContainsString( '' === $hidden ? 'GitHub installs are on hold: the last install of <a href="https://example.test/wp-admin/admin.php?page=ran-booster&tab=gh#ran-booster-native-hold">example-plugin</a> couldn\'t complete.' : 'GitHub installs are on hold: the last install couldn&#039;t complete.', $html );
		if ( ' hidden' === $hidden ) {
			self::assertStringContainsString( '<a href="https://example.test/wp-admin/admin.php?page=ran-booster&tab=gh">Review GitHub management.</a>', $html );
		}
		self::assertStringNotContainsString( 'private', $html );
		self::assertStringNotContainsString( 'native_mutation', $html );
	}

	public function test_notice_is_limited_to_the_required_screens_and_links_only_for_authorized_administrators(): void {
		$booster                                      = $this->booster(
			array(
				'state'     => 'recovery_required',
				'available' => false,
			)
		);
		$GLOBALS['ran_booster_native_warning_screen'] = (object) array( 'id' => 'index' );
		ob_start();
		$this->render_warning( $booster );
		self::assertSame( '', (string) ob_get_clean() );

		$GLOBALS['ran_booster_native_warning_screen']                         = (object) array( 'id' => 'update-core-network' );
		$GLOBALS['ran_booster_native_warning_capabilities']['manage_options'] = false;
		$GLOBALS['ran_booster_test_capabilities']['manage_options']           = false;
		ob_start();
		$this->render_warning( $booster );
		$html = (string) ob_get_clean();
		self::assertStringContainsString( 'the last install of example-plugin couldn\'t complete. Contact a site administrator.', $html );
		self::assertStringNotContainsString( 'href', $html );

		$GLOBALS['ran_booster_native_warning_capabilities']['manage_options'] = true;
		$GLOBALS['ran_booster_test_capabilities']['manage_options']           = true;
		ob_start();
		$this->render_warning( $booster );
		$html = (string) ob_get_clean();
		self::assertStringContainsString( 'admin.php?page=ran-booster&tab=gh#ran-booster-native-hold', $html );
		self::assertStringNotContainsString( 'Contact a site administrator.', $html );
	}

	public function test_a_readable_hold_links_its_name_to_the_package_page(): void {
		$booster = $this->booster(
			array(
				'state'     => 'recovery_required',
				'available' => false,
			),
			'gh',
			array(
				'name' => 'Example Plugin',
				'url'  => 'https://example.test/wp-admin/admin.php?page=ran-booster-plugins&package=example-plugin%2Fexample-plugin.php#ran-booster-native-hold',
			)
		);
		ob_start();
		$this->render_warning( $booster );
		$html = (string) ob_get_clean();
		self::assertStringContainsString( '<a href="https://example.test/wp-admin/admin.php?page=ran-booster-plugins&package=example-plugin%2Fexample-plugin.php#ran-booster-native-hold">Example Plugin</a>', $html );
		self::assertStringNotContainsString( 'tab=gh', $html );
	}

	public function test_heartbeat_fallback_warning_uses_network_link_on_multisite_and_hides_link_without_capability(): void {
		$booster = $this->booster(
			array(
				'state'     => 'available',
				'available' => true,
			)
		);

		$GLOBALS['ran_booster_native_warning_multisite'] = true;
		ob_start();
		$this->render_warning( $booster );
		$html = (string) ob_get_clean();
		self::assertStringContainsString( '<a href="https://example.test/wp-admin/network/admin.php?page=ran-booster&tab=gh">Review GitHub management.</a>', $html );
		self::assertStringNotContainsString( 'Contact a site administrator.', $html );

		$GLOBALS['ran_booster_native_warning_capabilities']['manage_options'] = false;
		$GLOBALS['ran_booster_test_capabilities']['manage_options']           = false;
		ob_start();
		$this->render_warning( $booster );
		$restricted = (string) ob_get_clean();
		self::assertStringContainsString( 'GitHub installs are on hold: the last install couldn&#039;t complete. Contact a site administrator.', $restricted );
		self::assertStringNotContainsString( 'until verification is complete', $restricted );
		self::assertStringNotContainsString( 'Review GitHub management.', $restricted );
		self::assertStringNotContainsString( 'href', $restricted );
	}

	public function test_heartbeat_omits_unknown_and_unauthorized_statuses(): void {
		$requested = array( 'ran_booster_native_update_warning' => true );
		$booster   = $this->booster(
			array(
				'state'     => 'conflict',
				'available' => false,
			)
		);
		$this->assert_heartbeat_response( $booster, $requested );

		$GLOBALS['ran_booster_native_warning_capabilities']['update_plugins'] = false;
		$GLOBALS['ran_booster_test_capabilities']['update_plugins']           = false;
		$this->assert_heartbeat_response( $booster, $requested );
		$this->assert_heartbeat_response( $booster, array() );
	}

	public function test_warning_asset_is_registered_only_in_the_local_notice_scope(): void {
		$booster               = $this->booster(
			array(
				'state'     => 'available',
				'available' => true,
			)
		);
		$booster->booster_path = dirname( __DIR__, 2 );
		$booster->booster_url  = 'https://example.test/wp-content/plugins/ran-booster';
		$this->load_warning_asset( $booster, 'plugins.php' );
		self::assertSame( array( 'ran-booster-native-update-warning' ), $GLOBALS['ran_booster_asset_test_enqueued_scripts'] );
		self::assertSame( array( 'jquery', 'heartbeat' ), $GLOBALS['ran_booster_asset_test_registered_scripts']['ran-booster-native-update-warning']['dependencies'] );
		self::assertSame(
			array(
				array(
					'handle' => 'ran-booster-native-update-warning',
					'domain' => 'ran-booster',
					'path'   => dirname( __DIR__, 2 ) . '/languages',
				),
			),
			$GLOBALS['ran_booster_asset_test_script_translations']
		);

		$GLOBALS['ran_booster_native_warning_screen'] = (object) array( 'id' => 'index' );
		$this->load_warning_asset( $booster, 'index.php' );
		self::assertSame( array( 'ran-booster-native-update-warning' ), $GLOBALS['ran_booster_asset_test_enqueued_scripts'] );
	}

	public function test_theme_screens_enqueue_and_render_for_theme_updaters_only(): void {
		$booster               = $this->booster(
			array(
				'state'     => 'recovery_required',
				'available' => false,
			)
		);
		$booster->booster_path = dirname( __DIR__, 2 );
		$booster->booster_url  = 'https://example.test/wp-content/plugins/ran-booster';
		$GLOBALS['ran_booster_native_warning_capabilities']['update_plugins'] = false;
		$GLOBALS['ran_booster_native_warning_capabilities']['update_themes']  = true;
		$GLOBALS['ran_booster_test_capabilities']                             = $GLOBALS['ran_booster_native_warning_capabilities'];

		foreach ( array( 'themes', 'themes-network' ) as $screen_id ) {
			$GLOBALS['ran_booster_native_warning_screen']       = (object) array( 'id' => $screen_id );
			$GLOBALS['ran_booster_asset_test_enqueued_scripts'] = array();
			$this->load_warning_asset( $booster, $screen_id . '.php' );
			self::assertSame( array( 'ran-booster-native-update-warning' ), $GLOBALS['ran_booster_asset_test_enqueued_scripts'] );
			ob_start();
			$this->render_warning( $booster );
			self::assertStringContainsString( 'data-ran-booster-native-update-warning', (string) ob_get_clean() );
		}

		$GLOBALS['ran_booster_native_warning_screen']       = (object) array( 'id' => 'index' );
		$GLOBALS['ran_booster_asset_test_enqueued_scripts'] = array();
		$this->load_warning_asset( $booster, 'index.php' );
		self::assertSame( array(), $GLOBALS['ran_booster_asset_test_enqueued_scripts'] );

		$GLOBALS['ran_booster_native_warning_screen']                        = (object) array( 'id' => 'themes' );
		$GLOBALS['ran_booster_native_warning_capabilities']['update_themes'] = false;
		$GLOBALS['ran_booster_test_capabilities']                            = $GLOBALS['ran_booster_native_warning_capabilities'];
		$GLOBALS['ran_booster_asset_test_enqueued_scripts']                  = array();
		$this->load_warning_asset( $booster, 'themes.php' );
		self::assertSame( array(), $GLOBALS['ran_booster_asset_test_enqueued_scripts'] );
		ob_start();
		$this->render_warning( $booster );
		self::assertSame( '', (string) ob_get_clean() );
	}

	public function test_global_warning_is_omitted_on_the_github_provider_page_only(): void {
		$booster               = $this->booster(
			array(
				'state'     => 'recovery_required',
				'available' => false,
			)
		);
		$booster->booster_path = dirname( __DIR__, 2 );
		$booster->booster_url  = 'https://example.test/wp-content/plugins/ran-booster';

		try {
			foreach ( array(
				'canonical gh'                  => array( 'tab' => 'gh' ),
				'trimmed uppercase gh'          => array( 'tab' => ' GH ' ),
				'gh credentials view'           => array(
					'tab'  => 'gh',
					'view' => 'credentials',
				),
				'gh signing-secrets view'       => array(
					'tab'  => 'gh',
					'view' => 'signing-secrets',
				),
				'gh repositories branch detail' => array(
					'tab'             => 'gh',
					'panel'           => 'repositories',
					'repository_view' => 'branch',
				),
			) as $case => $query ) {
				unset( $_GET['tab'], $_GET['view'], $_GET['panel'], $_GET['repository_view'] );
				foreach ( $query as $key => $value ) {
					$_GET[ $key ] = $value;
				}
				foreach ( array( 'toplevel_page_ran-booster', 'toplevel_page_ran-booster-network' ) as $screen_id ) {
					$GLOBALS['ran_booster_native_warning_screen']       = (object) array( 'id' => $screen_id );
					$GLOBALS['ran_booster_asset_test_enqueued_scripts'] = array();
					$this->load_warning_asset( $booster, 'toplevel_page_ran-booster' );
					self::assertSame( array(), $GLOBALS['ran_booster_asset_test_enqueued_scripts'], $case );
					ob_start();
					$this->render_warning( $booster );
					\RAN\Admin\BoosterNoticeScope::render_shell_notices();
					self::assertSame( '', (string) ob_get_clean(), $case );
				}
			}

			$GLOBALS['ran_booster_native_warning_screen'] = (object) array( 'id' => 'toplevel_page_ran-booster' );
			foreach ( array(
				'rejected dotted tab'        => array( 'tab' => 'g.h' ),
				'gh unknown view'            => array(
					'tab'  => 'gh',
					'view' => 'unknown',
				),
				'gh unknown panel'           => array(
					'tab'   => 'gh',
					'panel' => 'unknown',
				),
				'gh unknown repository view' => array(
					'tab'             => 'gh',
					'repository_view' => 'unknown',
				),
				'bitbucket tab'              => array( 'tab' => 'bb' ),
				'overview default tab'       => array(),
			) as $case => $query ) {
				unset( $_GET['tab'], $_GET['view'], $_GET['panel'], $_GET['repository_view'] );
				foreach ( $query as $key => $value ) {
					$_GET[ $key ] = $value;
				}
				$GLOBALS['ran_booster_asset_test_enqueued_scripts'] = array();
				$this->load_warning_asset( $booster, 'toplevel_page_ran-booster' );
				self::assertSame( array( 'ran-booster-native-update-warning' ), $GLOBALS['ran_booster_asset_test_enqueued_scripts'], $case );
				ob_start();
				$this->render_warning( $booster );
				\RAN\Admin\BoosterNoticeScope::render_shell_notices();
				self::assertStringContainsString( 'data-ran-booster-native-update-warning', (string) ob_get_clean(), $case );
			}

			$without_github_tab               = $this->booster(
				array(
					'state'     => 'recovery_required',
					'available' => false,
				),
				'bb'
			);
			$without_github_tab->booster_path = dirname( __DIR__, 2 );
			$without_github_tab->booster_url  = 'https://example.test/wp-content/plugins/ran-booster';
			unset( $_GET['tab'], $_GET['view'], $_GET['panel'], $_GET['repository_view'] );
			$_GET['tab']                                        = 'gh';
			$GLOBALS['ran_booster_native_warning_actions']      = array();
			$GLOBALS['ran_booster_asset_test_enqueued_scripts'] = array();
			$this->load_warning_asset( $without_github_tab, 'toplevel_page_ran-booster' );
			self::assertSame( array( 'ran-booster-native-update-warning' ), $GLOBALS['ran_booster_asset_test_enqueued_scripts'] );
			ob_start();
			$this->render_warning( $without_github_tab );
			\RAN\Admin\BoosterNoticeScope::render_shell_notices();
			self::assertStringContainsString( 'data-ran-booster-native-update-warning', (string) ob_get_clean() );
		} finally {
			unset( $_GET['tab'], $_GET['view'], $_GET['panel'], $_GET['repository_view'] );
		}
	}

	public function test_global_warning_is_preserved_when_an_add_on_owns_the_github_tab_key(): void {
		$add_ons = new \RAN\Admin\AdminAddOnRegistry();
		$add_ons->register(
			new \RAN\Admin\AdminAddOnTab(
				'ran-booster-addon',
				'gh',
				'Custom Add-On',
				static function (): void {}
			)
		);
		$add_ons->seal();

		$booster               = $this->booster(
			array(
				'state'     => 'recovery_required',
				'available' => false,
			),
			'gh',
			null,
			$add_ons
		);
		$booster->booster_path = dirname( __DIR__, 2 );
		$booster->booster_url  = 'https://example.test/wp-content/plugins/ran-booster';

		try {
			foreach ( array( 'gh', ' GH ' ) as $tab ) {
				foreach ( array( 'toplevel_page_ran-booster', 'toplevel_page_ran-booster-network' ) as $screen_id ) {
					unset( $_GET['tab'], $_GET['view'], $_GET['panel'], $_GET['repository_view'] );
					$_GET['tab']                                        = $tab;
					$GLOBALS['ran_booster_native_warning_screen']       = (object) array( 'id' => $screen_id );
					$GLOBALS['ran_booster_native_warning_actions']      = array();
					$GLOBALS['ran_booster_asset_test_enqueued_scripts'] = array();
					$this->load_warning_asset( $booster, 'toplevel_page_ran-booster' );
					self::assertSame(
						array( 'ran-booster-native-update-warning' ),
						$GLOBALS['ran_booster_asset_test_enqueued_scripts'],
						$tab . ' on ' . $screen_id
					);
					ob_start();
					$this->render_warning( $booster );
					\RAN\Admin\BoosterNoticeScope::render_shell_notices();
					self::assertStringContainsString(
						'data-ran-booster-native-update-warning',
						(string) ob_get_clean(),
						$tab . ' on ' . $screen_id
					);
				}
			}
		} finally {
			unset( $_GET['tab'], $_GET['view'], $_GET['panel'], $_GET['repository_view'] );
		}
	}

	/**
	 * @param array<string,mixed> $status
	 * @param array{name: string, url: string}|null $hold
	 */
	private function booster(
		array $status,
		string $tab_provider = 'gh',
		?array $hold = null,
		?\RAN\Admin\AdminAddOnRegistry $add_ons = null
	): Booster {
		$container = new CoreContainer();
		$container->bind(
			'RAN\\Admin\\AdminAddOnRegistry',
			$add_ons ?? new \RAN\Admin\AdminAddOnRegistry()
		);
		$container->bind(
			'RAN\\Admin\\ProviderTabLinks',
			new \RAN\Admin\ProviderTabLinks(
				static fn (): \RAN\Admin\AdminTabRegistry => new \RAN\Admin\AdminTabRegistry( new \RAN\RepositoryProvider\ProviderRegistry( array( new \RAN\Tests\RepositoryProvider\Support\ExternalFixtureProvider( $tab_provider ) ) ) )
			)
		);
		$container->bind(
			'RAN\\Admin\\ProviderSettingsPresenter',
			new class( $status, $hold ?? array(
				'name' => 'example-plugin',
				'url'  => 'https://example.test/wp-admin/admin.php?page=ran-booster&tab=gh#ran-booster-native-hold',
			) ) {
				/**
				 * @param array<string,mixed> $status
				 * @param array{name: string, url: string} $hold
				 */
				public function __construct( private array $status, private array $hold ) {
				}

				/** @return array<string,mixed> */
				public function github_selection_diagnostics(): array {
					return $this->status;
				}

				/** @return array{name: string, url: string}|null */
				public function native_hold(): ?array {
					return 'recovery_required' === ( $this->status['state'] ?? null ) ? $this->hold : null;
				}
			}
		);
		foreach ( array(
			'RAN\\Admin\\CredentialSelfDestructPurger',
			'RAN\\Dispatcher',
			'RAN\\Admin\\RepositoryPickerController',
			'RAN\\Admin\\DevelopmentSafetyNoticeController',
			'RAN\\Admin\\CredentialExpiryNoticeController',
			'RAN\\Admin\\DeploymentAdminController',
			'RAN\\Admin\\PackageUpdateProgressController',
			'RAN\\Admin\\PortabilityController',
			'RAN\\Admin\\CredentialExpiryNotice',
			'RAN\\Admin\\DeploymentAdminPresenter',
			'RAN\\Admin\\SecretsRuntimeAvailabilityNotice',
			'RAN\\Admin\\DatabaseCompatibilityNotice',
			'RAN\\Admin\\ManagedPluginFailureRows',
		) as $service ) {
			$container->bind(
				$service,
				new class() {
					/** @param array<int,mixed> $arguments */
					public function __call( string $method, array $arguments ): void {
						unset( $method, $arguments );
					}
				}
			);
		}

		return new Booster( $container );
	}

	private function callback_for( string $hook ): callable {
		foreach ( $GLOBALS['ran_booster_native_warning_actions'] as $action ) {
			if ( $hook === $action['hook'] ) {
				return $action['callback'];
			}
		}

		self::fail( 'Expected hook was not registered.' );
	}

	private function render_warning( Booster $booster ): void {
		$callback = null;
		$booster->init();
		foreach ( $GLOBALS['ran_booster_native_warning_actions'] as $action ) {
			if ( 'admin_notices' === $action['hook'] && $action['callback'] instanceof \Closure ) {
				$callback = $action['callback'];
			}
		}
		self::assertIsCallable( $callback );
		$callback();
	}

	private function load_warning_asset( Booster $booster, string $hook ): void {
		$booster->init();
		foreach ( $GLOBALS['ran_booster_native_warning_actions'] as $action ) {
			if ( 'admin_enqueue_scripts' === $action['hook'] && $action['callback'] instanceof \Closure ) {
				$action['callback']( $hook );
				return;
			}
		}
		self::fail( 'Expected asset hook was not registered.' );
	}

	/** @param array<string,mixed> $request */
	private function assert_heartbeat_response( Booster $booster, array $request ): void {
		$booster->init();
		$callback = $this->callback_for( 'heartbeat_received' );
		self::assertSame( array( 'existing' => true ), $callback( array( 'existing' => true ), $request ) );
	}
}
