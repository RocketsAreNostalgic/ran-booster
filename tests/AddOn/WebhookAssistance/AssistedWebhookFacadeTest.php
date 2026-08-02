<?php

declare(strict_types=1);

namespace Tests\AddOn\WebhookAssistance;

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- Focused fixtures stay beside the facade contract tests.

require_once __DIR__ . '/WebhookAssistanceWordPressFunctions.php';

use PHPUnit\Framework\TestCase;
use RAN\AbstractPackage;
use RAN\AddOn\WebhookAssistance\AssistanceTarget;
use RAN\AddOn\WebhookAssistance\AssistedWebhookFacade;
use RAN\AddOn\WebhookAssistance\WebhookAssistanceReadinessEvaluator;
use RAN\Deployment\DeploymentPolicy;
use RAN\ManagedRepository;
use RAN\RepositoryProvider\ArchiveRequest;
use RAN\RepositoryProvider\PreparedArchive;
use RAN\RepositoryProvider\ProviderCode;
use RAN\RepositoryProvider\ProviderDiagnosticRequest;
use RAN\RepositoryProvider\ProviderDiagnostics;
use RAN\RepositoryProvider\ProviderMetadata;
use RAN\RepositoryProvider\ProviderRegistry;
use RAN\RepositoryProvider\RepositoryDescriptor;
use RAN\RepositoryProvider\RepositoryLookupRequest;
use RAN\RepositoryProvider\RepositoryProvider;
use RAN\RepositoryProvider\RepositoryWebhookFitness;
use RAN\RepositoryProvider\RepositoryWebhookFitnessResult;
use RAN\RepositoryProvider\RepositoryWebhookManagement;
use RAN\RepositoryProvider\RepositoryWebhookOperationResult;
use RAN\Secrets\SecretsFile;
use RAN\Storage\Database;
use RAN\Storage\PluginRepository;
use RAN\Storage\ThemeRepository;

final class AssistedWebhookFacadeTest extends TestCase {

	public function testBootstrapPublishesTheExactCutAndRemovesCleanup(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local source contract inspection.
		$bootstrap = file_get_contents( dirname( __DIR__, 3 ) . '/ran-booster.php' );
		self::assertIsString( $bootstrap );
		self::assertStringContainsString( "RAN_BOOSTER_PROVIDER_API_VERSION', 8", $bootstrap );
		self::assertStringContainsString( "RAN_BOOSTER_ADDON_API_VERSION', 14", $bootstrap );
		self::assertStringNotContainsString( 'RAN_BOOSTER_WEBHOOK_CLEANUP_API_VERSION', $bootstrap );
		self::assertStringNotContainsString( 'ran_booster_webhook_cleanup_ready', $bootstrap );
		self::assertFalse( interface_exists( 'RAN\\AddOn\\WebhookAssistance\\WebhookCleanupFacade' ) );
		self::assertFalse( class_exists( 'RAN\\AddOn\\WebhookAssistance\\ProvisioningCallbackResult' ) );
	}

	public function testSetupKeepsBothSecretsInsideCoreAndProvider(): void {
		$secrets  = new FixedFacadeSecretsFile();
		$provider = new FixedWebhookProvider();
		$facade   = $this->facade( $secrets, $provider );
		$target   = $facade->target( 'gh', '101' );
		self::assertInstanceOf( AssistanceTarget::class, $target );

		$result = $facade->setup( $target, 'profile_1', 'good' );

		self::assertTrue( $result->succeeded() );
		self::assertSame( '55', $result->hookId() );
		self::assertNotNull( $result->profile() );
		self::assertSame( $secrets->savedSecret, $provider->signingSecret );
		self::assertSame( 'profile_1', $provider->credentialId );
		self::assertNull( $provider->requestCredential );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Test-only secret-containment assertion.
		self::assertStringNotContainsString( $secrets->savedSecret, json_encode( $result->toArray(), JSON_THROW_ON_ERROR ) );
	}

	public function testRequestCredentialIsOneExplicitInput(): void {
		$secrets  = new FixedFacadeSecretsFile();
		$provider = new FixedWebhookProvider();
		$facade   = $this->facade( $secrets, $provider );
		$target   = $facade->target( 'gh', '101' );
		self::assertNotNull( $target );

		$result = $facade->setup( $target, null, 'good', 'request-only-canary' );

		self::assertTrue( $result->succeeded() );
		self::assertNull( $provider->credentialId );
		self::assertSame( 'request-only-canary', $provider->requestCredential );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Test-only secret-containment assertion.
		self::assertStringNotContainsString( 'request-only-canary', json_encode( $result->toArray(), JSON_THROW_ON_ERROR ) );
		self::assertSame( 'operation_unauthorized', $facade->setup( $target, 'profile_1', 'good', 'also-present' )->code() );
	}

	public function testStaleTargetNonceAndProfileFailBeforeProviderWork(): void {
		$secrets  = new FixedFacadeSecretsFile();
		$provider = new FixedWebhookProvider();
		$facade   = $this->facade( $secrets, $provider );
		$target   = $facade->target( 'gh', '101' );
		self::assertNotNull( $target );
		$setup = $facade->setup( $target, 'profile_1', 'bad' );
		self::assertSame( 'operation_unauthorized', $setup->code() );
		self::assertSame( 0, $provider->calls );

		$profileId                       = 'wh_' . str_repeat( 'a', 24 );
		$secrets->profiles[ $profileId ] = $secrets->profile( $profileId, 2 );
		$checked                         = $facade->check( $target, 'profile_1', '55', $profileId, 1, 'good' );
		self::assertSame( 'operation_unauthorized', $checked->code() );
		self::assertSame( 0, $provider->calls );
	}

	public function testRemoveReleasesOnlyAfterAuthoritativeAbsence(): void {
		$secrets  = new FixedFacadeSecretsFile();
		$provider = new FixedWebhookProvider();
		$facade   = $this->facade( $secrets, $provider );
		$target   = $facade->target( 'gh', '101' );
		self::assertNotNull( $target );
		$profileId                       = 'wh_' . str_repeat( 'a', 24 );
		$secrets->profiles[ $profileId ] = $secrets->profile( $profileId, 1 );

		$provider->removeState = 'ambiguous';
		self::assertSame( 'ambiguous', $facade->remove( $target, 'profile_1', '55', $profileId, 1, 'good' )->state() );
		self::assertArrayHasKey( $profileId, $secrets->profiles );

		$provider->removeState = 'succeeded';
		self::assertTrue( $facade->remove( $target, 'profile_1', '55', $profileId, 1, 'good' )->confirmsAbsence() );
		self::assertArrayNotHasKey( $profileId, $secrets->profiles );
	}

	public function testFitnessIsExplicitAndBoundToTheSavedProfile(): void {
		$secrets  = new FixedFacadeSecretsFile();
		$provider = new FixedWebhookProvider();
		$facade   = $this->facade( $secrets, $provider );
		$target   = $facade->target( 'gh', '101' );
		self::assertNotNull( $target );
		$result = $facade->assessSetup( $target, 'profile_1', 'good' )->toArray();
		self::assertSame( 'supported', $result['support'] );
		self::assertSame( 1, $provider->calls );
		self::assertSame( 'assessment_unauthorized', $facade->assessSetup( $target, 'missing', 'good' )->toArray()['code'] );
		self::assertSame( 1, $provider->calls );
	}

	private function facade( FixedFacadeSecretsFile $secrets, FixedWebhookProvider $provider ): AssistedWebhookFacade {
		$registry = new ProviderRegistry( array( $provider ) );
		$package  = new FixedFacadePackage( new ManagedRepository( 'gh', 'owner/example', '101', 'main' ) );

		return new AssistedWebhookFacade(
			new WebhookAssistanceReadinessEvaluator( new FixedPluginRepository( $package ), new FixedThemeRepository(), $secrets, new FixedDatabase(), static fn (): bool => true ),
			$secrets,
			$registry,
			static fn (): bool => true,
			static fn (): string => 'https://site.example/wp-json/ran-booster/v1/webhooks/gh',
			static fn ( string $nonce ): bool => 'good' === $nonce
		);
	}
}

final class FixedWebhookProvider implements RepositoryProvider, RepositoryWebhookFitness, RepositoryWebhookManagement {
	public const OPERATION            = 'repository-webhook-management';
	public const VERSION              = 1;
	public int $calls                 = 0;
	public ?string $credentialId      = null;
	public ?string $requestCredential = null;
	public string $signingSecret      = '';
	public string $removeState        = 'succeeded';

	public function getMetadata(): ProviderMetadata {
		return new ProviderMetadata( ProviderCode::parse( 'gh' ), 'GitHub fixture', 'https://example.test/', 'Owner' );
	}

	public function getProviderDiagnostics(): ProviderDiagnostics {
		return new class() implements ProviderDiagnostics {
			public function diagnose( ProviderDiagnosticRequest $request ): array {
				return array();
			}
		};
	}

	public function resolveRepository( RepositoryLookupRequest $request ): RepositoryDescriptor {
		throw new \RuntimeException( 'not used' );
	}

	public function prepareArchive( ArchiveRequest $request ): PreparedArchive {
		throw new \RuntimeException( 'not used' );
	}

	public function assessSetup( string $repositoryId, string $repository, string $credentialProfileId ): RepositoryWebhookFitnessResult {
		return $this->fitness( $credentialProfileId );
	}

	public function assessCheck( string $repositoryId, string $repository, string $credentialProfileId, string $hookId ): RepositoryWebhookFitnessResult {
		return $this->fitness( $credentialProfileId );
	}

	public function assessReconfigure( string $repositoryId, string $repository, string $credentialProfileId, string $hookId ): RepositoryWebhookFitnessResult {
		return $this->fitness( $credentialProfileId );
	}

	public function assessRemove( string $repositoryId, string $repository, string $credentialProfileId, string $hookId ): RepositoryWebhookFitnessResult {
		return $this->fitness( $credentialProfileId );
	}

	public function setup( string $repositoryId, string $repository, string $callbackUrl, ?string $credentialProfileId, ?string $requestCredential, string $signingSecret ): RepositoryWebhookOperationResult {
		++$this->calls;
		$this->credentialId      = $credentialProfileId;
		$this->requestCredential = $requestCredential;
		$this->signingSecret     = $signingSecret;

		return $this->operation( 'succeeded', 'configured_pending_delivery' );
	}

	public function check( string $repositoryId, string $repository, string $hookId, string $callbackUrl, ?string $credentialProfileId, ?string $requestCredential ): RepositoryWebhookOperationResult {
		++$this->calls;

		return $this->operation( 'succeeded', 'configuration_confirmed' );
	}

	public function reconfigure( string $repositoryId, string $repository, string $hookId, string $callbackUrl, ?string $credentialProfileId, ?string $requestCredential, string $signingSecret ): RepositoryWebhookOperationResult {
		++$this->calls;

		return $this->operation( 'succeeded', 'configured_pending_delivery' );
	}

	public function remove( string $repositoryId, string $repository, string $hookId, string $callbackUrl, ?string $credentialProfileId, ?string $requestCredential ): RepositoryWebhookOperationResult {
		++$this->calls;

		return $this->operation( $this->removeState, 'succeeded' === $this->removeState ? 'absence_confirmed' : 'remove_ambiguous', 'succeeded' === $this->removeState ? 'absent' : 'unknown' );
	}

	private function fitness( string $credentialId ): RepositoryWebhookFitnessResult {
		++$this->calls;
		$this->credentialId = $credentialId;

		return new RepositoryWebhookFitnessResult( 'supported', 'unknown', 'unknown', 'unknown_by_design', 'authority_unknown', '2026-08-02T00:00:00Z', 'Confirm the exact operation before continuing.' );
	}

	private function operation( string $state, string $code, string $delivery = 'configured_pending_delivery' ): RepositoryWebhookOperationResult {
		return new RepositoryWebhookOperationResult(
			$state,
			$code,
			'2026-08-02T00:00:00Z',
			'55',
			array(
				'endpoint'     => 'matched',
				'events'       => 'matched',
				'content_type' => 'matched',
				'active'       => 'matched',
			),
			$delivery,
			'Review the bounded result.'
		);
	}
}

final class FixedFacadeSecretsFile extends SecretsFile {
	/** @var array<string,array<string,mixed>> */
	public array $profiles     = array();
	public string $savedSecret = '';

	public function assertManagedStorageReady(): void {
	}

	public function credentialProfiles( ProviderCode|string $provider ): array {
		return array(
			'profile_1' => array(
				'id'         => 'profile_1',
				'source'     => 'file',
				'immutable'  => false,
				'label'      => 'Fixture',
				'kind'       => 'fine-grained',
				'destroy_on' => null,
			),
		);
	}

	public function webhookProfiles( ProviderCode|string $provider ): array {
		return $this->profiles;
	}

	public function webhookMaterials( ProviderCode|string $provider ): array {
		return $this->profiles;
	}

	public function saveWebhook( ProviderCode|string $provider, ?string $id, array $metadata, ?string $secret ): string {
		$id                  ??= 'wh_' . str_repeat( 'a', 24 );
		$this->savedSecret     = (string) $secret;
		$this->profiles[ $id ] = $metadata + array(
			'id'         => $id,
			'revision'   => 1,
			'source'     => 'file',
			'immutable'  => false,
			'configured' => true,
			'secret'     => $secret,
		);

		return $id;
	}

	public function deleteWebhook( ProviderCode|string $provider, string $id ): bool {
		unset( $this->profiles[ $id ] );

		return true;
	}

	/** @return array<string,mixed> */
	public function profile( string $id, int $revision ): array {
		return array(
			'id'           => $id,
			'scope'        => 'repository',
			'target'       => 'owner/example',
			'authority_id' => '101',
			'revision'     => $revision,
			'origin'       => 'assisted',
			'source'       => 'file',
			'immutable'    => false,
			'configured'   => true,
			'secret'       => str_repeat( 's', 32 ),
		);
	}
}

final class FixedPluginRepository extends PluginRepository {
	public function __construct( private FixedFacadePackage $package ) {
	}

	public function allDeploymentPlugins( ?\RAN\PackageSource $source = null ): array {
		return array( $this->package );
	}
}

final class FixedThemeRepository extends ThemeRepository {
	public function allDeploymentThemes( ?\RAN\PackageSource $source = null ): array {
		return array();
	}
}

final class FixedFacadePackage extends AbstractPackage {
	public function __construct( ManagedRepository $repository ) {
		$this->repository       = $repository;
		$this->deploymentPolicy = DeploymentPolicy::MANUAL;
	}

	public function getIdentifier(): mixed {
		return 'plugin/example.php';
	}

	public function getProviderRepositoryId(): ?string {
		return '101';
	}

	protected function runtimeSlug(): string {
		return 'example';
	}
}

final class FixedDatabase extends Database {
	public function requireReady(): void {
	}
}
