<?php

declare(strict_types=1);

namespace RAN\Portability;

use RAN\AddOn\Portability\PortabilityCandidate;
use RAN\RepositoryProvider\ProviderCode;
use RAN\RepositoryProvider\ProviderRegistry;
use RAN\RepositoryProvider\RepositoryLookupRequest;
use RAN\RepositoryProvider\UnknownProvider;
use RAN\Secrets\SecretsFile;
use Throwable;

/** Verifies only install/adopt rows against their declared provider repository. */
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
	public function resolveCandidate( PortabilityCandidate $candidate ): array {
		if ( null !== $candidate->credentialId
			&& ! $this->hasTargetCredential( $candidate->providerCode, $candidate->credentialId ) ) {
			return array(
				'package' => null,
				'private' => null,
				'reason'  => TargetPackageReason::CREDENTIAL_REQUIRED,
			);
		}

		try {
			$provider   = ProviderCode::parse( $candidate->providerCode );
			$descriptor = $this->providers->get( $provider )->resolveRepository(
				new RepositoryLookupRequest( $candidate->repository, $candidate->credentialId )
			);
			if ( ! $descriptor->provider->equals( $provider )
				|| $descriptor->credentialId !== $candidate->credentialId
				|| ( $descriptor->private && null === $candidate->credentialId ) ) {
				return array(
					'package' => null,
					'private' => null,
					'reason'  => null === $candidate->credentialId
						? TargetPackageReason::CREDENTIAL_REQUIRED
						: TargetPackageReason::REPOSITORY_IDENTITY_MISMATCH,
				);
			}

			return array(
				'package' => new BlueprintPackage(
					$candidate->type,
					$candidate->identifier,
					$candidate->displayName,
					$candidate->providerCode,
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
				'reason'  => $this->failureReason( $failure, true ),
			);
		}
	}

	public function verify(
		BlueprintPlanItem $item,
		?BlueprintCredential $credential = null,
		?string $targetCredentialId = null,
		?string &$credentialSource = null,
		?bool &$repositoryPrivate = null
	): BlueprintPlanItem {
		$credentialSource    = null;
		$repositoryPrivate   = null;
		$credentialAttempted = false;
		if ( ! in_array( $item->action, array( TargetPackageAction::INSTALL, TargetPackageAction::ADOPT ), true ) ) {
			return $item;
		}

		if ( $this->canTransfer( $item, $credential ) ) {
			$credentialAttempted = true;
			try {
				$verified = $this->secrets->withTemporaryCredential(
					$credential->provider,
					array(
						'label'         => $credential->label,
						'kind'          => $credential->kind,
						'configuration' => $credential->configuration,
					),
					$credential->secret,
					function ( string $credentialId ) use ( $item, &$repositoryPrivate ): BlueprintPlanItem {
						return $this->verifiedItem( $item, $credentialId, $repositoryPrivate );
					}
				);
				if ( $verified === $item ) {
					$credentialSource = 'transferred';
				}

				return $verified;
			} catch ( Throwable $failure ) {
				if ( ! $this->requiresCredential( $failure ) ) {
					return $this->blockedItem( $item, $failure );
				}
			}
		}

		if ( $this->hasTargetCredential( $item->package->provider, $targetCredentialId ) ) {
			$credentialAttempted = true;
			try {
				$verified = $this->verifiedItem( $item, $targetCredentialId, $repositoryPrivate );
				if ( $verified === $item ) {
					$credentialSource = 'target';
				}

				return $verified;
			} catch ( Throwable $failure ) {
				return $this->blockedItem( $item, $failure );
			}
		}

		if ( $credentialAttempted ) {
			return new BlueprintPlanItem( $item->package, TargetPackageAction::BLOCKED, TargetPackageReason::CREDENTIAL_REQUIRED );
		}

		try {
			return $this->verifiedItem( $item, null, $repositoryPrivate );
		} catch ( Throwable $failure ) {
			if ( ! $this->requiresCredential( $failure ) && 429 !== $failure->getCode() ) {
				return $this->blockedItem( $item, $failure );
			}
			if ( 429 === $failure->getCode()
				&& array() === $this->secrets->credentialProfiles( $item->package->provider ) ) {
				return $this->blockedItem( $item, $failure );
			}
		}

		return new BlueprintPlanItem( $item->package, TargetPackageAction::BLOCKED, TargetPackageReason::CREDENTIAL_REQUIRED );
	}

	private function canTransfer( BlueprintPlanItem $item, ?BlueprintCredential $credential ): bool {
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

	private function hasTargetCredential( string $provider, ?string $credentialId ): bool {
		return null !== $credentialId
			&& '' !== $credentialId
			&& isset( $this->secrets->credentialProfiles( $provider )[ $credentialId ] );
	}

	private function verifiedItem( BlueprintPlanItem $item, ?string $credentialId, ?bool &$repositoryPrivate ): BlueprintPlanItem {
		$package           = $item->package;
		$provider          = ProviderCode::parse( $package->provider );
		$descriptor        = $this->providers->get( $provider )->resolveRepository(
			new RepositoryLookupRequest( $package->repository, $credentialId )
		);
		$repositoryPrivate = $descriptor->private;
		$matches           = $descriptor->provider->equals( $provider )
			&& hash_equals( $package->providerRepositoryId, $descriptor->providerRepositoryId );

		return $matches
			? $item
			: new BlueprintPlanItem( $package, TargetPackageAction::BLOCKED, TargetPackageReason::REPOSITORY_IDENTITY_MISMATCH );
	}

	private function requiresCredential( Throwable $failure ): bool {
		return in_array( $failure->getCode(), array( 401, 403, 404 ), true );
	}

	private function blockedItem( BlueprintPlanItem $item, Throwable $failure ): BlueprintPlanItem {
		return new BlueprintPlanItem( $item->package, TargetPackageAction::BLOCKED, $this->failureReason( $failure ) );
	}

	private function failureReason( Throwable $failure, bool $credentialRequired = false ): TargetPackageReason {
		return match ( true ) {
			$failure instanceof UnknownProvider => TargetPackageReason::PROVIDER_UNAVAILABLE,
			$credentialRequired && $this->requiresCredential( $failure ) => TargetPackageReason::CREDENTIAL_REQUIRED,
			429 === $failure->getCode(), $failure->getCode() >= 500 => TargetPackageReason::PROVIDER_TEMPORARILY_UNAVAILABLE,
			default => TargetPackageReason::REPOSITORY_ACCESS_FAILED,
		};
	}
}
