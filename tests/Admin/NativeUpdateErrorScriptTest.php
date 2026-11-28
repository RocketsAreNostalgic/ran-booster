<?php

declare(strict_types=1);

namespace RAN\Tests\Admin;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use RAN\Booster;
use RAN\Internal\CoreContainer;

/** The Plugins-screen update error adapter (#501) loads only where WordPress renders plugin update rows. */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class NativeUpdateErrorScriptTest extends TestCase {

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this exact lifecycle override name.
	protected function setUp(): void {
		require_once __DIR__ . '/NativeUpdateErrorWordPressFunctions.php';
		require_once __DIR__ . '/BoosterAssetsWordPressFunctions.php';
		require_once dirname( __DIR__ ) . '/Support/NativeUpdateErrorGlobalWordPressFunctions.php';
		$GLOBALS['ran_booster_native_warning_actions']           = array();
		$GLOBALS['ran_booster_native_warning_capability_checks'] = array();
		$GLOBALS['ran_booster_native_warning_capabilities']      = array( 'update_plugins' => true );
		$GLOBALS['ran_booster_test_capability_checks']           = array();
		$GLOBALS['ran_booster_test_capabilities']                = $GLOBALS['ran_booster_native_warning_capabilities'];
		$GLOBALS['ran_booster_native_warning_screen']            = (object) array( 'id' => 'plugins' );
		$GLOBALS['ran_booster_native_warning_multisite']         = false;
		$GLOBALS['ran_booster_asset_test_registered_scripts']    = array();
		$GLOBALS['ran_booster_asset_test_enqueued_scripts']      = array();
		$GLOBALS['ran_booster_asset_test_script_events']         = array();
		$GLOBALS['ran_booster_asset_test_script_translations']   = array();
	}

	public function test_adapter_loads_on_the_plugins_screen_for_plugin_updaters_only(): void {
		$booster = $this->booster();
		$this->load_assets( $booster, 'plugins.php' );
		self::assertSame( array( 'ran-booster-native-update-error' ), $GLOBALS['ran_booster_asset_test_enqueued_scripts'] );
		self::assertSame( array( 'jquery', 'updates', 'wp-i18n', 'wp-a11y' ), $GLOBALS['ran_booster_asset_test_registered_scripts']['ran-booster-native-update-error']['dependencies'] );
		self::assertSame(
			array(
				array(
					'handle' => 'ran-booster-native-update-error',
					'domain' => 'ran-booster',
					'path'   => dirname( __DIR__, 2 ) . '/languages',
				),
			),
			$GLOBALS['ran_booster_asset_test_script_translations']
		);

		$GLOBALS['ran_booster_asset_test_enqueued_scripts'] = array();
		$this->load_assets( $booster, 'index.php' );
		self::assertSame( array(), $GLOBALS['ran_booster_asset_test_enqueued_scripts'] );

		$GLOBALS['ran_booster_native_warning_capabilities']['update_plugins'] = false;
		$GLOBALS['ran_booster_test_capabilities']['update_plugins']           = false;
		$this->load_assets( $booster, 'plugins.php' );
		self::assertSame( array(), $GLOBALS['ran_booster_asset_test_enqueued_scripts'] );
	}

	public function test_init_registers_no_heartbeat_callback(): void {
		$this->booster()->init();
		self::assertSame( array(), array_filter( $GLOBALS['ran_booster_native_warning_actions'], static fn ( array $action ): bool => 'heartbeat_received' === $action['hook'] ) );
	}

	public function test_the_github_supplier_notice_hooks_site_admin_screens_only(): void {
		$rendered = new \ArrayObject();
		$this->booster( $rendered )->init();
		foreach ( array(
			'admin_notices'         => 1,
			'network_admin_notices' => 0,
		) as $hook => $expected ) {
			$rendered->exchangeArray( array() );
			foreach ( $GLOBALS['ran_booster_native_warning_actions'] as $action ) {
				if ( $hook === $action['hook'] ) {
					$action['callback']();
				}
			}
			self::assertCount( $expected, $rendered, $hook );
		}
	}

	/** @param \ArrayObject<int,string>|null $supplier_renders Records each GitHub supplier notice render. */
	private function booster( ?\ArrayObject $supplier_renders = null ): Booster {
		$container = new CoreContainer();
		foreach ( array(
			'RAN\\Admin\\ProviderTabLinks',
			'RAN\\Admin\\ProviderSettingsPresenter',
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
			'RAN\\Admin\\GitHubSupplierNotice',
			'RAN\\Admin\\ManagedPluginFailureRows',
		) as $service ) {
			if ( 'RAN\\Admin\\GitHubSupplierNotice' === $service && null !== $supplier_renders ) {
				$container->bind(
					$service,
					new class( $supplier_renders ) {
						/** @param \ArrayObject<int,string> $renders */
						public function __construct( private \ArrayObject $renders ) {
						}

						public function render(): void {
							$this->renders->append( 'render' );
						}
					}
				);
				continue;
			}
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
		$booster               = new Booster( $container );
		$booster->booster_path = dirname( __DIR__, 2 );
		$booster->booster_url  = 'https://example.test/wp-content/plugins/ran-booster';

		return $booster;
	}

	private function load_assets( Booster $booster, string $hook ): void {
		$GLOBALS['ran_booster_native_warning_actions'] = array();
		$booster->init();
		foreach ( $GLOBALS['ran_booster_native_warning_actions'] as $action ) {
			if ( 'admin_enqueue_scripts' === $action['hook'] && $action['callback'] instanceof \Closure ) {
				$action['callback']( $hook );
				return;
			}
		}
		self::fail( 'Expected asset hook was not registered.' );
	}
}
