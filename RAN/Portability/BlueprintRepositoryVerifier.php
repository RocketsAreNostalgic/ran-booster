<?php

declare(strict_types=1);

namespace RAN\Portability;

use RAN\AddOn\Portability\PortabilityCandidate;
use RAN\RepositoryProvider\InvalidCredentialInput;
use RAN\RepositoryProvider\ProviderCode;
use RAN\RepositoryProvider\ProviderRegistry;
use RAN\RepositoryProvider\RepositoryLookupRequest;
use RAN\RepositoryProvider\UnknownProvider;
use RAN\Secrets\SecretsFile;
use Throwable;

/** Verifies package changes and explicit managed-row credential recovery. */
final readonly class BlueprintRepositoryVerifier {

	public function __construct(
		private ProviderRegistry $providers,
		private SecretsFile $secrets
	) {
	}

	/**
	 * Resolve one credential-reference-only public candidate without accepting
	 * a caller assertion about stable identity or repository privacy.
	 *
	 * @return array{package:BlueprintPackage|null,private:bool|null,reason:TargetPackageReason}
	 */
	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public method and named by-reference parameter contracts remain unchanged pending their connected-caller audit.
	public function resolveCandidate( PortabilityCandidate $candidate ): array {
		if ( null !== $candidate->credential_id
			&& ! $this->has_target_credential( $candidate->provider_code, $candidate->credential_id ) ) {
			return array(
				'package' => null,
				'private' => null,
				'reason'  => TargetPackageReason::CREDENTIAL_REQUIRED,
			);
		}

		try {
			$provider   = ProviderCode::parse( $candidate->provider_code );
			$descriptor = $this->providers->get( $provider )->resolve_repository(
				new RepositoryLookupRequest( $candidate->repository, $candidate->credential_id )
			);
			if ( ! $descriptor->provider->equals( $provider )
				// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Foreign DTO properties retain their separately owned contracts.
				|| $descriptor->credentialId !== $candidate->credential_id
				|| ( $descriptor->private && null === $candidate->credential_id ) ) {
				return array(
					'package' => null,
					'private' => null,
					'reason'  => null === $candidate->credential_id
						? TargetPackageReason::CREDENTIAL_REQUIRED
						: TargetPackageReason::REPOSITORY_IDENTITY_MISMATCH,
				);
			}

			return array(
				'package' => new BlueprintPackage(
					$candidate->type,
					$candidate->identifier,
					$candidate->display_name,
					$candidate->provider_code,
					// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Foreign DTO properties retain their separately owned contracts.
					$descriptor->providerRepositoryId,
					$candidate->repository,
					$candidate->branch,
					$candidate->subdirectory
				),
				'private' => $descriptor->private,
				'reason'  => TargetPackageReason::NONE,
			);
		} catch ( Throwable $failure ) {
			return array(
				'package' => null,
				'private' => null,
				'reason'  => $this->failure_reason( $failure, true ),
			);
		}
	}

	public function verify(
		BlueprintPlanItem $item,
		?BlueprintCredential $credential = null,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public named parameters and their uses retain the existing caller contract.
		?BlueprintCredentialAction $credentialAction = null,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public named parameters and their uses retain the existing caller contract.
		?string $targetCredentialId = null,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public named parameters and their uses retain the existing caller contract.
		?bool &$repositoryPrivate = null
	): BlueprintPlanItem {
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public named parameters and their uses retain the existing caller contract.
		$repositoryPrivate = null;
		$managed_import    = TargetPackageAction::MANAGED === $item->action
			&& null !== $credential
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public named parameters and their uses retain the existing caller contract.
			&& BlueprintCredentialAction::IMPORT === $credentialAction;
		if ( ! $managed_import && ! in_array( $item->action, array( TargetPackageAction::INSTALL, TargetPackageAction::ADOPT ), true ) ) {
			return $item;
		}

		if ( null !== $credential ) {
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public named parameters and their uses retain the existing caller contract.
			if ( BlueprintCredentialAction::IMPORT !== $credentialAction || ! $this->can_transfer( $item, $credential ) ) {
				// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public named parameters and their uses retain the existing caller contract.
				if ( BlueprintCredentialAction::TARGET !== $credentialAction
					// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public named parameters and their uses retain the existing caller contract.
					|| ! $this->has_target_credential( $item->package->provider, $targetCredentialId ) ) {
					return new BlueprintPlanItem( $item->package, TargetPackageAction::BLOCKED, TargetPackageReason::CREDENTIAL_REQUIRED );
				}

				try {
					// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public named parameters and their uses retain the existing caller contract.
					return $this->verified_item( $item, $targetCredentialId, $repositoryPrivate );
				} catch ( Throwable $failure ) {
					return $this->blocked_item( $item, $failure );
				}
			}

			try {
				return $this->secrets->withTemporaryCredential(
					$credential->provider,
					array(
						'label'         => $credential->label,
						'kind'          => $credential->kind,
						'configuration' => $credential->configuration,
					),
					$credential->secret,
					// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public named parameters and their uses retain the existing caller contract.
					function ( string $credential_id ) use ( $item, &$repositoryPrivate ): BlueprintPlanItem {
						// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public named parameters and their uses retain the existing caller contract.
						return $this->verified_item( $item, $credential_id, $repositoryPrivate );
					}
				);
			} catch ( Throwable $failure ) {
				return $this->requires_credential( $failure )
					? new BlueprintPlanItem( $item->package, TargetPackageAction::BLOCKED, TargetPackageReason::CREDENTIAL_REQUIRED )
					: $this->blocked_item( $item, $failure );
			}
		}

		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public named parameters and their uses retain the existing caller contract.
		if ( $this->has_target_credential( $item->package->provider, $targetCredentialId ) ) {
			try {
				// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public named parameters and their uses retain the existing caller contract.
				return $this->verified_item( $item, $targetCredentialId, $repositoryPrivate );
			} catch ( Throwable $failure ) {
				return $this->blocked_item( $item, $failure );
			}
		}

		try {
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public named parameters and their uses retain the existing caller contract.
			return $this->verified_item( $item, null, $repositoryPrivate );
		} catch ( Throwable $failure ) {
			if ( ! $this->requires_credential( $failure ) && 429 !== $failure->getCode() ) {
				return $this->blocked_item( $item, $failure );
			}
			if ( 429 === $failure->getCode()
				&& array() === $this->secrets->credentialProfiles( $item->package->provider ) ) {
				return $this->blocked_item( $item, $failure );
			}
		}

		return new BlueprintPlanItem( $item->package, TargetPackageAction::BLOCKED, TargetPackageReason::CREDENTIAL_REQUIRED );
	}

	private function can_transfer( BlueprintPlanItem $item, ?BlueprintCredential $credential ): bool {
		return null !== $credential
			&& $credential->provider === $item->package->provider
			&& in_array(
				array(
					'type'       => $item->package->type,
					'identifier' => $item->package->identifier,
				),
				$credential->packages,
				true
			);
	}

	private function has_target_credential( string $provider, ?string $credential_id ): bool {
		return null !== $credential_id
			&& '' !== $credential_id
			&& isset( $this->secrets->credentialProfiles( $provider )[ $credential_id ] );
	}

	private function verified_item( BlueprintPlanItem $item, ?string $credential_id, ?bool &$repository_private ): BlueprintPlanItem {
		$package            = $item->package;
		$provider           = ProviderCode::parse( $package->provider );
		$descriptor         = $this->providers->get( $provider )->resolve_repository(
			new RepositoryLookupRequest( $package->repository, $credential_id )
		);
		$repository_private = $descriptor->private;
		$matches            = $descriptor->provider->equals( $provider )
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Foreign DTO properties retain their separately owned contracts.
			&& hash_equals( $package->providerRepositoryId, $descriptor->providerRepositoryId );

		return $matches
			? $item
			: new BlueprintPlanItem( $package, TargetPackageAction::BLOCKED, TargetPackageReason::REPOSITORY_IDENTITY_MISMATCH );
	}

	private function requires_credential( Throwable $failure ): bool {
		return $failure instanceof InvalidCredentialInput
			|| in_array( $failure->getCode(), array( 401, 403, 404 ), true );
	}

	private function blocked_item( BlueprintPlanItem $item, Throwable $failure ): BlueprintPlanItem {
		return new BlueprintPlanItem( $item->package, TargetPackageAction::BLOCKED, $this->failure_reason( $failure ) );
	}

	private function failure_reason( Throwable $failure, bool $credential_required = false ): TargetPackageReason {
		return match ( true ) {
			$failure instanceof UnknownProvider => TargetPackageReason::PROVIDER_UNAVAILABLE,
			$credential_required && $this->requires_credential( $failure ) => TargetPackageReason::CREDENTIAL_REQUIRED,
			429 === $failure->getCode(), $failure->getCode() >= 500 => TargetPackageReason::PROVIDER_TEMPORARILY_UNAVAILABLE,
			default => TargetPackageReason::REPOSITORY_ACCESS_FAILED,
		};
	}
}
