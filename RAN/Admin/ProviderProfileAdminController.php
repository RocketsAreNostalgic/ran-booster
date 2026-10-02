<?php

declare(strict_types=1);

namespace RAN\Admin;

use RAN\Dashboard;
use RAN\Logging\BoosterLogger;
use RAN\RepositoryProvider\{Admin\ProviderAdminMetadata, CredentialedPublicRepositoryBrowser, CredentialValidator, InvalidCredentialInput, InvalidWebhookInput, ProviderCode, ProviderRegistry, UnsupportedProviderCapability, WebhookNormalizer};
use RAN\Secrets\SecretsFile;
use RAN\Storage\CredentialUsageReader;
use RAN\WordPress\WordPressUpdaterLock;
use RAN\Admin\Interaction\{CoreAdminInteractionFacade, SignedAdminInteractionRequest};

/** @internal Core provider-profile request and response owner. */
class ProviderProfileAdminController {
	public const TARGET_KEY      = 'core_provider_profiles';
	public const TARGET_SELECTOR = '#ran-booster-provider-profile-region';
	private RepositoryBranchCheckEvidenceStore $branch_check_evidence;
	public function __construct(
		private Dashboard $dashboard,
		private ProviderRegistry $providers,
		private SecretsFile $secrets,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
		private ManagedPackageWebhookAuthorityResolver $webhook_authorities,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
		private WordPressUpdaterLock $updater_lock,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
		private CredentialUsageReader $credential_usage,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
		private PublicRepositoryLookupProfileStore $public_lookup_profiles,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
		private CredentialExpiryObservationStore $expiry_observations,
		private ?CoreAdminInteractionFacade $interaction = null,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
		?RepositoryBranchCheckEvidenceStore $branch_check_evidence = null
	) {
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
		$this->branch_check_evidence = $branch_check_evidence ?? new RepositoryBranchCheckEvidenceStore();
	}
	public function manage_credential_profiles( array $request ): void {
		$this->authorize( 'ran-booster-save-secrets' );
		$action              = is_string( $request['action'] ?? null ) ? $request['action'] : '';
		$interaction_request = null;
		try {
			$provider = $this->provider_code( $request );
			if ( null !== $this->interaction ) {
				$interaction_request = $this->interaction->provider_profile_request( $action, $provider->value );
			}
			$id = $this->profile_id( $request );
			if ( 'delete-access-profile' === $action ) {
				$message = $this->delete_access_profile( $provider, $id );
			} elseif ( 'delete-webhook-profile' === $action ) {
				$message = $this->delete_webhook_profile( $provider, $id );
			} else {
				$label = is_string( $request['label'] ?? null )
					? sanitize_text_field( wp_unslash( $request['label'] ) )
					: '';
				if ( '' === trim( $label ) ) {
					throw new CredentialRequestException( __( 'Enter a label for this credential.', 'ran-booster' ) );
				}
				$message = 'save-access-profile' === $action
					? $this->save_access_profile( $request, $provider, $id, $label )
					: $this->save_webhook_profile( $request, $provider, $id, $label );
			}
			$this->complete_mutation( $message, $interaction_request );
		} catch ( \Throwable $exception ) {
			$this->profile_failure( $action, $interaction_request, $exception );
		}
	}
	// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
	public function manage_credential_validation( array $request, bool $htmx_request ): void {
		$this->authorize( 'ran-booster-save-secrets' );
		$provider = null;
		$id       = null;
		$message  = null;
		$error    = null;
		$status   = 200;
		try {
			$provider = $this->provider_code( $request );
			$id       = $this->profile_id( $request );
			if ( null === $id ) {
				throw new CredentialRequestException( __( 'Choose a repository credential to validate.', 'ran-booster' ) );
			}
			try {
				$validator = $this->providers->require_capability( $provider, CredentialValidator::class );
			} catch ( UnsupportedProviderCapability ) {
				throw new CredentialRequestException( __( 'Credential validation is unavailable for this repository provider.', 'ran-booster' ) );
			}
			$result = $validator->validate_credential( $id );
			if ( $result->is_valid() ) {
				if ( null !== $result->expiry ) {
					// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Retain the promoted constructor or external DTO property contract.
					$this->expiry_observations->record_provider_expiry(
						$provider->value,
						$id,
						$result->expiry,
						gmdate( 'Y-m-d\\TH:i:s\\Z' )
					);
					if ( $result->expiry->is_known() && is_string( $result->expiry->expires_at ) ) {
						$this->secrets->record_credential_provider_expiry(
							$provider,
							$id,
							substr( $result->expiry->expires_at, 0, 10 )
						);
					}
				}
				$message = __( 'Repository credential validated successfully.', 'ran-booster' );
				// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
				if ( ! $htmx_request ) {
					$this->dashboard->add_message( $message );
				}
			} else {
				$error = $result->get_display_message();
				if ( null === $error ) {
					throw new \LogicException( 'Invalid credential validation results require a core display message.' );
				}
				// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
				if ( $htmx_request ) {
					$status = 422;
				} else {
					$this->dashboard->add_message( new \WP_Error( 'ran_booster_credential_validation_error', $error ) );
				}
			}
		} catch ( \Throwable $exception ) {
			$error  = $this->record_failure( $exception, 'validate-access-profile', 'credential_validation' );
			$status = $exception instanceof CredentialRequestException ? 422 : 500;
		}
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
		if ( $htmx_request && $provider instanceof ProviderCode && is_string( $id ) ) {
			$this->respond_to_htmx_credential_validation( $id, $message, $error, $status );
		}
	}
	// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
	public function manage_public_lookup_profile( array $request, bool $htmx_request ): void {
		$this->authorize( 'ran-booster-save-public-lookup-profile' );
		$provider = null;
		$message  = null;
		$error    = null;
		$status   = 200;
		try {
			$provider = $this->provider_code( $request );
			try {
				$browser = $this->providers->require_capability( $provider, CredentialedPublicRepositoryBrowser::class );
			} catch ( UnsupportedProviderCapability ) {
				throw new CredentialRequestException( __( 'A default public repository lookup profile is unavailable for this provider.', 'ran-booster' ) );
			}
			if ( ! $browser->get_public_repository_browse_metadata()->supports_provider_default_profile ) {
				throw new CredentialRequestException( __( 'A default public repository lookup profile is unavailable for this provider.', 'ran-booster' ) );
			}
			if ( ! array_key_exists( 'profile_id', $request ) || ! is_string( $request['profile_id'] ) ) {
				throw new CredentialRequestException( __( 'Choose Anonymous or a saved repository credential.', 'ran-booster' ) );
			}
			$profile_id = wp_unslash( $request['profile_id'] );
			if ( '' !== $profile_id && 1 !== preg_match( '/^[A-Za-z0-9_-]{3,64}$/D', $profile_id ) ) {
				throw new CredentialRequestException( __( 'Choose Anonymous or a saved repository credential.', 'ran-booster' ) );
			}
			if ( '' !== $profile_id ) {
				$profile = $this->secrets->credential_profiles( $provider )[ $profile_id ] ?? null;
				if ( ! is_array( $profile ) || empty( $profile['configured'] ) ) {
					throw new CredentialRequestException( __( 'Choose Anonymous or a saved repository credential.', 'ran-booster' ) );
				}
			}
			$this->branch_check_evidence->bump_provider_generation( $provider->value );
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Retain the promoted constructor or external DTO property contract.
			$this->public_lookup_profiles->set( $provider->value, '' === $profile_id ? null : $profile_id );
			$message = '' === $profile_id
				? __( 'Public repository lookup will use anonymous access.', 'ran-booster' )
				: __( 'Default public repository lookup profile saved.', 'ran-booster' );
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
			if ( ! $htmx_request ) {
				$this->dashboard->add_message( $message );
			}
		} catch ( \Throwable $exception ) {
			$error  = $this->record_failure( $exception, 'save-public-lookup-profile', 'public_lookup_profile' );
			$status = $exception instanceof CredentialRequestException ? 422 : 500;
		}
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
		if ( $htmx_request && $provider instanceof ProviderCode ) {
			$this->respond_to_htmx_public_lookup_profile( $provider->value, $message, $error, $status );
		}
	}
	private function save_access_profile(
		array $request,
		ProviderCode $provider,
		?string $id,
		string $label
	): string {
		$kind          = is_string( $request['kind'] ?? null ) ? sanitize_key( wp_unslash( $request['kind'] ) ) : '';
		$kind_metadata = $this->provider_admin( $provider )->get_credential_kind( $kind );
		if ( null === $kind_metadata ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Fixed translated failure text is escaped by the controller response boundary, not when thrown.
			throw new CredentialRequestException( __( 'Choose a supported credential type.', 'ran-booster' ) );
		}
		$submitted_configuration = is_array( $request['configuration'] ?? null )
			? wp_unslash( $request['configuration'] )
			: array();
		$configuration           = array();
		foreach ( $kind_metadata->fields as $field ) {
			$value                        = $submitted_configuration[ $field->key ] ?? '';
			$configuration[ $field->key ] = is_string( $value ) ? sanitize_text_field( $value ) : '';
			if ( $field->required && '' === trim( $configuration[ $field->key ] ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Fixed translated failure text is escaped by the controller response boundary, not when thrown.
				throw new CredentialRequestException( __( 'Complete every required credential field.', 'ran-booster' ) );
			}
			if ( 'email' === $field->type && false === filter_var( $configuration[ $field->key ], FILTER_VALIDATE_EMAIL ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Fixed translated failure text is escaped by the controller response boundary, not when thrown.
				throw new CredentialRequestException( __( 'Enter a valid account email address.', 'ran-booster' ) );
			}
		}
		$secret = is_string( $request['secret'] ?? null ) ? trim( wp_unslash( $request['secret'] ) ) : '';
		if ( null === $id && '' === $secret ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Fixed translated failure text is escaped by the controller response boundary, not when thrown.
			throw new CredentialRequestException( __( 'Enter the credential secret.', 'ran-booster' ) );
		}
		$manual_expiry_submitted = array_key_exists( 'expires_on', $request );
		$manual_expiry           = null;
		if ( $manual_expiry_submitted ) {
			if ( ! is_string( $request['expires_on'] ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Fixed translated failure text is escaped by the controller response boundary, not when thrown.
				throw new CredentialRequestException( __( 'Enter a valid credential expiry date.', 'ran-booster' ) );
			}
			$manual_expiry = trim( wp_unslash( $request['expires_on'] ) );
			$manual_expiry = '' === $manual_expiry ? null : $manual_expiry;
			if ( null !== $manual_expiry
				&& ( 1 !== preg_match( '/\A(\d{4})-(\d{2})-(\d{2})\z/D', $manual_expiry, $expiry_parts )
					|| ! checkdate( (int) $expiry_parts[2], (int) $expiry_parts[3], (int) $expiry_parts[1] ) ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Fixed translated failure text is escaped by the controller response boundary, not when thrown.
				throw new CredentialRequestException( __( 'Enter a valid expiry / removal date.', 'ran-booster' ) );
			}
		}
		$existing_manual_expiry = null;
		$provider_expiry        = null;
		if ( null !== $id ) {
			try {
				// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Retain the promoted constructor or external DTO property contract.
				$observation            = $this->expiry_observations->get( $provider->value, $id );
				$existing_manual_expiry = is_string( $observation['manual_expires_on'] ?? null ) ? $observation['manual_expires_on'] : null;
				$provider_expires_at    = $observation['provider_expires_at'] ?? null;
				$provider_expiry        = is_string( $provider_expires_at ) ? substr( $provider_expires_at, 0, 10 ) : null;
			} catch ( \RuntimeException ) {
				$existing_manual_expiry = null;
				$provider_expiry        = null;
			}
		}
		if ( '' === $secret && null !== $manual_expiry && null !== $provider_expiry && $manual_expiry > $provider_expiry ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Fixed translated failure text is escaped by the controller response boundary, not when thrown.
			throw new CredentialRequestException( __( 'The expiry / removal date cannot be later than the expiry reported by the provider.', 'ran-booster' ) );
		}
		$self_destruct = isset( $request['self_destruct'] ) && '1' === $request['self_destruct'];
		if ( $self_destruct && null === $manual_expiry ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Fixed translated failure text is escaped by the controller response boundary, not when thrown.
			throw new CredentialRequestException( __( 'Enter an expiry / removal date before enabling automatic removal.', 'ran-booster' ) );
		}
		$manual_expiry_is_provider_fallback = '' === $secret
			&& null === $existing_manual_expiry
			&& null !== $provider_expiry
			&& $manual_expiry === $provider_expiry;
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Retain the promoted constructor or external DTO property contract.
		return $this->updater_lock->run(
			function () use ( $provider, $id, $secret, $label, $kind, $configuration, $self_destruct, $manual_expiry_submitted, $manual_expiry, $manual_expiry_is_provider_fallback ): string {
				$existing_profile = null === $id ? null : ( $this->secrets->credential_profiles( $provider )[ $id ] ?? null );
				$is_replacement   = is_array( $existing_profile ) && '' !== $secret;
				$access_changed   = is_array( $existing_profile )
					&& ( $is_replacement
						|| ( $existing_profile['kind'] ?? null ) !== $kind
						|| ! is_array( $existing_profile['configuration'] ?? null )
						|| $configuration !== $existing_profile['configuration'] );
				if ( $access_changed && null !== $id ) {
					$this->branch_check_evidence->bump_profile_generation( $provider->value, $id );
				}
				$saved_id      = $this->secrets->save_credential(
					$provider,
					$id,
					array(
						'label'         => $label,
						'kind'          => $kind,
						'configuration' => $configuration,
						'self_destruct' => $self_destruct,
						'destroy_on'    => $self_destruct ? $manual_expiry : null,
					),
					$secret,
					true
				);
				$saved_profile = $this->secrets->credential_profiles( $provider )[ $saved_id ] ?? null;
				if ( ! is_array( $saved_profile )
					|| ( $saved_profile['label'] ?? null ) !== $label
					|| ( $saved_profile['kind'] ?? null ) !== $kind
					|| ! is_array( $saved_profile['configuration'] ?? null )
					|| array() !== array_diff_assoc( $configuration, $saved_profile['configuration'] )
					|| ( $saved_profile['self_destruct'] ?? null ) !== $self_destruct
					|| ( $self_destruct ? $manual_expiry : null ) !== ( $saved_profile['destroy_on'] ?? null )
					|| empty( $saved_profile['configured'] ) ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Fixed translated failure text is escaped by the controller response boundary, not when thrown.
					throw new CredentialRequestException( __( 'Booster could not verify that the repository credential was saved.', 'ran-booster' ) );
				}
				if ( $is_replacement ) {
					// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Retain the promoted constructor or external DTO property contract.
					$this->expiry_observations->clear( $provider->value, $saved_id );
				}
				if ( $manual_expiry_submitted && ! $manual_expiry_is_provider_fallback ) {
					// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Retain the promoted constructor or external DTO property contract.
					$this->expiry_observations->set_manual_expiry( $provider->value, $saved_id, $manual_expiry );
				}
				return $self_destruct
						? __( 'Repository credential saved with automatic removal enabled.', 'ran-booster' )
					: ( $is_replacement
							? __( 'Repository credential replaced. Validate it to refresh provider expiry information.', 'ran-booster' )
							: __( 'Repository credential saved.', 'ran-booster' ) );
			}
		);
	}
	private function save_webhook_profile(
		array $request,
		ProviderCode $provider,
		?string $id,
		string $label
	): string {
		$admin          = $this->provider_admin( $provider );
		$normalizer     = $this->providers->require_capability( $provider, WebhookNormalizer::class );
		$scope          = is_string( $request['scope'] ?? null ) ? sanitize_key( wp_unslash( $request['scope'] ) ) : '';
		$target         = is_string( $request['target'] ?? null ) ? sanitize_text_field( wp_unslash( $request['target'] ) ) : '';
		$secret         = is_string( $request['secret'] ?? null ) ? trim( wp_unslash( $request['secret'] ) ) : '';
		$scope_metadata = $admin->get_webhook_scope( $scope );
		if ( null === $scope_metadata ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Fixed translated failure text is escaped by the controller response boundary, not when thrown.
			throw new CredentialRequestException( __( 'Choose a supported Push-to-Deploy scope.', 'ran-booster' ) );
		}
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Retain the promoted constructor or external DTO property contract.
		if ( $scope_metadata->requires_target && '' === trim( $target ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Fixed translated failure text is escaped by the controller response boundary, not when thrown.
			throw new CredentialRequestException( __( 'Enter the target for this Push-to-Deploy scope.', 'ran-booster' ) );
		}
		if ( null === $id && '' === $secret ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Fixed translated failure text is escaped by the controller response boundary, not when thrown.
			throw new CredentialRequestException( __( 'Enter the Push-to-Deploy secret.', 'ran-booster' ) );
		}
		$authority_id = '';
		if ( 'repository' === $scope ) {
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Retain the promoted constructor or external DTO property contract.
			$authority_id = $this->webhook_authorities->resolve( $provider, $normalizer->get_webhook_policy(), $target );
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Retain the promoted constructor or external DTO property contract.
		} elseif ( 'owner' === $scope && $scope_metadata->requires_managed_target ) {
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Retain the promoted constructor or external DTO property contract.
			$target = $this->webhook_authorities->resolve_owner( $provider, $target );
		}
		$saved_id      = $this->secrets->save_webhook(
			$provider,
			$id,
			array(
				'label'        => $label,
				'scope'        => $scope,
				'target'       => $target,
				'authority_id' => $authority_id,
				'origin'       => 'manual',
			),
			$secret
		);
		$saved_profile = $this->secrets->webhook_profiles( $provider )[ $saved_id ] ?? null;
		if ( ! is_array( $saved_profile )
			|| ( $saved_profile['label'] ?? null ) !== $label
			|| ( $saved_profile['scope'] ?? null ) !== $scope
			|| ( $saved_profile['target'] ?? null ) !== $target
			|| ( $saved_profile['authority_id'] ?? null ) !== $authority_id
			|| 'manual' !== ( $saved_profile['origin'] ?? null )
			|| empty( $saved_profile['configured'] ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Fixed translated failure text is escaped by the controller response boundary, not when thrown.
			throw new CredentialRequestException( __( 'Booster could not verify that the Push-to-Deploy secret was saved.', 'ran-booster' ) );
		}
		return __( 'Push-to-Deploy secret saved.', 'ran-booster' );
	}
	private function delete_access_profile( ProviderCode $provider, ?string $id ): string {
		if ( null === $id ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Fixed translated failure text is escaped by the controller response boundary, not when thrown.
			throw new CredentialRequestException( __( 'Choose a repository credential to remove.', 'ran-booster' ) );
		}
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Retain the promoted constructor or external DTO property contract.
		return $this->updater_lock->run(
			function () use ( $provider, $id ): string {
				$profile = $this->secrets->credential_profiles( $provider )[ $id ] ?? null;
				if ( ! is_array( $profile ) || ! empty( $profile['immutable'] ) || 'file' !== ( $profile['source'] ?? null ) ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Fixed translated failure text is escaped by the controller response boundary, not when thrown.
					throw new CredentialRequestException( __( 'Choose a saved repository credential to remove.', 'ran-booster' ) );
				}
				// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Retain the promoted constructor or external DTO property contract.
				$usage_count = $this->credential_usage->read( $provider, $id )['total'];
				if ( $usage_count > 0 ) {
					throw new CredentialRequestException(
						sprintf(
							/* translators: %d is the number of managed packages using this repository credential. */
							_n( 'This repository credential is used by %d managed package. Assign another credential before deleting it.', 'This repository credential is used by %d managed packages. Assign another credential before deleting it.', $usage_count, 'ran-booster' ), // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Fixed translated failure text is escaped by the controller response boundary, not when thrown.
							$usage_count // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal count is escaped at the response boundary.
						)
					);
				}
				// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Retain the promoted constructor or external DTO property contract.
				$cleared_default = $id === $this->public_lookup_profiles->get( $provider->value );
				if ( ! $this->secrets->delete_credential( $provider, $id ) || isset( $this->secrets->credential_profiles( $provider )[ $id ] ) ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Fixed translated failure text is escaped by the controller response boundary, not when thrown.
					throw new CredentialRequestException( __( 'Booster could not verify that the repository credential was removed.', 'ran-booster' ) );
				}
				if ( $cleared_default ) {
					// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Retain the promoted constructor or external DTO property contract.
					$this->public_lookup_profiles->set( $provider->value, null );
				}
				// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Retain the promoted constructor or external DTO property contract.
				$this->expiry_observations->clear( $provider->value, $id );
				try {
					$this->branch_check_evidence->bump_profile_generation( $provider->value, $id );
					if ( $cleared_default ) {
						$this->branch_check_evidence->bump_provider_generation( $provider->value );
					}
				} catch ( \Throwable $failure ) {
					BoosterLogger::log_exception(
						'repository branch evidence invalidation failed after credential removal',
						$failure,
						array(
							'source'    => 'admin',
							'operation' => 'delete-access-profile',
							'step'      => 'branch_check_evidence_invalidation',
							'provider'  => $provider->value,
						)
					);
				}
				return $cleared_default
						? __( 'Repository credential removed. Public repository lookup now uses anonymous access.', 'ran-booster' )
						: __( 'Repository credential removed.', 'ran-booster' );
			}
		);
	}
	private function delete_webhook_profile( ProviderCode $provider, ?string $id ): string {
		if ( null === $id ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Fixed translated failure text is escaped by the controller response boundary, not when thrown.
			throw new CredentialRequestException( __( 'Choose a Push-to-Deploy secret to remove.', 'ran-booster' ) );
		}
		$profile = $this->secrets->webhook_profiles( $provider )[ $id ] ?? null;
		if ( ! is_array( $profile ) || ! empty( $profile['immutable'] ) || 'file' !== ( $profile['source'] ?? null ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Fixed translated failure text is escaped by the controller response boundary, not when thrown.
			throw new CredentialRequestException( __( 'Choose a saved Push-to-Deploy secret to remove.', 'ran-booster' ) );
		}
		if ( ! $this->secrets->delete_webhook( $provider, $id ) || isset( $this->secrets->webhook_profiles( $provider )[ $id ] ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Fixed translated failure text is escaped by the controller response boundary, not when thrown.
			throw new CredentialRequestException( __( 'Booster could not verify that the Push-to-Deploy secret was removed.', 'ran-booster' ) );
		}
		return __( 'Push-to-Deploy secret removed.', 'ran-booster' );
	}
	private function authorize( string $nonce ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			$message = 'ran-booster-save-public-lookup-profile' === $nonce
				? esc_html__( 'You do not have sufficient permissions to manage Booster provider settings.', 'ran-booster' )
				: esc_html__( 'You do not have sufficient permissions to manage Booster credentials.', 'ran-booster' );
			wp_die( $message ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Both closed branches are escaped immediately above.
		}
		check_admin_referer( $nonce );
	}
	private function profile_id( array $request ): ?string {
		if ( ! array_key_exists( 'id', $request ) ) {
			return null;
		}
		if ( ! is_string( $request['id'] ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Fixed translated failure text is escaped by the controller response boundary, not when thrown.
			throw new CredentialRequestException( __( 'Choose a valid credential profile.', 'ran-booster' ) );
		}
		$id = trim( wp_unslash( $request['id'] ) );
		if ( '' !== $id && 1 !== preg_match( '/^[A-Za-z0-9_-]{3,64}$/D', $id ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Fixed translated failure text is escaped by the controller response boundary, not when thrown.
			throw new CredentialRequestException( __( 'Choose a valid credential profile.', 'ran-booster' ) );
		}
		return '' === $id ? null : $id;
	}
	private function complete_mutation( string $message, ?SignedAdminInteractionRequest $request ): void {
		if ( null === $request || null === $this->interaction ) {
			$this->dashboard->add_message( $message );
			return;
		}
		$this->interaction->respond_to_provider_profile_success( $request, $message );
	}
	private function profile_failure(
		string $action,
		?SignedAdminInteractionRequest $request,
		\Throwable $exception
	): void {
		$error = $this->record_failure( $exception, $action, 'credential_profile' );
		if ( null === $request || null === $this->interaction ) {
			return;
		}
		if ( $exception instanceof CredentialRequestException || $exception instanceof InvalidCredentialInput || $exception instanceof InvalidWebhookInput ) {
			$this->interaction->respond_to_provider_profile_validation_failure( $request, $error );
		}
		$this->interaction->respond_to_provider_profile_unexpected_failure( $request );
	}
	private function record_failure( \Throwable $exception, string $operation, string $step ): string {
		$error = $this->safe_error( $exception );
		$this->dashboard->add_failure_message(
			new \WP_Error( 'ran_booster_credentials_error', $error ),
			$exception,
			array(
				'operation' => $operation,
				'step'      => $step,
			)
		);
		return $error;
	}
	private function safe_error( \Throwable $exception ): string {
		return $exception instanceof CredentialRequestException
			|| $exception instanceof InvalidCredentialInput
			|| $exception instanceof InvalidWebhookInput
				? $exception->getMessage()
				: __( 'Booster could not complete the credential request.', 'ran-booster' );
	}
	private function provider_code( array $request ): ProviderCode {
		try {
			if ( ! is_string( $request['provider'] ?? null ) ) {
					throw new CredentialRequestException( __( 'Choose a supported repository provider.', 'ran-booster' ) );
			}
			$provider = ProviderCode::parse( wp_unslash( $request['provider'] ) );
			$this->providers->get( $provider );
			return $provider;
		} catch ( \Throwable ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Fixed translated failure text is escaped by the controller response boundary, not when thrown.
			throw new CredentialRequestException( __( 'Choose a supported repository provider.', 'ran-booster' ) );
		}
	}
	private function provider_admin( ProviderCode $provider ): ProviderAdminMetadata {
		try {
			$admin = $this->providers->get( $provider )->get_metadata()->admin;
		} catch ( \Throwable ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Fixed translated failure text is escaped by the controller response boundary, not when thrown.
			throw new CredentialRequestException( __( 'Choose a supported repository provider.', 'ran-booster' ) );
		}
		if ( null === $admin ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Fixed translated failure text is escaped by the controller response boundary, not when thrown.
			throw new CredentialRequestException( __( 'Repository provider settings are unavailable.', 'ran-booster' ) );
		}
		return $admin;
	}
	protected function respond_to_htmx_public_lookup_profile( string $provider, ?string $message, ?string $error, int $status ): never {
		status_header( $status );
		$this->emit_success_header( $message );
		echo $this->dashboard->render_public_lookup_profile_region( $provider, $error ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Core-owned, escaped view fragment.
		exit;
	}
	// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
	protected function respond_to_htmx_credential_validation( string $credential_id, ?string $message, ?string $error, int $status ): never {
		status_header( $status );
		$this->emit_success_header( $message );
		echo '<div id="' . esc_attr( 'ran-booster-credential-validation-error-' . $credential_id ) . '" class="notice notice-error inline" data-ran-booster-admin-mutation-error role="alert" tabindex="-1"' . ( null === $error ? ' hidden' : '' ) . '><p>' . esc_html( $error ?? '' ) . '</p></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped,WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Core-owned escaped fragment. Retain the public named-parameter contract.
		exit;
	}
	private function emit_success_header( ?string $message ): void {
		if ( null !== $message ) {
			header(
				'HX-Trigger-After-Swap: ' . wp_json_encode(
					array( 'ran-booster:admin-mutation-success' => array( 'message' => $message ) )
				)
			);
		}
	}
}
