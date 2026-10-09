<?php

declare(strict_types=1);

namespace RAN\Tests\WordPress;

use PHPUnit\Framework\TestCase;

final class Phase0CliInputTest extends TestCase {
	public function test_boolean_cli_flags_are_rejected_before_ability_execution(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read only the actual test-fixture command without registering WordPress abilities or CLI routes.
		$source = file_get_contents( dirname( __DIR__ ) . '/fixtures/ran-booster-p4-phase-0-core/ran-booster-p4-phase-0-core.php' );
		self::assertIsString( $source );
		$start = strpos( $source, 'final class AbilityCommand extends WP_CLI_Command {' );
		self::assertIsInt( $start );
		$end = strpos( $source, '/**' . "\n" . ' * Register the Core-owned WP-CLI root', $start );
		self::assertIsInt( $end );
		$fixture = <<<'FIXTURE'
namespace RAN\Tests\Phase0CliInput;
class WP_CLI_Command {}
function get_current_user_id() { return 1; }
function wp_get_ability($name) {
	return new class {
		public function execute($input) {
			throw new \UnexpectedValueException(json_encode($input, JSON_THROW_ON_ERROR));
		}
	};
}
FIXTURE;
		// phpcs:ignore Squiz.PHP.Eval.Discouraged -- Evaluate the actual isolated command with a sentinel ability; no fixture registration or WordPress mutations execute.
		$command = eval( $fixture . substr( $source, $start, $end - $start ) . ' return new AbilityCommand();' );
		$run     = array( $command, 'run' );
		self::assertIsCallable( $run );
		foreach ( array( true, false ) as $flag ) {
			try {
				$run( array( 'fixture/read' ), array( 'input' => $flag ) );
				self::fail( 'A boolean input flag reached ability execution.' );
			} catch ( \InvalidArgumentException $failure ) {
				self::assertSame( 'Input must be a JSON string or - for stdin.', $failure->getMessage() );
			}
		}

		$this->expectException( \UnexpectedValueException::class );
		$this->expectExceptionMessage( '{"target":"kept"}' );
		$run( array( 'fixture/read' ), array( 'input' => '{"target":"kept"}' ) );
	}
}
