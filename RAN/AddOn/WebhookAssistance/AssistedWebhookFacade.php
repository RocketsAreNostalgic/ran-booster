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

	/** @param callable(): bool|null $can_manage @param callable(string): string|null $endpoint @param callable(string,string): bool|null $verify_nonce @param callable(string): bool|null $acquire_lock @param callable(string): bool|null $release_lock */
	public function __construct(
		private WebhookAssistanceReadinessEvaluator $readiness_evaluator,
		private SecretsFile $secrets,
		private ProviderRegistry $providers,
		?callable $can_manage = null,
		?callable $endpoint = null,
		?callable $verify_nonce = null,
		?callable $acquire_lock = null,
		?callable $release_lock = null
	) {
		$this->can_manage   = null === $can_manage
			? static fn (): bool => current_user_can( 'manage_options' )
			: \Closure::fromCallable( $can_manage );
		$this->endpoint     = null === $endpoint
			? static fn ( string $provider_code ): string => rest_url( 'ran-booster/v1/webhooks/' . rawurlencode( $provider_code ) )
			: \Closure::fromCallable( $endpoint );
		$this->verify_nonce = null === $verify_nonce
			? static fn ( string $nonce, string $action ): bool => 1 === wp_verify_nonce( $nonce, $action )
			: \Closure::fromCallable( $verify_nonce );
		$this->acquire_lock = null === $acquire_lock
			? static function ( string $name ): bool {
				global $wpdb;
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- MySQL advisory locks are connection-local and have no persistent cacheable state.
				$result = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $name ) );

				return '' === trim( (string) ( $wpdb->last_error ?? '' ) ) && '1' === (string) $result;
			}
			: \Closure::fromCallable( $acquire_lock );
		$this->release_lock = null === $release_lock
			? static function ( string $name ): bool {
				global $wpdb;
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- MySQL advisory locks are connection-local and have no persistent cacheable state.
				$result = $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );

				return '' === trim( (string) ( $wpdb->last_error ?? '' ) ) && '1' === (string) $result;
			}
			: \Closure::fromCallable( $release_lock );
	}

	public function readiness( string $provider_code ): AssistanceReadiness {
		$provider_code = $this->provider_code( $provider_code );

		return $this->readiness_evaluator->evaluate( $provider_code, ( $this->endpoint )( $provider_code ) );
	}

	public function target( string $provider_code, string $repository_id ): ?AssistanceTarget {
		return $this->projected_target( $this->provider_code( $provider_code ), $repository_id, true );
	}

	public function credential_choices( string $provider_code ): array {
		$provider_code = $this->provider_code( $provider_code );
		if ( ! ( $this->can_manage )() || ! $this->storage_available() ) {
			return array();
		}
		try {
			$choices = array();
			foreach ( $this->secrets->credential_profiles( $provider_code ) as $profile ) {
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
	public function webhook_profile_choices( string $provider_code, string $repository_id ): array {
		$provider_code = $this->provider_code( $provider_code );
		if ( ! ( $this->can_manage )() || ! $this->storage_available() || ! $this->valid_repository_id( $repository_id ) ) {
			return array();
		}
		try {
			$target = $this->current_target( $provider_code, $repository_id, true );
			if ( null === $target ) {
				return array();
			}
			$choices = array();
			foreach ( $this->secrets->webhook_profiles( $provider_code ) as $profile_id => $profile ) {
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

	public function profile( string $provider_code, string $repository_id, string $profile_id ): ?WebhookProfileMetadata {
		$provider_code = $this->provider_code( $provider_code );
		if ( ! ( $this->can_manage )() || ! $this->storage_available() || ! $this->valid_repository_id( $repository_id ) || ! $this->valid_webhook_profile_id( $profile_id ) ) {
			return null;
		}
		try {
			$target  = $this->current_target( $provider_code, $repository_id, true );
			$profile = null === $target ? null : ( $this->secrets->webhook_profiles( $provider_code )[ $profile_id ] ?? null );

			return is_array( $profile ) && $this->applies_to( $target, $profile ) ? $this->metadata( $provider_code, $profile_id, $profile ) : null;
		} catch ( \Throwable ) {
			return null;
		}
	}

	public function assess_setup( AssistanceTarget $target, ?string $credential_profile_id, string $nonce ): RepositoryWebhookFitnessResult {
		return $this->assess( 'setup', $target, $credential_profile_id, null, null, null, $nonce, false );
	}

	public function assess_check( AssistanceTarget $target, ?string $credential_profile_id, string $hook_id, string $profile_id, int $profile_revision, string $nonce ): RepositoryWebhookFitnessResult {
		return $this->assess( 'check', $target, $credential_profile_id, $hook_id, $profile_id, $profile_revision, $nonce, false );
	}

	public function assess_reconfigure( AssistanceTarget $target, ?string $credential_profile_id, string $hook_id, string $profile_id, int $profile_revision, string $nonce ): RepositoryWebhookFitnessResult {
		return $this->assess( 'reconfigure', $target, $credential_profile_id, $hook_id, $profile_id, $profile_revision, $nonce, false );
	}

	public function assess_remove( AssistanceTarget $target, ?string $credential_profile_id, string $hook_id, string $profile_id, int $profile_revision, string $nonce ): RepositoryWebhookFitnessResult {
		return $this->assess( 'remove', $target, $credential_profile_id, $hook_id, $profile_id, $profile_revision, $nonce, true );
	}

	public function assess_test( AssistanceTarget $target, ?string $credential_profile_id, string $hook_id, string $profile_id, int $profile_revision, string $nonce ): RepositoryWebhookFitnessResult {
		return $this->assess( 'test', $target, $credential_profile_id, $hook_id, $profile_id, $profile_revision, $nonce, false );
	}

	public function setup( AssistanceTarget $target, ?string $credential_profile_id, string $nonce, ?string $webhook_profile_id = null ): RepositoryWebhookOperationResult {
		return $this->with_target_lock(
			$target,
			fn (): RepositoryWebhookOperationResult => $this->setup_locked( $target, $credential_profile_id, $webhook_profile_id, $nonce )
		);
	}

	public function check( AssistanceTarget $target, ?string $credential_profile_id, string $hook_id, string $profile_id, int $profile_revision, string $nonce ): RepositoryWebhookOperationResult {
		return $this->with_target_lock(
			$target,
			function () use ( $target, $credential_profile_id, $hook_id, $profile_id, $profile_revision, $nonce ): RepositoryWebhookOperationResult {
				$current = $this->authorize( 'check', $target, $nonce );
				$profile = null === $current ? null : $this->exact_profile( $current, $profile_id, $profile_revision );
				if ( null === $current || null === $profile || ! $this->credential_profile_available( $current->provider_code(), (string) $credential_profile_id ) ) {
					return $this->failed( 'operation_unauthorized' );
				}
				try {
					if ( ! $this->identity_confirmed( 'check', $current, $credential_profile_id, $hook_id ) ) {
						return $this->failed( 'repository_identity_unconfirmed' )->with_profile( $profile );
					}
					$provider = $this->complete_webhook_provider( $current->provider_code() );

					return $provider->check( $current->repository_id(), $current->repository(), $hook_id, $current->endpoint(), $credential_profile_id )->with_profile( $profile );
				} catch ( \Throwable ) {
					return $this->failed( 'operation_failed' )->with_profile( $profile );
				}
			}
		);
	}

	public function reconfigure( AssistanceTarget $target, ?string $credential_profile_id, string $hook_id, string $profile_id, int $profile_revision, string $nonce ): RepositoryWebhookOperationResult {
		return $this->with_target_lock(
			$target,
			function () use ( $target, $credential_profile_id, $hook_id, $profile_id, $profile_revision, $nonce ): RepositoryWebhookOperationResult {
				$current = $this->authorize( 'reconfigure', $target, $nonce );
				$record  = null === $current ? null : $this->profile_record( $current->provider_code(), $profile_id );
				if ( null === $current || null === $record || $profile_revision !== $record[0]->revision() || ! $this->applies_metadata( $current, $record[0] ) || ! $this->credential_profile_available( $current->provider_code(), (string) $credential_profile_id ) ) {
					return $this->failed( 'operation_unauthorized' );
				}
				$provider_started = false;
				try {
					if ( ! $this->identity_confirmed( 'reconfigure', $current, $credential_profile_id, $hook_id ) ) {
						return $this->failed( 'repository_identity_unconfirmed' )->with_profile( $record[0] );
					}
					$provider         = $this->complete_webhook_provider( $current->provider_code() );
					$provider_started = true;

					return $provider->reconfigure( $current->repository_id(), $current->repository(), $hook_id, $current->endpoint(), $credential_profile_id, $record[1] )->with_profile( $record[0] );
				} catch ( \Throwable ) {
					if ( $provider_started ) {
						return $this->mutation_outcome_unknown( 'reconfigure_outcome_unknown', $hook_id )->with_profile( $record[0] );
					}

					return $this->failed( 'operation_failed' )->with_profile( $record[0] );
				}
			}
		);
	}

	public function remove( AssistanceTarget $target, ?string $credential_profile_id, string $hook_id, string $profile_id, int $profile_revision, string $nonce ): RepositoryWebhookOperationResult {
		return $this->with_target_lock(
			$target,
			function () use ( $target, $credential_profile_id, $hook_id, $profile_id, $profile_revision, $nonce ): RepositoryWebhookOperationResult {
				$current = $this->authorize( 'remove', $target, $nonce, true );
				$record  = null === $current ? null : $this->profile_record( $current->provider_code(), $profile_id );
				if ( null === $current || null === $record || $profile_revision !== $record[0]->revision() || ! $this->applies_metadata( $current, $record[0] ) || ! $this->credential_profile_available( $current->provider_code(), (string) $credential_profile_id ) ) {
					return $this->failed( 'operation_unauthorized' );
				}
				$profile          = $record[0];
				$provider_started = false;
				try {
					if ( ! $this->identity_confirmed( 'remove', $current, $credential_profile_id, $hook_id ) ) {
						return $this->failed( 'repository_identity_unconfirmed' )->with_profile( $profile );
					}
					$provider         = $this->complete_webhook_provider( $current->provider_code() );
					$provider_started = true;
					$result           = $provider->remove( $current->repository_id(), $current->repository(), $hook_id, $current->endpoint(), $credential_profile_id )->with_profile( $profile );
					if ( $result->confirms_absence() && 'created' === $profile->disposition() && ! $this->delete_profile_if_revision( $current->provider_code(), $profile->id(), $profile->revision() ) ) {
						return $result->as_partial( 'local_profile_release_failed', 'The remote hook is absent; remove the retained local profile before replacing it.' );
					}

					return $result;
				} catch ( \Throwable ) {
					if ( $provider_started ) {
						return $this->mutation_outcome_unknown( 'remove_outcome_unknown', $hook_id )->with_profile( $profile );
					}

					return $this->failed( 'operation_failed' )->with_profile( $profile );
				}
			}
		);
	}

	public function test( AssistanceTarget $target, ?string $credential_profile_id, string $hook_id, string $profile_id, int $profile_revision, string $nonce ): RepositoryWebhookOperationResult {
		return $this->with_target_lock(
			$target,
			function () use ( $target, $credential_profile_id, $hook_id, $profile_id, $profile_revision, $nonce ): RepositoryWebhookOperationResult {
				$current = $this->authorize( 'test', $target, $nonce );
				$profile = null === $current ? null : $this->exact_profile( $current, $profile_id, $profile_revision );
				if ( null === $current || null === $profile || ! $this->credential_profile_available( $current->provider_code(), (string) $credential_profile_id ) ) {
					return $this->failed( 'operation_unauthorized' );
				}
				try {
					if ( ! $this->identity_confirmed( 'test', $current, $credential_profile_id, $hook_id ) ) {
						return $this->failed( 'repository_identity_unconfirmed' )->with_profile( $profile );
					}
					$provider = $this->complete_webhook_provider( $current->provider_code() );

					return $provider->test( $current->repository_id(), $current->repository(), $hook_id, $current->endpoint(), $credential_profile_id )->with_profile( $profile );
				} catch ( \Throwable ) {
					return $this->failed( 'operation_failed' )->with_profile( $profile );
				}
			}
		);
	}

	/** @param 'setup'|'check'|'reconfigure'|'remove'|'test' $action */
	private function assess( string $action, AssistanceTarget $target, ?string $credential_id, ?string $hook_id, ?string $profile_id, ?int $profile_revision, string $nonce, bool $allow_cleanup ): RepositoryWebhookFitnessResult {
		$current = $this->authorize( $action, $target, $nonce, $allow_cleanup );
		if ( null === $current || ! $this->credential_profile_available( $current->provider_code(), (string) $credential_id ) || ( null !== $profile_id && null === $this->exact_profile( $current, $profile_id, (int) $profile_revision ) ) ) {
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
		if ( null === $current || ! $this->credential_profile_available( $current->provider_code(), (string) $credential_id ) ) {
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
			$provider = $this->complete_webhook_provider( $current->provider_code() );
			if ( null === $selection && null === $selected_profile_id ) {
				$profile_id       = $this->secrets->save_webhook(
					$current->provider_code(),
					null,
					array(
						'label'        => 'Assisted hook for ' . $current->repository(),
						'scope'        => 'repository',
						'target'       => $current->repository(),
						'authority_id' => $current->repository_id(),
						'origin'       => 'assisted',
					),
					bin2hex( random_bytes( 32 ) )
				);
				$created          = true;
				$created_revision = 1;
				$selection        = $this->profile_record( $current->provider_code(), $profile_id );
			}
			if ( null === $selection ) {
				throw new \RuntimeException( 'The webhook profile snapshot is unavailable.' );
			}
			list($metadata, $secret) = $selection;
			$provider_started        = true;
			$result                  = $provider->setup( $current->repository_id(), $current->repository(), $current->endpoint(), $credential_id, $secret );
			if ( $created && 'failed' === $result->state() ) {
				return $this->cleanup_created_profile( $current->provider_code(), $metadata->id(), $metadata->revision(), $result, $metadata, 'Retain the local profile and inspect both local and remote state.' );
			}

			return $result->with_profile( $metadata );
		} catch ( \Throwable ) {
			if ( $provider_started && null !== $metadata ) {
				return $this->failed( 'setup_outcome_unknown' )->as_partial( 'setup_outcome_unknown', 'Retain the local signing profile and inspect the provider before retrying.' )->with_profile( $metadata );
			}
			if ( $created && null !== $profile_id && null !== $created_revision ) {
				return $this->cleanup_created_profile( $current->provider_code(), $profile_id, $created_revision, $this->failed( 'operation_failed' ), $metadata, 'Retain the local profile and inspect local state before retrying.' );
			}

			return $this->failed( 'operation_failed' );
		}
	}

	/** @param 'setup'|'check'|'reconfigure'|'remove'|'test' $action */
	private function identity_confirmed( string $action, AssistanceTarget $target, ?string $credential_id, ?string $hook_id = null ): bool {
		$fitness = $this->provider_assessment( $action, $target, $credential_id, $hook_id )->to_array();

		return 'supported' === $fitness['support']
			&& in_array( $fitness['suitability'], array( 'suitable', 'unknown' ), true )
			&& in_array( $fitness['evidence'], array( 'observed', 'inferred', 'unknown_by_design' ), true );
	}

	/** @param 'setup'|'check'|'reconfigure'|'remove'|'test' $action */
	private function provider_assessment( string $action, AssistanceTarget $target, ?string $credential_id, ?string $hook_id ): RepositoryWebhookFitnessResult {
		$provider = $this->complete_webhook_provider( $target->provider_code() );

		return match ( $action ) {
			'setup'       => $provider->assess_setup( $target->repository_id(), $target->repository(), $credential_id ),
			'check'       => $provider->assess_check( $target->repository_id(), $target->repository(), $credential_id, (string) $hook_id ),
			'reconfigure' => $provider->assess_reconfigure( $target->repository_id(), $target->repository(), $credential_id, (string) $hook_id ),
			'remove'      => $provider->assess_remove( $target->repository_id(), $target->repository(), $credential_id, (string) $hook_id ),
			'test'        => $provider->assess_test( $target->repository_id(), $target->repository(), $credential_id, (string) $hook_id ),
		};
	}

	/** @return RepositoryWebhookManagement&RepositoryWebhookFitness&WebhookNormalizer */
	private function complete_webhook_provider( string $provider_code ): RepositoryWebhookManagement {
		$fitness    = $this->providers->require_capability( $provider_code, RepositoryWebhookFitness::class );
		$management = $this->providers->require_capability( $provider_code, RepositoryWebhookManagement::class );
		$normalizer = $this->providers->require_capability( $provider_code, WebhookNormalizer::class );
		if ( $fitness !== $management || $management !== $normalizer ) {
			throw new \RuntimeException( 'The provider webhook management capability is incomplete.' );
		}

		return $management;
	}

	/** @param callable(): RepositoryWebhookOperationResult $operation */
	private function with_target_lock( AssistanceTarget $target, callable $operation ): RepositoryWebhookOperationResult {
		$name = 'ran_booster:webhook:' . substr( hash( 'sha256', $target->provider_code() . "\0" . $target->repository_id() ), 0, 40 );
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

		if ( 'succeeded' !== $result->state() || $result->confirms_absence() ) {
			return $result;
		}

		return $result->as_partial( 'operation_lock_release_failed', 'The operation completed, but do not retry until the current database request has ended.' );
	}

	private function authorize( string $action, AssistanceTarget $submitted, string $nonce, bool $allow_cleanup = false ): ?AssistanceTarget {
		if ( ! ( $this->can_manage )() || ! ( $this->verify_nonce )( $nonce, $this->nonce_action( $action, $submitted ) ) ) {
			return null;
		}
		$current = $this->current_target( $submitted->provider_code(), $submitted->repository_id(), $allow_cleanup );

		return null !== $current && $current->to_array() === $submitted->to_array() ? $current : null;
	}

	private function nonce_action( string $action, AssistanceTarget $target ): string {
		return 'ran_booster_repository_webhook_' . $action . '_' . $target->provider_code() . '_' . $target->repository_id();
	}

	private function current_target( string $provider_code, string $repository_id, bool $allow_cleanup ): ?AssistanceTarget {
		$current = $this->projected_target( $provider_code, $repository_id, true );
		if ( null === $current && $allow_cleanup ) {
			$current = $this->readiness_evaluator->cleanup_target( $provider_code, $repository_id, ( $this->endpoint )( $provider_code ) );
		}

		return $current;
	}

	private function projected_target( string $provider_code, string $repository_id, bool $require_ready_site ): ?AssistanceTarget {
		if ( ! $this->valid_repository_id( $repository_id ) ) {
			return null;
		}
		$readiness = $this->readiness( $provider_code )->to_array();
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
			$profile = $this->secrets->credential_profiles( $provider_code )[ $credential_id ] ?? null;

			return is_array( $profile ) && 'file' === ( $profile['source'] ?? null ) && empty( $profile['immutable'] );
		} catch ( \Throwable ) {
			return false;
		}
	}

	private function exact_profile( AssistanceTarget $target, string $profile_id, int $revision ): ?WebhookProfileMetadata {
		$profile = $this->secrets->webhook_profiles( $target->provider_code() )[ $profile_id ] ?? null;
		if ( ! is_array( $profile ) || (int) ( $profile['revision'] ?? 0 ) !== $revision || ! $this->applies_to( $target, $profile ) ) {
			return null;
		}

		return $this->metadata( $target->provider_code(), $profile_id, $profile );
	}

	/** @return array{WebhookProfileMetadata,string}|null */
	private function select_profile( AssistanceTarget $target, string $profile_id ): ?array {
		$materials = $this->secrets->webhook_materials( $target->provider_code() );
		if ( isset( $materials[ $profile_id ] ) && 'file' === ( $materials[ $profile_id ]['source'] ?? null ) && empty( $materials[ $profile_id ]['immutable'] ) && $this->applies_to( $target, $materials[ $profile_id ] ) ) {
			return $this->profile_record_from_material( $target->provider_code(), $profile_id, $materials[ $profile_id ] );
		}

		return null;
	}

	/** @return array{WebhookProfileMetadata,string}|null */
	private function profile_record( string $provider_code, string $profile_id ): ?array {
		$material = $this->secrets->webhook_materials( $provider_code )[ $profile_id ] ?? null;

		return is_array( $material ) ? $this->profile_record_from_material( $provider_code, $profile_id, $material ) : null;
	}

	/**
	 * @param array<string,mixed> $material
	 * @return array{WebhookProfileMetadata,string}|null
	 */
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
			return is_string( $profile['authority_id'] ?? null ) && hash_equals( $target->repository_id(), $profile['authority_id'] );
		}

		return 'owner' === ( $profile['scope'] ?? null ) && is_string( $profile['target'] ?? null ) && 0 === strcasecmp( explode( '/', trim( $target->repository(), '/' ), 2 )[0], trim( $profile['target'], '/' ) );
	}

	private function applies_metadata( AssistanceTarget $target, WebhookProfileMetadata $profile ): bool {
		return $profile->provider_code() === $target->provider_code()
			&& ( ( 'repository' === $profile->scope() && hash_equals( $target->repository_id(), $profile->authority_id() ) )
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
		$partial = $result->as_partial( 'profile_cleanup_failed', $remediation );

		return null === $current && ( $read || null === $fallback ) ? $partial : $partial->with_profile( $current ?? $fallback );
	}

	private function delete_profile_if_revision( string $provider_code, string $profile_id, int $revision ): bool {
		try {
			return $this->secrets->delete_webhook_if_revision( $provider_code, $profile_id, $revision );
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
		return $this->readiness_evaluator->managed_storage_available();
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
