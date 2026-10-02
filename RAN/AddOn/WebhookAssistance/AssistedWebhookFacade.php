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

/** Core-governed repository-webhook-management/3 facade. */
final class AssistedWebhookFacade implements WebhookAssistanceFacade {

	/** @var \Closure(): bool */
	private \Closure $can_manage;

	/** @var \Closure(string): string */
	private \Closure $endpoint;

	/** @var \Closure(string,string): bool */
	private \Closure $verify_nonce;
	/** @var \Closure(string): bool */
	private \Closure $acquire_lock;
	/** @var \Closure(string): bool */
	private \Closure $release_lock;
	/** @var array<string,true> */
	private array $held_locks = array();

	/** @param callable(): bool|null $canManage @param callable(string): string|null $endpoint @param callable(string,string): bool|null $verifyNonce @param callable(string): bool|null $acquireLock @param callable(string): bool|null $releaseLock */
	public function __construct(
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
		private WebhookAssistanceReadinessEvaluator $readinessEvaluator,
		private SecretsFile $secrets,
		private ProviderRegistry $providers,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
		?callable $canManage = null,
		?callable $endpoint = null,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
		?callable $verifyNonce = null,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
		?callable $acquireLock = null,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
		?callable $releaseLock = null
	) {
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
		$this->can_manage = null === $canManage
			? static fn (): bool => current_user_can( 'manage_options' )
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
			: \Closure::fromCallable( $canManage );
		$this->endpoint   = null === $endpoint
			? static fn ( string $provider_code ): string => rest_url( 'ran-booster/v1/webhooks/' . rawurlencode( $provider_code ) )
			: \Closure::fromCallable( $endpoint );
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
		$this->verify_nonce = null === $verifyNonce
			? static fn ( string $nonce, string $action ): bool => 1 === wp_verify_nonce( $nonce, $action )
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
			: \Closure::fromCallable( $verifyNonce );
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
		$this->acquire_lock = null === $acquireLock
			? static function ( string $name ): bool {
				global $wpdb;
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- MySQL advisory locks are connection-local and have no persistent cacheable state.
				$result = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $name ) );

				return '' === trim( (string) ( $wpdb->last_error ?? '' ) ) && '1' === (string) $result;
			}
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
			: \Closure::fromCallable( $acquireLock );
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
		$this->release_lock = null === $releaseLock
			? static function ( string $name ): bool {
				global $wpdb;
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- MySQL advisory locks are connection-local and have no persistent cacheable state.
				$result = $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );

				return '' === trim( (string) ( $wpdb->last_error ?? '' ) ) && '1' === (string) $result;
			}
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
			: \Closure::fromCallable( $releaseLock );
	}

	// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
	public function readiness( string $providerCode ): AssistanceReadiness {
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
		$providerCode = $this->provider_code( $providerCode );

		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase,WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the promoted constructor or external DTO property contract. Retain the public named-parameter contract.
		return $this->readinessEvaluator->evaluate( $providerCode, ( $this->endpoint )( $providerCode ) );
	}

	// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
	public function target( string $providerCode, string $repositoryId ): ?AssistanceTarget {
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
		return $this->projected_target( $this->provider_code( $providerCode ), $repositoryId, true );
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase,WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public and protected methods retain the existing caller and override contracts. Retain the public named-parameter contract.
	public function credentialChoices( string $providerCode ): array {
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
		$providerCode = $this->provider_code( $providerCode );
		if ( ! ( $this->can_manage )() || ! $this->storage_available() ) {
			return array();
		}
		try {
			$choices = array();
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
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
	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase,WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public and protected methods retain the existing caller and override contracts. Retain the public named-parameter contract.
	public function webhookProfileChoices( string $providerCode, string $repositoryId ): array {
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
		$providerCode = $this->provider_code( $providerCode );
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
		if ( ! ( $this->can_manage )() || ! $this->storage_available() || ! $this->valid_repository_id( $repositoryId ) ) {
			return array();
		}
		try {
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
			$target  = $this->current_target( $providerCode, $repositoryId, true );
			$choices = array();
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
			foreach ( null === $target ? array() : $this->secrets->webhookProfiles( $providerCode ) as $profile_id => $profile ) {
				if ( ! is_string( $profile_id ) || ! $this->valid_webhook_profile_id( $profile_id ) || ! is_array( $profile ) || 'file' !== ( $profile['source'] ?? null ) || ! empty( $profile['immutable'] ) || ! $this->applies_to( $target, $profile ) || ! is_string( $profile['label'] ?? null ) ) {
					continue;
				}
				$choices[] = array(
					'id'    => $profile_id,
					'label' => $profile['label'],
					'scope' => (string) $profile['scope'],
				);
			}

			return $choices;
		} catch ( \Throwable ) {
			return array();
		}
	}

	// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
	public function profile( string $providerCode, string $repositoryId, string $profileId ): ?WebhookProfileMetadata {
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
		$providerCode = $this->provider_code( $providerCode );
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
		if ( ! ( $this->can_manage )() || ! $this->storage_available() || ! $this->valid_repository_id( $repositoryId ) || ! $this->valid_webhook_profile_id( $profileId ) ) {
			return null;
		}
		try {
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
			$target = $this->current_target( $providerCode, $repositoryId, true );
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
			$profile = null === $target ? null : ( $this->secrets->webhookProfiles( $providerCode )[ $profileId ] ?? null );

			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
			return is_array( $profile ) && $this->applies_to( $target, $profile ) ? $this->metadata( $providerCode, $profileId, $profile ) : null;
		} catch ( \Throwable ) {
			return null;
		}
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase,WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public and protected methods retain the existing caller and override contracts. Retain the public named-parameter contract.
	public function assessSetup( AssistanceTarget $target, ?string $credentialProfileId, string $nonce ): RepositoryWebhookFitnessResult {
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
		return $this->assess( 'setup', $target, $credentialProfileId, null, null, null, $nonce, false );
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase,WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public and protected methods retain the existing caller and override contracts. Retain the public named-parameter contract.
	public function assessCheck( AssistanceTarget $target, ?string $credentialProfileId, string $hookId, string $profileId, int $profileRevision, string $nonce ): RepositoryWebhookFitnessResult {
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
		return $this->assess( 'check', $target, $credentialProfileId, $hookId, $profileId, $profileRevision, $nonce, false );
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase,WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public and protected methods retain the existing caller and override contracts. Retain the public named-parameter contract.
	public function assessReconfigure( AssistanceTarget $target, ?string $credentialProfileId, string $hookId, string $profileId, int $profileRevision, string $nonce ): RepositoryWebhookFitnessResult {
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
		return $this->assess( 'reconfigure', $target, $credentialProfileId, $hookId, $profileId, $profileRevision, $nonce, false );
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase,WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public and protected methods retain the existing caller and override contracts. Retain the public named-parameter contract.
	public function assessRemove( AssistanceTarget $target, ?string $credentialProfileId, string $hookId, string $profileId, int $profileRevision, string $nonce ): RepositoryWebhookFitnessResult {
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
		return $this->assess( 'remove', $target, $credentialProfileId, $hookId, $profileId, $profileRevision, $nonce, true );
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase,WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public and protected methods retain the existing caller and override contracts. Retain the public named-parameter contract.
	public function assessTest( AssistanceTarget $target, ?string $credentialProfileId, string $hookId, string $profileId, int $profileRevision, string $nonce ): RepositoryWebhookFitnessResult {
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
		return $this->assess( 'test', $target, $credentialProfileId, $hookId, $profileId, $profileRevision, $nonce, false );
	}

	// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
	public function setup( AssistanceTarget $target, ?string $credentialProfileId, string $nonce, ?string $webhookProfileId = null ): RepositoryWebhookOperationResult {
		return $this->with_target_lock(
			$target,
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
			fn (): RepositoryWebhookOperationResult => $this->setup_locked( $target, $credentialProfileId, $webhookProfileId, $nonce )
		);
	}

	// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
	public function check( AssistanceTarget $target, ?string $credentialProfileId, string $hookId, string $profileId, int $profileRevision, string $nonce ): RepositoryWebhookOperationResult {
		return $this->with_target_lock(
			$target,
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
			function () use ( $target, $credentialProfileId, $hookId, $profileId, $profileRevision, $nonce ): RepositoryWebhookOperationResult {
				$current = $this->authorize( 'check', $target, $nonce );
				// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
				$profile = null === $current ? null : $this->exact_profile( $current, $profileId, $profileRevision );
				// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
				if ( null === $current || null === $profile || ! $this->credential_profile_available( $current->providerCode(), (string) $credentialProfileId ) ) {
					return $this->failed( 'operation_unauthorized' );
				}
				try {
					// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
					if ( ! $this->identity_confirmed( 'check', $current, $credentialProfileId, $hookId ) ) {
						return $this->failed( 'repository_identity_unconfirmed' )->withProfile( $profile );
					}
					$provider = $this->complete_webhook_provider( $current->providerCode() );

					// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
					return $provider->check( $current->repositoryId(), $current->repository(), $hookId, $current->endpoint(), $credentialProfileId )->withProfile( $profile );
				} catch ( \Throwable ) {
					return $this->failed( 'operation_failed' )->withProfile( $profile );
				}
			}
		);
	}

	// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
	public function reconfigure( AssistanceTarget $target, ?string $credentialProfileId, string $hookId, string $profileId, int $profileRevision, string $nonce ): RepositoryWebhookOperationResult {
		return $this->with_target_lock(
			$target,
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
			function () use ( $target, $credentialProfileId, $hookId, $profileId, $profileRevision, $nonce ): RepositoryWebhookOperationResult {
				$current = $this->authorize( 'reconfigure', $target, $nonce );
				// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
				$record = null === $current ? null : $this->profile_record( $current->providerCode(), $profileId );
				// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
				if ( null === $current || null === $record || $profileRevision !== $record[0]->revision() || ! $this->applies_metadata( $current, $record[0] ) || ! $this->credential_profile_available( $current->providerCode(), (string) $credentialProfileId ) ) {
					return $this->failed( 'operation_unauthorized' );
				}
				$provider_started = false;
				try {
					// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
					if ( ! $this->identity_confirmed( 'reconfigure', $current, $credentialProfileId, $hookId ) ) {
						return $this->failed( 'repository_identity_unconfirmed' )->withProfile( $record[0] );
					}
					$provider         = $this->complete_webhook_provider( $current->providerCode() );
					$provider_started = true;

					// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
					return $provider->reconfigure( $current->repositoryId(), $current->repository(), $hookId, $current->endpoint(), $credentialProfileId, $record[1] )->withProfile( $record[0] );
				} catch ( \Throwable ) {
					if ( $provider_started ) {
						// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
						return $this->mutation_outcome_unknown( 'reconfigure_outcome_unknown', $hookId )->withProfile( $record[0] );
					}

					return $this->failed( 'operation_failed' )->withProfile( $record[0] );
				}
			}
		);
	}

	// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
	public function remove( AssistanceTarget $target, ?string $credentialProfileId, string $hookId, string $profileId, int $profileRevision, string $nonce ): RepositoryWebhookOperationResult {
		return $this->with_target_lock(
			$target,
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
			function () use ( $target, $credentialProfileId, $hookId, $profileId, $profileRevision, $nonce ): RepositoryWebhookOperationResult {
				$current = $this->authorize( 'remove', $target, $nonce, true );
				// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
				$record = null === $current ? null : $this->profile_record( $current->providerCode(), $profileId );
				// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
				if ( null === $current || null === $record || $profileRevision !== $record[0]->revision() || ! $this->applies_metadata( $current, $record[0] ) || ! $this->credential_profile_available( $current->providerCode(), (string) $credentialProfileId ) ) {
					return $this->failed( 'operation_unauthorized' );
				}
				$profile          = $record[0];
				$provider_started = false;
				try {
					// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
					if ( ! $this->identity_confirmed( 'remove', $current, $credentialProfileId, $hookId ) ) {
						return $this->failed( 'repository_identity_unconfirmed' )->withProfile( $profile );
					}
					$provider         = $this->complete_webhook_provider( $current->providerCode() );
					$provider_started = true;
					// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
					$result = $provider->remove( $current->repositoryId(), $current->repository(), $hookId, $current->endpoint(), $credentialProfileId )->withProfile( $profile );
					if ( $result->confirmsAbsence() && 'created' === $profile->disposition() && ! $this->delete_profile_if_revision( $current->providerCode(), $profile->id(), $profile->revision() ) ) {
						return $result->asPartial( 'local_profile_release_failed', 'The remote hook is absent; remove the retained local profile before replacing it.' );
					}

					return $result;
				} catch ( \Throwable ) {
					if ( $provider_started ) {
						// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
						return $this->mutation_outcome_unknown( 'remove_outcome_unknown', $hookId )->withProfile( $profile );
					}

					return $this->failed( 'operation_failed' )->withProfile( $profile );
				}
			}
		);
	}

	// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
	public function test( AssistanceTarget $target, ?string $credentialProfileId, string $hookId, string $profileId, int $profileRevision, string $nonce ): RepositoryWebhookOperationResult {
		return $this->with_target_lock(
			$target,
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
			function () use ( $target, $credentialProfileId, $hookId, $profileId, $profileRevision, $nonce ): RepositoryWebhookOperationResult {
				$current = $this->authorize( 'test', $target, $nonce );
				// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
				$profile = null === $current ? null : $this->exact_profile( $current, $profileId, $profileRevision );
				// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
				if ( null === $current || null === $profile || ! $this->credential_profile_available( $current->providerCode(), (string) $credentialProfileId ) ) {
					return $this->failed( 'operation_unauthorized' );
				}
				try {
					// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
					if ( ! $this->identity_confirmed( 'test', $current, $credentialProfileId, $hookId ) ) {
						return $this->failed( 'repository_identity_unconfirmed' )->withProfile( $profile );
					}
					$provider = $this->complete_webhook_provider( $current->providerCode() );

					// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
					return $provider->test( $current->repositoryId(), $current->repository(), $hookId, $current->endpoint(), $credentialProfileId )->withProfile( $profile );
				} catch ( \Throwable ) {
					return $this->failed( 'operation_failed' )->withProfile( $profile );
				}
			}
		);
	}

	private function assess( string $action, AssistanceTarget $target, ?string $credential_id, ?string $hook_id, ?string $profile_id, ?int $profile_revision, string $nonce, bool $allow_cleanup ): RepositoryWebhookFitnessResult {
		$current = $this->authorize( $action, $target, $nonce, $allow_cleanup );
		if ( null === $current || ! $this->credential_profile_available( $current->providerCode(), (string) $credential_id ) || ( null !== $profile_id && null === $this->exact_profile( $current, $profile_id, (int) $profile_revision ) ) ) {
			return $this->fitness_unavailable( 'assessment_unauthorized' );
		}
		try {
			return $this->provider_assessment( $action, $current, $credential_id, $hook_id );
		} catch ( \Throwable ) {
			return $this->fitness_unavailable( 'assessment_unavailable' );
		}
	}

	private function setup_locked( AssistanceTarget $target, ?string $credential_id, ?string $selected_profile_id, string $nonce ): RepositoryWebhookOperationResult {
		$current = $this->authorize( 'setup', $target, $nonce );
		if ( null === $current || ! $this->credential_profile_available( $current->providerCode(), (string) $credential_id ) ) {
			return $this->failed( 'operation_unauthorized' );
		}
		$created          = false;
		$provider_started = false;
		$metadata         = null;
		$profile_id       = null;
		$created_revision = null;
		try {
			$selection = null === $selected_profile_id ? null : $this->select_profile( $current, $selected_profile_id );
			if ( null !== $selected_profile_id && null === $selection ) {
				return $this->failed( 'operation_unauthorized' );
			}
			if ( ! $this->identity_confirmed( 'setup', $current, $credential_id ) ) {
				return $this->failed( 'repository_identity_unconfirmed' );
			}
			$provider = $this->complete_webhook_provider( $current->providerCode() );
			if ( null === $selection && null === $selected_profile_id ) {
				$profile_id       = $this->secrets->saveWebhook(
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
				$created          = true;
				$created_revision = 1;
				$selection        = $this->profile_record( $current->providerCode(), $profile_id );
			}
			if ( null === $selection ) {
				throw new \RuntimeException( 'The webhook profile snapshot is unavailable.' );
			}
			list($metadata, $secret) = $selection;
			$provider_started        = true;
			$result                  = $provider->setup( $current->repositoryId(), $current->repository(), $current->endpoint(), $credential_id, $secret );
			if ( $created && 'failed' === $result->state() ) {
				return $this->cleanup_created_profile( $current->providerCode(), $metadata->id(), $metadata->revision(), $result, $metadata, 'Retain the local profile and inspect both local and remote state.' );
			}

			return $result->withProfile( $metadata );
		} catch ( \Throwable ) {
			if ( $provider_started && null !== $metadata ) {
				return $this->failed( 'setup_outcome_unknown' )->asPartial( 'setup_outcome_unknown', 'Retain the local signing profile and inspect the provider before retrying.' )->withProfile( $metadata );
			}
			if ( $created && null !== $profile_id && null !== $created_revision ) {
				return $this->cleanup_created_profile( $current->providerCode(), $profile_id, $created_revision, $this->failed( 'operation_failed' ), $metadata, 'Retain the local profile and inspect local state before retrying.' );
			}

			return $this->failed( 'operation_failed' );
		}
	}

	private function identity_confirmed( string $action, AssistanceTarget $target, ?string $credential_id, ?string $hook_id = null ): bool {
		$fitness = $this->provider_assessment( $action, $target, $credential_id, $hook_id )->toArray();

		return 'supported' === $fitness['support']
			&& in_array( $fitness['suitability'], array( 'suitable', 'unknown' ), true )
			&& in_array( $fitness['evidence'], array( 'observed', 'inferred', 'unknown_by_design' ), true );
	}

	private function provider_assessment( string $action, AssistanceTarget $target, ?string $credential_id, ?string $hook_id ): RepositoryWebhookFitnessResult {
		$provider = $this->complete_webhook_provider( $target->providerCode() );

		return match ( $action ) {
			'setup'       => $provider->assess_setup( $target->repositoryId(), $target->repository(), $credential_id ),
			'check'       => $provider->assess_check( $target->repositoryId(), $target->repository(), $credential_id, (string) $hook_id ),
			'reconfigure' => $provider->assess_reconfigure( $target->repositoryId(), $target->repository(), $credential_id, (string) $hook_id ),
			'remove'      => $provider->assess_remove( $target->repositoryId(), $target->repository(), $credential_id, (string) $hook_id ),
			'test'        => $provider->assess_test( $target->repositoryId(), $target->repository(), $credential_id, (string) $hook_id ),
		};
	}

	private function complete_webhook_provider( string $provider_code ): RepositoryWebhookManagement {
		$fitness    = $this->providers->requireCapability( $provider_code, RepositoryWebhookFitness::class );
		$management = $this->providers->requireCapability( $provider_code, RepositoryWebhookManagement::class );
		$normalizer = $this->providers->requireCapability( $provider_code, WebhookNormalizer::class );
		if ( $fitness !== $management || $management !== $normalizer ) {
			throw new \RuntimeException( 'The provider webhook management capability is incomplete.' );
		}

		return $management;
	}

	/** @param callable(): RepositoryWebhookOperationResult $operation */
	private function with_target_lock( AssistanceTarget $target, callable $operation ): RepositoryWebhookOperationResult {
		$name = 'ran_booster:webhook:' . substr( hash( 'sha256', $target->providerCode() . "\0" . $target->repositoryId() ), 0, 40 );
		try {
			if ( isset( $this->held_locks[ $name ] ) || ! ( $this->acquire_lock )( $name ) ) {
				return $this->failed( 'operation_busy' );
			}
			$this->held_locks[ $name ] = true;
		} catch ( \Throwable ) {
			return $this->failed( 'operation_busy' );
		}
		try {
			$result = $operation();
		} catch ( \Throwable ) {
			$result = $this->failed( 'operation_failed' );
		}
		try {
			$released = ( $this->release_lock )( $name );
		} catch ( \Throwable ) {
			$released = false;
		}
		if ( $released ) {
			unset( $this->held_locks[ $name ] );

			return $result;
		}

		if ( 'succeeded' !== $result->state() || $result->confirmsAbsence() ) {
			return $result;
		}

		return $result->asPartial( 'operation_lock_release_failed', 'The operation completed, but do not retry until the current database request has ended.' );
	}

	private function authorize( string $action, AssistanceTarget $submitted, string $nonce, bool $allow_cleanup = false ): ?AssistanceTarget {
		if ( ! ( $this->can_manage )() || ! ( $this->verify_nonce )( $nonce, $this->nonce_action( $action, $submitted ) ) ) {
			return null;
		}
		$current = $this->current_target( $submitted->providerCode(), $submitted->repositoryId(), $allow_cleanup );

		return null !== $current && $current->toArray() === $submitted->toArray() ? $current : null;
	}

	private function nonce_action( string $action, AssistanceTarget $target ): string {
		return 'ran_booster_repository_webhook_' . $action . '_' . $target->providerCode() . '_' . $target->repositoryId();
	}

	private function current_target( string $provider_code, string $repository_id, bool $allow_cleanup ): ?AssistanceTarget {
		$current = $this->projected_target( $provider_code, $repository_id, true );
		if ( null === $current && $allow_cleanup ) {
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Retain the promoted constructor or external DTO property contract.
			$current = $this->readinessEvaluator->cleanupTarget( $provider_code, $repository_id, ( $this->endpoint )( $provider_code ) );
		}

		return $current;
	}

	private function projected_target( string $provider_code, string $repository_id, bool $require_ready_site ): ?AssistanceTarget {
		if ( ! $this->valid_repository_id( $repository_id ) ) {
			return null;
		}
		$readiness = $this->readiness( $provider_code )->toArray();
		if ( $require_ready_site && AssistanceReadiness::READY !== $readiness['site']['status'] ) {
			return null;
		}
		foreach ( $readiness['repositories'] as $repository ) {
			if ( $repository_id === $repository['repository_id'] && ( ! $require_ready_site || true === $repository['eligible'] ) ) {
				return new AssistanceTarget( $provider_code, $repository_id, $repository['repository'], $repository['label'], $repository['package_references'], $repository['deployment_policies'], $readiness['site']['callback_url'] );
			}
		}

		return null;
	}

	private function credential_profile_available( string $provider_code, string $credential_id ): bool {
		if ( ! $this->valid_credential_id( $credential_id ) ) {
			return false;
		}
		try {
			$profile = $this->secrets->credentialProfiles( $provider_code )[ $credential_id ] ?? null;

			return is_array( $profile ) && 'file' === ( $profile['source'] ?? null ) && empty( $profile['immutable'] );
		} catch ( \Throwable ) {
			return false;
		}
	}

	private function exact_profile( AssistanceTarget $target, string $profile_id, int $revision ): ?WebhookProfileMetadata {
		$profile = $this->secrets->webhookProfiles( $target->providerCode() )[ $profile_id ] ?? null;
		if ( ! is_array( $profile ) || (int) ( $profile['revision'] ?? 0 ) !== $revision || ! $this->applies_to( $target, $profile ) ) {
			return null;
		}

		return $this->metadata( $target->providerCode(), $profile_id, $profile );
	}

	/** @return array{WebhookProfileMetadata,string}|null */
	private function select_profile( AssistanceTarget $target, string $profile_id ): ?array {
		$materials = $this->secrets->webhookMaterials( $target->providerCode() );
		if ( isset( $materials[ $profile_id ] ) && 'file' === ( $materials[ $profile_id ]['source'] ?? null ) && empty( $materials[ $profile_id ]['immutable'] ) && $this->applies_to( $target, $materials[ $profile_id ] ) ) {
			return $this->profile_record_from_material( $target->providerCode(), $profile_id, $materials[ $profile_id ] );
		}

		return null;
	}

	/** @return array{WebhookProfileMetadata,string}|null */
	private function profile_record( string $provider_code, string $profile_id ): ?array {
		$material = $this->secrets->webhookMaterials( $provider_code )[ $profile_id ] ?? null;

		return is_array( $material ) ? $this->profile_record_from_material( $provider_code, $profile_id, $material ) : null;
	}

	/** @param array<string,mixed> $material @return array{WebhookProfileMetadata,string}|null */
	private function profile_record_from_material( string $provider_code, string $profile_id, array $material ): ?array {
		if ( ! is_string( $material['secret'] ?? null ) ) {
			return null;
		}

		return array( $this->metadata( $provider_code, $profile_id, $material ), $material['secret'] );
	}

	/** @param array<string,mixed> $profile */
	private function applies_to( AssistanceTarget $target, array $profile ): bool {
		if ( false === ( $profile['configured'] ?? true ) ) {
			return false;
		}
		if ( 'repository' === ( $profile['scope'] ?? null ) ) {
			return is_string( $profile['authority_id'] ?? null ) && hash_equals( $target->repositoryId(), $profile['authority_id'] );
		}

		return 'owner' === ( $profile['scope'] ?? null ) && is_string( $profile['target'] ?? null ) && 0 === strcasecmp( explode( '/', trim( $target->repository(), '/' ), 2 )[0], trim( $profile['target'], '/' ) );
	}

	private function applies_metadata( AssistanceTarget $target, WebhookProfileMetadata $profile ): bool {
		return $profile->providerCode() === $target->providerCode()
			&& ( ( 'repository' === $profile->scope() && hash_equals( $target->repositoryId(), $profile->authorityId() ) )
				|| ( 'owner' === $profile->scope() && 0 === strcasecmp( explode( '/', $target->repository(), 2 )[0], $profile->target() ) ) );
	}

	/** @param array<string,mixed> $profile */
	private function metadata( string $provider_code, string $profile_id, array $profile ): WebhookProfileMetadata {
		$disposition = 'assisted' === ( $profile['origin'] ?? 'manual' ) && 'repository' === ( $profile['scope'] ?? null ) ? 'created' : 'reused';

		return new WebhookProfileMetadata( $profile_id, $provider_code, (string) ( $profile['scope'] ?? '' ), (string) ( $profile['target'] ?? '' ), (string) ( $profile['authority_id'] ?? '' ), (int) ( $profile['revision'] ?? 1 ), $disposition, (string) ( $profile['source'] ?? 'file' ), ! empty( $profile['immutable'] ) );
	}

	private function cleanup_created_profile( string $provider_code, string $profile_id, int $revision, RepositoryWebhookOperationResult $result, ?WebhookProfileMetadata $fallback, string $remediation ): RepositoryWebhookOperationResult {
		if ( $this->delete_profile_if_revision( $provider_code, $profile_id, $revision ) ) {
			return $result;
		}
		try {
			$current = $this->profile_record( $provider_code, $profile_id )[0] ?? null;
			$read    = true;
		} catch ( \Throwable ) {
			$current = null;
			$read    = false;
		}
		$partial = $result->asPartial( 'profile_cleanup_failed', $remediation );

		return null === $current && ( $read || null === $fallback ) ? $partial : $partial->withProfile( $current ?? $fallback );
	}

	private function delete_profile_if_revision( string $provider_code, string $profile_id, int $revision ): bool {
		try {
			return $this->secrets->deleteWebhookIfRevision( $provider_code, $profile_id, $revision );
		} catch ( \Throwable ) {
			return false;
		}
	}

	private function failed( string $code ): RepositoryWebhookOperationResult {
		return $this->unknown_result( 'failed', $code, null, 'Review the current target, profile, credential and provider capability.' );
	}

	private function mutation_outcome_unknown( string $code, string $hook_id ): RepositoryWebhookOperationResult {
		return $this->unknown_result( 'ambiguous', $code, $hook_id, 'Inspect the identified remote hook and run Check before retrying.' );
	}

	private function unknown_result( string $state, string $code, ?string $hook_id, string $remediation ): RepositoryWebhookOperationResult {
		return new RepositoryWebhookOperationResult(
			$state,
			$code,
			gmdate( 'Y-m-d\TH:i:s\Z' ),
			$hook_id,
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

	private function fitness_unavailable( string $code ): RepositoryWebhookFitnessResult {
		return new RepositoryWebhookFitnessResult( 'unknown', 'unknown', 'unknown', 'assessment_unavailable', $code, gmdate( 'Y-m-d\TH:i:s\Z' ), 'Review the current target, profile, credential and provider capability.' );
	}

	private function storage_available(): bool {
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Retain the promoted constructor or external DTO property contract.
		return $this->readinessEvaluator->managedStorageAvailable();
	}

	private function valid_repository_id( string $repository_id ): bool {
		return '' !== trim( $repository_id ) && strlen( $repository_id ) <= 191 && 1 !== preg_match( '/[\x00-\x1F\x7F]/', $repository_id );
	}

	private function valid_profile_id( string $profile_id ): bool {
		return 1 === preg_match( '/^wh_[a-f0-9]{24}$/', $profile_id );
	}

	private function valid_webhook_profile_id( string $profile_id ): bool {
		return SecretsFile::CONSTANT_PROFILE === $profile_id || $this->valid_profile_id( $profile_id );
	}

	private function valid_credential_id( string $credential_id ): bool {
		return 1 === preg_match( '/^[A-Za-z0-9_-]{3,64}$/D', $credential_id );
	}

	private function provider_code( string $provider_code ): string {
		return ProviderCode::parse( $provider_code )->value;
	}
}
