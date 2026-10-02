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
	public function resolve_candidate( PortabilityCandidate $candidate ): array {
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
				|| $descriptor->credential_id !== $candidate->credential_id
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
					$descriptor->provider_repository_id,
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
		?BlueprintCredentialAction $credential_action = null,
		?string $target_credential_id = null,
		?bool &$repository_private = null
	): BlueprintPlanItem {
		$repository_private = null;
		$managed_import     = TargetPackageAction::MANAGED === $item->action
			&& null !== $credential
			&& BlueprintCredentialAction::IMPORT === $credential_action;
		if ( ! $managed_import && ! in_array( $item->action, array( TargetPackageAction::INSTALL, TargetPackageAction::ADOPT ), true ) ) {
			return $item;
		}

		if ( null !== $credential ) {
			if ( BlueprintCredentialAction::IMPORT !== $credential_action || ! $this->can_transfer( $item, $credential ) ) {
				if ( BlueprintCredentialAction::TARGET !== $credential_action
					|| ! $this->has_target_credential( $item->package->provider, $target_credential_id ) ) {
					return new BlueprintPlanItem( $item->package, TargetPackageAction::BLOCKED, TargetPackageReason::CREDENTIAL_REQUIRED );
				}

				try {
					return $this->verified_item( $item, $target_credential_id, $repository_private );
				} catch ( Throwable $failure ) {
					return $this->blocked_item( $item, $failure );
				}
			}

			try {
				return $this->secrets->with_temporary_credential(
					$credential->provider,
					array(
						'label'         => $credential->label,
						'kind'          => $credential->kind,
						'configuration' => $credential->configuration,
					),
					$credential->secret,
					function ( string $credential_id ) use ( $item, &$repository_private ): BlueprintPlanItem {
						return $this->verified_item( $item, $credential_id, $repository_private );
					}
				);
			} catch ( Throwable $failure ) {
				return $this->requires_credential( $failure )
					? new BlueprintPlanItem( $item->package, TargetPackageAction::BLOCKED, TargetPackageReason::CREDENTIAL_REQUIRED )
					: $this->blocked_item( $item, $failure );
			}
		}

		if ( $this->has_target_credential( $item->package->provider, $target_credential_id ) ) {
			try {
				return $this->verified_item( $item, $target_credential_id, $repository_private );
			} catch ( Throwable $failure ) {
				return $this->blocked_item( $item, $failure );
			}
		}

		try {
			return $this->verified_item( $item, null, $repository_private );
		} catch ( Throwable $failure ) {
			if ( ! $this->requires_credential( $failure ) && 429 !== $failure->getCode() ) {
				return $this->blocked_item( $item, $failure );
			}
			if ( 429 === $failure->getCode()
				&& array() === $this->secrets->credential_profiles( $item->package->provider ) ) {
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
			&& isset( $this->secrets->credential_profiles( $provider )[ $credential_id ] );
	}

	private function verified_item( BlueprintPlanItem $item, ?string $credential_id, ?bool &$repository_private ): BlueprintPlanItem {
		$package            = $item->package;
		$provider           = ProviderCode::parse( $package->provider );
		$descriptor         = $this->providers->get( $provider )->resolve_repository(
			new RepositoryLookupRequest( $package->repository, $credential_id )
		);
		$repository_private = $descriptor->private;
		$matches            = $descriptor->provider->equals( $provider )
			&& hash_equals( $package->provider_repository_id, $descriptor->provider_repository_id );

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
