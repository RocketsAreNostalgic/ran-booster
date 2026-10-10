<?php

declare(strict_types=1);

namespace RAN\Tests\WordPress;

use PHPUnit\Framework\TestCase;

final class Phase44CommandBoundaryTest extends TestCase {
	public function test_native_target_uses_the_loaded_artifact_constructor_argument_order(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Inspect only the historical artifact construction expression without starting the disposable worker.
		$source = file_get_contents( dirname( __DIR__ ) . '/Integration/phase-4.4-core-disposable-harness.php' );
		self::assertIsString( $source );
		$start = strpos( $source, '( new ReflectionClass( RAN\\BoosterGitHubProvider\\V1\\GitHubReleaseNativeTarget::class ) )' );
		self::assertIsInt( $start );
		$end = strpos( $source, ';', $start );
		self::assertIsInt( $end );
		$recorder    = new class() {
			/** @var array<array-key, mixed> */
			public array $arguments;

			public function __construct( mixed ...$arguments ) {
				$this->arguments = $arguments;
			}
		};
		$expression  = str_replace(
			array( 'RAN\\BoosterGitHubProvider\\V1\\GitHubReleaseNativeTarget::class', 'WP_PLUGIN_DIR', 'get_theme_root()' ),
			array( '$recorder::class', '$plugin_root', '$theme_root' ),
			substr( $source, $start, $end - $start )
		);
		$plugin_root = '/fixture/plugins';
		$theme_root  = '/fixture/themes';
		foreach ( array( 'plugin', 'theme' ) as $type ) {
			$slug   = 'phase44-' . $type;
			$id     = 'plugin' === $type ? $slug . '/' . $slug . '.php' : $slug;
			$policy = 'controlled-policy';
			// phpcs:ignore Squiz.PHP.Eval.Discouraged -- Execute the actual nine-argument constructor expression against a recorder in place of the installed historical class.
			$target = eval( 'return ' . $expression . ';' );
			self::assertInstanceOf( $recorder::class, $target );
			self::assertCount( 9, $target->arguments );
			self::assertSame( array( $type, 'plugin' === $type ? $plugin_root . '/' . $id : $theme_root . '/' . $id . '/style.css', 'phase44-owner/' . $slug, '101', $slug, $id ), array_slice( $target->arguments, 0, 6 ) );
			self::assertInstanceOf( \Closure::class, $target->arguments[6] );
			self::assertSame( 'phase44-token', $target->arguments[6]() );
			self::assertSame( array( 'stable', $policy ), array_slice( $target->arguments, 7 ) );
		}
	}

	public function test_command_preserves_output_and_rejects_failed_reads(): void {
		$command = $this->command( false );
		self::assertSame(
			array(
				'stdout' => 'out',
				'stderr' => 'err',
			),
			$command( array( PHP_BINARY, '-r', 'echo "out"; fwrite(STDERR, "err");' ), __DIR__ )
		);

		$command = $this->command( true );
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Could not read disposable command output.' );
		$command( array( PHP_BINARY, '-r', 'echo "out";' ), __DIR__ );
	}

	public function test_uninitialized_mysql_handles_never_reach_connection_attempts(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read only the readiness helper without launching WordPress or MySQL.
		$source = file_get_contents( dirname( __DIR__ ) . '/Integration/phase-4.4-core-disposable-harness.php' );
		self::assertIsString( $source );
		$start = strpos( $source, 'function ran_booster_phase44_mysql_ready(' );
		self::assertIsInt( $start );
		$end = strpos( $source, '/**', $start );
		self::assertIsInt( $end );
		$fixture = <<<'FIXTURE'
namespace RAN\Tests\Phase44FailedMySQL;
use RuntimeException;
function mysqli_init() { return false; }
function mysqli_real_connect() { throw new \LogicException('An invalid MySQL handle reached connection.'); }
function usleep($delay) {}
FIXTURE;
		// phpcs:ignore Squiz.PHP.Eval.Discouraged -- Evaluate the actual isolated readiness loop with failed allocation and no socket/process side effects.
		$ready = eval( $fixture . substr( $source, $start, $end - $start ) . ' return \Closure::fromCallable(__NAMESPACE__ . "\\\\ran_booster_phase44_mysql_ready");' );
		self::assertInstanceOf( \Closure::class, $ready );
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Isolated MySQL did not become ready.' );
		$ready( '/unused-fixture-socket' );
	}

	/** @return \Closure(list<string>, string): array{stdout: string, stderr: string} */
	private function command( bool $failed_reads ): \Closure {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read the actual opt-in harness without launching its WordPress/MySQL process.
		$source = file_get_contents( dirname( __DIR__ ) . '/Integration/phase-4.4-core-disposable-harness.php' );
		self::assertIsString( $source );
		$start = strpos( $source, 'function ran_booster_phase44_command(' );
		self::assertIsInt( $start );
		$end = strpos( $source, '/** @param list<string> $exclude */', $start );
		self::assertIsInt( $end );
		$namespace = 'RAN\\Tests\\Phase44Command' . ( $failed_reads ? 'FailedRead' : 'SuccessfulRead' );
		$reader    = $failed_reads ? 'function stream_get_contents($stream) { \stream_get_contents($stream); return false; }' : '';
		// phpcs:ignore Squiz.PHP.Eval.Discouraged -- Evaluate only the real command helper in isolated fixture namespaces; inject failed pipe reads without launching the destructive harness.
		$command = eval( 'namespace ' . $namespace . '; use RuntimeException; ' . $reader . substr( $source, $start, $end - $start ) . ' return \Closure::fromCallable(__NAMESPACE__ . "\\\\ran_booster_phase44_command");' );
		self::assertInstanceOf( \Closure::class, $command );

		return $command;
	}
}
