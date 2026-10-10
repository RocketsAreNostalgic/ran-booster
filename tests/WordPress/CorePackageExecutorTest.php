<?php

declare(strict_types=1);

namespace RAN\Tests\WordPress;

use PHPUnit\Framework\TestCase;
use RAN\WordPress\CorePackageExecutor;
use RAN\WordPress\CorePackageExecutionResult;
use ReflectionMethod;


final class CorePackageExecutorTest extends TestCase {

	public function test_optional_operation_preserves_callable_forms_binding_and_nullable_offer(): void {
		$receiver       = new class() {
			/** @return array{0: object, 1: string, 2: string, 3: string, 4: object|null} */
			public function operation( string $action, string $type, string $path, ?object $offer ): array {
				return array( $this, $action, $type, $path, $offer );
			}
			/** @return array{0: object, 1: string, 2: string, 3: string, 4: object|null} */
			public function __invoke( string $action, string $type, string $path, ?object $offer ): array {
				return $this->operation( $action, $type, $path, $offer );
			}
		};
		$magic_receiver = new /** @method list<mixed> operation(string $action, string $type, string $path, ?object $offer) */ class() {
			/**
			 * @param list<mixed> $arguments
			 * @return list<mixed>
			 */
			public function __call( string $name, array $arguments ): array {
				TestCase::assertSame( 'operation', $name );
				return array_merge( array( $this ), $arguments );
			}
		};
		$method         = new ReflectionMethod( CorePackageExecutor::class, 'run_core_operation' );
		foreach ( array(
			array( new CorePackageExecutor( array( $receiver, 'operation' ) ), $receiver ),
			array( new CorePackageExecutor( $receiver ), $receiver ),
			array( new CorePackageExecutor( $receiver->operation( ... ) ), $receiver ),
			array( new CorePackageExecutor( array( $magic_receiver, 'operation' ) ), $magic_receiver ),
			array( new CorePackageExecutor( array( self::class, 'capture_operation' ) ), null ),
			array( new CorePackageExecutor( self::class . '::capture_operation' ), null ),
			array( new CorePackageExecutor( static fn ( string $action, string $type, string $path, ?object $offer ): array => self::capture_operation( $action, $type, $path, $offer ) ), null ),
		) as [ $executor, $binding ] ) {
			foreach ( array( null, new \stdClass() ) as $offer ) {
				$action = null === $offer ? 'install' : 'update';
				self::assertSame(
					array( $binding, $action, 'plugin', '/tmp/package.zip', $offer ),
					$method->invoke( $executor, $action, 'plugin', '/tmp/package.zip', $offer )
				);
			}
		}
		$property = new \ReflectionProperty( CorePackageExecutor::class, 'core_operation' );
		self::assertNull( $property->getValue( new CorePackageExecutor() ) );
	}

	/** @return array{0: null, 1: string, 2: string, 3: string, 4: object|null} */
	public static function capture_operation( string $action, string $type, string $path, ?object $offer ): array {
		return array( null, $action, $type, $path, $offer );
	}

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
