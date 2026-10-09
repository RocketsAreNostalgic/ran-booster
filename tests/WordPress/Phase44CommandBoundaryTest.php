<?php

declare(strict_types=1);

namespace RAN\Tests\WordPress;

use PHPUnit\Framework\TestCase;

final class Phase44CommandBoundaryTest extends TestCase {
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
