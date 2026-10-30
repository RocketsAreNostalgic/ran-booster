<?php

declare(strict_types=1);

namespace RAN\Tests\Admin;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use RAN\Booster;
use RAN\Internal\CoreContainer;
use RAN\WordPress\CorePackageExecutor;
use RAN\WordPress\WordPressUpdaterLock;
use RuntimeException;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class ExtensionInstallRequestTest extends TestCase {
	private string $root;

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit lifecycle override.
	protected function setUp(): void {
		require_once __DIR__ . '/ExtensionInstallRequestWordPressFunctions.php';
		$this->root = sys_get_temp_dir() . '/ran-extension-request-' . bin2hex( random_bytes( 8 ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Isolated test fixture setup and cleanup.
		mkdir( $this->root . '/wp-admin/includes', 0700, true );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Isolated test fixture setup and cleanup.
		file_put_contents( $this->root . '/wp-admin/includes/plugin.php', '<?php' );
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Isolated WordPress test root.
		define( 'ABSPATH', $this->root . '/' );
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST                     = array(
			'extension_id' => 'unknown',
			'_wpnonce'     => 'good:ran_booster_extension_install_unknown',
		);
		$GLOBALS['ran_booster_extension_request_capabilities'] = array(
			'manage_options'  => true,
			'install_plugins' => true,
		);
		$GLOBALS['ran_booster_extension_request_plugin_reads'] = 0;
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit lifecycle override.
	protected function tearDown(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Isolated test fixture setup and cleanup.
		unlink( $this->root . '/wp-admin/includes/plugin.php' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Isolated test fixture setup and cleanup.
		rmdir( $this->root . '/wp-admin/includes' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Isolated test fixture setup and cleanup.
		rmdir( $this->root . '/wp-admin' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Isolated test fixture setup and cleanup.
		rmdir( $this->root );
	}

	private function prepare_activation( bool $compatible = true ): string {
		define( 'RAN_BOOSTER_PROVIDER_API_VERSION', $compatible ? 15 : 10 );
		define( 'RAN_BOOSTER_ADDON_API_VERSION', 17 );
		$plugin = 'ran-booster-bitbucket/ran-booster-bitbucket.php';
		$GLOBALS['ran_booster_extension_request_plugins'][ $plugin ]              = array( 'Version' => '0.1.0-beta.17' );
		$GLOBALS['ran_booster_extension_request_capabilities']['activate_plugin'] = true;
		$_POST = array(
			'extension_id' => 'ran-booster-bitbucket',
			'_wpnonce'     => 'good:activate-plugin_' . $plugin,
		);
		return $plugin;
	}

	private function activation_response(): string {
		try {
			( new Booster() )->activate_extension();
			self::fail( 'Expected denial or redirect.' );
		} catch ( RuntimeException $failure ) {
			return $failure->getMessage();
		}
	}

	public function test_activation_uses_catalogue_basename_meta_capability_and_native_nonce(): void {
		$plugin = $this->prepare_activation();
		self::assertStringContainsString( 'ran_booster_extension_result=activated', $this->activation_response() );
		self::assertSame( array( $plugin ), $GLOBALS['ran_booster_extension_request_capability_args'] );
		self::assertSame( 'activate-plugin_' . $plugin, $GLOBALS['ran_booster_extension_request_nonce_action'] );
		self::assertSame( $plugin, $GLOBALS['ran_booster_extension_request_activation'][0] );
		self::assertStringContainsString( 'ran_booster_extension_result=activation_failed', $GLOBALS['ran_booster_extension_request_activation'][1] );
		self::assertFalse( $GLOBALS['ran_booster_extension_request_activation'][2] );
	}

	public function test_activation_requires_post_and_manage_options(): void {
		$this->prepare_activation();
		$_SERVER['REQUEST_METHOD'] = 'GET';
		self::assertStringStartsWith( 'denied:', $this->activation_response() );
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$GLOBALS['ran_booster_extension_request_capabilities']['manage_options'] = false;
		self::assertStringStartsWith( 'denied:', $this->activation_response() );
		self::assertSame( 0, $GLOBALS['ran_booster_extension_request_plugin_reads'] );
		self::assertArrayNotHasKey( 'ran_booster_extension_request_nonce_action', $GLOBALS );
		self::assertArrayNotHasKey( 'ran_booster_extension_request_activation', $GLOBALS );
	}

	public function test_activation_requires_plugin_capability_and_native_nonce(): void {
		$this->prepare_activation();
		$GLOBALS['ran_booster_extension_request_capabilities']['activate_plugin'] = false;
		self::assertStringStartsWith( 'denied:', $this->activation_response() );
		$GLOBALS['ran_booster_extension_request_capabilities']['activate_plugin'] = true;
		$_POST['_wpnonce'] = 'wrong';
		self::assertSame( 'bad nonce', $this->activation_response() );
		self::assertArrayNotHasKey( 'ran_booster_extension_request_activation', $GLOBALS );
	}

	public function test_activation_refuses_unknown_and_absent_targets(): void {
		$plugin                = $this->prepare_activation();
		$_POST['extension_id'] = 'unknown';
		self::assertStringStartsWith( 'denied:', $this->activation_response() );
		unset( $_POST['extension_id'] );
		self::assertStringStartsWith( 'denied:', $this->activation_response() );
		$_POST['extension_id'] = 'ran-booster-bitbucket';
		unset( $GLOBALS['ran_booster_extension_request_plugins'][ $plugin ] );
		self::assertStringContainsString( 'ran_booster_extension_result=activation_failed', $this->activation_response() );
		self::assertArrayNotHasKey( 'ran_booster_extension_request_activation', $GLOBALS );
	}

	public function test_activation_refuses_incompatible_target(): void {
		$this->prepare_activation( false );
		self::assertStringContainsString( 'ran_booster_extension_result=activation_failed', $this->activation_response() );
		self::assertArrayNotHasKey( 'ran_booster_extension_request_activation', $GLOBALS );
	}

	public function test_activation_refuses_network_only_plugin(): void {
		$this->prepare_activation();
		$GLOBALS['ran_booster_extension_request_multisite']    = true;
		$GLOBALS['ran_booster_extension_request_network_only'] = true;
		self::assertStringContainsString( 'ran_booster_extension_result=activation_failed', $this->activation_response() );
		self::assertArrayNotHasKey( 'ran_booster_extension_request_activation', $GLOBALS );
	}

	public function test_already_active_is_idempotent(): void {
		$this->prepare_activation();
		$GLOBALS['ran_booster_extension_request_active'] = true;
		self::assertStringContainsString( 'ran_booster_extension_result=activated', $this->activation_response() );
		self::assertArrayNotHasKey( 'ran_booster_extension_request_activation', $GLOBALS );
	}

	public function test_null_result_without_active_readback_does_not_claim_success(): void {
		$this->prepare_activation();
		$GLOBALS['ran_booster_extension_request_becomes_active'] = false;
		self::assertStringContainsString( 'ran_booster_extension_result=activation_failed', $this->activation_response() );
		self::assertArrayHasKey( 'ran_booster_extension_request_activation', $GLOBALS );
	}

	public function test_activation_error_preserves_actual_active_state_and_maps_dependency_failure(): void {
		$this->prepare_activation();
		$GLOBALS['ran_booster_extension_request_error'] = new class() {
			public function get_error_code(): string {
				return 'unexpected_output';
			}
		};
		self::assertStringContainsString( 'ran_booster_extension_result=activation_failed', $this->activation_response() );
		self::assertTrue( $GLOBALS['ran_booster_extension_request_active'] );
		$GLOBALS['ran_booster_extension_request_active']         = false;
		$GLOBALS['ran_booster_extension_request_becomes_active'] = false;
		$GLOBALS['ran_booster_extension_request_error']          = new class() {
			public function get_error_code(): string {
				return 'plugin_missing_dependencies';
			}
		};
		self::assertStringContainsString( 'ran_booster_extension_result=plugin_missing_dependencies', $this->activation_response() );
		self::assertFalse( $GLOBALS['ran_booster_extension_request_active'] );
	}

	public function test_get_never_runs_nonce_or_install(): void {
		$_SERVER['REQUEST_METHOD'] = 'GET';
		try {
			( new Booster() )->install_extension();
			self::fail( 'Expected denial.' ); } catch ( RuntimeException $failure ) {
			self::assertStringStartsWith( 'denied:', $failure->getMessage() );
			}
			self::assertArrayNotHasKey( 'ran_booster_extension_request_nonce_action', $GLOBALS );
			self::assertSame( 0, $GLOBALS['ran_booster_extension_request_plugin_reads'] );
	}

	public function test_both_capabilities_are_required_before_nonce(): void {
		$GLOBALS['ran_booster_extension_request_capabilities']['install_plugins'] = false;
		try {
			( new Booster() )->install_extension();
			self::fail( 'Expected denial.' ); } catch ( RuntimeException $failure ) {
			self::assertStringStartsWith( 'denied:', $failure->getMessage() );
			}
			self::assertArrayNotHasKey( 'ran_booster_extension_request_nonce_action', $GLOBALS );
			self::assertSame( 0, $GLOBALS['ran_booster_extension_request_plugin_reads'] );
	}

	public function test_manage_options_is_required_before_nonce(): void {
		$GLOBALS['ran_booster_extension_request_capabilities']['manage_options'] = false;
		try {
			( new Booster() )->install_extension();
			self::fail( 'Expected denial.' );
		} catch ( RuntimeException $failure ) {
			self::assertStringStartsWith( 'denied:', $failure->getMessage() );
		}
		self::assertArrayNotHasKey( 'ran_booster_extension_request_nonce_action', $GLOBALS );
		self::assertSame( 0, $GLOBALS['ran_booster_extension_request_plugin_reads'] );
	}

	public function test_nonce_is_bound_to_the_requested_id(): void {
		$_POST['_wpnonce'] = 'good:ran_booster_extension_install_other';
		try {
			( new Booster() )->install_extension();
			self::fail( 'Expected nonce failure.' ); } catch ( RuntimeException $failure ) {
			self::assertSame( 'bad nonce', $failure->getMessage() );
			}
			self::assertSame( 'ran_booster_extension_install_unknown', $GLOBALS['ran_booster_extension_request_nonce_action'] );
			self::assertSame( 0, $GLOBALS['ran_booster_extension_request_plugin_reads'] );
	}

	public function test_unknown_id_returns_closed_failure_without_download(): void {
		$container = new CoreContainer();
		$container->bind( WordPressUpdaterLock::class, new WordPressUpdaterLock() );
		$container->bind( CorePackageExecutor::class, new CorePackageExecutor() );
		try {
			( new Booster( $container ) )->install_extension();
			self::fail( 'Expected redirect.' ); } catch ( RuntimeException $failure ) {
			self::assertStringContainsString( 'ran_booster_extension_result=failed', $failure->getMessage() );
			}
			self::assertSame( 'ran_booster_extension_install_unknown', $GLOBALS['ran_booster_extension_request_nonce_action'] );
			self::assertSame( 1, $GLOBALS['ran_booster_extension_request_plugin_reads'] );
	}
}
