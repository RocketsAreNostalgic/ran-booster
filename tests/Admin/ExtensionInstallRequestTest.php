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
