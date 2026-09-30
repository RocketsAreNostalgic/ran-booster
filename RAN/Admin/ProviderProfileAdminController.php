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
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Fixed translated messages are escaped by the controller response boundaries.
class ProviderProfileAdminController {
	public const TARGET_KEY      = 'core_provider_profiles';
	public const TARGET_SELECTOR = '#ran-booster-provider-profile-region';
	private RepositoryBranchCheckEvidenceStore $branch_check_evidence;
	public function __construct(
		private Dashboard $dashboard,
		private ProviderRegistry $providers,
		private SecretsFile $secrets,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
		private ManagedPackageWebhookAuthorityResolver $webhookAuthorities,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
		private WordPressUpdaterLock $updaterLock,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
		private CredentialUsageReader $credentialUsage,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
		private PublicRepositoryLookupProfileStore $publicLookupProfiles,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
		private CredentialExpiryObservationStore $expiryObservations,
		private ?CoreAdminInteractionFacade $interaction = null,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
		?RepositoryBranchCheckEvidenceStore $branchCheckEvidence = null
	) {
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
		$this->branch_check_evidence = $branchCheckEvidence ?? new RepositoryBranchCheckEvidenceStore();
	}
	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Preserve the existing public callback and caller contract.
	public function manageCredentialProfiles( array $request ): void {
		$this->authorize( 'ran-booster-save-secrets' );
		$action              = is_string( $request['action'] ?? null ) ? $request['action'] : '';
		$interaction_request = null;
		try {
			$provider = $this->provider_code( $request );
			if ( null !== $this->interaction ) {
				$interaction_request = $this->interaction->providerProfileRequest( $action, $provider->value );
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
	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase,WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve the existing public callback and caller contract. Retain the public named-parameter contract.
	public function manageCredentialValidation( array $request, bool $htmxRequest ): void {
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
				$validator = $this->providers->requireCapability( $provider, CredentialValidator::class );
			} catch ( UnsupportedProviderCapability ) {
				throw new CredentialRequestException( __( 'Credential validation is unavailable for this repository provider.', 'ran-booster' ) );
			}
			$result = $validator->validateCredential( $id );
			if ( $result->isValid() ) {
				if ( null !== $result->expiry ) {
					// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Retain the promoted constructor or external DTO property contract.
					$this->expiryObservations->record_provider_expiry(
						$provider->value,
						$id,
						$result->expiry,
						gmdate( 'Y-m-d\\TH:i:s\\Z' )
					);
					if ( $result->expiry->isKnown() && is_string( $result->expiry->expiresAt ) ) {
						$this->secrets->recordCredentialProviderExpiry(
							$provider,
							$id,
							substr( $result->expiry->expiresAt, 0, 10 )
						);
					}
				}
				$message = __( 'Repository credential validated successfully.', 'ran-booster' );
				// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
				if ( ! $htmxRequest ) {
					$this->dashboard->addMessage( $message );
				}
			} else {
				$error = $result->getDisplayMessage();
				if ( null === $error ) {
					throw new \LogicException( 'Invalid credential validation results require a core display message.' );
				}
				// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
				if ( $htmxRequest ) {
					$status = 422;
				} else {
					$this->dashboard->addMessage( new \WP_Error( 'ran_booster_credential_validation_error', $error ) );
				}
			}
		} catch ( \Throwable $exception ) {
			$error  = $this->record_failure( $exception, 'validate-access-profile', 'credential_validation' );
			$status = $exception instanceof CredentialRequestException ? 422 : 500;
		}
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
		if ( $htmxRequest && $provider instanceof ProviderCode && is_string( $id ) ) {
			$this->respondToHtmxCredentialValidation( $id, $message, $error, $status );
		}
	}
	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase,WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve the existing public callback and caller contract. Retain the public named-parameter contract.
	public function managePublicLookupProfile( array $request, bool $htmxRequest ): void {
		$this->authorize( 'ran-booster-save-public-lookup-profile' );
		$provider = null;
		$message  = null;
		$error    = null;
		$status   = 200;
		try {
			$provider = $this->provider_code( $request );
			try {
				$browser = $this->providers->requireCapability( $provider, CredentialedPublicRepositoryBrowser::class );
			} catch ( UnsupportedProviderCapability ) {
				throw new CredentialRequestException( __( 'A default public repository lookup profile is unavailable for this provider.', 'ran-booster' ) );
			}
			if ( ! $browser->getPublicRepositoryBrowseMetadata()->supportsProviderDefaultProfile ) {
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
				$profile = $this->secrets->credentialProfiles( $provider )[ $profile_id ] ?? null;
				if ( ! is_array( $profile ) || empty( $profile['configured'] ) ) {
					throw new CredentialRequestException( __( 'Choose Anonymous or a saved repository credential.', 'ran-booster' ) );
				}
			}
			$this->branch_check_evidence->bump_provider_generation( $provider->value );
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Retain the promoted constructor or external DTO property contract.
			$this->publicLookupProfiles->set( $provider->value, '' === $profile_id ? null : $profile_id );
			$message = '' === $profile_id
				? __( 'Public repository lookup will use anonymous access.', 'ran-booster' )
				: __( 'Default public repository lookup profile saved.', 'ran-booster' );
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
			if ( ! $htmxRequest ) {
				$this->dashboard->addMessage( $message );
			}
		} catch ( \Throwable $exception ) {
			$error  = $this->record_failure( $exception, 'save-public-lookup-profile', 'public_lookup_profile' );
			$status = $exception instanceof CredentialRequestException ? 422 : 500;
		}
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
		if ( $htmxRequest && $provider instanceof ProviderCode ) {
			$this->respondToHtmxPublicLookupProfile( $provider->value, $message, $error, $status );
		}
	}
	private function save_access_profile(
		array $request,
		ProviderCode $provider,
		?string $id,
		string $label
	): string {
		$kind          = is_string( $request['kind'] ?? null ) ? sanitize_key( wp_unslash( $request['kind'] ) ) : '';
		$kind_metadata = $this->provider_admin( $provider )->getCredentialKind( $kind );
		if ( null === $kind_metadata ) {
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
				throw new CredentialRequestException( __( 'Complete every required credential field.', 'ran-booster' ) );
			}
			if ( 'email' === $field->type && false === filter_var( $configuration[ $field->key ], FILTER_VALIDATE_EMAIL ) ) {
				throw new CredentialRequestException( __( 'Enter a valid account email address.', 'ran-booster' ) );
			}
		}
		$secret = is_string( $request['secret'] ?? null ) ? trim( wp_unslash( $request['secret'] ) ) : '';
		if ( null === $id && '' === $secret ) {
			throw new CredentialRequestException( __( 'Enter the credential secret.', 'ran-booster' ) );
		}
		$manual_expiry_submitted = array_key_exists( 'expires_on', $request );
		$manual_expiry           = null;
		if ( $manual_expiry_submitted ) {
			if ( ! is_string( $request['expires_on'] ) ) {
				throw new CredentialRequestException( __( 'Enter a valid credential expiry date.', 'ran-booster' ) );
			}
			$manual_expiry = trim( wp_unslash( $request['expires_on'] ) );
			$manual_expiry = '' === $manual_expiry ? null : $manual_expiry;
			if ( null !== $manual_expiry
				&& ( 1 !== preg_match( '/\A(\d{4})-(\d{2})-(\d{2})\z/D', $manual_expiry, $expiry_parts )
					|| ! checkdate( (int) $expiry_parts[2], (int) $expiry_parts[3], (int) $expiry_parts[1] ) ) ) {
				throw new CredentialRequestException( __( 'Enter a valid expiry / removal date.', 'ran-booster' ) );
			}
		}
		$existing_manual_expiry = null;
		$provider_expiry        = null;
		if ( null !== $id ) {
			try {
				// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Retain the promoted constructor or external DTO property contract.
				$observation            = $this->expiryObservations->get( $provider->value, $id );
				$existing_manual_expiry = is_string( $observation['manual_expires_on'] ?? null ) ? $observation['manual_expires_on'] : null;
				$provider_expires_at    = $observation['provider_expires_at'] ?? null;
				$provider_expiry        = is_string( $provider_expires_at ) ? substr( $provider_expires_at, 0, 10 ) : null;
			} catch ( \RuntimeException ) {
				$existing_manual_expiry = null;
				$provider_expiry        = null;
			}
		}
		if ( '' === $secret && null !== $manual_expiry && null !== $provider_expiry && $manual_expiry > $provider_expiry ) {
			throw new CredentialRequestException( __( 'The expiry / removal date cannot be later than the expiry reported by the provider.', 'ran-booster' ) );
		}
		$self_destruct = isset( $request['self_destruct'] ) && '1' === $request['self_destruct'];
		if ( $self_destruct && null === $manual_expiry ) {
			throw new CredentialRequestException( __( 'Enter an expiry / removal date before enabling automatic removal.', 'ran-booster' ) );
		}
		$manual_expiry_is_provider_fallback = '' === $secret
			&& null === $existing_manual_expiry
			&& null !== $provider_expiry
			&& $manual_expiry === $provider_expiry;
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Retain the promoted constructor or external DTO property contract.
		return $this->updaterLock->run(
			function () use ( $provider, $id, $secret, $label, $kind, $configuration, $self_destruct, $manual_expiry_submitted, $manual_expiry, $manual_expiry_is_provider_fallback ): string {
				$existing_profile = null === $id ? null : ( $this->secrets->credentialProfiles( $provider )[ $id ] ?? null );
				$is_replacement   = is_array( $existing_profile ) && '' !== $secret;
				$access_changed   = is_array( $existing_profile )
					&& ( $is_replacement
						|| $kind !== ( $existing_profile['kind'] ?? null )
						|| ! is_array( $existing_profile['configuration'] ?? null )
						|| $configuration !== $existing_profile['configuration'] );
				if ( $access_changed && null !== $id ) {
					$this->branch_check_evidence->bump_profile_generation( $provider->value, $id );
				}
				$saved_id      = $this->secrets->saveCredential(
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
				$saved_profile = $this->secrets->credentialProfiles( $provider )[ $saved_id ] ?? null;
				if ( ! is_array( $saved_profile )
					|| $label !== ( $saved_profile['label'] ?? null )
					|| $kind !== ( $saved_profile['kind'] ?? null )
					|| ! is_array( $saved_profile['configuration'] ?? null )
					|| array() !== array_diff_assoc( $configuration, $saved_profile['configuration'] )
					|| $self_destruct !== ( $saved_profile['self_destruct'] ?? null )
					|| ( $self_destruct ? $manual_expiry : null ) !== ( $saved_profile['destroy_on'] ?? null )
					|| empty( $saved_profile['configured'] ) ) {
					throw new CredentialRequestException( __( 'Booster could not verify that the repository credential was saved.', 'ran-booster' ) );
				}
				if ( $is_replacement ) {
					// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Retain the promoted constructor or external DTO property contract.
					$this->expiryObservations->clear( $provider->value, $saved_id );
				}
				if ( $manual_expiry_submitted && ! $manual_expiry_is_provider_fallback ) {
					// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Retain the promoted constructor or external DTO property contract.
					$this->expiryObservations->set_manual_expiry( $provider->value, $saved_id, $manual_expiry );
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
		$normalizer     = $this->providers->requireCapability( $provider, WebhookNormalizer::class );
		$scope          = is_string( $request['scope'] ?? null ) ? sanitize_key( wp_unslash( $request['scope'] ) ) : '';
		$target         = is_string( $request['target'] ?? null ) ? sanitize_text_field( wp_unslash( $request['target'] ) ) : '';
		$secret         = is_string( $request['secret'] ?? null ) ? trim( wp_unslash( $request['secret'] ) ) : '';
		$scope_metadata = $admin->getWebhookScope( $scope );
		if ( null === $scope_metadata ) {
			throw new CredentialRequestException( __( 'Choose a supported Push-to-Deploy scope.', 'ran-booster' ) );
		}
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Retain the promoted constructor or external DTO property contract.
		if ( $scope_metadata->requiresTarget && '' === trim( $target ) ) {
			throw new CredentialRequestException( __( 'Enter the target for this Push-to-Deploy scope.', 'ran-booster' ) );
		}
		if ( null === $id && '' === $secret ) {
			throw new CredentialRequestException( __( 'Enter the Push-to-Deploy secret.', 'ran-booster' ) );
		}
		$authority_id = '';
		if ( 'repository' === $scope ) {
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Retain the promoted constructor or external DTO property contract.
			$authority_id = $this->webhookAuthorities->resolve( $provider, $normalizer->getWebhookPolicy(), $target );
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Retain the promoted constructor or external DTO property contract.
		} elseif ( 'owner' === $scope && $scope_metadata->requiresManagedTarget ) {
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Retain the promoted constructor or external DTO property contract.
			$target = $this->webhookAuthorities->resolveOwner( $provider, $target );
		}
		$saved_id      = $this->secrets->saveWebhook(
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
		$saved_profile = $this->secrets->webhookProfiles( $provider )[ $saved_id ] ?? null;
		if ( ! is_array( $saved_profile )
			|| $label !== ( $saved_profile['label'] ?? null )
			|| $scope !== ( $saved_profile['scope'] ?? null )
			|| $target !== ( $saved_profile['target'] ?? null )
			|| $authority_id !== ( $saved_profile['authority_id'] ?? null )
			|| 'manual' !== ( $saved_profile['origin'] ?? null )
			|| empty( $saved_profile['configured'] ) ) {
			throw new CredentialRequestException( __( 'Booster could not verify that the Push-to-Deploy secret was saved.', 'ran-booster' ) );
		}
		return __( 'Push-to-Deploy secret saved.', 'ran-booster' );
	}
	private function delete_access_profile( ProviderCode $provider, ?string $id ): string {
		if ( null === $id ) {
			throw new CredentialRequestException( __( 'Choose a repository credential to remove.', 'ran-booster' ) );
		}
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Retain the promoted constructor or external DTO property contract.
		return $this->updaterLock->run(
			function () use ( $provider, $id ): string {
				$profile = $this->secrets->credentialProfiles( $provider )[ $id ] ?? null;
				if ( ! is_array( $profile ) || ! empty( $profile['immutable'] ) || 'file' !== ( $profile['source'] ?? null ) ) {
					throw new CredentialRequestException( __( 'Choose a saved repository credential to remove.', 'ran-booster' ) );
				}
				// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Retain the promoted constructor or external DTO property contract.
				$usage_count = $this->credentialUsage->read( $provider, $id )['total'];
				if ( $usage_count > 0 ) {
					throw new CredentialRequestException(
						sprintf(
							/* translators: %d is the number of managed packages using this repository credential. */
							_n( 'This repository credential is used by %d managed package. Assign another credential before deleting it.', 'This repository credential is used by %d managed packages. Assign another credential before deleting it.', $usage_count, 'ran-booster' ),
							$usage_count // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal count is escaped at the response boundary.
						)
					);
				}
				// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Retain the promoted constructor or external DTO property contract.
				$cleared_default = $id === $this->publicLookupProfiles->get( $provider->value );
				if ( ! $this->secrets->deleteCredential( $provider, $id ) || isset( $this->secrets->credentialProfiles( $provider )[ $id ] ) ) {
					throw new CredentialRequestException( __( 'Booster could not verify that the repository credential was removed.', 'ran-booster' ) );
				}
				if ( $cleared_default ) {
					// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Retain the promoted constructor or external DTO property contract.
					$this->publicLookupProfiles->set( $provider->value, null );
				}
				// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Retain the promoted constructor or external DTO property contract.
				$this->expiryObservations->clear( $provider->value, $id );
				try {
					$this->branch_check_evidence->bump_profile_generation( $provider->value, $id );
					if ( $cleared_default ) {
						$this->branch_check_evidence->bump_provider_generation( $provider->value );
					}
				} catch ( \Throwable $failure ) {
					BoosterLogger::logException(
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
			throw new CredentialRequestException( __( 'Choose a Push-to-Deploy secret to remove.', 'ran-booster' ) );
		}
		$profile = $this->secrets->webhookProfiles( $provider )[ $id ] ?? null;
		if ( ! is_array( $profile ) || ! empty( $profile['immutable'] ) || 'file' !== ( $profile['source'] ?? null ) ) {
			throw new CredentialRequestException( __( 'Choose a saved Push-to-Deploy secret to remove.', 'ran-booster' ) );
		}
		if ( ! $this->secrets->deleteWebhook( $provider, $id ) || isset( $this->secrets->webhookProfiles( $provider )[ $id ] ) ) {
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
			throw new CredentialRequestException( __( 'Choose a valid credential profile.', 'ran-booster' ) );
		}
		$id = trim( wp_unslash( $request['id'] ) );
		if ( '' !== $id && 1 !== preg_match( '/^[A-Za-z0-9_-]{3,64}$/D', $id ) ) {
			throw new CredentialRequestException( __( 'Choose a valid credential profile.', 'ran-booster' ) );
		}
		return '' === $id ? null : $id;
	}
	private function complete_mutation( string $message, ?SignedAdminInteractionRequest $request ): void {
		if ( null === $request || null === $this->interaction ) {
			$this->dashboard->addMessage( $message );
			return;
		}
		$this->interaction->respondToProviderProfileSuccess( $request, $message );
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
			$this->interaction->respondToProviderProfileValidationFailure( $request, $error );
		}
		$this->interaction->respondToProviderProfileUnexpectedFailure( $request );
	}
	private function record_failure( \Throwable $exception, string $operation, string $step ): string {
		$error = $this->safe_error( $exception );
		$this->dashboard->addFailureMessage(
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
			throw new CredentialRequestException( __( 'Choose a supported repository provider.', 'ran-booster' ) );
		}
	}
	private function provider_admin( ProviderCode $provider ): ProviderAdminMetadata {
		try {
			$admin = $this->providers->get( $provider )->getMetadata()->admin;
		} catch ( \Throwable ) {
			throw new CredentialRequestException( __( 'Choose a supported repository provider.', 'ran-booster' ) );
		}
		if ( null === $admin ) {
			throw new CredentialRequestException( __( 'Repository provider settings are unavailable.', 'ran-booster' ) );
		}
		return $admin;
	}
	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Preserve the existing public callback and caller contract.
	protected function respondToHtmxPublicLookupProfile( string $provider, ?string $message, ?string $error, int $status ): never {
		status_header( $status );
		$this->emit_success_header( $message );
		echo $this->dashboard->renderPublicLookupProfileRegion( $provider, $error ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Core-owned, escaped view fragment.
		exit;
	}
	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase,WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve the existing public callback and caller contract. Retain the public named-parameter contract.
	protected function respondToHtmxCredentialValidation( string $credentialId, ?string $message, ?string $error, int $status ): never {
		status_header( $status );
		$this->emit_success_header( $message );
		echo '<div id="' . esc_attr( 'ran-booster-credential-validation-error-' . $credentialId ) . '" class="notice notice-error inline" data-ran-booster-admin-mutation-error role="alert" tabindex="-1"' . ( null === $error ? ' hidden' : '' ) . '><p>' . esc_html( $error ?? '' ) . '</p></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped,WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Core-owned escaped fragment. Retain the public named-parameter contract.
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
// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
