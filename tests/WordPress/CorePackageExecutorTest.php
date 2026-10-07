<?php

declare(strict_types=1);

namespace RAN\Tests\WordPress;

use PHPUnit\Framework\TestCase;
use RAN\WordPress\CorePackageExecutor;
use RAN\WordPress\CorePackageExecutionResult;
use ReflectionMethod;


final class CorePackageExecutorTest extends TestCase {

	public function test_automatic_updater_installation_result_with_one_exact_completion_succeeds(): void {
		$result = $this->map_core_result(
			$this->wordpress_installation_result(),
			array( $this->extra() )
		);

		self::assertTrue( $result->is_successful() );
	}

	public function test_installation_result_array_without_one_exact_completion_fails_closed(): void {
		$cases = array(
			'missing completion'   => array(),
			'wrong completion'     => array(
				array(
					'plugin' => 'other/other.php',
					'type'   => 'plugin',
					'action' => 'update',
				),
			),
			'multiple completions' => array(
				$this->extra(),
				$this->extra(),
			),
		);

		foreach ( $cases as $case => $completions ) {
			$result = $this->map_core_result( $this->wordpress_installation_result(), $completions );

			self::assertFalse( $result->is_successful(), $case );
		}
	}

	/** @return array{plugin: string, type: string, action: string} */
	private function extra( string $action = 'update' ): array {
		return array(
			'plugin' => 'example/example.php',
			'type'   => 'plugin',
			'action' => $action,
		);
	}

	/** @return array{source: string, source_files: list<string>, destination: string, destination_name: string, local_destination: string, remote_destination: string, clear_destination: bool} */
	private function wordpress_installation_result(): array {
		return array(
			'source'             => '/tmp/upgrade/example/',
			'source_files'       => array( 'example' ),
			'destination'        => '/wp-content/plugins/example/',
			'destination_name'   => 'example',
			'local_destination'  => '/wp-content/plugins',
			'remote_destination' => '/wp-content/plugins/example/',
			'clear_destination'  => true,
		);
	}

	/** @param list<array<string, mixed>> $completions */
	private function map_core_result( mixed $core_result, array $completions ): CorePackageExecutionResult {
		$method = new ReflectionMethod( CorePackageExecutor::class, 'map_result' );

		return $method->invoke( new CorePackageExecutor(), $core_result, 'plugin', 'update', 'example/example.php', $completions );
	}
}
