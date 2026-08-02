<?php

declare(strict_types=1);

namespace RAN\AddOn\WebhookAssistance;

use RAN\RepositoryProvider\ProviderCode;
use RAN\Secrets\SecretsFile;

/**
 * Narrow add-on API for repository webhook provisioning.
 *
 * It intentionally exposes neither Core's container nor sidecar secrets.
 * Secret material crosses the boundary only as a request-scoped callback
 * argument while Core retains profile lifecycle ownership.
 */
final class AssistedWebhookFacade implements WebhookAssistanceFacade, WebhookCleanupFacade {

	/** @var \Closure(): bool */
	private \Closure $canManage;

	/** @var \Closure(string): string */
	private \Closure $endpoint;

	/** @param callable(): bool|null $canManage @param callable(string): string|null $endpoint */
	public function __construct(
		private WebhookAssistanceReadinessEvaluator $readinessEvaluator,
		private SecretsFile $secrets,
		?callable $canManage = null,
		?callable $endpoint = null
	) {
		$this->canManage = null === $canManage
			? static fn (): bool => current_user_can( 'manage_options' )
			: \Closure::fromCallable( $canManage );
		$this->endpoint  = null === $endpoint
			? static fn ( string $providerCode ): string => rest_url( 'ran-booster/v1/webhooks/' . rawurlencode( $providerCode ) )
			: \Closure::fromCallable( $endpoint );
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
				if ( 'file' !== ( $profile['source'] ?? null )
					|| ! empty( $profile['immutable'] )
					|| ! is_string( $profile['id'] ?? null )
					|| ! is_string( $profile['label'] ?? null )
					|| ! is_string( $profile['kind'] ?? null )
				) {
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

	public function withCredential( string $providerCode, string $credentialId, callable $operation ): mixed {
		$providerCode = $this->providerCode( $providerCode );
		if ( ! ( $this->canManage )() || ! $this->storageAvailable() || ! $this->validCredentialId( $credentialId ) ) {
			return null;
		}

		try {
			$profile  = $this->secrets->credentialProfiles( $providerCode )[ $credentialId ] ?? null;
			$material = $this->secrets->credentialMaterial( $providerCode, $credentialId );
			if ( ! is_array( $profile ) || ! is_array( $material )
				|| 'file' !== ( $profile['source'] ?? null )
				|| ! is_string( $material['secret'] ?? null )
			) {
				return null;
			}

			return $operation( $material['secret'] );
		} catch ( \Throwable ) {
			return null;
		}
	}

	public function cleanupTarget( string $providerCode, string $repositoryId ): ?AssistanceTarget {
		$providerCode = $this->providerCode( $providerCode );

		return $this->readinessEvaluator->cleanupTarget(
			$providerCode,
			$repositoryId,
			( $this->endpoint )( $providerCode )
		);
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
			if ( $repositoryId !== $repository['repository_id']
				|| ( $requireReadySite && true !== $repository['eligible'] )
				|| ( ! $requireReadySite && array() !== ( $repository['reason_codes'] ?? array() ) )
			) {
				continue;
			}

			return new AssistanceTarget(
				$providerCode,
				$repositoryId,
				$repository['repository'],
				$repository['label'],
				$repository['package_references'],
				$repository['deployment_policies'],
				$readiness['site']['callback_url']
			);
		}

		return null;
	}

	public function provision( AssistanceTarget $target, callable $createRemoteHook ): ProvisioningResult {
		return $this->configure( $target, null, $createRemoteHook );
	}

	public function profile( string $providerCode, string $repositoryId, string $profileId ): ?WebhookProfileMetadata {
		$providerCode = $this->providerCode( $providerCode );
		if ( ! ( $this->canManage )() || ! $this->storageAvailable() || ! $this->validRepositoryId( $repositoryId ) || ! $this->validProfileId( $profileId ) ) {
			return null;
		}

		try {
			$target  = $this->projectedTarget( $providerCode, $repositoryId, false );
			$profile = null === $target ? null : ( $this->secrets->webhookProfiles( $providerCode )[ $profileId ] ?? null );

			return is_array( $profile ) && $this->appliesTo( $target, $profile )
				? $this->metadata( $providerCode, $profileId, $profile )
				: null;
		} catch ( \Throwable ) {
			return null;
		}
	}

	public function cleanupProfile( AssistanceTarget $target, string $profileId ): ?WebhookProfileMetadata {
		if ( ! ( $this->canManage )() || ! $this->storageAvailable() || ! $this->validProfileId( $profileId ) ) {
			return null;
		}

		try {
			$current = $this->cleanupTarget( $target->providerCode(), $target->repositoryId() );
			$profile = null === $current || $current->toArray() !== $target->toArray()
				? null
				: ( $this->secrets->webhookProfiles( $current->providerCode() )[ $profileId ] ?? null );

			return is_array( $profile ) && $this->appliesTo( $current, $profile )
				? $this->metadata( $current->providerCode(), $profileId, $profile )
				: null;
		} catch ( \Throwable ) {
			return null;
		}
	}

	public function reconfigure( AssistanceTarget $target, string $recordedProfileId, callable $updateRemoteHook ): ProvisioningResult {
		return $this->configure( $target, $recordedProfileId, $updateRemoteHook );
	}

	public function releaseProfile( string $providerCode, string $repositoryId, string $profileId ): bool {
		$providerCode = $this->providerCode( $providerCode );
		if ( ! ( $this->canManage )() || ! $this->storageAvailable() || ! $this->validRepositoryId( $repositoryId ) || ! $this->validProfileId( $profileId ) ) {
			return false;
		}

		try {
			$profiles = $this->secrets->webhookProfiles( $providerCode );
			if ( ! isset( $profiles[ $profileId ] ) ) {
				return true;
			}

			$profile = $profiles[ $profileId ];
			if ( 'assisted' !== ( $profile['origin'] ?? 'manual' )
				|| 'repository' !== ( $profile['scope'] ?? null )
				|| 'file' !== ( $profile['source'] ?? null )
				|| ! empty( $profile['immutable'] )
			) {
				return true;
			}

			$target = $this->projectedTarget( $providerCode, $repositoryId, false );
			if ( null === $target || ! $this->appliesTo( $target, $profile ) ) {
				return false;
			}

			return $this->secrets->deleteWebhook( $providerCode, $profileId );
		} catch ( \Throwable ) {
			return false;
		}
	}

	public function releaseCleanupProfile( AssistanceTarget $target, string $profileId ): bool {
		if ( ! ( $this->canManage )() || ! $this->storageAvailable() || ! $this->validProfileId( $profileId ) ) {
			return false;
		}

		try {
			$current = $this->cleanupTarget( $target->providerCode(), $target->repositoryId() );
			if ( null === $current || $current->toArray() !== $target->toArray() ) {
				return false;
			}

			$profiles = $this->secrets->webhookProfiles( $current->providerCode() );
			if ( ! isset( $profiles[ $profileId ] ) ) {
				return true;
			}
			$profile = $profiles[ $profileId ];
			if ( 'assisted' !== ( $profile['origin'] ?? 'manual' )
				|| 'repository' !== ( $profile['scope'] ?? null )
				|| 'file' !== ( $profile['source'] ?? null )
				|| ! empty( $profile['immutable'] )
			) {
				return true;
			}
			if ( ! $this->appliesTo( $current, $profile ) ) {
				return false;
			}

			return $this->secrets->deleteWebhook( $current->providerCode(), $profileId );
		} catch ( \Throwable ) {
			return false;
		}
	}

	private function configure( AssistanceTarget $submitted, ?string $recordedProfileId, callable $callback ): ProvisioningResult {
		if ( ! ( $this->canManage )() ) {
			return ProvisioningResult::failed( 'forbidden' );
		}
		if ( ! $this->storageAvailable() ) {
			return ProvisioningResult::failed( 'storage_unavailable' );
		}

		$current = $this->target( $submitted->providerCode(), $submitted->repositoryId() );
		if ( null === $current || $current->toArray() !== $submitted->toArray() ) {
			return ProvisioningResult::failed( 'target_unavailable' );
		}

		$created = false;
		try {
			$selection = $this->selectProfile( $current, $recordedProfileId );
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
					$this->newSecret()
				);
				$created   = true;
				$selection = $this->profileRecord( $current->providerCode(), $profileId );
			}
			if ( null === $selection ) {
				if ( $created && isset( $profileId ) && ! $this->deleteCreatedProfile( $current->providerCode(), $profileId ) ) {
					return ProvisioningResult::failed( 'profile_cleanup_failed' );
				}

				return ProvisioningResult::failed( 'profile_unavailable' );
			}
		} catch ( \Throwable ) {
			if ( $created && isset( $profileId ) && ! $this->deleteCreatedProfile( $current->providerCode(), $profileId ) ) {
				return ProvisioningResult::failed( 'profile_cleanup_failed' );
			}

			return ProvisioningResult::failed( 'profile_unavailable' );
		}

		list( $metadata, $secret ) = $selection;
		try {
			$result = $callback( $metadata->id(), $secret, $metadata->revision() );
		} catch ( \Throwable ) {
			$result = ProvisioningCallbackResult::failed();
		}
		if ( ! $result instanceof ProvisioningCallbackResult || ! $result->wasSuccessful() ) {
			if ( $created && ! $this->deleteCreatedProfile( $current->providerCode(), $metadata->id() ) ) {
				return ProvisioningResult::failed( 'profile_cleanup_failed' );
			}

			return ProvisioningResult::failed( 'remote_configuration_failed' );
		}

		return ProvisioningResult::success( $metadata );
	}

	private function deleteCreatedProfile( string $providerCode, string $profileId ): bool {
		try {
			return $this->secrets->deleteWebhook( $providerCode, $profileId );
		} catch ( \Throwable ) {
			return false;
		}
	}

	/**
	 * @return array{WebhookProfileMetadata, string}|null
	 */
	private function selectProfile( AssistanceTarget $target, ?string $recordedProfileId ): ?array {
		$profiles = $this->secrets->webhookProfiles( $target->providerCode() );
		if ( is_string( $recordedProfileId ) && $this->validProfileId( $recordedProfileId ) ) {
			$recorded = $profiles[ $recordedProfileId ] ?? null;
			if ( is_array( $recorded ) && $this->appliesTo( $target, $recorded ) ) {
				return $this->profileRecord( $target->providerCode(), $recordedProfileId );
			}
		}

		foreach ( array( 'repository', 'owner' ) as $scope ) {
			foreach ( $profiles as $profileId => $profile ) {
				if ( $scope === ( $profile['scope'] ?? null ) && $this->appliesTo( $target, $profile ) ) {
					return $this->profileRecord( $target->providerCode(), (string) $profileId );
				}
			}
		}

		return null;
	}

	/**
	 * @return array{WebhookProfileMetadata, string}|null
	 */
	private function profileRecord( string $providerCode, string $profileId ): ?array {
		$profile  = $this->secrets->webhookProfiles( $providerCode )[ $profileId ] ?? null;
		$material = $this->secrets->webhookMaterials( $providerCode )[ $profileId ] ?? null;
		if ( ! is_array( $profile ) || ! is_array( $material ) || ! is_string( $material['secret'] ?? null ) ) {
			return null;
		}

		return array( $this->metadata( $providerCode, $profileId, $profile ), $material['secret'] );
	}

	/** @param array<string, mixed> $profile */
	private function appliesTo( AssistanceTarget $target, array $profile ): bool {
		if ( false === ( $profile['configured'] ?? true ) ) {
			return false;
		}
		if ( 'repository' === ( $profile['scope'] ?? null ) ) {
			return is_string( $profile['authority_id'] ?? null )
				&& hash_equals( $target->repositoryId(), $profile['authority_id'] );
		}
		if ( 'owner' !== ( $profile['scope'] ?? null ) || ! is_string( $profile['target'] ?? null ) ) {
			return false;
		}

		$owner = explode( '/', trim( $target->repository(), '/' ), 2 )[0];

		return 0 === strcasecmp( $owner, trim( $profile['target'], '/' ) );
	}

	/** @param array<string, mixed> $profile */
	private function metadata( string $providerCode, string $profileId, array $profile ): WebhookProfileMetadata {
		$disposition = 'assisted' === ( $profile['origin'] ?? 'manual' )
			&& 'repository' === ( $profile['scope'] ?? null )
				? 'created'
				: 'reused';

		return new WebhookProfileMetadata(
			$profileId,
			$providerCode,
			(string) ( $profile['scope'] ?? '' ),
			(string) ( $profile['target'] ?? '' ),
			(string) ( $profile['authority_id'] ?? '' ),
			(int) ( $profile['revision'] ?? 1 ),
			$disposition,
			(string) ( $profile['source'] ?? 'file' ),
			! empty( $profile['immutable'] )
		);
	}

	private function storageAvailable(): bool {
		return $this->readinessEvaluator->managedStorageAvailable();
	}

	private function newSecret(): string {
		return bin2hex( random_bytes( 32 ) );
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
