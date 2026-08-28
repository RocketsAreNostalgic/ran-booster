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
use RAN\RepositoryProvider\WebhookNormalizer;
use RAN\RepositoryProvider\WebhookEnvelope;
use RAN\RepositoryProvider\WebhookRequest;
use RAN\Secrets\SecretsFile;
use RAN\Storage\Database;
use RAN\Storage\PluginRepository;
use RAN\Storage\ThemeRepository;
use Tests\RepositoryProvider\Support\InertWebhookPolicy;
use Tests\Support\FitnessOnlyWebhookManagementCapabilityProvider;

require_once dirname( __DIR__, 2 ) . '/Support/WebhookManagementCapabilityProviders.php';

final class AssistedWebhookFacadeTest extends TestCase {

	public function testBootstrapPublishesTheExactCutAndRemovesCleanup(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local source contract inspection.
		$bootstrap = file_get_contents( dirname( __DIR__, 3 ) . '/ran-booster.php' );
		self::assertIsString( $bootstrap );
		self::assertStringContainsString( "RAN_BOOSTER_PROVIDER_API_VERSION', 10", $bootstrap );
		self::assertStringContainsString( "RAN_BOOSTER_ADDON_API_VERSION', 16", $bootstrap );
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
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Test-only secret-containment assertion.
		self::assertStringNotContainsString( $secrets->savedSecret, json_encode( $result->toArray(), JSON_THROW_ON_ERROR ) );
	}

	public function testSetupRequiresASavedCredential(): void {
		$secrets  = new FixedFacadeSecretsFile();
		$provider = new FixedWebhookProvider();
		$facade   = $this->facade( $secrets, $provider );
		$target   = $facade->target( 'gh', '101' );
		self::assertNotNull( $target );

		self::assertSame( 'operation_unauthorized', $facade->setup( $target, null, 'good' )->code() );
	}

	public function testSetupRejectsAnInapplicableSelectedSigningProfileBeforeProviderWork(): void {
		$secrets  = new FixedFacadeSecretsFile();
		$provider = new FixedWebhookProvider();
		$facade   = $this->facade( $secrets, $provider );
		$target   = $facade->target( 'gh', '101' );
		self::assertNotNull( $target );

		$result = $facade->setup( $target, 'profile_1', 'good', 'wh_' . str_repeat( 'b', 24 ) );

		self::assertSame( 'operation_unauthorized', $result->code() );
		self::assertSame( 0, $provider->calls );
		self::assertSame( array(), $secrets->profiles );
	}

	public function testSetupCanSelectAnApplicableConstantSigningProfileWithoutCreatingAnother(): void {
		$secrets                        = new FixedFacadeSecretsFile();
		$secrets->profiles['constant']  = $secrets->profile( 'constant', 1, 'constant-secret' ) + array(
			'label'     => 'Deployment signing secret',
			'source'    => 'constant',
			'immutable' => true,
		);
		$secrets->materials['constant'] = $secrets->profiles['constant'];
		$provider                       = new FixedWebhookProvider();
		$facade                         = $this->facade( $secrets, $provider );
		$target                         = $facade->target( 'gh', '101' );
		self::assertNotNull( $target );

		self::assertSame(
			array(
				array(
					'id'    => 'constant',
					'label' => 'Deployment signing secret',
					'scope' => 'repository',
				),
			),
			$facade->webhookProfileChoices( 'gh', '101' )
		);
		$result = $facade->setup( $target, 'profile_1', 'good', 'constant' );
		self::assertTrue( $result->succeeded(), $result->code() );
		self::assertSame( 'constant-secret', $provider->signingSecret );
		self::assertCount( 1, $secrets->profiles );
	}

	public function testAssessmentRequiresASavedCredential(): void {
		$secrets  = new FixedFacadeSecretsFile();
		$provider = new FixedWebhookProvider();
		$facade   = $this->facade( $secrets, $provider );
		$target   = $facade->target( 'gh', '101' );
		self::assertNotNull( $target );

		$result = $facade->assessSetup( $target, null, 'good' );

		self::assertSame( 'unknown', $result->toArray()['support'] );
		self::assertNull( $provider->credentialId );
		self::assertSame( array(), $secrets->profiles );
	}

	public function testProviderOpaqueNestedRepositoryLocatorRemainsEligible(): void {
		$facade = $this->facade(
			new FixedFacadeSecretsFile(),
			new FixedWebhookProvider(),
			repository: 'group/subgroup/package'
		);

		self::assertNotNull( $facade->target( 'gh', '101' ) );
	}

	public function testSetupExceptionAfterProviderInvocationRetainsRecoveryProfile(): void {
		$secrets              = new FixedFacadeSecretsFile();
		$provider             = new FixedWebhookProvider();
		$provider->throwSetup = true;
		$facade               = $this->facade( $secrets, $provider );
		$target               = $facade->target( 'gh', '101' );
		self::assertNotNull( $target );

		$result = $facade->setup( $target, 'profile_1', 'good' );

		self::assertSame( 'partial', $result->state() );
		self::assertSame( 'setup_outcome_unknown', $result->code() );
		self::assertNotNull( $result->profile() );
		self::assertCount( 1, $secrets->profiles );
	}

	public function testFailedSetupCannotDeleteAProfileRotatedDuringTheProviderRequest(): void {
		$secrets              = new FixedFacadeSecretsFile();
		$provider             = new FixedWebhookProvider();
		$provider->setupState = 'failed';
		$facade               = $this->facade( $secrets, $provider );
		$target               = $facade->target( 'gh', '101' );
		self::assertNotNull( $target );
		$provider->duringSetup = static function () use ( $secrets ): void {
			$profileId                        = (string) array_key_first( $secrets->profiles );
			$rotated                          = $secrets->profile( $profileId, 2, 'rotated-secret' );
			$secrets->profiles[ $profileId ]  = $rotated;
			$secrets->materials[ $profileId ] = $rotated;
		};

		$result = $facade->setup( $target, 'profile_1', 'good' );

		self::assertSame( 'partial', $result->state() );
		self::assertSame( 'profile_cleanup_failed', $result->code() );
		self::assertSame( 2, $result->profile()?->revision() );
		self::assertSame( 'rotated-secret', $secrets->materials[ $result->profile()?->id() ]['secret'] ?? null );
	}

	public function testPreProviderFailureCannotDeleteAProfileRotatedAfterCreation(): void {
		$secrets                         = new FixedFacadeSecretsFile();
		$secrets->throwMaterialAfterSave = true;
		$provider                        = new FixedWebhookProvider();
		$facade                          = $this->facade( $secrets, $provider );
		$target                          = $facade->target( 'gh', '101' );
		self::assertNotNull( $target );

		$result = $facade->setup( $target, 'profile_1', 'good' );

		self::assertSame( 'partial', $result->state() );
		self::assertSame( 'profile_cleanup_failed', $result->code() );
		self::assertSame( 2, $result->profile()?->revision() );
		self::assertSame( 1, $provider->calls, 'Only the identity assessment may run before the local snapshot failure.' );
		self::assertCount( 1, $secrets->profiles );
	}

	public function testSameTargetSetupCannotOverlapWithAReusableNonce(): void {
		$secrets  = new FixedFacadeSecretsFile();
		$provider = new FixedWebhookProvider();
		$held     = false;
		$facade   = $this->facade(
			$secrets,
			$provider,
			static function () use ( &$held ): bool {
				if ( $held ) {
					return false;
				}
				$held = true;

				return true;
			},
			static function () use ( &$held ): bool {
				$held = false;

				return true;
			}
		);
		$target   = $facade->target( 'gh', '101' );
		self::assertNotNull( $target );
		$nested                = null;
		$provider->duringSetup = static function () use ( $facade, $target, &$nested ): void {
			$nested = $facade->setup( $target, 'profile_1', 'good' );
		};

		$first = $facade->setup( $target, 'profile_1', 'good' );

		self::assertTrue( $first->succeeded() );
		self::assertInstanceOf( RepositoryWebhookOperationResult::class, $nested );
		self::assertSame( 'operation_busy', $nested->code() );
		self::assertCount( 1, $secrets->profiles );
		self::assertFalse( $held );
	}

	public function testSeparateRequestFacadesContendOnTheSameTargetLock(): void {
		$secrets     = new FixedFacadeSecretsFile();
		$provider    = new FixedWebhookProvider();
		$held        = false;
		$acquire     = static function () use ( &$held ): bool {
			if ( $held ) {
				return false;
			}
			$held = true;

			return true;
		};
		$release     = static function () use ( &$held ): bool {
			$held = false;

			return true;
		};
		$firstFacade = $this->facade( $secrets, $provider, $acquire, $release );
		$otherFacade = $this->facade( $secrets, $provider, $acquire, $release );
		$firstTarget = $firstFacade->target( 'gh', '101' );
		$otherTarget = $otherFacade->target( 'gh', '101' );
		self::assertNotNull( $firstTarget );
		self::assertNotNull( $otherTarget );
		$nested                = null;
		$provider->duringSetup = static function () use ( $otherFacade, $otherTarget, &$nested ): void {
			$nested = $otherFacade->setup( $otherTarget, 'profile_1', 'good' );
		};

		self::assertTrue( $firstFacade->setup( $firstTarget, 'profile_1', 'good' )->succeeded() );
		self::assertInstanceOf( RepositoryWebhookOperationResult::class, $nested );
		self::assertSame( 'operation_busy', $nested->code() );
		self::assertCount( 1, $secrets->profiles );
		self::assertFalse( $held );
	}

	public function testLockReleaseFailureDoesNotOverwriteAmbiguousRecoveryEvidence(): void {
		$secrets               = new FixedFacadeSecretsFile();
		$provider              = new FixedWebhookProvider();
		$provider->removeState = 'ambiguous';
		$facade                = $this->facade( $secrets, $provider, static fn (): bool => true, static fn (): bool => false );
		$target                = $facade->target( 'gh', '101' );
		self::assertNotNull( $target );
		$profileId                       = 'wh_' . str_repeat( 'a', 24 );
		$secrets->profiles[ $profileId ] = $secrets->profile( $profileId, 1 );

		$result = $facade->remove( $target, 'profile_1', '55', $profileId, 1, 'good' );

		self::assertSame( 'ambiguous', $result->state() );
		self::assertSame( 'remove_ambiguous', $result->code() );
		self::assertSame( '55', $result->hookId() );
		self::assertSame( $profileId, $result->profile()?->id() );
		self::assertArrayHasKey( $profileId, $secrets->profiles );
	}

	public function testLockReleaseFailureDoesNotOverwritePartialSetupRecoveryEvidence(): void {
		$secrets              = new FixedFacadeSecretsFile();
		$provider             = new FixedWebhookProvider();
		$provider->throwSetup = true;
		$facade               = $this->facade( $secrets, $provider, static fn (): bool => true, static fn (): bool => false );
		$target               = $facade->target( 'gh', '101' );
		self::assertNotNull( $target );

		$result = $facade->setup( $target, 'profile_1', 'good' );

		self::assertSame( 'partial', $result->state() );
		self::assertSame( 'setup_outcome_unknown', $result->code() );
		self::assertNotNull( $result->profile() );
		self::assertCount( 1, $secrets->profiles );
	}

	public function testLockReleaseFailurePreservesConfirmedAbsenceAfterProfileCleanup(): void {
		$secrets  = new FixedFacadeSecretsFile();
		$provider = new FixedWebhookProvider();
		$facade   = $this->facade( $secrets, $provider, static fn (): bool => true, static fn (): bool => false );
		$target   = $facade->target( 'gh', '101' );
		self::assertNotNull( $target );
		$profileId                       = 'wh_' . str_repeat( 'a', 24 );
		$secrets->profiles[ $profileId ] = $secrets->profile( $profileId, 1 );

		$result = $facade->remove( $target, 'profile_1', '55', $profileId, 1, 'good' );

		self::assertTrue( $result->confirmsAbsence() );
		self::assertSame( 'absence_confirmed', $result->code() );
		self::assertArrayNotHasKey( $profileId, $secrets->profiles );
	}

	public function testLockReleaseFailureIsReportedAfterAnOtherwiseSuccessfulSetup(): void {
		$secrets  = new FixedFacadeSecretsFile();
		$provider = new FixedWebhookProvider();
		$facade   = $this->facade( $secrets, $provider, static fn (): bool => true, static fn (): bool => false );
		$target   = $facade->target( 'gh', '101' );
		self::assertNotNull( $target );

		$result = $facade->setup( $target, 'profile_1', 'good' );

		self::assertSame( 'partial', $result->state() );
		self::assertSame( 'operation_lock_release_failed', $result->code() );
		self::assertSame( '55', $result->hookId() );
		self::assertNotNull( $result->profile() );
	}

	public function testMutationStopsWhenSameRequestFitnessCannotRebindRepositoryIdentity(): void {
		$secrets                      = new FixedFacadeSecretsFile();
		$provider                     = new FixedWebhookProvider();
		$provider->fitnessSuitability = 'insufficient';
		$facade                       = $this->facade( $secrets, $provider );
		$target                       = $facade->target( 'gh', '101' );
		self::assertNotNull( $target );

		$result = $facade->setup( $target, 'profile_1', 'good' );

		self::assertSame( 'repository_identity_unconfirmed', $result->code() );
		self::assertSame( 1, $provider->calls, 'Only the read-only identity assessment may run.' );
		self::assertSame( '', $provider->signingSecret );
		self::assertSame( array(), $secrets->profiles );
	}

	public function testReconfigureUsesMetadataAndSecretFromOneMaterialSnapshot(): void {
		$secrets  = new FixedFacadeSecretsFile();
		$provider = new FixedWebhookProvider();
		$facade   = $this->facade( $secrets, $provider );
		$target   = $facade->target( 'gh', '101' );
		self::assertNotNull( $target );
		$profileId                        = 'wh_' . str_repeat( 'a', 24 );
		$secrets->profiles[ $profileId ]  = $secrets->profile( $profileId, 1, 'old-secret' );
		$secrets->materials[ $profileId ] = $secrets->profile( $profileId, 2, 'rotated-secret' );

		$result = $facade->reconfigure( $target, 'profile_1', '55', $profileId, 2, 'good' );

		self::assertTrue( $result->succeeded() );
		self::assertSame( 2, $result->profile()?->revision() );
		self::assertSame( 'rotated-secret', $provider->signingSecret );
	}

	public function testReconfigureExceptionAfterProviderInvocationRetainsRecoveryEvidence(): void {
		$secrets                    = new FixedFacadeSecretsFile();
		$provider                   = new FixedWebhookProvider();
		$provider->throwReconfigure = true;
		$facade                     = $this->facade( $secrets, $provider );
		$target                     = $facade->target( 'gh', '101' );
		self::assertNotNull( $target );
		$profileId                        = 'wh_' . str_repeat( 'a', 24 );
		$secrets->profiles[ $profileId ]  = $secrets->profile( $profileId, 1, 'current-secret' );
		$secrets->materials[ $profileId ] = $secrets->profiles[ $profileId ];

		$result = $facade->reconfigure( $target, 'profile_1', '55', $profileId, 1, 'good' );

		self::assertSame( 'ambiguous', $result->state() );
		self::assertSame( 'reconfigure_outcome_unknown', $result->code() );
		self::assertSame( '55', $result->hookId() );
		self::assertSame( $profileId, $result->profile()?->id() );
		self::assertSame( 'current-secret', $provider->signingSecret );
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

	public function testRemoveDoesNotDeleteAProfileRotatedWhileTheProviderRequestWasInFlight(): void {
		$secrets  = new FixedFacadeSecretsFile();
		$provider = new FixedWebhookProvider();
		$facade   = $this->facade( $secrets, $provider );
		$target   = $facade->target( 'gh', '101' );
		self::assertNotNull( $target );
		$profileId                        = 'wh_' . str_repeat( 'a', 24 );
		$secrets->profiles[ $profileId ]  = $secrets->profile( $profileId, 1, 'original-secret' );
		$secrets->materials[ $profileId ] = $secrets->profiles[ $profileId ];
		$provider->duringRemove           = static function () use ( $secrets, $profileId ): void {
			$rotated                          = $secrets->profile( $profileId, 2, 'rotated-secret' );
			$secrets->profiles[ $profileId ]  = $rotated;
			$secrets->materials[ $profileId ] = $rotated;
		};

		$result = $facade->remove( $target, 'profile_1', '55', $profileId, 1, 'good' );

		self::assertSame( 'partial', $result->state() );
		self::assertSame( 'local_profile_release_failed', $result->code() );
		self::assertSame( 2, $secrets->profiles[ $profileId ]['revision'] );
		self::assertSame( 'rotated-secret', $secrets->materials[ $profileId ]['secret'] );
	}

	public function testRemoveExceptionAfterProviderInvocationRetainsRecoveryEvidence(): void {
		$secrets               = new FixedFacadeSecretsFile();
		$provider              = new FixedWebhookProvider();
		$provider->throwRemove = true;
		$facade                = $this->facade( $secrets, $provider );
		$target                = $facade->target( 'gh', '101' );
		self::assertNotNull( $target );
		$profileId                       = 'wh_' . str_repeat( 'a', 24 );
		$secrets->profiles[ $profileId ] = $secrets->profile( $profileId, 1 );

		$result = $facade->remove( $target, 'profile_1', '55', $profileId, 1, 'good' );

		self::assertSame( 'ambiguous', $result->state() );
		self::assertSame( 'remove_outcome_unknown', $result->code() );
		self::assertSame( '55', $result->hookId() );
		self::assertSame( $profileId, $result->profile()?->id() );
		self::assertArrayHasKey( $profileId, $secrets->profiles );
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

	public function testIncompleteWebhookAggregateRefusesAssessmentBeforeProviderWork(): void {
		$secrets  = new FixedFacadeSecretsFile();
		$provider = new FitnessOnlyWebhookManagementCapabilityProvider( 'gh', 'Partial fixture' );
		$facade   = $this->facade( $secrets, $provider );
		$target   = $facade->target( 'gh', '101' );
		self::assertNotNull( $target );

		$result = $facade->assessSetup( $target, 'profile_1', 'good' )->toArray();

		self::assertSame( 'assessment_unavailable', $result['code'] );
		self::assertSame( 0, $provider->providerOperationCalls );
	}

	private function facade( FixedFacadeSecretsFile $secrets, RepositoryProvider $provider, ?callable $acquireLock = null, ?callable $releaseLock = null, string $repository = 'owner/example' ): AssistedWebhookFacade {
		$registry = new ProviderRegistry( array( $provider ) );
		$package  = new FixedFacadePackage( new ManagedRepository( 'gh', $repository, '101', 'main' ) );

		return new AssistedWebhookFacade(
			new WebhookAssistanceReadinessEvaluator( new FixedPluginRepository( $package ), new FixedThemeRepository(), $secrets, new FixedDatabase(), static fn (): bool => true ),
			$secrets,
			$registry,
			static fn (): bool => true,
			static fn (): string => 'https://site.example/wp-json/ran-booster/v1/webhooks/gh',
			static fn ( string $nonce ): bool => 'good' === $nonce,
			$acquireLock ?? static fn (): bool => true,
			$releaseLock ?? static fn (): bool => true
		);
	}
}

final class FixedWebhookProvider implements RepositoryProvider, RepositoryWebhookFitness, RepositoryWebhookManagement, WebhookNormalizer {
	public const OPERATION            = 'repository-webhook-management';
	public const VERSION              = 1;
	public int $calls                 = 0;
	public ?string $credentialId      = null;
	public string $signingSecret      = '';
	public string $removeState        = 'succeeded';
	public string $setupState         = 'succeeded';
	public string $fitnessSuitability = 'unknown';
	public bool $throwSetup           = false;
	public bool $throwReconfigure     = false;
	public bool $throwRemove          = false;
	/** @var callable(): void|null */
	public $duringSetup = null;
	/** @var callable(): void|null */
	public $duringRemove = null;

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

	public function getWebhookPolicy(): \RAN\RepositoryProvider\ProviderWebhookPolicy {
		return new InertWebhookPolicy( ProviderCode::parse( 'gh' ) );
	}

	public function diagnoseWebhookReadiness(): \RAN\RepositoryProvider\ProviderDiagnosticResult {
		return new \RAN\RepositoryProvider\ProviderDiagnosticResult( \RAN\RepositoryProvider\ProviderDiagnosticResult::PASSED, 'fixture_webhook_ready', 'Fixture is ready.', 'No action is required.' );
	}

	public function normalizeWebhook( WebhookRequest $request ): WebhookEnvelope {
		unset( $request );

		return WebhookEnvelope::ignored();
	}

	public function resolveRepository( RepositoryLookupRequest $request ): RepositoryDescriptor {
		throw new \RuntimeException( 'not used' );
	}

	public function prepareArchive( ArchiveRequest $request ): PreparedArchive {
		throw new \RuntimeException( 'not used' );
	}

	public function assessSetup( string $repositoryId, string $repository, ?string $credentialProfileId ): RepositoryWebhookFitnessResult {
		return $this->fitness( $credentialProfileId );
	}

	public function assessCheck( string $repositoryId, string $repository, ?string $credentialProfileId, string $hookId ): RepositoryWebhookFitnessResult {
		return $this->fitness( $credentialProfileId );
	}

	public function assessReconfigure( string $repositoryId, string $repository, ?string $credentialProfileId, string $hookId ): RepositoryWebhookFitnessResult {
		return $this->fitness( $credentialProfileId );
	}

	public function assessRemove( string $repositoryId, string $repository, ?string $credentialProfileId, string $hookId ): RepositoryWebhookFitnessResult {
		return $this->fitness( $credentialProfileId );
	}

	public function assessTest( string $repositoryId, string $repository, ?string $credentialProfileId, string $hookId ): RepositoryWebhookFitnessResult {
		return $this->fitness( $credentialProfileId );
	}

	public function setup( string $repositoryId, string $repository, string $callbackUrl, ?string $credentialProfileId, string $signingSecret ): RepositoryWebhookOperationResult {
		++$this->calls;
		$this->credentialId  = $credentialProfileId;
		$this->signingSecret = $signingSecret;
		if ( null !== $this->duringSetup ) {
			$callback          = $this->duringSetup;
			$this->duringSetup = null;
			$callback();
		}
		if ( $this->throwSetup ) {
			throw new \RuntimeException( 'Provider response was lost after invocation.' );
		}

		return $this->operation( $this->setupState, 'succeeded' === $this->setupState ? 'configured_pending_delivery' : 'setup_failed' );
	}

	public function check( string $repositoryId, string $repository, string $hookId, string $callbackUrl, ?string $credentialProfileId ): RepositoryWebhookOperationResult {
		++$this->calls;

		return $this->operation( 'succeeded', 'configuration_confirmed' );
	}

	public function reconfigure( string $repositoryId, string $repository, string $hookId, string $callbackUrl, ?string $credentialProfileId, string $signingSecret ): RepositoryWebhookOperationResult {
		++$this->calls;
		$this->signingSecret = $signingSecret;
		if ( $this->throwReconfigure ) {
			throw new \RuntimeException( 'Provider response was lost after invocation.' );
		}

		return $this->operation( 'succeeded', 'configured_pending_delivery' );
	}

	public function remove( string $repositoryId, string $repository, string $hookId, string $callbackUrl, ?string $credentialProfileId ): RepositoryWebhookOperationResult {
		++$this->calls;
		if ( null !== $this->duringRemove ) {
			$callback           = $this->duringRemove;
			$this->duringRemove = null;
			$callback();
		}
		if ( $this->throwRemove ) {
			throw new \RuntimeException( 'Provider response was lost after invocation.' );
		}

		return $this->operation( $this->removeState, 'succeeded' === $this->removeState ? 'absence_confirmed' : 'remove_ambiguous', 'succeeded' === $this->removeState ? 'absent' : 'unknown' );
	}

	public function test( string $repositoryId, string $repository, string $hookId, string $callbackUrl, ?string $credentialProfileId ): RepositoryWebhookOperationResult {
		++$this->calls;
		$this->credentialId = $credentialProfileId;

		return $this->operation( 'succeeded', 'ping_verified', 'verified' );
	}

	private function fitness( ?string $credentialId ): RepositoryWebhookFitnessResult {
		++$this->calls;
		$this->credentialId = $credentialId;

		return new RepositoryWebhookFitnessResult( 'supported', $this->fitnessSuitability, 'unknown', 'unknown_by_design', 'authority_unknown', '2026-08-02T00:00:00Z', 'Confirm the exact operation before continuing.' );
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
	public array $profiles = array();
	/** @var array<string,array<string,mixed>> */
	public array $materials             = array();
	public string $savedSecret          = '';
	public bool $throwMaterialAfterSave = false;

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
		if ( $this->throwMaterialAfterSave && array() !== $this->profiles ) {
			$this->throwMaterialAfterSave  = false;
			$profileId                     = (string) array_key_first( $this->profiles );
			$rotated                       = $this->profile( $profileId, 2, 'rotated-before-snapshot' );
			$this->profiles[ $profileId ]  = $rotated;
			$this->materials[ $profileId ] = $rotated;
			throw new \RuntimeException( 'Material snapshot failed after concurrent rotation.' );
		}

		return array() === $this->materials ? $this->profiles : $this->materials;
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
		unset( $this->materials[ $id ] );

		return true;
	}

	public function deleteWebhookIfRevision( ProviderCode|string $provider, string $id, int $expectedRevision ): bool {
		$current = $this->webhookMaterials( $provider )[ $id ] ?? null;
		if ( ! is_array( $current ) || $expectedRevision !== (int) ( $current['revision'] ?? 0 ) ) {
			return false;
		}

		return $this->deleteWebhook( $provider, $id );
	}

	/** @return array<string,mixed> */
	public function profile( string $id, int $revision, string $secret = 'ssssssssssssssssssssssssssssssss' ): array {
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
			'secret'       => $secret,
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
