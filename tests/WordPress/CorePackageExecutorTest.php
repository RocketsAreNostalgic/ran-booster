<?php

declare(strict_types=1);

namespace Tests\WordPress;

use PHPUnit\Framework\TestCase;
use RAN\WordPress\CorePackageExecutor;
use RAN\WordPress\CorePackageExecutionResult;
use ReflectionMethod;

// phpcs:disable WordPress.WP.AlternativeFunctions -- Tests deliberately own private temporary files.

final class CorePackageExecutorTest extends TestCase {

	public function testAutomaticUpdaterInstallationResultWithOneExactCompletionSucceeds(): void {
		$result = $this->mapCoreResult(
			$this->wordpressInstallationResult(),
			array( $this->extra() )
		);

		self::assertTrue( $result->isSuccessful() );
	}

	public function testInstallationResultArrayWithoutOneExactCompletionFailsClosed(): void {
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
			$result = $this->mapCoreResult( $this->wordpressInstallationResult(), $completions );

			self::assertFalse( $result->isSuccessful(), $case );
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
	private function wordpressInstallationResult(): array {
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
	private function mapCoreResult( mixed $coreResult, array $completions ): CorePackageExecutionResult {
		$method = new ReflectionMethod( CorePackageExecutor::class, 'mapResult' );

		return $method->invoke( new CorePackageExecutor(), $coreResult, 'plugin', 'update', 'example/example.php', $completions );
	}
}
