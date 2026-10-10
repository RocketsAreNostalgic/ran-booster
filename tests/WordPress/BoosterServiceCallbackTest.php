<?php

declare(strict_types=1);

namespace RAN\Tests\WordPress;

use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use RAN\Admin\CredentialSelfDestructPurger;
use RAN\Booster;
use RAN\Internal\CoreContainer;
use RuntimeException;

final class BoosterServiceCallbackTest extends TestCase {

	/** @return iterable<string, array{string}> */
	public static function invalid_services(): iterable {
		foreach ( array( 'null', 'scalar', 'missing', 'inaccessible' ) as $scenario ) {
			yield $scenario => array( $scenario );
		}
	}

	#[DataProvider( 'invalid_services' )]
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_invalid_service_fails_before_registering_a_hook( string $scenario ): void {
		require dirname( __DIR__ ) . '/Support/BootstrapRuntimeWordPressFunctions.php';
		$service = match ( $scenario ) {
			'null' => null,
			'scalar' => 7,
			'missing' => new \stdClass(),
			default => new class() {
				protected function purge(): void {}
			},
		};
		$container = new CoreContainer();
		$container->bind( CredentialSelfDestructPurger::class, static fn (): mixed => $service );
		try {
			( new Booster( $container ) )->init();
			self::fail( 'An invalid service callback was registered.' );
		} catch ( LogicException $exception ) {
			self::assertSame( 'Core service does not provide a callable hook.', $exception->getMessage() );
		}
		self::assertSame( array(), $GLOBALS['ran_booster_bootstrap_actions'] );
	}

	/** @return iterable<string, array{bool}> */
	public static function callable_services(): iterable {
		yield 'public method' => array( false );
		yield 'magic method' => array( true );
	}

	#[DataProvider( 'callable_services' )]
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_callable_duck_service_retains_identity_and_single_resolution( bool $magic ): void {
		require dirname( __DIR__ ) . '/Support/BootstrapRuntimeWordPressFunctions.php';
		$service     = $magic ? new class() {
			public int $calls = 0;
			/** @param array<array-key, mixed> $arguments */
			public function __call( string $method, array $arguments ): void {
				TestCase::assertSame( 'purge', $method );
				TestCase::assertSame( array(), $arguments );
				++$this->calls;
			}
		} : new class() {
			public int $calls = 0;
			public function purge(): void {
				++$this->calls;
			}
		};
		$container   = new CoreContainer();
		$resolutions = 0;
		$container->bind(
			CredentialSelfDestructPurger::class,
			static function () use ( $service, &$resolutions ): object {
				++$resolutions;
				return $service;
			}
		);
		$stop = new RuntimeException( 'Stop after the first service callback.' );
		$container->bind( 'RAN\Dispatcher', static fn (): never => throw $stop );
		try {
			( new Booster( $container ) )->init();
			self::fail( 'The sentinel factory should stop initialization.' );
		} catch ( RuntimeException $exception ) {
			self::assertSame( $stop, $exception );
		}
		self::assertSame( 1, $resolutions );
		$registered = $GLOBALS['ran_booster_bootstrap_actions'][0];
		self::assertSame( array( $service, 'purge' ), $registered['callback'] );
		self::assertSame( 'admin_init', $registered['hook'] );
		self::assertSame( 1, $registered['priority'] );
		self::assertSame( 1, $registered['acceptedArgs'] );
		( $registered['callback'] )();
		self::assertSame( 1, $service->calls );
	}
}
