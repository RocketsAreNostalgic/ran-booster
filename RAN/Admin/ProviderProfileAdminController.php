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
	private RepositoryBranchCheckEvidenceStore $branchCheckEvidence;
	public function __construct(
		private Dashboard $dashboard,
		private ProviderRegistry $providers,
		private SecretsFile $secrets,
		private ManagedPackageWebhookAuthorityResolver $webhookAuthorities,
		private WordPressUpdaterLock $updaterLock,
		private CredentialUsageReader $credentialUsage,
		private PublicRepositoryLookupProfileStore $publicLookupProfiles,
		private CredentialExpiryObservationStore $expiryObservations,
		private ?CoreAdminInteractionFacade $interaction = null,
		?RepositoryBranchCheckEvidenceStore $branchCheckEvidence = null
	) {
		$this->branchCheckEvidence = $branchCheckEvidence ?? new RepositoryBranchCheckEvidenceStore();
	}
	public function manageCredentialProfiles( array $request ): void {
		$this->authorize( 'ran-booster-save-secrets' );
		$action             = is_string( $request['action'] ?? null ) ? $request['action'] : '';
		$interactionRequest = null;
		try {
			$provider = $this->providerCode( $request );
			if ( null !== $this->interaction ) {
				$interactionRequest = $this->interaction->providerProfileRequest( $action, $provider->value );
			}
			$id = $this->profileId( $request );
			if ( 'delete-access-profile' === $action ) {
				$message = $this->deleteAccessProfile( $provider, $id );
			} elseif ( 'delete-webhook-profile' === $action ) {
				$message = $this->deleteWebhookProfile( $provider, $id );
			} else {
				$label = is_string( $request['label'] ?? null )
					? sanitize_text_field( wp_unslash( $request['label'] ) )
					: '';
				if ( '' === trim( $label ) ) {
					throw new CredentialRequestException( __( 'Enter a label for this credential.', 'ran-booster' ) );
				}
				$message = 'save-access-profile' === $action
					? $this->saveAccessProfile( $request, $provider, $id, $label )
					: $this->saveWebhookProfile( $request, $provider, $id, $label );
			}
			$this->completeMutation( $message, $interactionRequest );
		} catch ( \Throwable $exception ) {
			$this->profileFailure( $action, $interactionRequest, $exception );
		}
	}
	public function manageCredentialValidation( array $request, bool $htmxRequest ): void {
		$this->authorize( 'ran-booster-save-secrets' );
		$provider = null;
		$id       = null;
		$message  = null;
		$error    = null;
		$status   = 200;
		try {
			$provider = $this->providerCode( $request );
			$id       = $this->profileId( $request );
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
					$this->expiryObservations->recordProviderExpiry(
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
				if ( ! $htmxRequest ) {
					$this->dashboard->addMessage( $message );
				}
			} else {
				$error = $result->getDisplayMessage();
				if ( null === $error ) {
					throw new \LogicException( 'Invalid credential validation results require a core display message.' );
				}
				if ( $htmxRequest ) {
					$status = 422;
				} else {
					$this->dashboard->addMessage( new \WP_Error( 'ran_booster_credential_validation_error', $error ) );
				}
			}
		} catch ( \Throwable $exception ) {
			$error  = $this->recordFailure( $exception, 'validate-access-profile', 'credential_validation' );
			$status = $exception instanceof CredentialRequestException ? 422 : 500;
		}
		if ( $htmxRequest && $provider instanceof ProviderCode && is_string( $id ) ) {
			$this->respondToHtmxCredentialValidation( $id, $message, $error, $status );
		}
	}
	public function managePublicLookupProfile( array $request, bool $htmxRequest ): void {
		$this->authorize( 'ran-booster-save-public-lookup-profile' );
		$provider = null;
		$message  = null;
		$error    = null;
		$status   = 200;
		try {
			$provider = $this->providerCode( $request );
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
			$profileId = wp_unslash( $request['profile_id'] );
			if ( '' !== $profileId && 1 !== preg_match( '/^[A-Za-z0-9_-]{3,64}$/D', $profileId ) ) {
				throw new CredentialRequestException( __( 'Choose Anonymous or a saved repository credential.', 'ran-booster' ) );
			}
			if ( '' !== $profileId ) {
				$profile = $this->secrets->credentialProfiles( $provider )[ $profileId ] ?? null;
				if ( ! is_array( $profile ) || empty( $profile['configured'] ) ) {
					throw new CredentialRequestException( __( 'Choose Anonymous or a saved repository credential.', 'ran-booster' ) );
				}
			}
			$this->branchCheckEvidence->bumpProviderGeneration( $provider->value );
			$this->publicLookupProfiles->set( $provider->value, '' === $profileId ? null : $profileId );
			$message = '' === $profileId
				? __( 'Public repository lookup will use anonymous access.', 'ran-booster' )
				: __( 'Default public repository lookup profile saved.', 'ran-booster' );
			if ( ! $htmxRequest ) {
				$this->dashboard->addMessage( $message );
			}
		} catch ( \Throwable $exception ) {
			$error  = $this->recordFailure( $exception, 'save-public-lookup-profile', 'public_lookup_profile' );
			$status = $exception instanceof CredentialRequestException ? 422 : 500;
		}
		if ( $htmxRequest && $provider instanceof ProviderCode ) {
			$this->respondToHtmxPublicLookupProfile( $provider->value, $message, $error, $status );
		}
	}
	private function saveAccessProfile(
		array $request,
		ProviderCode $provider,
		?string $id,
		string $label
	): string {
		$kind         = is_string( $request['kind'] ?? null ) ? sanitize_key( wp_unslash( $request['kind'] ) ) : '';
		$kindMetadata = $this->providerAdmin( $provider )->getCredentialKind( $kind );
		if ( null === $kindMetadata ) {
			throw new CredentialRequestException( __( 'Choose a supported credential type.', 'ran-booster' ) );
		}
		$submittedConfiguration = is_array( $request['configuration'] ?? null )
			? wp_unslash( $request['configuration'] )
			: array();
		$configuration          = array();
		foreach ( $kindMetadata->fields as $field ) {
			$value                        = $submittedConfiguration[ $field->key ] ?? '';
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
		$manualExpirySubmitted = array_key_exists( 'expires_on', $request );
		$manualExpiry          = null;
		if ( $manualExpirySubmitted ) {
			if ( ! is_string( $request['expires_on'] ) ) {
				throw new CredentialRequestException( __( 'Enter a valid credential expiry date.', 'ran-booster' ) );
			}
			$manualExpiry = trim( wp_unslash( $request['expires_on'] ) );
			$manualExpiry = '' === $manualExpiry ? null : $manualExpiry;
			if ( null !== $manualExpiry
				&& ( 1 !== preg_match( '/\A(\d{4})-(\d{2})-(\d{2})\z/D', $manualExpiry, $expiryParts )
					|| ! checkdate( (int) $expiryParts[2], (int) $expiryParts[3], (int) $expiryParts[1] ) ) ) {
				throw new CredentialRequestException( __( 'Enter a valid expiry / removal date.', 'ran-booster' ) );
			}
		}
		$existingManualExpiry = null;
		$providerExpiry       = null;
		if ( null !== $id ) {
			try {
				$observation          = $this->expiryObservations->get( $provider->value, $id );
				$existingManualExpiry = is_string( $observation['manual_expires_on'] ?? null ) ? $observation['manual_expires_on'] : null;
				$providerExpiresAt    = $observation['provider_expires_at'] ?? null;
				$providerExpiry       = is_string( $providerExpiresAt ) ? substr( $providerExpiresAt, 0, 10 ) : null;
			} catch ( \RuntimeException ) {
				$existingManualExpiry = null;
				$providerExpiry       = null;
			}
		}
		if ( '' === $secret && null !== $manualExpiry && null !== $providerExpiry && $manualExpiry > $providerExpiry ) {
			throw new CredentialRequestException( __( 'The expiry / removal date cannot be later than the expiry reported by the provider.', 'ran-booster' ) );
		}
		$selfDestruct = isset( $request['self_destruct'] ) && '1' === $request['self_destruct'];
		if ( $selfDestruct && null === $manualExpiry ) {
			throw new CredentialRequestException( __( 'Enter an expiry / removal date before enabling automatic removal.', 'ran-booster' ) );
		}
		$manualExpiryIsProviderFallback = '' === $secret
			&& null === $existingManualExpiry
			&& null !== $providerExpiry
			&& $manualExpiry === $providerExpiry;
		return $this->updaterLock->run(
			function () use ( $provider, $id, $secret, $label, $kind, $configuration, $selfDestruct, $manualExpirySubmitted, $manualExpiry, $manualExpiryIsProviderFallback ): string {
				$existingProfile = null === $id ? null : ( $this->secrets->credentialProfiles( $provider )[ $id ] ?? null );
				$isReplacement   = is_array( $existingProfile ) && '' !== $secret;
				$accessChanged   = is_array( $existingProfile )
					&& ( $isReplacement
						|| $kind !== ( $existingProfile['kind'] ?? null )
						|| ! is_array( $existingProfile['configuration'] ?? null )
						|| $configuration !== $existingProfile['configuration'] );
				if ( $accessChanged && null !== $id ) {
					$this->branchCheckEvidence->bumpProfileGeneration( $provider->value, $id );
				}
				$savedId      = $this->secrets->saveCredential(
					$provider,
					$id,
					array(
						'label'         => $label,
						'kind'          => $kind,
						'configuration' => $configuration,
						'self_destruct' => $selfDestruct,
						'destroy_on'    => $selfDestruct ? $manualExpiry : null,
					),
					$secret,
					true
				);
				$savedProfile = $this->secrets->credentialProfiles( $provider )[ $savedId ] ?? null;
				if ( ! is_array( $savedProfile )
					|| $label !== ( $savedProfile['label'] ?? null )
					|| $kind !== ( $savedProfile['kind'] ?? null )
					|| ! is_array( $savedProfile['configuration'] ?? null )
					|| array() !== array_diff_assoc( $configuration, $savedProfile['configuration'] )
					|| $selfDestruct !== ( $savedProfile['self_destruct'] ?? null )
					|| ( $selfDestruct ? $manualExpiry : null ) !== ( $savedProfile['destroy_on'] ?? null )
					|| empty( $savedProfile['configured'] ) ) {
					throw new CredentialRequestException( __( 'Booster could not verify that the repository credential was saved.', 'ran-booster' ) );
				}
				if ( $isReplacement ) {
					$this->expiryObservations->clear( $provider->value, $savedId );
				}
				if ( $manualExpirySubmitted && ! $manualExpiryIsProviderFallback ) {
					$this->expiryObservations->setManualExpiry( $provider->value, $savedId, $manualExpiry );
				}
				return $selfDestruct
						? __( 'Repository credential saved with automatic removal enabled.', 'ran-booster' )
					: ( $isReplacement
							? __( 'Repository credential replaced. Validate it to refresh provider expiry information.', 'ran-booster' )
							: __( 'Repository credential saved.', 'ran-booster' ) );
			}
		);
	}
	private function saveWebhookProfile(
		array $request,
		ProviderCode $provider,
		?string $id,
		string $label
	): string {
		$admin         = $this->providerAdmin( $provider );
		$normalizer    = $this->providers->requireCapability( $provider, WebhookNormalizer::class );
		$scope         = is_string( $request['scope'] ?? null ) ? sanitize_key( wp_unslash( $request['scope'] ) ) : '';
		$target        = is_string( $request['target'] ?? null ) ? sanitize_text_field( wp_unslash( $request['target'] ) ) : '';
		$secret        = is_string( $request['secret'] ?? null ) ? trim( wp_unslash( $request['secret'] ) ) : '';
		$scopeMetadata = $admin->getWebhookScope( $scope );
		if ( null === $scopeMetadata ) {
			throw new CredentialRequestException( __( 'Choose a supported Push-to-Deploy scope.', 'ran-booster' ) );
		}
		if ( $scopeMetadata->requiresTarget && '' === trim( $target ) ) {
			throw new CredentialRequestException( __( 'Enter the target for this Push-to-Deploy scope.', 'ran-booster' ) );
		}
		if ( null === $id && '' === $secret ) {
			throw new CredentialRequestException( __( 'Enter the Push-to-Deploy secret.', 'ran-booster' ) );
		}
		$authorityId = '';
		if ( 'repository' === $scope ) {
			$authorityId = $this->webhookAuthorities->resolve( $provider, $normalizer->getWebhookPolicy(), $target );
		} elseif ( 'owner' === $scope && $scopeMetadata->requiresManagedTarget ) {
			$target = $this->webhookAuthorities->resolveOwner( $provider, $target );
		}
		$savedId      = $this->secrets->saveWebhook(
			$provider,
			$id,
			array(
				'label'        => $label,
				'scope'        => $scope,
				'target'       => $target,
				'authority_id' => $authorityId,
				'origin'       => 'manual',
			),
			$secret
		);
		$savedProfile = $this->secrets->webhookProfiles( $provider )[ $savedId ] ?? null;
		if ( ! is_array( $savedProfile )
			|| $label !== ( $savedProfile['label'] ?? null )
			|| $scope !== ( $savedProfile['scope'] ?? null )
			|| $target !== ( $savedProfile['target'] ?? null )
			|| $authorityId !== ( $savedProfile['authority_id'] ?? null )
			|| 'manual' !== ( $savedProfile['origin'] ?? null )
			|| empty( $savedProfile['configured'] ) ) {
			throw new CredentialRequestException( __( 'Booster could not verify that the Push-to-Deploy secret was saved.', 'ran-booster' ) );
		}
		return __( 'Push-to-Deploy secret saved.', 'ran-booster' );
	}
	private function deleteAccessProfile( ProviderCode $provider, ?string $id ): string {
		if ( null === $id ) {
			throw new CredentialRequestException( __( 'Choose a repository credential to remove.', 'ran-booster' ) );
		}
		return $this->updaterLock->run(
			function () use ( $provider, $id ): string {
				$profile = $this->secrets->credentialProfiles( $provider )[ $id ] ?? null;
				if ( ! is_array( $profile ) || ! empty( $profile['immutable'] ) || 'file' !== ( $profile['source'] ?? null ) ) {
					throw new CredentialRequestException( __( 'Choose a saved repository credential to remove.', 'ran-booster' ) );
				}
				$usageCount = $this->credentialUsage->read( $provider, $id )['total'];
				if ( $usageCount > 0 ) {
					throw new CredentialRequestException(
						sprintf(
							/* translators: %d is the number of managed packages using this repository credential. */
							_n( 'This repository credential is used by %d managed package. Assign another credential before deleting it.', 'This repository credential is used by %d managed packages. Assign another credential before deleting it.', $usageCount, 'ran-booster' ),
							$usageCount // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal count is escaped at the response boundary.
						)
					);
				}
				$clearedDefault = $id === $this->publicLookupProfiles->get( $provider->value );
				if ( ! $this->secrets->deleteCredential( $provider, $id ) || isset( $this->secrets->credentialProfiles( $provider )[ $id ] ) ) {
					throw new CredentialRequestException( __( 'Booster could not verify that the repository credential was removed.', 'ran-booster' ) );
				}
				if ( $clearedDefault ) {
					$this->publicLookupProfiles->set( $provider->value, null );
				}
				$this->expiryObservations->clear( $provider->value, $id );
				try {
					$this->branchCheckEvidence->bumpProfileGeneration( $provider->value, $id );
					if ( $clearedDefault ) {
						$this->branchCheckEvidence->bumpProviderGeneration( $provider->value );
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
				return $clearedDefault
						? __( 'Repository credential removed. Public repository lookup now uses anonymous access.', 'ran-booster' )
						: __( 'Repository credential removed.', 'ran-booster' );
			}
		);
	}
	private function deleteWebhookProfile( ProviderCode $provider, ?string $id ): string {
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
	private function profileId( array $request ): ?string {
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
	private function completeMutation( string $message, ?SignedAdminInteractionRequest $request ): void {
		if ( null === $request || null === $this->interaction ) {
			$this->dashboard->addMessage( $message );
			return;
		}
		$this->interaction->respondToProviderProfileSuccess( $request, $message );
	}
	private function profileFailure(
		string $action,
		?SignedAdminInteractionRequest $request,
		\Throwable $exception
	): void {
		$error = $this->recordFailure( $exception, $action, 'credential_profile' );
		if ( null === $request || null === $this->interaction ) {
			return;
		}
		if ( $exception instanceof CredentialRequestException || $exception instanceof InvalidCredentialInput || $exception instanceof InvalidWebhookInput ) {
			$this->interaction->respondToProviderProfileValidationFailure( $request, $error );
		}
		$this->interaction->respondToProviderProfileUnexpectedFailure( $request );
	}
	private function recordFailure( \Throwable $exception, string $operation, string $step ): string {
		$error = $this->safeError( $exception );
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
	private function safeError( \Throwable $exception ): string {
		return $exception instanceof CredentialRequestException
			|| $exception instanceof InvalidCredentialInput
			|| $exception instanceof InvalidWebhookInput
				? $exception->getMessage()
				: __( 'Booster could not complete the credential request.', 'ran-booster' );
	}
	private function providerCode( array $request ): ProviderCode {
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
	private function providerAdmin( ProviderCode $provider ): ProviderAdminMetadata {
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
	protected function respondToHtmxPublicLookupProfile( string $provider, ?string $message, ?string $error, int $status ): never {
		status_header( $status );
		$this->emitSuccessHeader( $message );
		echo $this->dashboard->renderPublicLookupProfileRegion( $provider, $error ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Core-owned, escaped view fragment.
		exit;
	}
	protected function respondToHtmxCredentialValidation( string $credentialId, ?string $message, ?string $error, int $status ): never {
		status_header( $status );
		$this->emitSuccessHeader( $message );
		echo '<div id="' . esc_attr( 'ran-booster-credential-validation-error-' . $credentialId ) . '" class="notice notice-error inline" data-ran-booster-admin-mutation-error role="alert" tabindex="-1"' . ( null === $error ? ' hidden' : '' ) . '><p>' . esc_html( $error ?? '' ) . '</p></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Core-owned escaped fragment.
		exit;
	}
	private function emitSuccessHeader( ?string $message ): void {
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
