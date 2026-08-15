<?php

declare(strict_types=1);

namespace Tests\AddOn;

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- The facade fake stays beside its contract fixture.

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use RAN\AddOn\WebhookAssistance\AssistanceReadiness;
use RAN\AddOn\WebhookAssistance\AssistanceTarget;
use RAN\AddOn\WebhookAssistance\WebhookAssistanceFacade;
use RAN\AddOn\WebhookAssistance\WebhookProfileMetadata;
use RAN\RepositoryProvider\RepositoryWebhookFitnessResult;
use RAN\RepositoryProvider\RepositoryWebhookOperationResult;

final class ExternalFixtureAddOnPluginTest extends TestCase {

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function testPluginLoadedBeforeCoreComposesTheReservedProviderAction(): void {
		$this->loadFixturePlugin();
		self::assertFalse( defined( 'RAN_BOOSTER_ADDON_API_VERSION' ) );
		define( 'RAN_BOOSTER_ADDON_API_VERSION', 15 );

		$facade = new FixtureAddOnFacade( array( $this->target() ) );
		$rows   = $this->compose( $facade );

		self::assertFalse( $rows['101']['actions']['core:webhook-management']['disabled'] );
		self::assertStringContainsString( 'tab=fixture-provider&repository=101', $rows['101']['actions']['core:webhook-management']['url'] );
		self::assertSame( 1, $facade->readinessReads );
		self::assertSame( 0, $facade->otherCalls );
		self::assertTrue( $this->runFilter( 'ran_booster_admin_provider_repository_assistance_active', false, 'fixture-provider' ) );
		self::assertFalse( $this->runFilter( 'ran_booster_admin_provider_repository_assistance_active', false, 'gh' ) );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function testPluginLoadedAfterCoreUsesTheSamePublishedHooks(): void {
		define( 'RAN_BOOSTER_ADDON_API_VERSION', 15 );
		$this->loadFixturePlugin();

		$facade = new FixtureAddOnFacade();
		$rows   = $this->compose( $facade );

		self::assertTrue( $rows['101']['actions']['core:webhook-management']['disabled'] );
		self::assertSame( '', $rows['101']['actions']['core:webhook-management']['url'] );
		self::assertSame( 1, $facade->readinessReads );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function testPluginIsHarmlessWhenBoosterIsAbsentOrItsApiVersionIsUnavailable(): void {
		$this->loadFixturePlugin();
		$this->runHook( 'plugins_loaded' );
		self::assertArrayNotHasKey( 'ran_booster_webhook_assistance_ready', $GLOBALS['ran_booster_external_fixture_addon_actions'] );

		$GLOBALS['ran_booster_external_fixture_addon_actions'] = array();
		define( 'RAN_BOOSTER_ADDON_API_VERSION', 14 );
		$this->loadFixturePlugin();
		$this->runHook( 'plugins_loaded' );
		self::assertArrayNotHasKey( 'ran_booster_webhook_assistance_ready', $GLOBALS['ran_booster_external_fixture_addon_actions'] );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function testUnavailableFacadeLeavesTheCoreActionDisabled(): void {
		define( 'RAN_BOOSTER_ADDON_API_VERSION', 15 );
		$this->loadFixturePlugin();

		$rows = $this->compose( new FixtureAddOnFacade( failure: true ) );

		self::assertTrue( $rows['101']['actions']['core:webhook-management']['disabled'] );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function testProviderOwnedAdapterRendersItsFixedPanel(): void {
		require_once __DIR__ . '/../Support/ExternalFixtureWebhookManagementWordPressFunctions.php';
		define( 'RAN_BOOSTER_ADDON_API_VERSION', 15 );
		$this->loadFixturePlugin();
		$facade = new FixtureAddOnFacade( array( $this->target() ) );
		$this->runHook( 'plugins_loaded' );
		$this->runHook( 'ran_booster_webhook_assistance_ready', $facade );

		ob_start();
		$this->runHook(
			'ran_booster_admin_provider_repository_panel',
			'fixture-provider',
			'101',
			'https://example.test/wp-admin/admin.php?page=ran-booster&tab=fixture-provider'
		);
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'fixture_webhook_management_setup', $html );
		self::assertStringContainsString( 'name="fixture_api_key"', $html );
		self::assertStringNotContainsString( 'github_pat', $html );
		self::assertSame( 1, $facade->otherCalls );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function testProviderOwnedAdminPostUsesOneRequestOnlyCredential(): void {
		require_once __DIR__ . '/../Support/ExternalFixtureWebhookManagementWordPressFunctions.php';
		define( 'RAN_BOOSTER_ADDON_API_VERSION', 15 );
		$this->loadFixturePlugin();
		$facade = new FixtureAddOnFacade( array( $this->target() ) );
		$this->runHook( 'plugins_loaded' );
		$this->runHook( 'ran_booster_webhook_assistance_ready', $facade );
		$nonce = wp_create_nonce( 'fixture_webhook_management_setup_101' );
		$_POST = array(
			'provider_code'   => 'fixture-provider',
			'repository_id'   => '101',
			'_wpnonce'        => $nonce,
			'fixture_api_key' => 'fixture_request_only',
		);

		$GLOBALS['ran_booster_external_fixture_addon_admin'] = false;
		$this->runHook( 'admin_post_fixture_webhook_management_setup' );
		self::assertSame( array(), $facade->setupCalls );

		$GLOBALS['ran_booster_external_fixture_addon_admin'] = true;
		$this->runHook( 'admin_post_fixture_webhook_management_setup' );

		self::assertSame( array( array( null, true, $nonce ) ), $facade->setupCalls );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- The fixture handler verified the nonce before this test-only assertion.
		self::assertArrayNotHasKey( 'fixture_api_key', $_POST );
	}

	private function loadFixturePlugin(): void {
		require_once __DIR__ . '/../Support/ExternalFixtureAddOnWordPressFunctions.php';
		$GLOBALS['ran_booster_external_fixture_addon_actions'] = array();
		require dirname( __DIR__ ) . '/fixtures/ran-booster-fixture-addon/ran-booster-fixture-addon.php';
	}

	/** @return array<string, array<string, mixed>> */
	private function compose( FixtureAddOnFacade $facade ): array {
		$this->runHook( 'plugins_loaded' );
		$this->runHook( 'ran_booster_webhook_assistance_ready', $facade );

		return $this->runFilter(
			'ran_booster_admin_provider_repository_rows',
			array(
				'101' => array(
					'provider_code' => 'fixture-provider',
					'repository_id' => '101',
					'actions'       => array(
						'core:webhook-management' => array(
							'label'    => 'Assisted Hooks',
							'type'     => 'link',
							'url'      => '',
							'disabled' => true,
						),
					),
				),
			),
			'fixture-provider',
			array(),
			'https://example.test/wp-admin/admin.php?page=ran-booster&tab=fixture-provider'
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
			'fixture-provider',
			'101',
			'owner/example',
			'owner/example',
			array( 'example/example.php' ),
			array(
				'automatic' => 0,
				'manual'    => 1,
				'disabled'  => 0,
			),
			'https://example.test/wp-json/ran-booster/v1/webhooks/fixture-provider'
		);
	}
}

final class FixtureAddOnFacade implements WebhookAssistanceFacade {

	public int $readinessReads = 0;
	public int $otherCalls     = 0;
	/** @var list<array{?string, bool, string}> */
	public array $setupCalls = array();

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

		foreach ( $this->targets as $target ) {
			if ( $providerCode === $target->providerCode() && $repositoryId === $target->repositoryId() ) {
				return $target;
			}
		}

		return null;
	}

	public function credentialChoices( string $providerCode ): array {
		++$this->otherCalls;

		return array();
	}

	public function profile( string $providerCode, string $repositoryId, string $profileId ): ?WebhookProfileMetadata {
		++$this->otherCalls;

		return null;
	}

	public function assessSetup( AssistanceTarget $target, ?string $credentialProfileId, string $nonce, ?string $requestCredential = null ): RepositoryWebhookFitnessResult {
		++$this->otherCalls;

		return $this->fitness();
	}

	public function assessCheck( AssistanceTarget $target, ?string $credentialProfileId, string $hookId, string $profileId, int $profileRevision, string $nonce, ?string $requestCredential = null ): RepositoryWebhookFitnessResult {
		++$this->otherCalls;

		return $this->fitness();
	}

	public function assessReconfigure( AssistanceTarget $target, ?string $credentialProfileId, string $hookId, string $profileId, int $profileRevision, string $nonce, ?string $requestCredential = null ): RepositoryWebhookFitnessResult {
		++$this->otherCalls;

		return $this->fitness();
	}

	public function assessRemove( AssistanceTarget $target, ?string $credentialProfileId, string $hookId, string $profileId, int $profileRevision, string $nonce, ?string $requestCredential = null ): RepositoryWebhookFitnessResult {
		++$this->otherCalls;

		return $this->fitness();
	}

	public function setup( AssistanceTarget $target, ?string $credentialProfileId, string $nonce, ?string $requestCredential = null ): RepositoryWebhookOperationResult {
		++$this->otherCalls;
		$this->setupCalls[] = array( $credentialProfileId, null !== $requestCredential, $nonce );

		return $this->operation();
	}

	public function check( AssistanceTarget $target, ?string $credentialProfileId, string $hookId, string $profileId, int $profileRevision, string $nonce, ?string $requestCredential = null ): RepositoryWebhookOperationResult {
		++$this->otherCalls;

		return $this->operation();
	}

	public function reconfigure( AssistanceTarget $target, ?string $credentialProfileId, string $hookId, string $profileId, int $profileRevision, string $nonce, ?string $requestCredential = null ): RepositoryWebhookOperationResult {
		++$this->otherCalls;

		return $this->operation();
	}

	public function remove( AssistanceTarget $target, ?string $credentialProfileId, string $hookId, string $profileId, int $profileRevision, string $nonce, ?string $requestCredential = null ): RepositoryWebhookOperationResult {
		++$this->otherCalls;

		return $this->operation();
	}

	private function fitness(): RepositoryWebhookFitnessResult {
		return new RepositoryWebhookFitnessResult( 'unknown', 'unknown', 'unknown', 'assessment_unavailable', 'not_called', '2026-08-02T00:00:00Z', 'Not called by this fixture.' );
	}

	private function operation(): RepositoryWebhookOperationResult {
		return new RepositoryWebhookOperationResult(
			'failed',
			'not_called',
			'2026-08-02T00:00:00Z',
			null,
			array(
				'endpoint'     => 'unknown',
				'events'       => 'unknown',
				'content_type' => 'unknown',
				'active'       => 'unknown',
			),
			'unknown',
			'Not called by this fixture.'
		);
	}
}
