<?php

declare(strict_types=1);

namespace Tests\AddOn;

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- The facade fake stays beside its contract fixture.

require_once __DIR__ . '/../Support/ExternalFixtureAddOnWordPressFunctions.php';

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Tests\Support\NullLoggingFacade;
use RAN\AddOn\WebhookAssistance\AssistanceReadiness;
use RAN\AddOn\WebhookAssistance\AssistanceTarget;
use RAN\AddOn\WebhookAssistance\ProvisioningResult;
use RAN\AddOn\WebhookAssistance\WebhookAssistanceFacade;
use RAN\AddOn\WebhookAssistance\WebhookProfileMetadata;

final class ExternalFixtureAddOnPluginTest extends TestCase {

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function testPluginLoadedBeforeCoreComposesTheReservedGitHubAction(): void {
		$this->loadFixturePlugin();
		self::assertFalse( defined( 'RAN_BOOSTER_ADDON_API_VERSION' ) );
		define( 'RAN_BOOSTER_ADDON_API_VERSION', 12 );
		define( 'RAN_BOOSTER_LOGGING_API_VERSION', 1 );

		$facade = new FixtureAddOnFacade( array( $this->target() ) );
		$rows   = $this->compose( $facade );

		self::assertFalse( $rows['101']['actions']['core:assisted-hooks']['disabled'] );
		self::assertStringContainsString( 'tab=gh&assisted_repository=101', $rows['101']['actions']['core:assisted-hooks']['url'] );
		self::assertSame( 1, $facade->readinessReads );
		self::assertSame( 0, $facade->otherCalls );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function testPluginLoadedAfterCoreUsesTheSamePublishedHooks(): void {
		define( 'RAN_BOOSTER_ADDON_API_VERSION', 12 );
		define( 'RAN_BOOSTER_LOGGING_API_VERSION', 1 );
		$this->loadFixturePlugin();

		$facade = new FixtureAddOnFacade();
		$rows   = $this->compose( $facade );

		self::assertTrue( $rows['101']['actions']['core:assisted-hooks']['disabled'] );
		self::assertSame( '', $rows['101']['actions']['core:assisted-hooks']['url'] );
		self::assertSame( 1, $facade->readinessReads );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function testPluginIsHarmlessWhenBoosterIsAbsentOrLoggingIsUnavailable(): void {
		$this->loadFixturePlugin();
		$this->runHook( 'plugins_loaded' );
		self::assertArrayNotHasKey( 'ran_booster_webhook_assistance_ready', $GLOBALS['ran_booster_external_fixture_addon_actions'] );

		$GLOBALS['ran_booster_external_fixture_addon_actions'] = array();
		define( 'RAN_BOOSTER_ADDON_API_VERSION', 12 );
		$this->loadFixturePlugin();
		$this->runHook( 'plugins_loaded' );
		self::assertArrayNotHasKey( 'ran_booster_webhook_assistance_ready', $GLOBALS['ran_booster_external_fixture_addon_actions'] );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function testUnavailableFacadeLeavesTheCoreActionDisabled(): void {
		define( 'RAN_BOOSTER_ADDON_API_VERSION', 12 );
		define( 'RAN_BOOSTER_LOGGING_API_VERSION', 1 );
		$this->loadFixturePlugin();

		$rows = $this->compose( new FixtureAddOnFacade( failure: true ) );

		self::assertTrue( $rows['101']['actions']['core:assisted-hooks']['disabled'] );
	}

	private function loadFixturePlugin(): void {
		$GLOBALS['ran_booster_external_fixture_addon_actions'] = array();
		require dirname( __DIR__ ) . '/fixtures/ran-booster-fixture-addon/ran-booster-fixture-addon.php';
	}

	/** @return array<string, array<string, mixed>> */
	private function compose( FixtureAddOnFacade $facade ): array {
		$this->runHook( 'plugins_loaded' );
		$this->runHook( 'ran_booster_webhook_assistance_ready', $facade, new NullLoggingFacade() );

		return $this->runFilter(
			'ran_booster_admin_provider_repository_rows',
			array(
				'101' => array(
					'provider_code' => 'gh',
					'repository_id' => '101',
					'actions'       => array(
						'core:assisted-hooks' => array(
							'label'    => 'Assisted Hooks',
							'type'     => 'link',
							'url'      => '',
							'disabled' => true,
						),
					),
				),
			),
			'gh',
			array(),
			'https://example.test/wp-admin/admin.php?page=ran-booster&tab=gh'
		);
	}

	private function runHook( string $hook, mixed ...$arguments ): void {
		$callbacks = $GLOBALS['ran_booster_external_fixture_addon_actions'][ $hook ] ?? array();
		self::assertCount( 1, $callbacks, sprintf( 'The %s callback must be registered once.', $hook ) );
		$callbacks[0]( ...$arguments );
	}

	private function runFilter( string $hook, mixed $value, mixed ...$arguments ): mixed {
		$callbacks = $GLOBALS['ran_booster_external_fixture_addon_actions'][ $hook ] ?? array();
		self::assertCount( 1, $callbacks, sprintf( 'The %s callback must be registered once.', $hook ) );

		foreach ( $callbacks as $callback ) {
			$value = $callback( $value, ...$arguments );
		}

		return $value;
	}

	private function target(): AssistanceTarget {
		return new AssistanceTarget(
			'gh',
			'101',
			'owner/example',
			'owner/example',
			array( 'example/example.php' ),
			array(
				'automatic' => 0,
				'manual'    => 1,
				'disabled'  => 0,
			),
			'https://example.test/wp-json/ran-booster/v1/webhooks/gh'
		);
	}
}

final class FixtureAddOnFacade implements WebhookAssistanceFacade {

	public int $readinessReads = 0;
	public int $otherCalls     = 0;

	/** @param list<AssistanceTarget> $targets */
	public function __construct( private array $targets = array(), private bool $failure = false ) {
	}

	public function readiness( string $providerCode ): AssistanceReadiness {
		++$this->readinessReads;
		if ( $this->failure ) {
			throw new \RuntimeException( 'Fixture facade unavailable.' );
		}

		$repositories = array_map(
			static function ( AssistanceTarget $target ): array {
				$projection = $target->toArray();
				unset( $projection['endpoint'] );

				return $projection + array(
					'status'                => 'ready',
					'reason_codes'          => array(),
					'local_secret_coverage' => 'none',
				);
			},
			array_values(
				array_filter(
					$this->targets,
					static fn ( AssistanceTarget $target ): bool => $providerCode === $target->providerCode()
				)
			)
		);

		return new AssistanceReadiness(
			array(),
			'https://example.test/wp-json/ran-booster/v1/webhooks/' . rawurlencode( $providerCode ),
			$repositories
		);
	}

	public function target( string $providerCode, string $repositoryId ): ?AssistanceTarget {
		++$this->otherCalls;

		return null;
	}

	public function credentialChoices( string $providerCode ): array {
		++$this->otherCalls;

		return array();
	}

	public function withCredential( string $providerCode, string $credentialId, callable $operation ): mixed {
		++$this->otherCalls;

		return null;
	}

	public function provision( AssistanceTarget $target, callable $createRemoteHook ): ProvisioningResult {
		++$this->otherCalls;

		return ProvisioningResult::failed( 'not_called' );
	}

	public function profile( string $providerCode, string $repositoryId, string $profileId ): ?WebhookProfileMetadata {
		++$this->otherCalls;

		return null;
	}

	public function reconfigure( AssistanceTarget $target, string $recordedProfileId, callable $updateRemoteHook ): ProvisioningResult {
		++$this->otherCalls;

		return ProvisioningResult::failed( 'not_called' );
	}

	public function releaseProfile( string $providerCode, string $repositoryId, string $profileId ): bool {
		++$this->otherCalls;

		return false;
	}
}
