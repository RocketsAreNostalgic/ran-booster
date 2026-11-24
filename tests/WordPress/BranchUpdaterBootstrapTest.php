<?php

declare(strict_types=1);

namespace RAN\Tests\WordPress;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RAN\WordPress\BranchUpdaterBootstrap;

#[CoversClass( BranchUpdaterBootstrap::class )]
final class BranchUpdaterBootstrapTest extends TestCase {

	private ?string $temp_root = null;

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this exact lifecycle hook.
	protected function tearDown(): void {
		if ( is_string( $this->temp_root ) ) {
			$this->remove_tree( $this->temp_root );
			$this->temp_root = null;
		}
	}

	public function test_autoloader_loads_updater_support_without_branch_updater_bootstrap(): void {
		$root   = dirname( __DIR__, 2 );
		$script = <<<'PHP'
define( 'ABSPATH', $argv[1] . '/tests/fixtures/wordpress/' );
require $argv[1] . '/autoload.php';
if ( ! class_exists( RAN\UpdaterSupport\V1\FailureText::class ) ) {
	fwrite( STDERR, "FailureText did not autoload\n" );
	exit( 1 );
}
if ( 'Safe message.' !== RAN\UpdaterSupport\V1\FailureText::display( 'Safe message.', 64 ) ) {
	fwrite( STDERR, "Unexpected FailureText::display result\n" );
	exit( 2 );
}
echo 'OK';
PHP;

		$result = $this->run_isolated_php( $script, array( $root ) );

		self::assertSame( 0, $result['exit_code'], $result['stderr'] );
		self::assertSame( 'OK', $result['stdout'] );
	}

	public function test_multisite_early_return_keeps_updater_support_autoloadable_without_composer_autoload(): void {
		$root   = dirname( __DIR__, 2 );
		$script = <<<'PHP'
require $argv[1] . '/tests/Support/BootstrapRuntimeWordPressFunctions.php';
define( 'WPINC', 'wp-includes' );
define( 'ABSPATH', $argv[1] . '/tests/fixtures/wordpress/' );
require $argv[1] . '/ran-booster.php';
if ( ! defined( 'RAN_BOOSTER_RUNTIME_MODE' ) || 'multisite_unsupported' !== constant( 'RAN_BOOSTER_RUNTIME_MODE' ) ) {
	fwrite( STDERR, "Expected multisite early return\n" );
	exit( 1 );
}
if ( 'Multisite safe.' !== RAN\UpdaterSupport\V1\FailureText::display( 'Multisite safe.', 64 ) ) {
	fwrite( STDERR, "FailureText unavailable after multisite early return\n" );
	exit( 2 );
}
echo 'OK';
PHP;

		$result = $this->run_isolated_php( $script, array( $root ) );

		self::assertSame( 0, $result['exit_code'], $result['stderr'] );
		self::assertSame( 'OK', $result['stdout'] );
	}

	public function test_branch_updater_bootstrap_enforces_dependency_directory_readability(): void {
		$root            = dirname( __DIR__, 2 );
		$this->temp_root = sys_get_temp_dir() . '/ran-booster-branch-bootstrap-' . bin2hex( random_bytes( 6 ) );
		$bootstrap_copy  = $this->temp_root . '/RAN/WordPress/BranchUpdaterBootstrap.php';

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Create disposable isolated plugin root for readability checks.
		self::assertTrue( mkdir( dirname( $bootstrap_copy ), 0700, true ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.copy_copy -- Copy production bootstrap class into disposable root lacking vendor directories.
		self::assertTrue( copy( $root . '/RAN/WordPress/BranchUpdaterBootstrap.php', $bootstrap_copy ) );

		$script = <<<'PHP'
require $argv[1];
try {
	RAN\WordPress\BranchUpdaterBootstrap::register();
	echo 'REGISTERED';
} catch ( LogicException $exception ) {
	echo 'LOGIC:' . $exception->getMessage();
}
PHP;

		$missing_both = $this->run_isolated_php( $script, array( $bootstrap_copy ) );
		self::assertSame( 0, $missing_both['exit_code'], $missing_both['stderr'] );
		self::assertSame( 'LOGIC:RAN Booster branch updater dependency is unavailable.', $missing_both['stdout'] );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Create only one of the two required vendor directories to verify both are checked.
		self::assertTrue( mkdir( $this->temp_root . '/vendor/ran/wp-branch-updater/src', 0700, true ) );
		$missing_support = $this->run_isolated_php( $script, array( $bootstrap_copy ) );
		self::assertSame( 0, $missing_support['exit_code'], $missing_support['stderr'] );
		self::assertSame( 'LOGIC:RAN Booster branch updater dependency is unavailable.', $missing_support['stdout'] );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Create the second required vendor directory so registration succeeds.
		self::assertTrue( mkdir( $this->temp_root . '/vendor/ran/updater-support/src', 0700, true ) );
		$both_present = $this->run_isolated_php( $script, array( $bootstrap_copy ) );
		self::assertSame( 0, $both_present['exit_code'], $both_present['stderr'] );
		self::assertSame( 'REGISTERED', $both_present['stdout'] );
	}

	/**
	 * @param list<string> $arguments
	 * @return array{exit_code: int, stdout: string, stderr: string}
	 */
	private function run_isolated_php( string $code, array $arguments ): array {
		$command = array_merge( array( PHP_BINARY, '-r', $code, '--' ), $arguments );
		$pipes   = array();
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- Isolated PHP subprocess proves release-ZIP autoloading without Composer's vendor/autoload.php.
		$process = proc_open(
			$command,
			array(
				0 => array( 'pipe', 'r' ),
				1 => array( 'pipe', 'w' ),
				2 => array( 'pipe', 'w' ),
			),
			$pipes
		);
		self::assertIsResource( $process );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close child stdin pipe immediately.
		fclose( $pipes[0] );
		$stdout = (string) stream_get_contents( $pipes[1] );
		$stderr = (string) stream_get_contents( $pipes[2] );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close child stdout pipe after reading.
		fclose( $pipes[1] );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close child stderr pipe after reading.
		fclose( $pipes[2] );
		$exit_code = proc_close( $process );

		return array(
			'exit_code' => $exit_code,
			'stdout'    => $stdout,
			'stderr'    => $stderr,
		);
	}

	private function remove_tree( string $path ): void {
		if ( is_link( $path ) || is_file( $path ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Remove only disposable fixture files owned by this test.
			unlink( $path );
			return;
		}
		if ( ! is_dir( $path ) ) {
			return;
		}
		$entries = scandir( $path );
		foreach ( false === $entries ? array() : $entries as $entry ) {
			if ( '.' !== $entry && '..' !== $entry ) {
				$this->remove_tree( $path . '/' . $entry );
			}
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Remove only disposable fixture directories owned by this test.
		rmdir( $path );
	}
}
