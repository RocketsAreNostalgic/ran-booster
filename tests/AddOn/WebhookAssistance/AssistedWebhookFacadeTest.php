<?php

declare(strict_types=1);

namespace RAN\Tests\AddOn\WebhookAssistance;

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
use RAN\Tests\RepositoryProvider\Support\InertWebhookPolicy;
use RAN\Tests\Support\FitnessOnlyWebhookManagementCapabilityProvider;

require_once dirname( __DIR__, 2 ) . '/Support/WebhookManagementCapabilityProviders.php';

final class AssistedWebhookFacadeTest extends TestCase {

	public function test_bootstrap_publishes_the_exact_cut_and_removes_cleanup(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local source contract inspection.
		$bootstrap = file_get_contents( dirname( __DIR__, 3 ) . '/ran-booster.php' );
		self::assertIsString( $bootstrap );
		self::assertStringContainsString( "RAN_BOOSTER_PROVIDER_API_VERSION', 14", $bootstrap );
		self::assertStringContainsString( "RAN_BOOSTER_ADDON_API_VERSION', 17", $bootstrap );
		self::assertStringNotContainsString( 'RAN_BOOSTER_WEBHOOK_CLEANUP_API_VERSION', $bootstrap );
		self::assertStringNotContainsString( 'ran_booster_webhook_cleanup_ready', $bootstrap );
		self::assertFalse( interface_exists( 'RAN\\AddOn\\WebhookAssistance\\WebhookCleanupFacade' ) );
		self::assertFalse( class_exists( 'RAN\\AddOn\\WebhookAssistance\\ProvisioningCallbackResult' ) );
	}

	public function test_setup_keeps_both_secrets_inside_core_and_provider(): void {
		$secrets  = new FixedFacadeSecretsFile();
		$provider = new FixedWebhookProvider();
		$facade   = $this->facade( $secrets, $provider );
		$target   = $facade->target( 'gh', '101' );
		self::assertInstanceOf( AssistanceTarget::class, $target );

		$result = $facade->setup( $target, 'profile_1', 'good' );

		self::assertTrue( $result->succeeded() );
		self::assertSame( '55', $result->hook_id() );
		self::assertNotNull( $result->profile() );
		self::assertSame( $secrets->saved_secret, $provider->signing_secret );
		self::assertSame( 'profile_1', $provider->credential_id );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Test-only secret-containment assertion.
		self::assertStringNotContainsString( $secrets->saved_secret, json_encode( $result->to_array(), JSON_THROW_ON_ERROR ) );
	}

	public function test_setup_requires_a_saved_credential(): void {
		$secrets  = new FixedFacadeSecretsFile();
		$provider = new FixedWebhookProvider();
		$facade   = $this->facade( $secrets, $provider );
		$target   = $facade->target( 'gh', '101' );
		self::assertNotNull( $target );

		self::assertSame( 'operation_unauthorized', $facade->setup( $target, null, 'good' )->code() );
	}

	public function test_setup_rejects_an_inapplicable_selected_signing_profile_before_provider_work(): void {
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

	public function test_setup_rejects_a_constant_signing_profile_while_keeping_it_available_for_inbound_verification(): void {
		$secrets                        = new FixedFacadeSecretsFile();
		$secrets->profiles['constant']  = array_merge(
			$secrets->profile( 'constant', 1, 'constant-secret' ),
			array(
				'label'     => 'Deployment signing secret',
				'source'    => 'constant',
				'immutable' => true,
			)
		);
		$secrets->materials['constant'] = $secrets->profiles['constant'];
		$provider                       = new FixedWebhookProvider();
		$facade                         = $this->facade( $secrets, $provider );
		$target                         = $facade->target( 'gh', '101' );
		self::assertNotNull( $target );

		self::assertSame( array(), $facade->webhook_profile_choices( 'gh', '101' ) );
		$result = $facade->setup( $target, 'profile_1', 'good', 'constant' );
		self::assertSame( 'operation_unauthorized', $result->code() );
		self::assertSame( '', $provider->signing_secret );
		self::assertCount( 1, $secrets->profiles );
	}

	public function test_webhook_profile_choices_require_the_current_repository_target(): void {
		$secrets                          = new FixedFacadeSecretsFile();
		$profile_id                       = 'wh_' . str_repeat( 'a', 24 );
		$secrets->profiles[ $profile_id ] = $secrets->profile( $profile_id, 1 ) + array( 'label' => 'Signing profile' );
		$facade                           = $this->facade( $secrets, new FixedWebhookProvider() );

		self::assertSame( array(), $facade->webhook_profile_choices( 'gh', '102' ) );
		self::assertSame(
			array(
				array(
					'id'    => $profile_id,
					'label' => 'Signing profile',
					'scope' => 'repository',
				),
			),
			$facade->webhook_profile_choices( 'gh', '101' )
		);
	}

	public function test_assessment_requires_a_saved_credential(): void {
		$secrets  = new FixedFacadeSecretsFile();
		$provider = new FixedWebhookProvider();
		$facade   = $this->facade( $secrets, $provider );
		$target   = $facade->target( 'gh', '101' );
		self::assertNotNull( $target );

		$result = $facade->assess_setup( $target, null, 'good' );

		self::assertSame( 'unknown', $result->to_array()['support'] );
		self::assertNull( $provider->credential_id );
		self::assertSame( array(), $secrets->profiles );
	}

	public function test_provider_opaque_nested_repository_locator_remains_eligible(): void {
		$facade = $this->facade(
			new FixedFacadeSecretsFile(),
			new FixedWebhookProvider(),
			repository: 'group/subgroup/package'
		);

		self::assertNotNull( $facade->target( 'gh', '101' ) );
	}

	public function test_setup_exception_after_provider_invocation_retains_recovery_profile(): void {
		$secrets               = new FixedFacadeSecretsFile();
		$provider              = new FixedWebhookProvider();
		$provider->throw_setup = true;
		$facade                = $this->facade( $secrets, $provider );
		$target                = $facade->target( 'gh', '101' );
		self::assertNotNull( $target );

		$result = $facade->setup( $target, 'profile_1', 'good' );

		self::assertSame( 'partial', $result->state() );
		self::assertSame( 'setup_outcome_unknown', $result->code() );
		self::assertNotNull( $result->profile() );
		self::assertCount( 1, $secrets->profiles );
	}

	public function test_failed_setup_cannot_delete_a_profile_rotated_during_the_provider_request(): void {
		$secrets               = new FixedFacadeSecretsFile();
		$provider              = new FixedWebhookProvider();
		$provider->setup_state = 'failed';
		$facade                = $this->facade( $secrets, $provider );
		$target                = $facade->target( 'gh', '101' );
		self::assertNotNull( $target );
		$provider->during_setup = static function () use ( $secrets ): void {
			$profile_id                        = (string) array_key_first( $secrets->profiles );
			$rotated                           = $secrets->profile( $profile_id, 2, 'rotated-secret' );
			$secrets->profiles[ $profile_id ]  = $rotated;
			$secrets->materials[ $profile_id ] = $rotated;
		};

		$result = $facade->setup( $target, 'profile_1', 'good' );

		self::assertSame( 'partial', $result->state() );
		self::assertSame( 'profile_cleanup_failed', $result->code() );
		self::assertSame( 2, $result->profile()?->revision() );
		self::assertSame( 'rotated-secret', $secrets->materials[ $result->profile()->id() ]['secret'] ?? null );
	}

	public function test_pre_provider_failure_cannot_delete_a_profile_rotated_after_creation(): void {
		$secrets                            = new FixedFacadeSecretsFile();
		$secrets->throw_material_after_save = true;
		$provider                           = new FixedWebhookProvider();
		$facade                             = $this->facade( $secrets, $provider );
		$target                             = $facade->target( 'gh', '101' );
		self::assertNotNull( $target );

		$result = $facade->setup( $target, 'profile_1', 'good' );

		self::assertSame( 'partial', $result->state() );
		self::assertSame( 'profile_cleanup_failed', $result->code() );
		self::assertSame( 2, $result->profile()?->revision() );
		self::assertSame( 1, $provider->calls, 'Only the identity assessment may run before the local snapshot failure.' );
		self::assertCount( 1, $secrets->profiles );
	}

	public function test_same_target_setup_cannot_overlap_with_a_reusable_nonce(): void {
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
		$nested                 = null;
		$provider->during_setup = static function () use ( $facade, $target, &$nested ): void {
			$nested = $facade->setup( $target, 'profile_1', 'good' );
		};

		$first = $facade->setup( $target, 'profile_1', 'good' );

		self::assertTrue( $first->succeeded() );
		self::assertInstanceOf( RepositoryWebhookOperationResult::class, $nested );
		self::assertSame( 'operation_busy', $nested->code() );
		self::assertCount( 1, $secrets->profiles );
		self::assertThat( $held, self::identicalTo( false ) );
	}

	public function test_separate_request_facades_contend_on_the_same_target_lock(): void {
		$secrets      = new FixedFacadeSecretsFile();
		$provider     = new FixedWebhookProvider();
		$held         = false;
		$acquire      = static function () use ( &$held ): bool {
			if ( $held ) {
				return false;
			}
			$held = true;

			return true;
		};
		$release      = static function () use ( &$held ): bool {
			$held = false;

			return true;
		};
		$first_facade = $this->facade( $secrets, $provider, $acquire, $release );
		$other_facade = $this->facade( $secrets, $provider, $acquire, $release );
		$first_target = $first_facade->target( 'gh', '101' );
		$other_target = $other_facade->target( 'gh', '101' );
		self::assertNotNull( $first_target );
		self::assertNotNull( $other_target );
		$nested                 = null;
		$provider->during_setup = static function () use ( $other_facade, $other_target, &$nested ): void {
			$nested = $other_facade->setup( $other_target, 'profile_1', 'good' );
		};

		self::assertTrue( $first_facade->setup( $first_target, 'profile_1', 'good' )->succeeded() );
		self::assertInstanceOf( RepositoryWebhookOperationResult::class, $nested );
		self::assertSame( 'operation_busy', $nested->code() );
		self::assertCount( 1, $secrets->profiles );
		self::assertThat( $held, self::identicalTo( false ) );
	}

	public function test_lock_release_failure_does_not_overwrite_ambiguous_recovery_evidence(): void {
		$secrets                = new FixedFacadeSecretsFile();
		$provider               = new FixedWebhookProvider();
		$provider->remove_state = 'ambiguous';
		$facade                 = $this->facade( $secrets, $provider, static fn (): bool => true, static fn (): bool => false );
		$target                 = $facade->target( 'gh', '101' );
		self::assertNotNull( $target );
		$profile_id                       = 'wh_' . str_repeat( 'a', 24 );
		$secrets->profiles[ $profile_id ] = $secrets->profile( $profile_id, 1 );

		$result = $facade->remove( $target, 'profile_1', '55', $profile_id, 1, 'good' );

		self::assertSame( 'ambiguous', $result->state() );
		self::assertSame( 'remove_ambiguous', $result->code() );
		self::assertSame( '55', $result->hook_id() );
		self::assertSame( $profile_id, $result->profile()?->id() );
		self::assertArrayHasKey( $profile_id, $secrets->profiles );
	}

	public function test_lock_release_failure_does_not_overwrite_partial_setup_recovery_evidence(): void {
		$secrets               = new FixedFacadeSecretsFile();
		$provider              = new FixedWebhookProvider();
		$provider->throw_setup = true;
		$facade                = $this->facade( $secrets, $provider, static fn (): bool => true, static fn (): bool => false );
		$target                = $facade->target( 'gh', '101' );
		self::assertNotNull( $target );

		$result = $facade->setup( $target, 'profile_1', 'good' );

		self::assertSame( 'partial', $result->state() );
		self::assertSame( 'setup_outcome_unknown', $result->code() );
		self::assertNotNull( $result->profile() );
		self::assertCount( 1, $secrets->profiles );
	}

	public function test_lock_release_failure_preserves_confirmed_absence_after_profile_cleanup(): void {
		$secrets  = new FixedFacadeSecretsFile();
		$provider = new FixedWebhookProvider();
		$facade   = $this->facade( $secrets, $provider, static fn (): bool => true, static fn (): bool => false );
		$target   = $facade->target( 'gh', '101' );
		self::assertNotNull( $target );
		$profile_id                       = 'wh_' . str_repeat( 'a', 24 );
		$secrets->profiles[ $profile_id ] = $secrets->profile( $profile_id, 1 );

		$result = $facade->remove( $target, 'profile_1', '55', $profile_id, 1, 'good' );

		self::assertTrue( $result->confirms_absence() );
		self::assertSame( 'absence_confirmed', $result->code() );
		self::assertArrayNotHasKey( $profile_id, $secrets->profiles );
	}

	public function test_lock_release_failure_is_reported_after_an_otherwise_successful_setup(): void {
		$secrets  = new FixedFacadeSecretsFile();
		$provider = new FixedWebhookProvider();
		$facade   = $this->facade( $secrets, $provider, static fn (): bool => true, static fn (): bool => false );
		$target   = $facade->target( 'gh', '101' );
		self::assertNotNull( $target );

		$result = $facade->setup( $target, 'profile_1', 'good' );

		self::assertSame( 'partial', $result->state() );
		self::assertSame( 'operation_lock_release_failed', $result->code() );
		self::assertSame( '55', $result->hook_id() );
		self::assertNotNull( $result->profile() );
	}

	public function test_mutation_stops_when_same_request_fitness_cannot_rebind_repository_identity(): void {
		$secrets                       = new FixedFacadeSecretsFile();
		$provider                      = new FixedWebhookProvider();
		$provider->fitness_suitability = 'insufficient';
		$facade                        = $this->facade( $secrets, $provider );
		$target                        = $facade->target( 'gh', '101' );
		self::assertNotNull( $target );

		$result = $facade->setup( $target, 'profile_1', 'good' );

		self::assertSame( 'repository_identity_unconfirmed', $result->code() );
		self::assertSame( 1, $provider->calls, 'Only the read-only identity assessment may run.' );
		self::assertSame( '', $provider->signing_secret );
		self::assertSame( array(), $secrets->profiles );
	}

	public function test_reconfigure_uses_metadata_and_secret_from_one_material_snapshot(): void {
		$secrets  = new FixedFacadeSecretsFile();
		$provider = new FixedWebhookProvider();
		$facade   = $this->facade( $secrets, $provider );
		$target   = $facade->target( 'gh', '101' );
		self::assertNotNull( $target );
		$profile_id                        = 'wh_' . str_repeat( 'a', 24 );
		$secrets->profiles[ $profile_id ]  = $secrets->profile( $profile_id, 1, 'old-secret' );
		$secrets->materials[ $profile_id ] = $secrets->profile( $profile_id, 2, 'rotated-secret' );

		$result = $facade->reconfigure( $target, 'profile_1', '55', $profile_id, 2, 'good' );

		self::assertTrue( $result->succeeded() );
		self::assertSame( 2, $result->profile()?->revision() );
		self::assertSame( 'rotated-secret', $provider->signing_secret );
	}

	public function test_reconfigure_exception_after_provider_invocation_retains_recovery_evidence(): void {
		$secrets                     = new FixedFacadeSecretsFile();
		$provider                    = new FixedWebhookProvider();
		$provider->throw_reconfigure = true;
		$facade                      = $this->facade( $secrets, $provider );
		$target                      = $facade->target( 'gh', '101' );
		self::assertNotNull( $target );
		$profile_id                        = 'wh_' . str_repeat( 'a', 24 );
		$secrets->profiles[ $profile_id ]  = $secrets->profile( $profile_id, 1, 'current-secret' );
		$secrets->materials[ $profile_id ] = $secrets->profiles[ $profile_id ];

		$result = $facade->reconfigure( $target, 'profile_1', '55', $profile_id, 1, 'good' );

		self::assertSame( 'ambiguous', $result->state() );
		self::assertSame( 'reconfigure_outcome_unknown', $result->code() );
		self::assertSame( '55', $result->hook_id() );
		self::assertSame( $profile_id, $result->profile()?->id() );
		self::assertSame( 'current-secret', $provider->signing_secret );
	}

	public function test_stale_target_nonce_and_profile_fail_before_provider_work(): void {
		$secrets  = new FixedFacadeSecretsFile();
		$provider = new FixedWebhookProvider();
		$facade   = $this->facade( $secrets, $provider );
		$target   = $facade->target( 'gh', '101' );
		self::assertNotNull( $target );
		$setup = $facade->setup( $target, 'profile_1', 'bad' );
		self::assertSame( 'operation_unauthorized', $setup->code() );
		self::assertSame( 0, $provider->calls );

		$profile_id                       = 'wh_' . str_repeat( 'a', 24 );
		$secrets->profiles[ $profile_id ] = $secrets->profile( $profile_id, 2 );
		$checked                          = $facade->check( $target, 'profile_1', '55', $profile_id, 1, 'good' );
		self::assertSame( 'operation_unauthorized', $checked->code() );
		self::assertSame( 0, $provider->calls );
	}

	public function test_remove_releases_only_after_authoritative_absence(): void {
		$secrets  = new FixedFacadeSecretsFile();
		$provider = new FixedWebhookProvider();
		$facade   = $this->facade( $secrets, $provider );
		$target   = $facade->target( 'gh', '101' );
		self::assertNotNull( $target );
		$profile_id                       = 'wh_' . str_repeat( 'a', 24 );
		$secrets->profiles[ $profile_id ] = $secrets->profile( $profile_id, 1 );

		$provider->remove_state = 'ambiguous';
		self::assertSame( 'ambiguous', $facade->remove( $target, 'profile_1', '55', $profile_id, 1, 'good' )->state() );
		self::assertArrayHasKey( $profile_id, $secrets->profiles );

		$provider->remove_state = 'succeeded';
		self::assertTrue( $facade->remove( $target, 'profile_1', '55', $profile_id, 1, 'good' )->confirms_absence() );
		self::assertArrayNotHasKey( $profile_id, $secrets->profiles );
	}

	public function test_remove_does_not_delete_a_profile_rotated_while_the_provider_request_was_in_flight(): void {
		$secrets  = new FixedFacadeSecretsFile();
		$provider = new FixedWebhookProvider();
		$facade   = $this->facade( $secrets, $provider );
		$target   = $facade->target( 'gh', '101' );
		self::assertNotNull( $target );
		$profile_id                        = 'wh_' . str_repeat( 'a', 24 );
		$secrets->profiles[ $profile_id ]  = $secrets->profile( $profile_id, 1, 'original-secret' );
		$secrets->materials[ $profile_id ] = $secrets->profiles[ $profile_id ];
		$provider->during_remove           = static function () use ( $secrets, $profile_id ): void {
			$rotated                           = $secrets->profile( $profile_id, 2, 'rotated-secret' );
			$secrets->profiles[ $profile_id ]  = $rotated;
			$secrets->materials[ $profile_id ] = $rotated;
		};

		$result = $facade->remove( $target, 'profile_1', '55', $profile_id, 1, 'good' );

		self::assertSame( 'partial', $result->state() );
		self::assertSame( 'local_profile_release_failed', $result->code() );
		self::assertSame( 2, $secrets->profiles[ $profile_id ]['revision'] );
		self::assertSame( 'rotated-secret', $secrets->materials[ $profile_id ]['secret'] );
	}

	public function test_remove_exception_after_provider_invocation_retains_recovery_evidence(): void {
		$secrets                = new FixedFacadeSecretsFile();
		$provider               = new FixedWebhookProvider();
		$provider->throw_remove = true;
		$facade                 = $this->facade( $secrets, $provider );
		$target                 = $facade->target( 'gh', '101' );
		self::assertNotNull( $target );
		$profile_id                       = 'wh_' . str_repeat( 'a', 24 );
		$secrets->profiles[ $profile_id ] = $secrets->profile( $profile_id, 1 );

		$result = $facade->remove( $target, 'profile_1', '55', $profile_id, 1, 'good' );

		self::assertSame( 'ambiguous', $result->state() );
		self::assertSame( 'remove_outcome_unknown', $result->code() );
		self::assertSame( '55', $result->hook_id() );
		self::assertSame( $profile_id, $result->profile()?->id() );
		self::assertArrayHasKey( $profile_id, $secrets->profiles );
	}

	public function test_fitness_is_explicit_and_bound_to_the_saved_profile(): void {
		$secrets  = new FixedFacadeSecretsFile();
		$provider = new FixedWebhookProvider();
		$facade   = $this->facade( $secrets, $provider );
		$target   = $facade->target( 'gh', '101' );
		self::assertNotNull( $target );
		$result = $facade->assess_setup( $target, 'profile_1', 'good' )->to_array();
		self::assertSame( 'supported', $result['support'] );
		self::assertSame( 1, $provider->calls );
		self::assertSame( 'assessment_unauthorized', $facade->assess_setup( $target, 'missing', 'good' )->to_array()['code'] );
		self::assertSame( 1, $provider->calls );
	}

	public function test_incomplete_webhook_aggregate_refuses_assessment_before_provider_work(): void {
		$secrets  = new FixedFacadeSecretsFile();
		$provider = new FitnessOnlyWebhookManagementCapabilityProvider( 'gh', 'Partial fixture' );
		$facade   = $this->facade( $secrets, $provider );
		$target   = $facade->target( 'gh', '101' );
		self::assertNotNull( $target );

		$result = $facade->assess_setup( $target, 'profile_1', 'good' )->to_array();

		self::assertSame( 'assessment_unavailable', $result['code'] );
		self::assertSame( 0, $provider->provider_operation_calls );
	}

	private function facade( FixedFacadeSecretsFile $secrets, RepositoryProvider $provider, ?callable $acquire_lock = null, ?callable $release_lock = null, string $repository = 'owner/example' ): AssistedWebhookFacade {
		$registry = new ProviderRegistry( array( $provider ) );
		$package  = new FixedFacadePackage( new ManagedRepository( 'gh', $repository, '101', 'main' ) );

		return new AssistedWebhookFacade(
			new WebhookAssistanceReadinessEvaluator( new FixedPluginRepository( $package ), new FixedThemeRepository(), $secrets, new FixedDatabase(), static fn (): bool => true ),
			$secrets,
			$registry,
			static fn (): bool => true,
			static fn (): string => 'https://site.example/wp-json/ran-booster/v1/webhooks/gh',
			static fn ( string $nonce ): bool => 'good' === $nonce,
			$acquire_lock ?? static fn (): bool => true,
			$release_lock ?? static fn (): bool => true
		);
	}
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- This concrete test collaborator stays beside the contract it exercises.
final class FixedWebhookProvider implements RepositoryProvider, RepositoryWebhookFitness, RepositoryWebhookManagement, WebhookNormalizer {
	public const OPERATION             = 'repository-webhook-management';
	public const VERSION               = 1;
	public int $calls                  = 0;
	public ?string $credential_id      = null;
	public string $signing_secret      = '';
	public string $remove_state        = 'succeeded';
	public string $setup_state         = 'succeeded';
	public string $fitness_suitability = 'unknown';
	public bool $throw_setup           = false;
	public bool $throw_reconfigure     = false;
	public bool $throw_remove          = false;
	/** @var callable(): void|null */
	public $during_setup = null;
	/** @var callable(): void|null */
	public $during_remove = null;

	public function get_metadata(): ProviderMetadata {
		return new ProviderMetadata( ProviderCode::parse( 'gh' ), 'GitHub fixture', 'https://example.test/', 'Owner' );
	}

	public function get_provider_diagnostics(): ProviderDiagnostics {
		return new class() implements ProviderDiagnostics {
			// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundInImplementedInterface -- The fixture implementation of diagnose retains the production method contract; these inputs do not affect this controlled result.
			public function diagnose( ProviderDiagnosticRequest $request ): array {
				return array();
			}
		};
	}

	public function get_webhook_policy(): \RAN\RepositoryProvider\ProviderWebhookPolicy {
		return new InertWebhookPolicy( ProviderCode::parse( 'gh' ) );
	}

	public function diagnose_webhook_readiness(): \RAN\RepositoryProvider\ProviderDiagnosticResult {
		return new \RAN\RepositoryProvider\ProviderDiagnosticResult( \RAN\RepositoryProvider\ProviderDiagnosticResult::PASSED, 'fixture_webhook_ready', 'Fixture is ready.', 'No action is required.' );
	}

	public function normalize_webhook( WebhookRequest $request ): WebhookEnvelope {
		unset( $request );

		return WebhookEnvelope::ignored();
	}

	public function resolve_repository( RepositoryLookupRequest $request ): RepositoryDescriptor {
		throw new \RuntimeException( 'not used' );
	}

	public function prepare_archive( ArchiveRequest $request ): PreparedArchive {
		throw new \RuntimeException( 'not used' );
	}

	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundInImplementedInterfaceBeforeLastUsed -- The fixture implementation of assess_setup retains the production method contract; these inputs do not affect this controlled result.
	public function assess_setup( string $repository_id, string $repository, ?string $credential_profile_id ): RepositoryWebhookFitnessResult {
		return $this->fitness( $credential_profile_id );
	}

	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundInImplementedInterfaceAfterLastUsed,Generic.CodeAnalysis.UnusedFunctionParameter.FoundInImplementedInterfaceBeforeLastUsed -- The fixture implementation of assess_check retains the production method contract; these inputs do not affect this controlled result.
	public function assess_check( string $repository_id, string $repository, ?string $credential_profile_id, string $hook_id ): RepositoryWebhookFitnessResult {
		return $this->fitness( $credential_profile_id );
	}

	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundInImplementedInterfaceAfterLastUsed,Generic.CodeAnalysis.UnusedFunctionParameter.FoundInImplementedInterfaceBeforeLastUsed -- The fixture implementation of assess_reconfigure retains the production method contract; these inputs do not affect this controlled result.
	public function assess_reconfigure( string $repository_id, string $repository, ?string $credential_profile_id, string $hook_id ): RepositoryWebhookFitnessResult {
		return $this->fitness( $credential_profile_id );
	}

	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundInImplementedInterfaceAfterLastUsed,Generic.CodeAnalysis.UnusedFunctionParameter.FoundInImplementedInterfaceBeforeLastUsed -- The fixture implementation of assess_remove retains the production method contract; these inputs do not affect this controlled result.
	public function assess_remove( string $repository_id, string $repository, ?string $credential_profile_id, string $hook_id ): RepositoryWebhookFitnessResult {
		return $this->fitness( $credential_profile_id );
	}

	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundInImplementedInterfaceAfterLastUsed,Generic.CodeAnalysis.UnusedFunctionParameter.FoundInImplementedInterfaceBeforeLastUsed -- The fixture implementation of assess_test retains the production method contract; these inputs do not affect this controlled result.
	public function assess_test( string $repository_id, string $repository, ?string $credential_profile_id, string $hook_id ): RepositoryWebhookFitnessResult {
		return $this->fitness( $credential_profile_id );
	}

	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundInImplementedInterfaceBeforeLastUsed -- The fixture implementation of setup retains the production method contract; these inputs do not affect this controlled result.
	public function setup( string $repository_id, string $repository, string $callback_url, ?string $credential_profile_id, string $signing_secret ): RepositoryWebhookOperationResult {
		++$this->calls;
		$this->credential_id  = $credential_profile_id;
		$this->signing_secret = $signing_secret;
		if ( null !== $this->during_setup ) {
			$callback           = $this->during_setup;
			$this->during_setup = null;
			$callback();
		}
		if ( $this->throw_setup ) {
			throw new \RuntimeException( 'Provider response was lost after invocation.' );
		}

		return $this->operation( $this->setup_state, 'succeeded' === $this->setup_state ? 'configured_pending_delivery' : 'setup_failed' );
	}

	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundInImplementedInterfaceAfterLastUsed -- The fixture implementation of check retains the production method contract; these inputs do not affect this controlled result.
	public function check( string $repository_id, string $repository, string $hook_id, string $callback_url, ?string $credential_profile_id ): RepositoryWebhookOperationResult {
		++$this->calls;

		return $this->operation( 'succeeded', 'configuration_confirmed' );
	}

	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundInImplementedInterfaceBeforeLastUsed -- The fixture implementation of reconfigure retains the production method contract; these inputs do not affect this controlled result.
	public function reconfigure( string $repository_id, string $repository, string $hook_id, string $callback_url, ?string $credential_profile_id, string $signing_secret ): RepositoryWebhookOperationResult {
		++$this->calls;
		$this->signing_secret = $signing_secret;
		if ( $this->throw_reconfigure ) {
			throw new \RuntimeException( 'Provider response was lost after invocation.' );
		}

		return $this->operation( 'succeeded', 'configured_pending_delivery' );
	}

	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundInImplementedInterfaceAfterLastUsed -- The fixture implementation of remove retains the production method contract; these inputs do not affect this controlled result.
	public function remove( string $repository_id, string $repository, string $hook_id, string $callback_url, ?string $credential_profile_id ): RepositoryWebhookOperationResult {
		++$this->calls;
		if ( null !== $this->during_remove ) {
			$callback            = $this->during_remove;
			$this->during_remove = null;
			$callback();
		}
		if ( $this->throw_remove ) {
			throw new \RuntimeException( 'Provider response was lost after invocation.' );
		}

		return $this->operation( $this->remove_state, 'succeeded' === $this->remove_state ? 'absence_confirmed' : 'remove_ambiguous', 'succeeded' === $this->remove_state ? 'absent' : 'unknown' );
	}

	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundInImplementedInterfaceBeforeLastUsed -- The fixture implementation of test retains the production method contract; these inputs do not affect this controlled result.
	public function test( string $repository_id, string $repository, string $hook_id, string $callback_url, ?string $credential_profile_id ): RepositoryWebhookOperationResult {
		++$this->calls;
		$this->credential_id = $credential_profile_id;

		return $this->operation( 'succeeded', 'ping_verified', 'verified' );
	}

	private function fitness( ?string $credential_id ): RepositoryWebhookFitnessResult {
		++$this->calls;
		$this->credential_id = $credential_id;

		return new RepositoryWebhookFitnessResult( 'supported', $this->fitness_suitability, 'unknown', 'unknown_by_design', 'authority_unknown', '2026-08-02T00:00:00Z', 'Confirm the exact operation before continuing.' );
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

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- This concrete test collaborator stays beside the contract it exercises.
final class FixedFacadeSecretsFile extends SecretsFile {
	/** @var array<string,array<string,mixed>> */
	public array $profiles = array();
	/** @var array<string,array<string,mixed>> */
	public array $materials                = array();
	public string $saved_secret            = '';
	public bool $throw_material_after_save = false;

	public function assert_managed_storage_ready(): void {
	}

	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundInExtendedClass -- The fixture implementation of credential_profiles retains the production method contract; these inputs do not affect this controlled result.
	public function credential_profiles( ProviderCode|string $provider ): array {
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

	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundInExtendedClass -- The fixture implementation of webhook_profiles retains the production method contract; these inputs do not affect this controlled result.
	public function webhook_profiles( ProviderCode|string $provider ): array {
		return $this->profiles;
	}

	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundInExtendedClass -- The fixture implementation of webhook_materials retains the production method contract; these inputs do not affect this controlled result.
	public function webhook_materials( ProviderCode|string $provider ): array {
		if ( $this->throw_material_after_save && array() !== $this->profiles ) {
			$this->throw_material_after_save = false;
			$profile_id                      = (string) array_key_first( $this->profiles );
			$rotated                         = $this->profile( $profile_id, 2, 'rotated-before-snapshot' );
			$this->profiles[ $profile_id ]   = $rotated;
			$this->materials[ $profile_id ]  = $rotated;
			throw new \RuntimeException( 'Material snapshot failed after concurrent rotation.' );
		}

		return array() === $this->materials ? $this->profiles : $this->materials;
	}

	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundInExtendedClassBeforeLastUsed -- The fixture implementation of save_webhook retains the production method contract; these inputs do not affect this controlled result.
	public function save_webhook( ProviderCode|string $provider, ?string $id, array $metadata, ?string $secret ): string {
		$id                  ??= 'wh_' . str_repeat( 'a', 24 );
		$this->saved_secret    = (string) $secret;
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

	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundInExtendedClassBeforeLastUsed -- The fixture implementation of delete_webhook retains the production method contract; these inputs do not affect this controlled result.
	public function delete_webhook( ProviderCode|string $provider, string $id ): bool {
		unset( $this->profiles[ $id ] );
		unset( $this->materials[ $id ] );

		return true;
	}

	public function delete_webhook_if_revision( ProviderCode|string $provider, string $id, int $expected_revision ): bool {
		$current = $this->webhook_materials( $provider )[ $id ] ?? null;
		if ( ! is_array( $current ) || (int) ( $current['revision'] ?? 0 ) !== $expected_revision ) {
			return false;
		}

		return $this->delete_webhook( $provider, $id );
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

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- This concrete test collaborator stays beside the contract it exercises.
final class FixedPluginRepository extends PluginRepository {
	public function __construct( private FixedFacadePackage $package ) {
	}

	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundInExtendedClass -- The fixture implementation of all_deployment_plugins retains the production method contract; these inputs do not affect this controlled result.
	public function all_deployment_plugins( ?\RAN\PackageSource $source = null ): array {
		return array( (string) $this->package->get_identifier() => $this->package );
	}
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- This concrete test collaborator stays beside the contract it exercises.
final class FixedThemeRepository extends ThemeRepository {
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundInExtendedClass -- The fixture implementation of all_deployment_themes retains the production method contract; these inputs do not affect this controlled result.
	public function all_deployment_themes( ?\RAN\PackageSource $source = null ): array {
		return array();
	}
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- This concrete test collaborator stays beside the contract it exercises.
final class FixedFacadePackage extends AbstractPackage {
	public function __construct( ?ManagedRepository $repository = null ) {
		TestCase::assertNotNull( $repository );
		$this->repository        = $repository;
		$this->deployment_policy = DeploymentPolicy::MANUAL;
	}

	public function get_identifier(): mixed {
		return 'plugin/example.php';
	}

	public function get_provider_repository_id(): string {
		return '101';
	}

	protected function runtime_slug(): string {
		return 'example';
	}
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- This concrete test collaborator stays beside the contract it exercises.
final class FixedDatabase extends Database {
	public function require_ready(): void {
	}
}
