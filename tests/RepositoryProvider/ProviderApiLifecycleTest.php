<?php

declare(strict_types=1);

namespace Tests\RepositoryProvider;

use ArgumentCountError;
use LogicException;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use RAN\AddOn\Logging\CoreLoggingFacade;
use RAN\RepositoryProvider\ProviderRegistry;

final class ProviderApiLifecycleTest extends TestCase {

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function testConflictingProviderApiMarkerFailsClearly(): void {
		define( 'WPINC', 'wpinc' );
		define( 'RAN_BOOSTER_PROVIDER_API_VERSION', 1 );

		$this->expectException( LogicException::class );
		$this->expectExceptionMessage( 'RAN Booster Provider API 6 conflicts with an existing API version marker.' );

		require dirname( __DIR__, 2 ) . '/ran-booster.php';
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function testConflictingLoggingApiMarkerFailsClearly(): void {
		define( 'WPINC', 'wpinc' );
		define( 'RAN_BOOSTER_LOGGING_API_VERSION', 2 );

		$this->expectException( LogicException::class );
		$this->expectExceptionMessage( 'RAN Booster Logging API 1 conflicts with an existing API version marker.' );

		require dirname( __DIR__, 2 ) . '/ran-booster.php';
	}

	public function testProviderRegistryExposesTheInjectedLoggingFacade(): void {
		$logging = new CoreLoggingFacade();

		self::assertSame( $logging, ( new ProviderRegistry( $logging ) )->logging() );
	}

	public function testProviderRegistryRequiresLoggingFacade(): void {
		$this->expectException( ArgumentCountError::class );

		new ProviderRegistry();
	}
}
