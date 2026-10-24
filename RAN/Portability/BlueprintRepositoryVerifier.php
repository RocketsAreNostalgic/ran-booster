<?php

declare(strict_types=1);

namespace RAN\Portability;

use RAN\AddOn\Portability\PortabilityCandidate;
use RAN\RepositoryProvider\InvalidCredentialInput;
use RAN\RepositoryProvider\GitHubProviderSelection;
use RAN\RepositoryProvider\ProviderCode;
use RAN\RepositoryProvider\ProviderRegistry;
use RAN\RepositoryProvider\RepositoryLookupRequest;
use RAN\RepositoryProvider\UnknownProvider;
use RAN\Secrets\SecretsFile;
use Throwable;

/** Verifies package changes and explicit managed-row credential recovery. */
final class BlueprintRepositoryVerifier {

	/** @var array<string, array{id:string,private:bool}>|null */
	private ?array $observations = null;

	/** @var array<string, true> */
	private array $limited_scopes = array();

	private float $preview_started = 0.0;

	/** A new, non-persistent observation lifetime; ordinary verification stays fresh. */
	public function for_preview(): self {
		$preview                  = new self( $this->providers, $this->secrets, $this->selection );
		$preview->observations    = array();
		$preview->preview_started = hrtime( true ) / 1e9;
		return $preview;
	}

	public function __construct(
		private readonly ProviderRegistry $providers,
		private readonly SecretsFile $secrets,
		private readonly GitHubProviderSelection $selection
	) {
	}

	/** @return array{provider_code:string,selection_generation:int,implementation_fingerprint:string}|null */
	public function provider_expectation( string $provider ): ?array {
		return 'gh' === $provider ? $this->selection->expectation() : null;
	}

	/** @phpstan-impure */
	public function provider_review_refusal( string $provider, mixed $expectation ): ?string {
		if ( 'gh' === $provider ) {
			return $this->selection->review_refusal( $expectation );
		}
		if ( null !== $expectation ) {
			throw new \InvalidArgumentException( 'This provider does not accept selection context.' );
		}
		return null;
	}

	/**
	 * Resolve one credential-reference-only public candidate without accepting
	 * a caller assertion about stable identity or repository privacy.
	 *
	 * @return array{package:BlueprintPackage|null,private:bool|null,reason:TargetPackageReason}
	 */
	public function resolve_candidate( PortabilityCandidate $candidate ): array {
		$expectation = $this->provider_expectation( $candidate->provider_code );
		if ( 'gh' === $candidate->provider_code && null === $expectation ) {
			return array(
				'package' => null,
				'private' => null,
				'reason'  => TargetPackageReason::PROVIDER_UNAVAILABLE,
			);
		}
		if ( null !== $candidate->credential_id
			&& ! $this->has_target_credential( $candidate->provider_code, $candidate->credential_id ) ) {
			return array(
				'package' => null,
				'private' => null,
				'reason'  => TargetPackageReason::CREDENTIAL_REQUIRED,
			);
		}

		try {
			$refusal = $this->provider_review_refusal( $candidate->provider_code, $expectation );
			if ( null !== $refusal ) {
				return array(
					'package' => null,
					'private' => null,
					'reason'  => TargetPackageReason::from( $refusal ),
				);
			}
			$provider   = ProviderCode::parse( $candidate->provider_code );
			$descriptor = $this->providers->get( $provider )->resolve_repository(
				new RepositoryLookupRequest( $candidate->repository, $candidate->credential_id )
			);
			$refusal    = $this->provider_review_refusal( $candidate->provider_code, $expectation );
			if ( null !== $refusal ) {
				return array(
					'package' => null,
					'private' => null,
					'reason'  => TargetPackageReason::from( $refusal ),
				);
			}
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
		?bool &$repository_private = null,
		?\Closure $release_reviewer = null
	): BlueprintPlanItem {
		$repository_private = null;
		$expectation        = $item->provider_expectation ?? $this->provider_expectation( $item->package->provider );
		$refusal            = 'gh' === $item->package->provider && null === $expectation
			? 'provider_unavailable' : $this->provider_review_refusal( $item->package->provider, $expectation );
		if ( null !== $refusal ) {
			return new BlueprintPlanItem( $item->package, TargetPackageAction::BLOCKED, TargetPackageReason::from( $refusal ) );
		}
		$item           = new BlueprintPlanItem( $item->package, $item->action, $item->reason, $expectation );
		$managed_import = TargetPackageAction::MANAGED === $item->action
			&& null !== $credential
			&& BlueprintCredentialAction::IMPORT === $credential_action;
		if ( ! $managed_import && ! in_array( $item->action, array( TargetPackageAction::INSTALL, TargetPackageAction::ADOPT ), true ) ) {
			return $item;
		}

		if ( null !== $item->package->selection
			&& ( TargetPackageAction::INSTALL !== $item->action
				|| null !== $item->package->release_configuration
				|| ! defined( \RAN\WPBranchUpdater\V1\Runtime\BranchUpdater::class . '::ADMITTED_API_VERSION' )
				|| 3 !== constant( \RAN\WPBranchUpdater\V1\Runtime\BranchUpdater::class . '::ADMITTED_API_VERSION' )
				|| 2 !== ( $this->providers->metadata()[ $item->package->provider ]->archive_api_version ?? 1 )
				|| ( 'gh' === $item->package->provider && 40 !== strlen( (string) $item->package->selection['commit'] ) ) ) ) {
			return new BlueprintPlanItem( $item->package, TargetPackageAction::BLOCKED, TargetPackageReason::UNSUPPORTED_BLUEPRINT_OPERATION );
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
					function ( string $credential_id ) use ( $item, &$repository_private, $release_reviewer ): BlueprintPlanItem {
						return $this->verified_item( $item, $credential_id, $repository_private, $release_reviewer );
					}
				);
			} catch ( Throwable $failure ) {
				return $this->requires_credential( $failure )
					? new BlueprintPlanItem( $item->package, TargetPackageAction::BLOCKED, TargetPackageReason::CREDENTIAL_REQUIRED )
					: $this->blocked_item( $item, $failure );
			}
		}

		if ( null !== $target_credential_id && '' !== $target_credential_id ) {
			if ( ! $this->has_target_credential( $item->package->provider, $target_credential_id ) ) {
				return new BlueprintPlanItem( $item->package, TargetPackageAction::BLOCKED, TargetPackageReason::CREDENTIAL_REQUIRED );
			}
			try {
				return $this->verified_item( $item, $target_credential_id, $repository_private, $release_reviewer );
			} catch ( Throwable $failure ) {
				return $this->blocked_item( $item, $failure );
			}
		}

		try {
			return $this->verified_item( $item, null, $repository_private, $release_reviewer );
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

	/** @param-out bool $repository_private */
	private function verified_item( BlueprintPlanItem $item, ?string $credential_id, ?bool &$repository_private, ?\Closure $release_reviewer = null ): BlueprintPlanItem {
		$refusal = $this->provider_review_refusal( $item->package->provider, $item->provider_expectation );
		if ( null !== $refusal ) {
			return new BlueprintPlanItem( $item->package, TargetPackageAction::BLOCKED, TargetPackageReason::from( $refusal ) );
		}
		$package  = $item->package;
		$provider = ProviderCode::parse( $package->provider );
		$adapter  = $this->providers->get( $provider );
		$scope    = null;
		$key      = null;
		if ( null !== $this->observations ) {
			$material = null === $credential_id ? null : $this->secrets->credential_material( $provider, $credential_id );
			if ( null !== $credential_id && null === $material ) {
				return new BlueprintPlanItem( $package, TargetPackageAction::BLOCKED, TargetPackageReason::CREDENTIAL_REQUIRED );
			}
			// Temporary IDs are generated per row. Only validated, identical material
			// may share an observation; saved IDs remain part of their exact scope.
			if ( null !== $material && 'temporary' === ( $material['source'] ?? null ) ) {
				unset( $material['id'] );
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Fail closed on non-encodable material; WordPress recovery must not alter credential identity.
			$scope = hash( 'sha256', json_encode( array( spl_object_id( $adapter ), $package->provider, $material ), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION ) );
			$key   = hash( 'sha256', $scope . ':' . $package->repository );
			if ( isset( $this->limited_scopes[ $scope ] ) ) {
				return new BlueprintPlanItem( $package, TargetPackageAction::BLOCKED, TargetPackageReason::PREVIEW_RATE_LIMITED );
			}
		}
		$observation = null === $key ? null : ( $this->observations[ $key ] ?? null );
		if ( null === $observation ) {
			if ( null !== $this->observations && hrtime( true ) / 1e9 - $this->preview_started >= 8.0 ) {
				return new BlueprintPlanItem( $package, TargetPackageAction::BLOCKED, TargetPackageReason::PREVIEW_BUDGET_EXHAUSTED );
			}
			try {
				$descriptor = $adapter->resolve_repository( new RepositoryLookupRequest( $package->repository, $credential_id ) );
			} catch ( Throwable $failure ) {
				if ( null !== $scope && 429 === $failure->getCode() ) {
					$this->limited_scopes[ $scope ] = true;
				}
				throw $failure;
			}
			$repository_private = $descriptor->private;
			if ( ! $descriptor->provider->equals( $provider ) || $descriptor->credential_id !== $credential_id ) {
				return new BlueprintPlanItem( $package, TargetPackageAction::BLOCKED, TargetPackageReason::REPOSITORY_IDENTITY_MISMATCH );
			}
			$observation = array(
				'id'      => $descriptor->provider_repository_id,
				'private' => $descriptor->private,
			);
		}
		$refusal = $this->provider_review_refusal( $package->provider, $item->provider_expectation );
		if ( null !== $refusal ) {
			return new BlueprintPlanItem( $package, TargetPackageAction::BLOCKED, TargetPackageReason::from( $refusal ) );
		}
		$repository_private = $observation['private'];
		$matches            = hash_equals( $package->provider_repository_id, $observation['id'] );
		if ( $matches && $repository_private && null === $credential_id ) {
			return new BlueprintPlanItem( $package, TargetPackageAction::BLOCKED, TargetPackageReason::CREDENTIAL_REQUIRED );
		}
		if ( $matches && null !== $key ) {
			$this->observations[ $key ] = $observation;
		}
		if ( ! $matches ) {
			return new BlueprintPlanItem( $package, TargetPackageAction::BLOCKED, TargetPackageReason::REPOSITORY_IDENTITY_MISMATCH );
		}
		return null === $release_reviewer ? $item : $release_reviewer( $item, $credential_id, $repository_private );
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
