<?php

declare(strict_types=1);

namespace Tests\RepositoryProvider;

use LogicException;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use RAN\RepositoryProvider\ProviderRegistry;
use ReflectionClass;

final class ProviderApiLifecycleTest extends TestCase {

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function testConflictingProviderApiMarkerFailsClearly(): void {
		define( 'WPINC', 'wpinc' );
		define( 'RAN_BOOSTER_PROVIDER_API_VERSION', 1 );

		$this->expectException( LogicException::class );
		$this->expectExceptionMessage( 'RAN Booster Provider API 8 conflicts with an existing API version marker.' );

		require dirname( __DIR__, 2 ) . '/ran-booster.php';
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function testCorePublishesNoLoggingApiMarker(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Static local bootstrap contract.
		$bootstrap = file_get_contents( dirname( __DIR__, 2 ) . '/ran-booster.php' );

		self::assertIsString( $bootstrap );
		self::assertStringNotContainsString( 'RAN_BOOSTER_LOGGING_API_VERSION', $bootstrap );
	}

	public function testProviderRegistryExposesNoLoggingFacade(): void {
		$registry = new ReflectionClass( ProviderRegistry::class );

		self::assertFalse( $registry->hasMethod( 'logging' ) );
		self::assertCount( 3, $registry->getConstructor()?->getParameters() ?? array() );
	}

	public function testProviderRegistryRequiresNoLoggingFacade(): void {
		self::assertInstanceOf( ProviderRegistry::class, new ProviderRegistry() );
	}
}
