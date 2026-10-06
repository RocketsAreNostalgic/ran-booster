<?php

declare(strict_types=1);

namespace RAN\Tests\RepositoryProvider;

use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
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
	#[DataProvider( 'registration_load_orders' )]
	public function test_old_provider_is_rejected_before_its_incompatible_class_loads( bool $core_first, int $provider_api, string $provider_class ): void {
		require_once dirname( __DIR__ ) . '/Support/ExternalFixturePluginWordPressFunctions.php';
		$GLOBALS['ran_booster_external_fixture_actions'] = array();
		if ( $core_first ) {
			define( 'RAN_BOOSTER_PROVIDER_API_VERSION', 14 );
		}
		require dirname( __DIR__ ) . '/fixtures/provider-api' . $provider_api . '-registration/provider.php';
		if ( ! $core_first ) {
			define( 'RAN_BOOSTER_PROVIDER_API_VERSION', 14 );
		}
		$registry  = new ProviderRegistry();
		$callbacks = $GLOBALS['ran_booster_external_fixture_actions']['ran_booster_register_providers'];
		self::assertCount( 1, $callbacks );
		$callbacks[0]( $registry );
		self::assertFalse( class_exists( $provider_class, false ) );
		self::assertSame( array(), $registry->all() );
	}

	/** @return array<string, array{bool, int, string}> */
	public static function registration_load_orders(): array {
		return array(
			'API 11 core first'     => array( true, 11, 'RAN_Booster_ApiElevenWorkflowProvider' ),
			'API 11 provider first' => array( false, 11, 'RAN_Booster_ApiElevenWorkflowProvider' ),
			'API 12 core first'     => array( true, 12, 'RAN_Booster_ApiTwelveProvider' ),
			'API 12 provider first' => array( false, 12, 'RAN_Booster_ApiTwelveProvider' ),
		);
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_conflicting_provider_api_marker_fails_clearly(): void {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Exercise the WordPress-owned constant without changing its runtime identity.
		define( 'WPINC', 'wpinc' );
		define( 'RAN_BOOSTER_PROVIDER_API_VERSION', 1 );

		$this->expectException( LogicException::class );
		$this->expectExceptionMessage( 'RAN Booster Provider API 14 conflicts with an existing API version marker.' );

		require dirname( __DIR__, 2 ) . '/ran-booster.php';
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_core_publishes_no_logging_api_marker(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Static local bootstrap contract.
		$bootstrap = file_get_contents( dirname( __DIR__, 2 ) . '/ran-booster.php' );

		self::assertIsString( $bootstrap );
		self::assertStringNotContainsString( 'RAN_BOOSTER_LOGGING_API_VERSION', $bootstrap );
	}

	public function test_provider_registry_exposes_no_logging_facade(): void {
		$registry   = new ReflectionClass( ProviderRegistry::class );
		$parameters = $registry->getConstructor()?->getParameters() ?? array();

		self::assertFalse( $registry->hasMethod( 'logging' ) );
		self::assertCount( 5, $parameters );
		self::assertSame( 'registration_context', $parameters[4]->getName() );
		self::assertTrue( $parameters[4]->isOptional() );
		self::assertTrue( $parameters[4]->allowsNull() );
		self::assertInstanceOf( ReflectionNamedType::class, $parameters[4]->getType() );
		self::assertSame( ProviderRegistrationContext::class, $parameters[4]->getType()?->getName() );
	}

	public function test_provider_registry_requires_no_logging_facade(): void {
		self::assertInstanceOf( ProviderRegistry::class, new ProviderRegistry() );
	}

	public function test_managed_release_targets_and_bundled_controls_follow_provider_sealing(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Static local bootstrap contract.
		$bootstrap = file_get_contents( dirname( __DIR__, 2 ) . '/ran-booster.php' );

		self::assertIsString( $bootstrap );
		$provider_registration = strpos( $bootstrap, "do_action( 'ran_booster_register_providers'" );
		$provider_seal         = strpos( $bootstrap, '$provider_registry->seal()' );
		$target_registration   = strpos( $bootstrap, 'ManagedReleaseTargetRegistrar::class )->register()' );
		$release_controls      = strpos( $bootstrap, 'make( ReleaseManagementControls::class )->register()' );
		$workflow_controls     = strpos( $bootstrap, 'make( ReleaseWorkflowControls::class )->register()' );

		self::assertIsInt( $provider_registration );
		self::assertIsInt( $provider_seal );
		self::assertIsInt( $target_registration );
		self::assertTrue( $provider_registration < $provider_seal );
		self::assertTrue( $provider_seal < $target_registration );
		self::assertIsInt( $release_controls );
		self::assertIsInt( $workflow_controls );
		self::assertTrue( $target_registration < $release_controls );
		self::assertTrue( $target_registration < $workflow_controls );
		self::assertTrue( $workflow_controls < $release_controls );
		self::assertSame( 1, substr_count( $bootstrap, 'ManagedReleaseTargetRegistrar::class )->register()' ) );
		self::assertSame( 1, substr_count( $bootstrap, 'make( ReleaseManagementControls::class )->register()' ) );
		self::assertSame( 1, substr_count( $bootstrap, 'make( ReleaseWorkflowControls::class )->register()' ) );
		self::assertStringNotContainsString( 'RAN_BOOSTER_PROSPECTIVE_RELEASE_API_VERSION', $bootstrap );
		self::assertStringNotContainsString( 'ran_booster_release_tracking_ready', $bootstrap );
		self::assertStringNotContainsString( 'ran_booster_prospective_release_ready', $bootstrap );
	}
}
