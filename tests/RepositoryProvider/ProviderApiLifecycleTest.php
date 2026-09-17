<?php

declare(strict_types=1);

namespace Tests\RepositoryProvider;

use LogicException;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use RAN\RepositoryProvider\ProviderRegistrationContext;
use RAN\RepositoryProvider\ProviderRegistry;
use ReflectionClass;
use ReflectionNamedType;

final class ProviderApiLifecycleTest extends TestCase {

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function testConflictingProviderApiMarkerFailsClearly(): void {
		define( 'WPINC', 'wpinc' );
		define( 'RAN_BOOSTER_PROVIDER_API_VERSION', 1 );

		$this->expectException( LogicException::class );
		$this->expectExceptionMessage( 'RAN Booster Provider API 10 conflicts with an existing API version marker.' );

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
		$registry   = new ReflectionClass( ProviderRegistry::class );
		$parameters = $registry->getConstructor()?->getParameters() ?? array();

		self::assertFalse( $registry->hasMethod( 'logging' ) );
		self::assertCount( 5, $parameters );
		self::assertSame( 'registrationContext', $parameters[4]->getName() );
		self::assertTrue( $parameters[4]->isOptional() );
		self::assertTrue( $parameters[4]->allowsNull() );
		self::assertInstanceOf( ReflectionNamedType::class, $parameters[4]->getType() );
		self::assertSame( ProviderRegistrationContext::class, $parameters[4]->getType()?->getName() );
	}

	public function testProviderRegistryRequiresNoLoggingFacade(): void {
		self::assertInstanceOf( ProviderRegistry::class, new ProviderRegistry() );
	}

	public function testManagedReleaseTargetsAndBundledControlsFollowProviderSealing(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Static local bootstrap contract.
		$bootstrap = file_get_contents( dirname( __DIR__, 2 ) . '/ran-booster.php' );

		self::assertIsString( $bootstrap );
		$providerRegistration = strpos( $bootstrap, "do_action( 'ran_booster_register_providers'" );
		$providerSeal         = strpos( $bootstrap, '$providerRegistry->seal()' );
		$targetRegistration   = strpos( $bootstrap, 'ManagedReleaseTargetRegistrar::class )->register()' );
		$releaseControls      = strpos( $bootstrap, 'make( ReleaseManagementControls::class )->register()' );
		$workflowControls     = strpos( $bootstrap, 'make( ReleaseWorkflowControls::class )->register()' );

		self::assertIsInt( $providerRegistration );
		self::assertIsInt( $providerSeal );
		self::assertIsInt( $targetRegistration );
		self::assertTrue( $providerRegistration < $providerSeal );
		self::assertTrue( $providerSeal < $targetRegistration );
		self::assertIsInt( $releaseControls );
		self::assertIsInt( $workflowControls );
		self::assertTrue( $targetRegistration < $releaseControls );
		self::assertTrue( $targetRegistration < $workflowControls );
		self::assertTrue( $workflowControls < $releaseControls );
		self::assertSame( 1, substr_count( $bootstrap, 'ManagedReleaseTargetRegistrar::class )->register()' ) );
		self::assertSame( 1, substr_count( $bootstrap, 'make( ReleaseManagementControls::class )->register()' ) );
		self::assertSame( 1, substr_count( $bootstrap, 'make( ReleaseWorkflowControls::class )->register()' ) );
		self::assertStringNotContainsString( 'RAN_BOOSTER_PROSPECTIVE_RELEASE_API_VERSION', $bootstrap );
		self::assertStringNotContainsString( 'ran_booster_release_tracking_ready', $bootstrap );
		self::assertStringNotContainsString( 'ran_booster_prospective_release_ready', $bootstrap );
	}
}
