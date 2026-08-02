<?php

declare(strict_types=1);

namespace Tests\AddOn\WebhookAssistance;

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- Focused service fakes remain beside their contract test.

require_once __DIR__ . '/WebhookAssistanceWordPressFunctions.php';

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RAN\AbstractPackage;
use RAN\AddOn\WebhookAssistance\AssistanceReadiness;
use RAN\AddOn\WebhookAssistance\AssistedWebhookFacade;
use RAN\AddOn\WebhookAssistance\AssistanceTarget;
use RAN\AddOn\WebhookAssistance\ProvisioningCallbackResult;
use RAN\AddOn\WebhookAssistance\WebhookAssistanceReadinessEvaluator;
use RAN\AddOn\WebhookAssistance\WebhookProfileMetadata;
use RAN\Deployment\DeploymentPolicy;
use RAN\ManagedRepository;
use RAN\PackageSource;
use RAN\Secrets\SecretsFile;
use RAN\Storage\Database;
use RAN\Storage\DatabaseCompatibilityFailure;
use RAN\Storage\PluginRepository;
use RAN\Storage\ThemeRepository;
use RuntimeException;

#[CoversClass( AssistedWebhookFacade::class )]
final class AssistedWebhookFacadeTest extends TestCase {

	public function testCorePublishesAddOnApiSevenForProviderAwareWebhookAssistance(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Static local bootstrap contract.
		$bootstrap = file_get_contents( dirname( __DIR__, 3 ) . '/ran-booster.php' );

		self::assertIsString( $bootstrap );
		self::assertStringContainsString( "RAN_BOOSTER_ADDON_API_VERSION', 12", $bootstrap );
		self::assertStringContainsString( "RAN_BOOSTER_WEBHOOK_CLEANUP_API_VERSION', 1", $bootstrap );
		self::assertStringContainsString( "do_action( 'ran_booster_webhook_cleanup_ready', \$webhookCleanup )", $bootstrap );
	}

	public function testSafeProfileMetadataRejectsInvalidPublicStates(): void {
		foreach ( array(
			array( 'invalid', 'gh', 'repository', 'owner/example', '101', 1, 'created', 'file', false ),
			array( 'wh_' . str_repeat( 'a', 24 ), 'invalid provider', 'repository', 'owner/example', '101', 1, 'created', 'file', false ),
			array( 'wh_' . str_repeat( 'a', 24 ), 'gh', 'global', 'owner/example', '101', 1, 'created', 'file', false ),
			array( 'wh_' . str_repeat( 'a', 24 ), 'gh', 'repository', 'owner/example', '101', 0, 'created', 'file', false ),
			array( 'wh_' . str_repeat( 'a', 24 ), 'gh', 'owner', 'owner', '', 1, 'created', 'file', false ),
			array( 'wh_' . str_repeat( 'a', 24 ), 'gh', 'repository', 'owner/example', '', 1, 'reused', 'file', false ),
			array( 'wh_' . str_repeat( 'a', 24 ), 'gh', 'repository', "owner/exam\nple", '101', 1, 'reused', 'file', false ),
			array( 'wh_' . str_repeat( 'a', 24 ), 'gh', 'repository', 'owner/example', '101', 1, 'reused', 'constant', true ),
		) as $arguments ) {
			try {
				new WebhookProfileMetadata( ...$arguments );
				self::fail( 'Invalid public webhook metadata must be rejected.' );
			} catch ( \InvalidArgumentException $exception ) {
				self::assertSame( 'Webhook profile metadata is invalid.', $exception->getMessage() );
			}
		}
	}

	public function testReadinessIncludesGitHubCandidatesPoliciesAndLocalSecretCoverage(): void {
		$secrets           = new FacadeSecretsFile();
		$secrets->profiles = array(
			'owner'      => array(
				'scope'        => 'owner',
				'target'       => 'owner',
				'authority_id' => '',
			),
			'repository' => array(
				'scope'        => 'repository',
				'target'       => 'owner/example',
				'authority_id' => '101',
			),
		);
		$plugins           = new FacadePluginRepository(
			$this->package( 'plugin/example.php', 'owner/example', '101', DeploymentPolicy::MANUAL ),
			$this->package( 'plugin/other.php', 'owner/example', '101', DeploymentPolicy::AUTOMATIC ),
			$this->package( 'plugin/disabled.php', 'owner/example', '101', DeploymentPolicy::DISABLED ),
			$this->package( 'plugin/ignored.php', 'owner/ignored', '102', DeploymentPolicy::MANUAL, 'bb' )
		);
		$themes            = new FacadeThemeRepository( $this->package( 'example-theme', 'owner/example', '101', DeploymentPolicy::MANUAL ) );
		$facade            = $this->facade( $plugins, $themes, $secrets );

		$readiness = $facade->readiness( 'gh' )->toArray();

		self::assertSame(
			array(
				'status'       => 'ready',
				'reason_codes' => array(),
				'callback_url' => 'https://site.example/wp-json/ran-booster/v1/webhooks/gh',
			),
			$readiness['site']
		);
		self::assertCount( 1, $readiness['repositories'] );
		self::assertSame(
			array(
				'provider_code'         => 'gh',
				'repository_id'         => '101',
				'repository'            => 'owner/example',
				'label'                 => 'owner/example',
				'package_references'    => array( 'example-theme', 'plugin/disabled.php', 'plugin/example.php', 'plugin/other.php' ),
				'deployment_policies'   => array(
					'automatic' => 1,
					'manual'    => 2,
					'disabled'  => 1,
				),
				'status'                => 'ready',
				'reason_codes'          => array(),
				'local_secret_coverage' => 'repository',
				'eligible'              => true,
			),
			$readiness['repositories'][0]
		);
	}

	public function testUnsupportedDatabaseFailsBeforePackageStorageIsRead(): void {
		$plugins  = new FacadePluginRepository( $this->package( 'plugin/example.php', 'owner/example', '101' ) );
		$database = new FacadeDatabase( false );
		$facade   = $this->facade( $plugins, new FacadeThemeRepository(), database: $database );

		$readiness = $facade->readiness( 'gh' )->toArray();

		self::assertSame( 'blocked', $readiness['site']['status'] );
		self::assertSame( array( 'database_unavailable' ), $readiness['site']['reason_codes'] );
		self::assertSame( array(), $readiness['repositories'] );
		self::assertSame( 0, $plugins->reads );
	}

	public function testPackageStorageReadFailureHasAPathlessSiteReason(): void {
		$plugins          = new FacadePluginRepository( $this->package( 'plugin/example.php', 'owner/example', '101' ) );
		$plugins->failure = new RuntimeException( 'storage canary' );
		$facade           = $this->facade( $plugins, new FacadeThemeRepository() );

		$readiness = $facade->readiness( 'gh' )->toArray();

		self::assertSame( array( 'managed_packages_unavailable' ), $readiness['site']['reason_codes'] );
		self::assertSame( array(), $readiness['repositories'] );
		self::assertNull( $facade->target( 'gh', '101' ) );
	}

	public function testUnsafeCallbackBlocksButStillDescribesManagedCandidates(): void {
		$plugins = new FacadePluginRepository( $this->package( 'plugin/example.php', 'owner/example', '101' ) );
		$themes  = new FacadeThemeRepository();
		$secrets = new FacadeSecretsFile();
		$facade  = new AssistedWebhookFacade(
			new WebhookAssistanceReadinessEvaluator( $plugins, $themes, $secrets, new FacadeDatabase( true ), static fn (): bool => true ),
			$secrets,
			static fn (): bool => true,
			static fn ( string $providerCode ): string => 'https://127.0.0.1/wp-json/ran-booster/v1/webhooks/' . $providerCode
		);

		$readiness = $facade->readiness( 'gh' )->toArray();

		self::assertSame( array( 'callback_requires_public_https' ), $readiness['site']['reason_codes'] );
		self::assertCount( 1, $readiness['repositories'] );
		self::assertSame( 'blocked', $readiness['repositories'][0]['status'] );
		self::assertSame( array(), $readiness['repositories'][0]['reason_codes'] );
		self::assertFalse( $readiness['repositories'][0]['eligible'] );
		self::assertSame( 1, $plugins->reads );
	}

	public function testProfileMetadataRemainsReadableWhenCallbackReadinessIsBlocked(): void {
		$profileId         = 'wh_' . str_repeat( 'a', 24 );
		$plugins           = new FacadePluginRepository( $this->package( 'plugin/example.php', 'owner/example', '101' ) );
		$secrets           = new FacadeSecretsFile();
		$secrets->profiles = array(
			$profileId => $this->storedProfile( 'repository', 'owner/example', '101', 5, 'assisted', str_repeat( 'a', 32 ) ),
		);
		$facade            = new AssistedWebhookFacade(
			new WebhookAssistanceReadinessEvaluator( $plugins, new FacadeThemeRepository(), $secrets, new FacadeDatabase( true ), static fn (): bool => true ),
			$secrets,
			static fn (): bool => true,
			static fn ( string $providerCode ): string => 'https://127.0.0.1/wp-json/ran-booster/v1/webhooks/' . $providerCode
		);

		self::assertNull( $facade->target( 'gh', '101' ) );
		$metadata = $facade->profile( 'gh', '101', $profileId );
		self::assertNotNull( $metadata );
		self::assertSame( 5, $metadata->revision() );
		self::assertSame( 'created', $metadata->disposition() );
	}

	public function testEveryOperationReauthorisesManageOptions(): void {
		$plugins = new FacadePluginRepository( $this->package( 'plugin/example.php', 'owner/example', '101' ) );
		$facade  = $this->facade( $plugins, new FacadeThemeRepository(), canManage: static fn (): bool => false );
		$target  = new AssistanceTarget(
			'gh',
			'101',
			'owner/example',
			'owner/example',
			array( 'plugin/example.php' ),
			array(
				'automatic' => 0,
				'manual'    => 1,
				'disabled'  => 0,
			),
			'https://site.example/wp-json/ran-booster/v1/webhooks/gh'
		);

		$readiness = $facade->readiness( 'gh' )->toArray();
		self::assertSame( array( 'managed_packages_unavailable' ), $readiness['site']['reason_codes'] );
		self::assertNull( $facade->target( 'gh', '101' ) );
		self::assertSame( 'forbidden', $facade->provision( $target, static fn (): ProvisioningCallbackResult => ProvisioningCallbackResult::succeeded() )->code() );
		self::assertNull( $facade->profile( 'gh', '101', 'wh_' . str_repeat( 'a', 24 ) ) );
		self::assertSame( 'forbidden', $facade->reconfigure( $target, 'wh_' . str_repeat( 'a', 24 ), static fn (): ProvisioningCallbackResult => ProvisioningCallbackResult::succeeded() )->code() );
		self::assertFalse( $facade->releaseProfile( 'gh', '101', 'wh_' . str_repeat( 'a', 24 ) ) );
		self::assertSame( 0, $plugins->reads );
	}

	public function testReadinessReportsIdentityAndLocatorFailuresWithoutDroppingCandidates(): void {
		$plugins = new FacadePluginRepository(
			$this->package( 'plugin/missing.php', 'owner/missing', null ),
			$this->package( 'plugin/first.php', 'owner/conflict', '101' ),
			$this->package( 'plugin/second.php', 'owner/conflict', '102' ),
			$this->package( 'plugin/invalid.php', 'https://example.test/owner/repository', '103' ),
			$this->package( 'plugin/reused-a.php', 'owner/reused-a', '104' ),
			$this->package( 'plugin/reused-b.php', 'owner/reused-b', '104' )
		);

		$repositories = $this->facade( $plugins, new FacadeThemeRepository() )->readiness( 'gh' )->toArray()['repositories'];
		$reasons      = array_column( $repositories, 'reason_codes', 'repository' );

		self::assertSame( array( 'repository_identity_unavailable' ), $reasons['owner/missing'] );
		self::assertSame( array( 'repository_identity_conflict' ), $reasons['owner/conflict'] );
		self::assertSame( array( 'repository_locator_invalid' ), $reasons['https://example.test/owner/repository'] );
		self::assertSame( array( 'repository_identity_conflict' ), $reasons['owner/reused-a'] );
		self::assertSame( array( 'repository_identity_conflict' ), $reasons['owner/reused-b'] );
	}

	public function testReadinessDistinguishesSharedNoneAndUnknownSecretCoverage(): void {
		$plugins                    = new FacadePluginRepository(
			$this->package( 'plugin/shared.php', 'owner/shared', '101' ),
			$this->package( 'plugin/none.php', 'other/none', '102' )
		);
		$secrets                    = new FacadeSecretsFile();
		$secrets->profiles['owner'] = array(
			'scope'        => 'owner',
			'target'       => 'owner',
			'authority_id' => '',
		);

		$repositories = $this->facade( $plugins, new FacadeThemeRepository(), $secrets )->readiness( 'gh' )->toArray()['repositories'];
		self::assertSame(
			array(
				'other/none'   => AssistanceReadiness::SECRET_NONE,
				'owner/shared' => AssistanceReadiness::SECRET_SHARED,
			),
			array_column( $repositories, 'local_secret_coverage', 'repository' )
		);

		$secrets->storageReady = false;
		$blocked               = $this->facade( $plugins, new FacadeThemeRepository(), $secrets )->readiness( 'gh' )->toArray();
		self::assertContains( 'secrets_storage_unavailable', $blocked['site']['reason_codes'] );
		self::assertSame(
			array( AssistanceReadiness::SECRET_UNKNOWN, AssistanceReadiness::SECRET_UNKNOWN ),
			array_column( $blocked['repositories'], 'local_secret_coverage' )
		);
	}

	public function testSharedEvaluatorScopesCandidatesToTheRequestedProvider(): void {
		$plugins   = new FacadePluginRepository(
			$this->package( 'plugin/github.php', 'owner/github', '101' ),
			$this->package( 'plugin/bitbucket.php', 'owner/bitbucket', '102', provider: 'bb' )
		);
		$secrets   = new FacadeSecretsFile();
		$evaluator = new WebhookAssistanceReadinessEvaluator(
			$plugins,
			new FacadeThemeRepository(),
			$secrets,
			new FacadeDatabase( true ),
			static fn (): bool => true
		);

		$repositories = $evaluator->evaluate( 'bb', 'https://site.example/wp-json/ran-booster/v1/webhooks/bb' )->toArray()['repositories'];

		self::assertCount( 1, $repositories );
		self::assertSame( 'bb', $repositories[0]['provider_code'] );
		self::assertSame( 'owner/bitbucket', $repositories[0]['repository'] );
	}

	public function testSharedEvaluatorExcludesPublishedReleasePackagesFromBranchWebhookReadiness(): void {
		$branch  = $this->package( 'plugin/branch.php', 'owner/branch', '101' );
		$release = $this->package( 'plugin/release.php', 'owner/release', '102' );
		$release->setSource( PackageSource::RELEASE_ASSET, 2 );
		$evaluator = new WebhookAssistanceReadinessEvaluator(
			new FacadePluginRepository( $branch, $release ),
			new FacadeThemeRepository(),
			new FacadeSecretsFile(),
			new FacadeDatabase( true ),
			static fn (): bool => true
		);

		$repositories = $evaluator->evaluate( 'gh', 'https://site.example/wp-json/ran-booster/v1/webhooks/gh' )->toArray()['repositories'];

		self::assertSame( array( 'owner/branch' ), array_column( $repositories, 'repository' ) );
	}

	public function testCleanupTargetIsReleaseOnlyAndRejectsMixedBranchConsumers(): void {
		$release = $this->package( 'plugin/release.php', 'owner/release', '102' );
		$release->setSource( PackageSource::RELEASE_ASSET, 2 );
		$facade = $this->facade(
			new FacadePluginRepository( $release ),
			new FacadeThemeRepository()
		);

		$target = $facade->cleanupTarget( 'gh', '102' );

		self::assertInstanceOf( AssistanceTarget::class, $target );
		self::assertSame( array( 'plugin/release.php' ), $target->toArray()['package_references'] );

		$branch = $this->package( 'plugin/branch.php', 'owner/release', 'different-id' );
		$mixed  = $this->facade(
			new FacadePluginRepository( $release, $branch ),
			new FacadeThemeRepository()
		);

		self::assertNull( $mixed->cleanupTarget( 'gh', '102' ) );
	}

	public function testCleanupProfileLifecycleReauthorisesTheReleaseTarget(): void {
		$profileId = 'wh_' . str_repeat( 'd', 24 );
		$release   = $this->package( 'plugin/release.php', 'owner/release', '102' );
		$release->setSource( PackageSource::RELEASE_ASSET, 2 );
		$secrets           = new FacadeSecretsFile();
		$secrets->profiles = array(
			$profileId => $this->storedProfile( 'repository', 'owner/release', '102', 1, 'assisted', str_repeat( 's', 32 ) ),
		);
		$facade            = $this->facade(
			new FacadePluginRepository( $release ),
			new FacadeThemeRepository(),
			$secrets
		);
		$target            = $facade->cleanupTarget( 'gh', '102' );

		self::assertInstanceOf( AssistanceTarget::class, $target );
		self::assertSame( $profileId, $facade->cleanupProfile( $target, $profileId )?->id() );

		$release->setSource( PackageSource::BRANCH, 3 );

		self::assertNull( $facade->cleanupProfile( $target, $profileId ) );
		self::assertFalse( $facade->releaseCleanupProfile( $target, $profileId ) );
		self::assertSame( array(), $secrets->deleted );
	}

	public function testFacadeCarriesTheProviderThroughTargetProfileAndSecretLifecycle(): void {
		$secrets = new FacadeSecretsFile();
		$facade  = $this->facade(
			new FacadePluginRepository( $this->package( 'plugin/bitbucket.php', 'workspace/example', '202', provider: 'bb' ) ),
			new FacadeThemeRepository(),
			$secrets
		);
		$target  = $facade->target( 'bb', '202' );

		self::assertInstanceOf( AssistanceTarget::class, $target );
		self::assertSame( 'bb', $target->providerCode() );
		self::assertSame( 'bb', $target->toArray()['provider_code'] );
		self::assertSame( 'https://site.example/wp-json/ran-booster/v1/webhooks/bb', $target->endpoint() );

		$result = $facade->provision(
			$target,
			static fn (): ProvisioningCallbackResult => ProvisioningCallbackResult::succeeded()
		);

		self::assertTrue( $result->succeeded() );
		self::assertSame( array( 'bb' ), $secrets->savedProviders );
		$profileId = (string) $result->profileId();
		$metadata  = $facade->profile( 'bb', '202', $profileId );
		self::assertInstanceOf( WebhookProfileMetadata::class, $metadata );
		self::assertSame( 'bb', $metadata->providerCode() );
		self::assertSame( 'bb', $metadata->toArray()['provider_code'] );
		self::assertNull( $facade->profile( 'gh', '202', $profileId ) );
		self::assertTrue( $facade->releaseProfile( 'bb', '202', $profileId ) );
		self::assertSame( array( 'bb' ), $secrets->deletedProviders );
	}

	public function testProviderBearingPublicValuesRejectInvalidProviderCodes(): void {
		$this->expectException( \InvalidArgumentException::class );
		new AssistanceTarget(
			'invalid provider',
			'101',
			'owner/example',
			'owner/example',
			array( 'plugin/example.php' ),
			array(
				'automatic' => 0,
				'manual'    => 1,
				'disabled'  => 0,
			),
			'https://site.example/wp-json/ran-booster/v1/webhooks/gh'
		);
	}

	public function testPublicHttpsGateRejectsStructurallyUnsafeCallbackUrls(): void {
		$evaluator = new WebhookAssistanceReadinessEvaluator(
			new FacadePluginRepository( $this->package( 'plugin/example.php', 'owner/example', '101' ) ),
			new FacadeThemeRepository(),
			new FacadeSecretsFile(),
			new FacadeDatabase( true ),
			static fn (): bool => true
		);

		foreach ( array(
			'http://site.example/webhook',
			'https://localhost/webhook',
			'https://site.local/webhook',
			'https://10.0.0.1/webhook',
			'https://[::1]/webhook',
			'https://[fd00::1]/webhook',
			'https://user@site.example/webhook',
			'https://site.example:8443/webhook',
			'https://site.example/webhook?token=value',
		) as $endpoint ) {
			self::assertContains(
				'callback_requires_public_https',
				$evaluator->evaluate( 'gh', $endpoint )->toArray()['site']['reason_codes'],
				$endpoint
			);
		}

		self::assertSame(
			array(),
			$evaluator->evaluate( 'gh', 'https://site.example/webhook' )->toArray()['site']['reason_codes']
		);
	}

	public function testProvisionPassesGeneratedSecretOnlyToTheCallbackAndReturnsOnlyProfileIdentity(): void {
		$secrets = new FacadeSecretsFile();
		$facade  = $this->facade( new FacadePluginRepository( $this->package( 'plugin/example.php', 'owner/example', '101' ) ), new FacadeThemeRepository(), $secrets );
		$target  = $facade->target( 'gh', '101' );
		self::assertInstanceOf( AssistanceTarget::class, $target );
		$callbackSecret = '';

		$result = $facade->provision(
			$target,
			static function ( string $profileId, string $secret, int $revision ) use ( &$callbackSecret ): ProvisioningCallbackResult {
				self::assertSame( 'wh_' . str_repeat( 'a', 24 ), $profileId );
				self::assertSame( 1, $revision );
				$callbackSecret = $secret;

				return ProvisioningCallbackResult::succeeded();
			}
		);

		self::assertTrue( $result->succeeded() );
		self::assertSame( 'wh_' . str_repeat( 'a', 24 ), $result->profileId() );
		self::assertSame( 'succeeded', $result->code() );
		self::assertSame( $callbackSecret, $secrets->savedSecret );
		self::assertSame( 64, strlen( $callbackSecret ) );
		self::assertSame(
			array(
				'label'        => 'Assisted hook for owner/example',
				'scope'        => 'repository',
				'target'       => 'owner/example',
				'authority_id' => '101',
				'origin'       => 'assisted',
			),
			$secrets->savedMetadata
		);
		self::assertSame(
			array(
				'code'       => 'succeeded',
				'profile_id' => 'wh_' . str_repeat( 'a', 24 ),
			),
			array(
				'code'       => $result->code(),
				'profile_id' => $result->profileId(),
			)
		);
		self::assertSame( 'repository', $result->scope() );
		self::assertSame( 1, $result->revision() );
		self::assertSame( 'created', $result->disposition() );
	}

	public function testFailedRemoteCreationDeletesTheNewExactProfile(): void {
		$secrets = new FacadeSecretsFile();
		$facade  = $this->facade( new FacadePluginRepository( $this->package( 'plugin/example.php', 'owner/example', '101' ) ), new FacadeThemeRepository(), $secrets );
		$target  = $facade->target( 'gh', '101' );
		self::assertInstanceOf( AssistanceTarget::class, $target );

		$result = $facade->provision( $target, static fn (): ProvisioningCallbackResult => ProvisioningCallbackResult::failed() );

		self::assertSame( 'remote_configuration_failed', $result->code() );
		self::assertNull( $result->profileId() );
		self::assertSame( array( 'wh_' . str_repeat( 'a', 24 ) ), $secrets->deleted );
	}

	public function testProvisionReusesRepositoryBeforeCurrentOwnerWithoutRotatingSecret(): void {
		$repositoryId      = 'wh_' . str_repeat( 'a', 24 );
		$ownerId           = 'wh_' . str_repeat( 'b', 24 );
		$repositorySecret  = str_repeat( 'r', 32 );
		$secrets           = new FacadeSecretsFile();
		$secrets->profiles = array(
			$ownerId      => $this->storedProfile( 'owner', 'owner', '', 2, 'manual', str_repeat( 'o', 32 ) ),
			$repositoryId => $this->storedProfile( 'repository', 'owner/example', '101', 4, 'manual', $repositorySecret ),
		);
		$facade            = $this->facade( new FacadePluginRepository( $this->package( 'plugin/example.php', 'owner/example', '101' ) ), new FacadeThemeRepository(), $secrets );
		$target            = $facade->target( 'gh', '101' );
		self::assertInstanceOf( AssistanceTarget::class, $target );

		$result = $facade->provision(
			$target,
			static function ( string $profileId, string $secret, int $revision ) use ( $repositoryId, $repositorySecret ): ProvisioningCallbackResult {
				self::assertSame( $repositoryId, $profileId );
				self::assertSame( $repositorySecret, $secret );
				self::assertSame( 4, $revision );

				return ProvisioningCallbackResult::succeeded();
			}
		);

		self::assertSame( 'reused', $result->disposition() );
		self::assertSame( 'repository', $result->scope() );
		self::assertSame( array(), $secrets->savedMetadata );
	}

	public function testReconfigureRejectsStaleOwnerButKeepsStableRepositoryAcrossTransfer(): void {
		$staleOwnerId      = 'wh_' . str_repeat( 'a', 24 );
		$repositoryId      = 'wh_' . str_repeat( 'b', 24 );
		$currentOwnerId    = 'wh_' . str_repeat( 'c', 24 );
		$repositorySecret  = str_repeat( 'r', 32 );
		$secrets           = new FacadeSecretsFile();
		$secrets->profiles = array(
			$staleOwnerId   => $this->storedProfile( 'owner', 'old-owner', '', 1, 'manual', str_repeat( 's', 32 ) ),
			$repositoryId   => $this->storedProfile( 'repository', 'old-owner/old-slug', '101', 7, 'manual', $repositorySecret ),
			$currentOwnerId => $this->storedProfile( 'owner', 'new-owner', '', 3, 'manual', str_repeat( 'n', 32 ) ),
		);
		$facade            = $this->facade( new FacadePluginRepository( $this->package( 'plugin/example.php', 'new-owner/new-slug', '101' ) ), new FacadeThemeRepository(), $secrets );
		$target            = $facade->target( 'gh', '101' );
		self::assertInstanceOf( AssistanceTarget::class, $target );

		$result = $facade->reconfigure(
			$target,
			$staleOwnerId,
			static function ( string $profileId, string $secret, int $revision ) use ( $repositoryId, $repositorySecret ): ProvisioningCallbackResult {
				self::assertSame( $repositoryId, $profileId );
				self::assertSame( $repositorySecret, $secret );
				self::assertSame( 7, $revision );

				return ProvisioningCallbackResult::succeeded();
			}
		);

		self::assertSame( $repositoryId, $result->profileId() );
		self::assertSame( 'reused', $result->disposition() );
	}

	public function testReconfigureFallsBackToTheCurrentCanonicalOwner(): void {
		$staleOwnerId      = 'wh_' . str_repeat( 'a', 24 );
		$currentOwnerId    = 'wh_' . str_repeat( 'b', 24 );
		$currentSecret     = str_repeat( 'n', 32 );
		$secrets           = new FacadeSecretsFile();
		$secrets->profiles = array(
			$staleOwnerId   => $this->storedProfile( 'owner', 'old-owner', '', 1, 'manual', str_repeat( 's', 32 ) ),
			$currentOwnerId => $this->storedProfile( 'owner', 'new-owner', '', 3, 'manual', $currentSecret ),
		);
		$facade            = $this->facade( new FacadePluginRepository( $this->package( 'plugin/example.php', 'new-owner/new-slug', '101' ) ), new FacadeThemeRepository(), $secrets );
		$target            = $facade->target( 'gh', '101' );
		self::assertInstanceOf( AssistanceTarget::class, $target );

		$result = $facade->reconfigure(
			$target,
			$staleOwnerId,
			static function ( string $profileId, string $secret, int $revision ) use ( $currentOwnerId, $currentSecret ): ProvisioningCallbackResult {
				self::assertSame( $currentOwnerId, $profileId );
				self::assertSame( $currentSecret, $secret );
				self::assertSame( 3, $revision );

				return ProvisioningCallbackResult::succeeded();
			}
		);

		self::assertSame( 'owner', $result->scope() );
		self::assertSame( $currentOwnerId, $result->profileId() );
		self::assertTrue( $facade->releaseProfile( 'gh', '101', $staleOwnerId ) );
		self::assertSame( array(), $secrets->deleted );
	}

	public function testProfileMetadataAndReleaseKeepManualProfilesButDeleteAssistedRepositoryProfiles(): void {
		$secrets           = new FacadeSecretsFile();
		$secrets->profiles = array(
			'wh_' . str_repeat( 'a', 24 ) => array(
				'source'       => 'file',
				'immutable'    => false,
				'scope'        => 'repository',
				'authority_id' => '101',
				'target'       => 'owner/example',
				'revision'     => 3,
				'origin'       => 'manual',
				'configured'   => true,
				'secret'       => str_repeat( 'm', 32 ),
			),
			'wh_' . str_repeat( 'b', 24 ) => array(
				'source'       => 'file',
				'immutable'    => false,
				'scope'        => 'repository',
				'authority_id' => '101',
				'target'       => 'owner/other',
				'revision'     => 1,
				'origin'       => 'assisted',
				'configured'   => true,
				'secret'       => str_repeat( 'a', 32 ),
			),
			'wh_' . str_repeat( 'c', 24 ) => array(
				'source'       => 'file',
				'immutable'    => false,
				'scope'        => 'repository',
				'authority_id' => '999',
				'target'       => 'owner/example',
				'revision'     => 1,
				'origin'       => 'assisted',
				'configured'   => true,
				'secret'       => str_repeat( 's', 32 ),
			),
		);
		$facade            = $this->facade( new FacadePluginRepository( $this->package( 'plugin/example.php', 'owner/example', '101' ) ), new FacadeThemeRepository(), $secrets );
		$matchingProfileId = 'wh_' . str_repeat( 'a', 24 );
		$assistedProfileId = 'wh_' . str_repeat( 'b', 24 );
		$staleProfileId    = 'wh_' . str_repeat( 'c', 24 );

		self::assertSame( 3, $facade->profile( 'gh', '101', $matchingProfileId )?->revision() );
		self::assertSame( 'gh', $facade->profile( 'gh', '101', $matchingProfileId )?->providerCode() );
		self::assertSame( 'reused', $facade->profile( 'gh', '101', $matchingProfileId )?->disposition() );
		self::assertTrue( $facade->releaseProfile( 'gh', '101', $matchingProfileId ) );
		self::assertSame( array(), $secrets->deleted );
		self::assertTrue( $facade->releaseProfile( 'gh', '101', $assistedProfileId ) );
		self::assertSame( array( $assistedProfileId ), $secrets->deleted );
		self::assertTrue( $facade->releaseProfile( 'gh', '101', $assistedProfileId ) );
		self::assertFalse( $facade->releaseProfile( 'gh', '101', $staleProfileId ) );
		self::assertSame( array( $assistedProfileId ), $secrets->deleted );
	}

	private function facade(
		FacadePluginRepository $plugins,
		FacadeThemeRepository $themes,
		?FacadeSecretsFile $secrets = null,
		?FacadeDatabase $database = null,
		?callable $canManage = null
	): AssistedWebhookFacade {
		$secrets     = $secrets ?? new FacadeSecretsFile();
		$database    = $database ?? new FacadeDatabase( true );
		$canManage ??= static fn (): bool => true;

		return new AssistedWebhookFacade(
			new WebhookAssistanceReadinessEvaluator( $plugins, $themes, $secrets, $database, $canManage ),
			$secrets,
			$canManage,
			static fn ( string $providerCode ): string => 'https://site.example/wp-json/ran-booster/v1/webhooks/' . $providerCode
		);
	}

	private function package(
		string $identifier,
		string $repository,
		?string $repositoryId,
		DeploymentPolicy $policy = DeploymentPolicy::MANUAL,
		string $provider = 'gh'
	): FacadePackage {
		return new FacadePackage(
			$identifier,
			new ManagedRepository( $provider, $repository, $repositoryId ?? 'missing', 'main' ),
			$policy,
			$repositoryId
		);
	}

	/** @return array<string, mixed> */
	private function storedProfile(
		string $scope,
		string $target,
		string $authorityId,
		int $revision,
		string $origin,
		string $secret
	): array {
		return array(
			'source'       => 'file',
			'immutable'    => false,
			'scope'        => $scope,
			'target'       => $target,
			'authority_id' => $authorityId,
			'revision'     => $revision,
			'origin'       => $origin,
			'configured'   => true,
			'secret'       => $secret,
		);
	}
}

final class FacadePluginRepository extends PluginRepository {
	public int $reads           = 0;
	public ?\Throwable $failure = null;
	/** @var list<FacadePackage> */
	private array $packages;

	/** @param FacadePackage ...$packages */
	public function __construct( FacadePackage ...$packages ) {
		$this->packages = $packages;
	}

	public function allDeploymentPlugins( ?\RAN\PackageSource $source = null ): array {
		++$this->reads;
		if ( null !== $this->failure ) {
			throw $this->failure;
		}

		return $this->packages;
	}
}

final class FacadeThemeRepository extends ThemeRepository {
	/** @var list<FacadePackage> */
	private array $packages;

	/** @param FacadePackage ...$packages */
	public function __construct( FacadePackage ...$packages ) {
		$this->packages = $packages;
	}

	public function allDeploymentThemes( ?\RAN\PackageSource $source = null ): array {
		return $this->packages;
	}
}

final class FacadePackage extends AbstractPackage {
	public function __construct(
		private string $identifier,
		ManagedRepository $repository,
		DeploymentPolicy $policy,
		private ?string $repositoryId = null
	) {
		$this->repository       = $repository;
		$this->deploymentPolicy = $policy;
	}

	public function getIdentifier(): mixed {
		return $this->identifier;
	}

	public function getProviderRepositoryId(): ?string {
		return $this->repositoryId;
	}

	protected function runtimeSlug(): string {
		return 'fixture';
	}
}

final class FacadeSecretsFile extends SecretsFile {
	/** @var array<string, array<string, mixed>> */
	public array $profiles = array();
	/** @var list<string> */
	public array $deleted = array();
	/** @var array<string, mixed> */
	public array $savedMetadata = array();
	/** @var list<string> */
	public array $savedProviders = array();
	/** @var list<string> */
	public array $deletedProviders = array();
	public string $savedSecret     = '';
	public bool $storageReady      = true;

	public function assertManagedStorageReady(): void {
		if ( ! $this->storageReady ) {
			throw new \RAN\Secrets\SecretsStorageUnavailable( 'storage unavailable' );
		}
	}

	public function saveWebhook( \RAN\RepositoryProvider\ProviderCode|string $provider, ?string $id, array $metadata, ?string $secret ): string {
		$providerCode           = $provider instanceof \RAN\RepositoryProvider\ProviderCode ? $provider->value : $provider;
		$this->savedProviders[] = $providerCode;
		$this->savedMetadata    = $metadata;
		$this->savedSecret      = (string) $secret;
		$id                   ??= 'wh_' . str_repeat( 'a', 24 );
		$this->profiles[ $id ] = $metadata + array(
			'id'         => $id,
			'provider'   => $providerCode,
			'revision'   => 1,
			'origin'     => 'manual',
			'source'     => 'file',
			'immutable'  => false,
			'configured' => true,
			'secret'     => (string) $secret,
		);

		return $id;
	}

	public function webhookProfiles( \RAN\RepositoryProvider\ProviderCode|string $provider ): array {
		return $this->profiles;
	}

	public function webhookMaterials( \RAN\RepositoryProvider\ProviderCode|string $provider ): array {
		return $this->profiles;
	}

	public function deleteWebhook( \RAN\RepositoryProvider\ProviderCode|string $provider, string $id ): bool {
		$providerCode             = $provider instanceof \RAN\RepositoryProvider\ProviderCode ? $provider->value : $provider;
		$this->deleted[]          = $id;
		$this->deletedProviders[] = $providerCode;
		unset( $this->profiles[ $id ] );

		return true;
	}
}

final class FacadeDatabase extends Database {
	public function __construct( private bool $supported ) {
	}

	public function requireSupported(): void {
		if ( ! $this->supported ) {
			throw new DatabaseCompatibilityFailure( 'unsupported_version' );
		}
	}

	public function requireReady(): void {
		$this->requireSupported();
	}
}
