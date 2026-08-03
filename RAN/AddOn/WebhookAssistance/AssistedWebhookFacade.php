<?php

declare(strict_types=1);

namespace RAN\AddOn\WebhookAssistance;

use RAN\RepositoryProvider\ProviderCode;
use RAN\RepositoryProvider\ProviderRegistry;
use RAN\RepositoryProvider\RepositoryWebhookFitness;
use RAN\RepositoryProvider\RepositoryWebhookFitnessResult;
use RAN\RepositoryProvider\RepositoryWebhookManagement;
use RAN\RepositoryProvider\RepositoryWebhookOperationResult;
use RAN\Secrets\SecretsFile;

/** Core-governed repository-webhook-management/1 facade. */
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

	public function profile( string $providerCode, string $repositoryId, string $profileId ): ?WebhookProfileMetadata {
		$providerCode = $this->providerCode( $providerCode );
		if ( ! ( $this->canManage )() || ! $this->storageAvailable() || ! $this->validRepositoryId( $repositoryId ) || ! $this->validProfileId( $profileId ) ) {
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

	public function assessSetup( AssistanceTarget $target, ?string $credentialProfileId, string $nonce, #[\SensitiveParameter] ?string $requestCredential = null ): RepositoryWebhookFitnessResult {
		return $this->assess( 'setup', $target, $credentialProfileId, null, null, null, $nonce, false, $requestCredential );
	}

	public function assessCheck( AssistanceTarget $target, ?string $credentialProfileId, string $hookId, string $profileId, int $profileRevision, string $nonce, #[\SensitiveParameter] ?string $requestCredential = null ): RepositoryWebhookFitnessResult {
		return $this->assess( 'check', $target, $credentialProfileId, $hookId, $profileId, $profileRevision, $nonce, false, $requestCredential );
	}

	public function assessReconfigure( AssistanceTarget $target, ?string $credentialProfileId, string $hookId, string $profileId, int $profileRevision, string $nonce, #[\SensitiveParameter] ?string $requestCredential = null ): RepositoryWebhookFitnessResult {
		return $this->assess( 'reconfigure', $target, $credentialProfileId, $hookId, $profileId, $profileRevision, $nonce, false, $requestCredential );
	}

	public function assessRemove( AssistanceTarget $target, ?string $credentialProfileId, string $hookId, string $profileId, int $profileRevision, string $nonce, #[\SensitiveParameter] ?string $requestCredential = null ): RepositoryWebhookFitnessResult {
		return $this->assess( 'remove', $target, $credentialProfileId, $hookId, $profileId, $profileRevision, $nonce, true, $requestCredential );
	}

	public function setup( AssistanceTarget $target, ?string $credentialProfileId, string $nonce, #[\SensitiveParameter] ?string $requestCredential = null ): RepositoryWebhookOperationResult {
		return $this->withTargetLock(
			$target,
			fn (): RepositoryWebhookOperationResult => $this->setupLocked( $target, $credentialProfileId, $nonce, $requestCredential )
		);
	}

	public function check( AssistanceTarget $target, ?string $credentialProfileId, string $hookId, string $profileId, int $profileRevision, string $nonce, #[\SensitiveParameter] ?string $requestCredential = null ): RepositoryWebhookOperationResult {
		return $this->withTargetLock(
			$target,
			function () use ( $target, $credentialProfileId, $hookId, $profileId, $profileRevision, $nonce, $requestCredential ): RepositoryWebhookOperationResult {
				$current = $this->authorize( 'check', $target, $nonce );
				$profile = null === $current ? null : $this->exactProfile( $current, $profileId, $profileRevision );
				if ( null === $current || null === $profile || ! $this->credentialSourceAvailable( $current->providerCode(), $credentialProfileId, $requestCredential ) ) {
					return $this->failed( 'operation_unauthorized' );
				}
				try {
					if ( ! $this->identityConfirmed( 'check', $current, $credentialProfileId, $requestCredential, $hookId ) ) {
						return $this->failed( 'repository_identity_unconfirmed' )->withProfile( $profile );
					}
					$provider = $this->providers->requireCapability( $current->providerCode(), RepositoryWebhookManagement::class );

					return $provider->check( $current->repositoryId(), $current->repository(), $hookId, $current->endpoint(), $credentialProfileId, $requestCredential )->withProfile( $profile );
				} catch ( \Throwable ) {
					return $this->failed( 'operation_failed' )->withProfile( $profile );
				}
			}
		);
	}

	public function reconfigure( AssistanceTarget $target, ?string $credentialProfileId, string $hookId, string $profileId, int $profileRevision, string $nonce, #[\SensitiveParameter] ?string $requestCredential = null ): RepositoryWebhookOperationResult {
		return $this->withTargetLock(
			$target,
			function () use ( $target, $credentialProfileId, $hookId, $profileId, $profileRevision, $nonce, $requestCredential ): RepositoryWebhookOperationResult {
				$current = $this->authorize( 'reconfigure', $target, $nonce );
				$record  = null === $current ? null : $this->profileRecord( $current->providerCode(), $profileId );
				if ( null === $current || null === $record || $profileRevision !== $record[0]->revision() || ! $this->appliesMetadata( $current, $record[0] ) || ! $this->credentialSourceAvailable( $current->providerCode(), $credentialProfileId, $requestCredential ) ) {
					return $this->failed( 'operation_unauthorized' );
				}
				try {
					if ( ! $this->identityConfirmed( 'reconfigure', $current, $credentialProfileId, $requestCredential, $hookId ) ) {
						return $this->failed( 'repository_identity_unconfirmed' )->withProfile( $record[0] );
					}
					$provider = $this->providers->requireCapability( $current->providerCode(), RepositoryWebhookManagement::class );

					return $provider->reconfigure( $current->repositoryId(), $current->repository(), $hookId, $current->endpoint(), $credentialProfileId, $requestCredential, $record[1] )->withProfile( $record[0] );
				} catch ( \Throwable ) {
					return $this->failed( 'operation_failed' )->withProfile( $record[0] );
				}
			}
		);
	}

	public function remove( AssistanceTarget $target, ?string $credentialProfileId, string $hookId, string $profileId, int $profileRevision, string $nonce, #[\SensitiveParameter] ?string $requestCredential = null ): RepositoryWebhookOperationResult {
		return $this->withTargetLock(
			$target,
			function () use ( $target, $credentialProfileId, $hookId, $profileId, $profileRevision, $nonce, $requestCredential ): RepositoryWebhookOperationResult {
				$current = $this->authorize( 'remove', $target, $nonce, true );
				$record  = null === $current ? null : $this->profileRecord( $current->providerCode(), $profileId );
				if ( null === $current || null === $record || $profileRevision !== $record[0]->revision() || ! $this->appliesMetadata( $current, $record[0] ) || ! $this->credentialSourceAvailable( $current->providerCode(), $credentialProfileId, $requestCredential ) ) {
					return $this->failed( 'operation_unauthorized' );
				}
				$profile = $record[0];
				try {
					if ( ! $this->identityConfirmed( 'remove', $current, $credentialProfileId, $requestCredential, $hookId ) ) {
						return $this->failed( 'repository_identity_unconfirmed' )->withProfile( $profile );
					}
					$provider = $this->providers->requireCapability( $current->providerCode(), RepositoryWebhookManagement::class );
					$result   = $provider->remove( $current->repositoryId(), $current->repository(), $hookId, $current->endpoint(), $credentialProfileId, $requestCredential )->withProfile( $profile );
					if ( $result->confirmsAbsence() && 'created' === $profile->disposition() && ! $this->deleteProfileIfRevision( $current->providerCode(), $profile->id(), $profile->revision() ) ) {
						return $result->asPartial( 'local_profile_release_failed', 'The remote hook is absent; remove the retained local profile before replacing it.' );
					}

					return $result;
				} catch ( \Throwable ) {
					return $this->failed( 'operation_failed' )->withProfile( $profile );
				}
			}
		);
	}

	private function assess( string $action, AssistanceTarget $target, ?string $credentialId, ?string $hookId, ?string $profileId, ?int $profileRevision, string $nonce, bool $allowCleanup, ?string $requestCredential ): RepositoryWebhookFitnessResult {
		$current = $this->authorize( $action, $target, $nonce, $allowCleanup );
		if ( null === $current || ! $this->credentialSourceAvailable( $current->providerCode(), $credentialId, $requestCredential ) || ( null !== $profileId && null === $this->exactProfile( $current, $profileId, (int) $profileRevision ) ) ) {
			return $this->fitnessUnavailable( 'assessment_unauthorized' );
		}
		try {
			return $this->providerAssessment( $action, $current, $credentialId, $requestCredential, $hookId );
		} catch ( \Throwable ) {
			return $this->fitnessUnavailable( 'assessment_unavailable' );
		}
	}

	private function setupLocked( AssistanceTarget $target, ?string $credentialId, string $nonce, ?string $requestCredential ): RepositoryWebhookOperationResult {
		$current = $this->authorize( 'setup', $target, $nonce );
		if ( null === $current || ! $this->credentialSourceAvailable( $current->providerCode(), $credentialId, $requestCredential ) ) {
			return $this->failed( 'operation_unauthorized' );
		}
		$created         = false;
		$providerStarted = false;
		$metadata        = null;
		$profileId       = null;
		try {
			if ( ! $this->identityConfirmed( 'setup', $current, $credentialId, $requestCredential ) ) {
				return $this->failed( 'repository_identity_unconfirmed' );
			}
			$provider  = $this->providers->requireCapability( $current->providerCode(), RepositoryWebhookManagement::class );
			$selection = $this->selectProfile( $current, null );
			if ( null === $selection ) {
				$profileId = $this->secrets->saveWebhook(
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
				$created   = true;
				$selection = $this->profileRecord( $current->providerCode(), $profileId );
			}
			if ( null === $selection ) {
				throw new \RuntimeException( 'The webhook profile snapshot is unavailable.' );
			}
			list($metadata, $secret) = $selection;
			$providerStarted         = true;
			$result                  = $provider->setup( $current->repositoryId(), $current->repository(), $current->endpoint(), $credentialId, $requestCredential, $secret );
			if ( $created && 'failed' === $result->state() ) {
				if ( ! $this->deleteProfile( $current->providerCode(), $metadata->id() ) ) {
					return $result->asPartial( 'profile_cleanup_failed', 'Retain the local profile and inspect both local and remote state.' )->withProfile( $metadata );
				}

				return $result;
			}

			return $result->withProfile( $metadata );
		} catch ( \Throwable ) {
			if ( $providerStarted && null !== $metadata ) {
				return $this->failed( 'setup_outcome_unknown' )->asPartial( 'setup_outcome_unknown', 'Retain the local signing profile and inspect the provider before retrying.' )->withProfile( $metadata );
			}
			if ( $created && null !== $profileId && ! $this->deleteProfile( $current->providerCode(), $profileId ) ) {
				$metadata ??= $this->profileRecord( $current->providerCode(), $profileId )[0] ?? null;
				$result     = $this->failed( 'profile_cleanup_failed' )->asPartial( 'profile_cleanup_failed', 'Retain the local profile and inspect local state before retrying.' );

				return null === $metadata ? $result : $result->withProfile( $metadata );
			}

			return $this->failed( 'operation_failed' );
		}
	}

	private function identityConfirmed( string $action, AssistanceTarget $target, ?string $credentialId, ?string $requestCredential, ?string $hookId = null ): bool {
		$fitness = $this->providerAssessment( $action, $target, $credentialId, $requestCredential, $hookId )->toArray();

		return 'supported' === $fitness['support']
			&& in_array( $fitness['suitability'], array( 'suitable', 'unknown' ), true )
			&& in_array( $fitness['evidence'], array( 'observed', 'inferred', 'unknown_by_design' ), true );
	}

	private function providerAssessment( string $action, AssistanceTarget $target, ?string $credentialId, ?string $requestCredential, ?string $hookId ): RepositoryWebhookFitnessResult {
		$provider = $this->providers->requireCapability( $target->providerCode(), RepositoryWebhookFitness::class );

		return match ( $action ) {
			'setup'       => $provider->assessSetup( $target->repositoryId(), $target->repository(), $credentialId, $requestCredential ),
			'check'       => $provider->assessCheck( $target->repositoryId(), $target->repository(), $credentialId, (string) $hookId, $requestCredential ),
			'reconfigure' => $provider->assessReconfigure( $target->repositoryId(), $target->repository(), $credentialId, (string) $hookId, $requestCredential ),
			'remove'      => $provider->assessRemove( $target->repositoryId(), $target->repository(), $credentialId, (string) $hookId, $requestCredential ),
		};
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

		return $result->asPartial( 'operation_lock_release_failed', 'Do not retry until the current database request has ended.' );
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

	private function credentialSourceAvailable( string $providerCode, ?string $credentialId, ?string $requestCredential ): bool {
		$saved   = null !== $credentialId && '' !== trim( $credentialId );
		$request = null !== $requestCredential && '' !== trim( $requestCredential );

		return $saved !== $request && ( $request || $this->credentialProfileAvailable( $providerCode, (string) $credentialId ) );
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
	private function selectProfile( AssistanceTarget $target, ?string $recordedProfileId ): ?array {
		$materials = $this->secrets->webhookMaterials( $target->providerCode() );
		if ( null !== $recordedProfileId && isset( $materials[ $recordedProfileId ] ) && $this->appliesTo( $target, $materials[ $recordedProfileId ] ) ) {
			return $this->profileRecordFromMaterial( $target->providerCode(), $recordedProfileId, $materials[ $recordedProfileId ] );
		}
		foreach ( array( 'repository', 'owner' ) as $scope ) {
			foreach ( $materials as $profileId => $material ) {
				if ( $scope === ( $material['scope'] ?? null ) && $this->appliesTo( $target, $material ) ) {
					return $this->profileRecordFromMaterial( $target->providerCode(), (string) $profileId, $material );
				}
			}
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

	private function deleteProfile( string $providerCode, string $profileId ): bool {
		try {
			return $this->secrets->deleteWebhook( $providerCode, $profileId );
		} catch ( \Throwable ) {
			return false;
		}
	}

	private function deleteProfileIfRevision( string $providerCode, string $profileId, int $revision ): bool {
		try {
			return $this->secrets->deleteWebhookIfRevision( $providerCode, $profileId, $revision );
		} catch ( \Throwable ) {
			return false;
		}
	}

	private function failed( string $code ): RepositoryWebhookOperationResult {
		return new RepositoryWebhookOperationResult(
			'failed',
			$code,
			gmdate( 'Y-m-d\TH:i:s\Z' ),
			null,
			array(
				'endpoint'     => 'unknown',
				'events'       => 'unknown',
				'content_type' => 'unknown',
				'active'       => 'unknown',
			),
			'unknown',
			'Review the current target, profile, credential and provider capability.'
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

	private function validCredentialId( string $credentialId ): bool {
		return 1 === preg_match( '/^[A-Za-z0-9_-]{3,64}$/D', $credentialId );
	}

	private function providerCode( string $providerCode ): string {
		return ProviderCode::parse( $providerCode )->value;
	}
}
