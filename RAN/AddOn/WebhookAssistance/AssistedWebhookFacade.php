<?php

declare(strict_types=1);

namespace RAN\AddOn\WebhookAssistance;

use RAN\RepositoryProvider\ProviderCode;
use RAN\RepositoryProvider\ProviderRegistry;
use RAN\RepositoryProvider\RepositoryWebhookFitness;
use RAN\RepositoryProvider\RepositoryWebhookFitnessResult;
use RAN\RepositoryProvider\RepositoryWebhookManagement;
use RAN\RepositoryProvider\RepositoryWebhookOperationResult;
use RAN\RepositoryProvider\WebhookNormalizer;
use RAN\Secrets\SecretsFile;

/** Core-governed repository-webhook-management/2 facade. */
final class AssistedWebhookFacade implements WebhookAssistanceFacade {

	/** @var \Closure(): bool */
	private \Closure $canManage;

	/** @var \Closure(string): string */
	private \Closure $endpoint;

	/** @var \Closure(string,string): bool */
	private \Closure $verifyNonce;
	/** @var \Closure(string): bool */
	private \Closure $acquireLock;
	/** @var \Closure(string): bool */
	private \Closure $releaseLock;
	/** @var array<string,true> */
	private array $heldLocks = array();

	/** @param callable(): bool|null $canManage @param callable(string): string|null $endpoint @param callable(string,string): bool|null $verifyNonce @param callable(string): bool|null $acquireLock @param callable(string): bool|null $releaseLock */
	public function __construct(
		private WebhookAssistanceReadinessEvaluator $readinessEvaluator,
		private SecretsFile $secrets,
		private ProviderRegistry $providers,
		?callable $canManage = null,
		?callable $endpoint = null,
		?callable $verifyNonce = null,
		?callable $acquireLock = null,
		?callable $releaseLock = null
	) {
		$this->canManage   = null === $canManage
			? static fn (): bool => current_user_can( 'manage_options' )
			: \Closure::fromCallable( $canManage );
		$this->endpoint    = null === $endpoint
			? static fn ( string $providerCode ): string => rest_url( 'ran-booster/v1/webhooks/' . rawurlencode( $providerCode ) )
			: \Closure::fromCallable( $endpoint );
		$this->verifyNonce = null === $verifyNonce
			? static fn ( string $nonce, string $action ): bool => 1 === wp_verify_nonce( $nonce, $action )
			: \Closure::fromCallable( $verifyNonce );
		$this->acquireLock = null === $acquireLock
			? static function ( string $name ): bool {
				global $wpdb;
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- MySQL advisory locks are connection-local and have no persistent cacheable state.
				$result = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $name ) );

				return '' === trim( (string) ( $wpdb->last_error ?? '' ) ) && '1' === (string) $result;
			}
			: \Closure::fromCallable( $acquireLock );
		$this->releaseLock = null === $releaseLock
			? static function ( string $name ): bool {
				global $wpdb;
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- MySQL advisory locks are connection-local and have no persistent cacheable state.
				$result = $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );

				return '' === trim( (string) ( $wpdb->last_error ?? '' ) ) && '1' === (string) $result;
			}
			: \Closure::fromCallable( $releaseLock );
	}

	public function readiness( string $providerCode ): AssistanceReadiness {
		$providerCode = $this->providerCode( $providerCode );

		return $this->readinessEvaluator->evaluate( $providerCode, ( $this->endpoint )( $providerCode ) );
	}

	public function target( string $providerCode, string $repositoryId ): ?AssistanceTarget {
		return $this->projectedTarget( $this->providerCode( $providerCode ), $repositoryId, true );
	}

	public function credentialChoices( string $providerCode ): array {
		$providerCode = $this->providerCode( $providerCode );
		if ( ! ( $this->canManage )() || ! $this->storageAvailable() ) {
			return array();
		}
		try {
			$choices = array();
			foreach ( $this->secrets->credentialProfiles( $providerCode ) as $profile ) {
				if ( 'file' !== ( $profile['source'] ?? null ) || ! empty( $profile['immutable'] ) || ! is_string( $profile['id'] ?? null ) || ! is_string( $profile['label'] ?? null ) || ! is_string( $profile['kind'] ?? null ) ) {
					continue;
				}
				$choices[] = array(
					'id'         => $profile['id'],
					'label'      => $profile['label'],
					'kind'       => $profile['kind'],
					'destroy_on' => is_string( $profile['destroy_on'] ?? null ) ? $profile['destroy_on'] : null,
				);
			}

			return $choices;
		} catch ( \Throwable ) {
			return array();
		}
	}

	/** @return list<array{id:string,label:string,scope:string}> */
	public function webhookProfileChoices( string $providerCode, string $repositoryId ): array {
		$providerCode = $this->providerCode( $providerCode );
		if ( ! ( $this->canManage )() || ! $this->storageAvailable() || ! $this->validRepositoryId( $repositoryId ) ) {
			return array();
		}
		try {
			$target  = $this->currentTarget( $providerCode, $repositoryId, true );
			$choices = array();
			foreach ( null === $target ? array() : $this->secrets->webhookProfiles( $providerCode ) as $profileId => $profile ) {
				if ( ! is_string( $profileId ) || ! $this->validWebhookProfileId( $profileId ) || ! is_array( $profile ) || ! $this->appliesTo( $target, $profile ) || ! is_string( $profile['label'] ?? null ) ) {
					continue;
				}
				$choices[] = array(
					'id'    => $profileId,
					'label' => $profile['label'],
					'scope' => (string) $profile['scope'],
				);
			}

			return $choices;
		} catch ( \Throwable ) {
			return array();
		}
	}

	public function profile( string $providerCode, string $repositoryId, string $profileId ): ?WebhookProfileMetadata {
		$providerCode = $this->providerCode( $providerCode );
		if ( ! ( $this->canManage )() || ! $this->storageAvailable() || ! $this->validRepositoryId( $repositoryId ) || ! $this->validWebhookProfileId( $profileId ) ) {
			return null;
		}
		try {
			$target  = $this->currentTarget( $providerCode, $repositoryId, true );
			$profile = null === $target ? null : ( $this->secrets->webhookProfiles( $providerCode )[ $profileId ] ?? null );

			return is_array( $profile ) && $this->appliesTo( $target, $profile ) ? $this->metadata( $providerCode, $profileId, $profile ) : null;
		} catch ( \Throwable ) {
			return null;
		}
	}

	public function assessSetup( AssistanceTarget $target, ?string $credentialProfileId, string $nonce ): RepositoryWebhookFitnessResult {
		return $this->assess( 'setup', $target, $credentialProfileId, null, null, null, $nonce, false );
	}

	public function assessCheck( AssistanceTarget $target, ?string $credentialProfileId, string $hookId, string $profileId, int $profileRevision, string $nonce ): RepositoryWebhookFitnessResult {
		return $this->assess( 'check', $target, $credentialProfileId, $hookId, $profileId, $profileRevision, $nonce, false );
	}

	public function assessReconfigure( AssistanceTarget $target, ?string $credentialProfileId, string $hookId, string $profileId, int $profileRevision, string $nonce ): RepositoryWebhookFitnessResult {
		return $this->assess( 'reconfigure', $target, $credentialProfileId, $hookId, $profileId, $profileRevision, $nonce, false );
	}

	public function assessRemove( AssistanceTarget $target, ?string $credentialProfileId, string $hookId, string $profileId, int $profileRevision, string $nonce ): RepositoryWebhookFitnessResult {
		return $this->assess( 'remove', $target, $credentialProfileId, $hookId, $profileId, $profileRevision, $nonce, true );
	}

	public function assessTest( AssistanceTarget $target, ?string $credentialProfileId, string $hookId, string $profileId, int $profileRevision, string $nonce ): RepositoryWebhookFitnessResult {
		return $this->assess( 'test', $target, $credentialProfileId, $hookId, $profileId, $profileRevision, $nonce, false );
	}

	public function setup( AssistanceTarget $target, ?string $credentialProfileId, string $nonce, ?string $webhookProfileId = null ): RepositoryWebhookOperationResult {
		return $this->withTargetLock(
			$target,
			fn (): RepositoryWebhookOperationResult => $this->setupLocked( $target, $credentialProfileId, $webhookProfileId, $nonce )
		);
	}

	public function check( AssistanceTarget $target, ?string $credentialProfileId, string $hookId, string $profileId, int $profileRevision, string $nonce ): RepositoryWebhookOperationResult {
		return $this->withTargetLock(
			$target,
			function () use ( $target, $credentialProfileId, $hookId, $profileId, $profileRevision, $nonce ): RepositoryWebhookOperationResult {
				$current = $this->authorize( 'check', $target, $nonce );
				$profile = null === $current ? null : $this->exactProfile( $current, $profileId, $profileRevision );
				if ( null === $current || null === $profile || ! $this->credentialProfileAvailable( $current->providerCode(), (string) $credentialProfileId ) ) {
					return $this->failed( 'operation_unauthorized' );
				}
				try {
					if ( ! $this->identityConfirmed( 'check', $current, $credentialProfileId, $hookId ) ) {
						return $this->failed( 'repository_identity_unconfirmed' )->withProfile( $profile );
					}
					$provider = $this->completeWebhookProvider( $current->providerCode() );

					return $provider->check( $current->repositoryId(), $current->repository(), $hookId, $current->endpoint(), $credentialProfileId )->withProfile( $profile );
				} catch ( \Throwable ) {
					return $this->failed( 'operation_failed' )->withProfile( $profile );
				}
			}
		);
	}

	public function reconfigure( AssistanceTarget $target, ?string $credentialProfileId, string $hookId, string $profileId, int $profileRevision, string $nonce ): RepositoryWebhookOperationResult {
		return $this->withTargetLock(
			$target,
			function () use ( $target, $credentialProfileId, $hookId, $profileId, $profileRevision, $nonce ): RepositoryWebhookOperationResult {
				$current = $this->authorize( 'reconfigure', $target, $nonce );
				$record  = null === $current ? null : $this->profileRecord( $current->providerCode(), $profileId );
				if ( null === $current || null === $record || $profileRevision !== $record[0]->revision() || ! $this->appliesMetadata( $current, $record[0] ) || ! $this->credentialProfileAvailable( $current->providerCode(), (string) $credentialProfileId ) ) {
					return $this->failed( 'operation_unauthorized' );
				}
				$providerStarted = false;
				try {
					if ( ! $this->identityConfirmed( 'reconfigure', $current, $credentialProfileId, $hookId ) ) {
						return $this->failed( 'repository_identity_unconfirmed' )->withProfile( $record[0] );
					}
					$provider        = $this->completeWebhookProvider( $current->providerCode() );
					$providerStarted = true;

					return $provider->reconfigure( $current->repositoryId(), $current->repository(), $hookId, $current->endpoint(), $credentialProfileId, $record[1] )->withProfile( $record[0] );
				} catch ( \Throwable ) {
					if ( $providerStarted ) {
						return $this->mutationOutcomeUnknown( 'reconfigure_outcome_unknown', $hookId )->withProfile( $record[0] );
					}

					return $this->failed( 'operation_failed' )->withProfile( $record[0] );
				}
			}
		);
	}

	public function remove( AssistanceTarget $target, ?string $credentialProfileId, string $hookId, string $profileId, int $profileRevision, string $nonce ): RepositoryWebhookOperationResult {
		return $this->withTargetLock(
			$target,
			function () use ( $target, $credentialProfileId, $hookId, $profileId, $profileRevision, $nonce ): RepositoryWebhookOperationResult {
				$current = $this->authorize( 'remove', $target, $nonce, true );
				$record  = null === $current ? null : $this->profileRecord( $current->providerCode(), $profileId );
				if ( null === $current || null === $record || $profileRevision !== $record[0]->revision() || ! $this->appliesMetadata( $current, $record[0] ) || ! $this->credentialProfileAvailable( $current->providerCode(), (string) $credentialProfileId ) ) {
					return $this->failed( 'operation_unauthorized' );
				}
				$profile         = $record[0];
				$providerStarted = false;
				try {
					if ( ! $this->identityConfirmed( 'remove', $current, $credentialProfileId, $hookId ) ) {
						return $this->failed( 'repository_identity_unconfirmed' )->withProfile( $profile );
					}
					$provider        = $this->completeWebhookProvider( $current->providerCode() );
					$providerStarted = true;
					$result          = $provider->remove( $current->repositoryId(), $current->repository(), $hookId, $current->endpoint(), $credentialProfileId )->withProfile( $profile );
					if ( $result->confirmsAbsence() && 'created' === $profile->disposition() && ! $this->deleteProfileIfRevision( $current->providerCode(), $profile->id(), $profile->revision() ) ) {
						return $result->asPartial( 'local_profile_release_failed', 'The remote hook is absent; remove the retained local profile before replacing it.' );
					}

					return $result;
				} catch ( \Throwable ) {
					if ( $providerStarted ) {
						return $this->mutationOutcomeUnknown( 'remove_outcome_unknown', $hookId )->withProfile( $profile );
					}

					return $this->failed( 'operation_failed' )->withProfile( $profile );
				}
			}
		);
	}

	public function test( AssistanceTarget $target, ?string $credentialProfileId, string $hookId, string $profileId, int $profileRevision, string $nonce ): RepositoryWebhookOperationResult {
		return $this->withTargetLock(
			$target,
			function () use ( $target, $credentialProfileId, $hookId, $profileId, $profileRevision, $nonce ): RepositoryWebhookOperationResult {
				$current = $this->authorize( 'test', $target, $nonce );
				$profile = null === $current ? null : $this->exactProfile( $current, $profileId, $profileRevision );
				if ( null === $current || null === $profile || ! $this->credentialProfileAvailable( $current->providerCode(), (string) $credentialProfileId ) ) {
					return $this->failed( 'operation_unauthorized' );
				}
				try {
					if ( ! $this->identityConfirmed( 'test', $current, $credentialProfileId, $hookId ) ) {
						return $this->failed( 'repository_identity_unconfirmed' )->withProfile( $profile );
					}
					$provider = $this->completeWebhookProvider( $current->providerCode() );

					return $provider->test( $current->repositoryId(), $current->repository(), $hookId, $current->endpoint(), $credentialProfileId )->withProfile( $profile );
				} catch ( \Throwable ) {
					return $this->failed( 'operation_failed' )->withProfile( $profile );
				}
			}
		);
	}

	private function assess( string $action, AssistanceTarget $target, ?string $credentialId, ?string $hookId, ?string $profileId, ?int $profileRevision, string $nonce, bool $allowCleanup ): RepositoryWebhookFitnessResult {
		$current = $this->authorize( $action, $target, $nonce, $allowCleanup );
		if ( null === $current || ! $this->credentialProfileAvailable( $current->providerCode(), (string) $credentialId ) || ( null !== $profileId && null === $this->exactProfile( $current, $profileId, (int) $profileRevision ) ) ) {
			return $this->fitnessUnavailable( 'assessment_unauthorized' );
		}
		try {
			return $this->providerAssessment( $action, $current, $credentialId, $hookId );
		} catch ( \Throwable ) {
			return $this->fitnessUnavailable( 'assessment_unavailable' );
		}
	}

	private function setupLocked( AssistanceTarget $target, ?string $credentialId, ?string $selectedProfileId, string $nonce ): RepositoryWebhookOperationResult {
		$current = $this->authorize( 'setup', $target, $nonce );
		if ( null === $current || ! $this->credentialProfileAvailable( $current->providerCode(), (string) $credentialId ) ) {
			return $this->failed( 'operation_unauthorized' );
		}
		$created         = false;
		$providerStarted = false;
		$metadata        = null;
		$profileId       = null;
		$createdRevision = null;
		try {
			$selection = null === $selectedProfileId ? null : $this->selectProfile( $current, $selectedProfileId );
			if ( null !== $selectedProfileId && null === $selection ) {
				return $this->failed( 'operation_unauthorized' );
			}
			if ( ! $this->identityConfirmed( 'setup', $current, $credentialId ) ) {
				return $this->failed( 'repository_identity_unconfirmed' );
			}
			$provider = $this->completeWebhookProvider( $current->providerCode() );
			if ( null === $selection && null === $selectedProfileId ) {
				$profileId       = $this->secrets->saveWebhook(
					$current->providerCode(),
					null,
					array(
						'label'        => 'Assisted hook for ' . $current->repository(),
						'scope'        => 'repository',
						'target'       => $current->repository(),
						'authority_id' => $current->repositoryId(),
						'origin'       => 'assisted',
					),
					bin2hex( random_bytes( 32 ) )
				);
				$created         = true;
				$createdRevision = 1;
				$selection       = $this->profileRecord( $current->providerCode(), $profileId );
			}
			if ( null === $selection ) {
				throw new \RuntimeException( 'The webhook profile snapshot is unavailable.' );
			}
			list($metadata, $secret) = $selection;
			$providerStarted         = true;
			$result                  = $provider->setup( $current->repositoryId(), $current->repository(), $current->endpoint(), $credentialId, $secret );
			if ( $created && 'failed' === $result->state() ) {
				return $this->cleanupCreatedProfile( $current->providerCode(), $metadata->id(), $metadata->revision(), $result, $metadata, 'Retain the local profile and inspect both local and remote state.' );
			}

			return $result->withProfile( $metadata );
		} catch ( \Throwable ) {
			if ( $providerStarted && null !== $metadata ) {
				return $this->failed( 'setup_outcome_unknown' )->asPartial( 'setup_outcome_unknown', 'Retain the local signing profile and inspect the provider before retrying.' )->withProfile( $metadata );
			}
			if ( $created && null !== $profileId && null !== $createdRevision ) {
				return $this->cleanupCreatedProfile( $current->providerCode(), $profileId, $createdRevision, $this->failed( 'operation_failed' ), $metadata, 'Retain the local profile and inspect local state before retrying.' );
			}

			return $this->failed( 'operation_failed' );
		}
	}

	private function identityConfirmed( string $action, AssistanceTarget $target, ?string $credentialId, ?string $hookId = null ): bool {
		$fitness = $this->providerAssessment( $action, $target, $credentialId, $hookId )->toArray();

		return 'supported' === $fitness['support']
			&& in_array( $fitness['suitability'], array( 'suitable', 'unknown' ), true )
			&& in_array( $fitness['evidence'], array( 'observed', 'inferred', 'unknown_by_design' ), true );
	}

	private function providerAssessment( string $action, AssistanceTarget $target, ?string $credentialId, ?string $hookId ): RepositoryWebhookFitnessResult {
		$provider = $this->completeWebhookProvider( $target->providerCode() );

		return match ( $action ) {
			'setup'       => $provider->assessSetup( $target->repositoryId(), $target->repository(), $credentialId ),
			'check'       => $provider->assessCheck( $target->repositoryId(), $target->repository(), $credentialId, (string) $hookId ),
			'reconfigure' => $provider->assessReconfigure( $target->repositoryId(), $target->repository(), $credentialId, (string) $hookId ),
			'remove'      => $provider->assessRemove( $target->repositoryId(), $target->repository(), $credentialId, (string) $hookId ),
			'test'        => $provider->assessTest( $target->repositoryId(), $target->repository(), $credentialId, (string) $hookId ),
		};
	}

	private function completeWebhookProvider( string $providerCode ): RepositoryWebhookManagement {
		$fitness    = $this->providers->requireCapability( $providerCode, RepositoryWebhookFitness::class );
		$management = $this->providers->requireCapability( $providerCode, RepositoryWebhookManagement::class );
		$normalizer = $this->providers->requireCapability( $providerCode, WebhookNormalizer::class );
		if ( $fitness !== $management || $management !== $normalizer ) {
			throw new \RuntimeException( 'The provider webhook management capability is incomplete.' );
		}

		return $management;
	}

	/** @param callable(): RepositoryWebhookOperationResult $operation */
	private function withTargetLock( AssistanceTarget $target, callable $operation ): RepositoryWebhookOperationResult {
		$name = 'ran_booster:webhook:' . substr( hash( 'sha256', $target->providerCode() . "\0" . $target->repositoryId() ), 0, 40 );
		try {
			if ( isset( $this->heldLocks[ $name ] ) || ! ( $this->acquireLock )( $name ) ) {
				return $this->failed( 'operation_busy' );
			}
			$this->heldLocks[ $name ] = true;
		} catch ( \Throwable ) {
			return $this->failed( 'operation_busy' );
		}
		try {
			$result = $operation();
		} catch ( \Throwable ) {
			$result = $this->failed( 'operation_failed' );
		}
		try {
			$released = ( $this->releaseLock )( $name );
		} catch ( \Throwable ) {
			$released = false;
		}
		if ( $released ) {
			unset( $this->heldLocks[ $name ] );

			return $result;
		}

		if ( 'succeeded' !== $result->state() || $result->confirmsAbsence() ) {
			return $result;
		}

		return $result->asPartial( 'operation_lock_release_failed', 'The operation completed, but do not retry until the current database request has ended.' );
	}

	private function authorize( string $action, AssistanceTarget $submitted, string $nonce, bool $allowCleanup = false ): ?AssistanceTarget {
		if ( ! ( $this->canManage )() || ! ( $this->verifyNonce )( $nonce, $this->nonceAction( $action, $submitted ) ) ) {
			return null;
		}
		$current = $this->currentTarget( $submitted->providerCode(), $submitted->repositoryId(), $allowCleanup );

		return null !== $current && $current->toArray() === $submitted->toArray() ? $current : null;
	}

	private function nonceAction( string $action, AssistanceTarget $target ): string {
		return 'ran_booster_repository_webhook_' . $action . '_' . $target->providerCode() . '_' . $target->repositoryId();
	}

	private function currentTarget( string $providerCode, string $repositoryId, bool $allowCleanup ): ?AssistanceTarget {
		$current = $this->projectedTarget( $providerCode, $repositoryId, true );
		if ( null === $current && $allowCleanup ) {
			$current = $this->readinessEvaluator->cleanupTarget( $providerCode, $repositoryId, ( $this->endpoint )( $providerCode ) );
		}

		return $current;
	}

	private function projectedTarget( string $providerCode, string $repositoryId, bool $requireReadySite ): ?AssistanceTarget {
		if ( ! $this->validRepositoryId( $repositoryId ) ) {
			return null;
		}
		$readiness = $this->readiness( $providerCode )->toArray();
		if ( $requireReadySite && AssistanceReadiness::READY !== $readiness['site']['status'] ) {
			return null;
		}
		foreach ( $readiness['repositories'] as $repository ) {
			if ( $repositoryId === $repository['repository_id'] && ( ! $requireReadySite || true === $repository['eligible'] ) ) {
				return new AssistanceTarget( $providerCode, $repositoryId, $repository['repository'], $repository['label'], $repository['package_references'], $repository['deployment_policies'], $readiness['site']['callback_url'] );
			}
		}

		return null;
	}

	private function credentialProfileAvailable( string $providerCode, string $credentialId ): bool {
		if ( ! $this->validCredentialId( $credentialId ) ) {
			return false;
		}
		try {
			$profile = $this->secrets->credentialProfiles( $providerCode )[ $credentialId ] ?? null;

			return is_array( $profile ) && 'file' === ( $profile['source'] ?? null ) && empty( $profile['immutable'] );
		} catch ( \Throwable ) {
			return false;
		}
	}

	private function exactProfile( AssistanceTarget $target, string $profileId, int $revision ): ?WebhookProfileMetadata {
		$profile = $this->secrets->webhookProfiles( $target->providerCode() )[ $profileId ] ?? null;
		if ( ! is_array( $profile ) || (int) ( $profile['revision'] ?? 0 ) !== $revision || ! $this->appliesTo( $target, $profile ) ) {
			return null;
		}

		return $this->metadata( $target->providerCode(), $profileId, $profile );
	}

	/** @return array{WebhookProfileMetadata,string}|null */
	private function selectProfile( AssistanceTarget $target, string $profileId ): ?array {
		$materials = $this->secrets->webhookMaterials( $target->providerCode() );
		if ( isset( $materials[ $profileId ] ) && $this->appliesTo( $target, $materials[ $profileId ] ) ) {
			return $this->profileRecordFromMaterial( $target->providerCode(), $profileId, $materials[ $profileId ] );
		}

		return null;
	}

	/** @return array{WebhookProfileMetadata,string}|null */
	private function profileRecord( string $providerCode, string $profileId ): ?array {
		$material = $this->secrets->webhookMaterials( $providerCode )[ $profileId ] ?? null;

		return is_array( $material ) ? $this->profileRecordFromMaterial( $providerCode, $profileId, $material ) : null;
	}

	/** @param array<string,mixed> $material @return array{WebhookProfileMetadata,string}|null */
	private function profileRecordFromMaterial( string $providerCode, string $profileId, array $material ): ?array {
		if ( ! is_string( $material['secret'] ?? null ) ) {
			return null;
		}

		return array( $this->metadata( $providerCode, $profileId, $material ), $material['secret'] );
	}

	/** @param array<string,mixed> $profile */
	private function appliesTo( AssistanceTarget $target, array $profile ): bool {
		if ( false === ( $profile['configured'] ?? true ) ) {
			return false;
		}
		if ( 'repository' === ( $profile['scope'] ?? null ) ) {
			return is_string( $profile['authority_id'] ?? null ) && hash_equals( $target->repositoryId(), $profile['authority_id'] );
		}

		return 'owner' === ( $profile['scope'] ?? null ) && is_string( $profile['target'] ?? null ) && 0 === strcasecmp( explode( '/', trim( $target->repository(), '/' ), 2 )[0], trim( $profile['target'], '/' ) );
	}

	private function appliesMetadata( AssistanceTarget $target, WebhookProfileMetadata $profile ): bool {
		return $profile->providerCode() === $target->providerCode()
			&& ( ( 'repository' === $profile->scope() && hash_equals( $target->repositoryId(), $profile->authorityId() ) )
				|| ( 'owner' === $profile->scope() && 0 === strcasecmp( explode( '/', $target->repository(), 2 )[0], $profile->target() ) ) );
	}

	/** @param array<string,mixed> $profile */
	private function metadata( string $providerCode, string $profileId, array $profile ): WebhookProfileMetadata {
		$disposition = 'assisted' === ( $profile['origin'] ?? 'manual' ) && 'repository' === ( $profile['scope'] ?? null ) ? 'created' : 'reused';

		return new WebhookProfileMetadata( $profileId, $providerCode, (string) ( $profile['scope'] ?? '' ), (string) ( $profile['target'] ?? '' ), (string) ( $profile['authority_id'] ?? '' ), (int) ( $profile['revision'] ?? 1 ), $disposition, (string) ( $profile['source'] ?? 'file' ), ! empty( $profile['immutable'] ) );
	}

	private function cleanupCreatedProfile( string $providerCode, string $profileId, int $revision, RepositoryWebhookOperationResult $result, ?WebhookProfileMetadata $fallback, string $remediation ): RepositoryWebhookOperationResult {
		if ( $this->deleteProfileIfRevision( $providerCode, $profileId, $revision ) ) {
			return $result;
		}
		try {
			$current = $this->profileRecord( $providerCode, $profileId )[0] ?? null;
			$read    = true;
		} catch ( \Throwable ) {
			$current = null;
			$read    = false;
		}
		$partial = $result->asPartial( 'profile_cleanup_failed', $remediation );

		return null === $current && ( $read || null === $fallback ) ? $partial : $partial->withProfile( $current ?? $fallback );
	}

	private function deleteProfileIfRevision( string $providerCode, string $profileId, int $revision ): bool {
		try {
			return $this->secrets->deleteWebhookIfRevision( $providerCode, $profileId, $revision );
		} catch ( \Throwable ) {
			return false;
		}
	}

	private function failed( string $code ): RepositoryWebhookOperationResult {
		return $this->unknownResult( 'failed', $code, null, 'Review the current target, profile, credential and provider capability.' );
	}

	private function mutationOutcomeUnknown( string $code, string $hookId ): RepositoryWebhookOperationResult {
		return $this->unknownResult( 'ambiguous', $code, $hookId, 'Inspect the identified remote hook and run Check before retrying.' );
	}

	private function unknownResult( string $state, string $code, ?string $hookId, string $remediation ): RepositoryWebhookOperationResult {
		return new RepositoryWebhookOperationResult(
			$state,
			$code,
			gmdate( 'Y-m-d\TH:i:s\Z' ),
			$hookId,
			array(
				'endpoint'     => 'unknown',
				'events'       => 'unknown',
				'content_type' => 'unknown',
				'active'       => 'unknown',
			),
			'unknown',
			$remediation
		);
	}

	private function fitnessUnavailable( string $code ): RepositoryWebhookFitnessResult {
		return new RepositoryWebhookFitnessResult( 'unknown', 'unknown', 'unknown', 'assessment_unavailable', $code, gmdate( 'Y-m-d\TH:i:s\Z' ), 'Review the current target, profile, credential and provider capability.' );
	}

	private function storageAvailable(): bool {
		return $this->readinessEvaluator->managedStorageAvailable();
	}

	private function validRepositoryId( string $repositoryId ): bool {
		return '' !== trim( $repositoryId ) && strlen( $repositoryId ) <= 191 && 1 !== preg_match( '/[\x00-\x1F\x7F]/', $repositoryId );
	}

	private function validProfileId( string $profileId ): bool {
		return 1 === preg_match( '/^wh_[a-f0-9]{24}$/', $profileId );
	}

	private function validWebhookProfileId( string $profileId ): bool {
		return SecretsFile::CONSTANT_PROFILE === $profileId || $this->validProfileId( $profileId );
	}

	private function validCredentialId( string $credentialId ): bool {
		return 1 === preg_match( '/^[A-Za-z0-9_-]{3,64}$/D', $credentialId );
	}

	private function providerCode( string $providerCode ): string {
		return ProviderCode::parse( $providerCode )->value;
	}
}
