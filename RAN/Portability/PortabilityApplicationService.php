<?php

declare(strict_types=1);

namespace RAN\Portability;

use InvalidArgumentException;
use RAN\AddOn\Portability\PortabilityApplyResult;
use RAN\AddOn\Portability\PortabilityCandidate;
use RAN\AddOn\Portability\PortabilityReviewResult;
use RAN\Deployment\DeploymentPolicy;
use RAN\Package;
use RAN\PackageOperation;
use RAN\PackageOperationService;
use RAN\PackageSource;
use RAN\Plugin;
use RAN\Secrets\SecretsFile;
use RAN\Theme;
use Throwable;

/** Canonical review and Apply behavior shared by Transporter adapters. */
final readonly class PortabilityApplicationService {

	public function __construct(
		private BlueprintReviewer $reviewer,
		private BlueprintRepositoryVerifier $verifier,
		private PackageOperationService $operations,
		private SecretsFile $secrets
	) {
	}

	/**
	 * @param array<int, string> $targetCredentialIds
	 * @return list<BlueprintPlanItem>
	 */
	public function review( PackageBlueprint $blueprint, array $targetCredentialIds = array() ): array {
		$items        = $this->reviewer->review( $blueprint );
		$verified     = array();
		$storageReady = $this->credentialStorageReady( $blueprint );

		foreach ( $items as $index => $item ) {
			$credential = $this->credentialFor( $blueprint, $item );
			$verified[] = null !== $credential && $this->isActionable( $item ) && ! $storageReady
				? new BlueprintPlanItem(
					$item->package,
					TargetPackageAction::BLOCKED,
					TargetPackageReason::LOCAL_SECRET_STORE_UNAVAILABLE
				)
				: $this->verifier->verify( $item, $credential, $targetCredentialIds[ $index ] ?? null );
		}

		return $verified;
	}

	public function reviewCandidate( PortabilityCandidate $candidate ): PortabilityReviewResult {
		return $this->candidateContext( $candidate )['review'];
	}

	public function applyCandidate(
		PortabilityCandidate $candidate,
		string $expectedFingerprint
	): PortabilityApplyResult {
		$context = $this->candidateContext( $candidate );
		$review  = $context['review'];
		if ( ! hash_equals( $review->fingerprint, $expectedFingerprint ) ) {
			return new PortabilityApplyResult(
				PortabilityApplyResult::BLOCKED,
				'review_changed',
				__( 'The package changed since review. Review it again before applying.', 'ran-booster' ),
				false
			);
		}
		if ( PortabilityReviewResult::MANAGED === $review->action ) {
			return new PortabilityApplyResult(
				PortabilityApplyResult::UNCHANGED,
				TargetPackageReason::ALREADY_MANAGED->value,
				__( 'This package is already managed with the exact disabled configuration.', 'ran-booster' ),
				true
			);
		}
		if ( PortabilityReviewResult::ADOPT !== $review->action
			|| ! $context['package'] instanceof BlueprintPackage
			|| ! is_bool( $context['private'] ) ) {
			return new PortabilityApplyResult(
				PortabilityApplyResult::BLOCKED,
				$review->reason,
				$review->message,
				false
			);
		}

		$package   = $context['package'];
		$blueprint = new PackageBlueprint( array( $package ) );
		$item      = new BlueprintPlanItem( $package, TargetPackageAction::ADOPT, TargetPackageReason::NONE );
		$result    = $this->applyItem(
			$blueprint,
			$item,
			null,
			null === $candidate->credentialId ? null : 'target',
			$candidate->credentialId,
			$context['private'],
			true,
			true
		);

		return new PortabilityApplyResult(
			PortabilityApplyResult::ADOPTED,
			TargetPackageReason::NONE->value,
			$result['message'],
			true
		);
	}

	/**
	 * @return array{status:string,message:string}
	 */
	public function apply(
		PackageBlueprint $blueprint,
		int $row,
		?string $expectedAction,
		?string $targetCredentialId,
		bool $adopt,
		bool $canInstall
	): array {
		$item = $this->reviewer->review( $blueprint )[ $row ] ?? null;
		if ( ! $item instanceof BlueprintPlanItem ) {
			throw new InvalidArgumentException();
		}
		$credential = $this->credentialFor( $blueprint, $item );
		if ( null !== $credential && $this->isActionable( $item ) ) {
			$this->assertLocalSecretStoreReady();
		}
		$source            = null;
		$repositoryPrivate = null;
		$item              = $this->verifier->verify( $item, $credential, $targetCredentialId, $source, $repositoryPrivate );
		if ( $expectedAction !== $item->action->value ) {
			return array(
				'status'  => 'skipped',
				'message' => __( 'This package changed since review. Review the Transporter Blueprint again.', 'ran-booster' ),
			);
		}

		return $this->applyItem( $blueprint, $item, $credential, $source, $targetCredentialId, $repositoryPrivate, $adopt, $canInstall );
	}

	/** @return array{status:string,message:string} */
	private function applyItem(
		PackageBlueprint $blueprint,
		BlueprintPlanItem $item,
		?BlueprintCredential $credential,
		?string $source,
		?string $targetCredentialId,
		?bool $repositoryPrivate,
		bool $adopt,
		bool $canInstall
	): array {
		if ( TargetPackageAction::MANAGED === $item->action ) {
			return array(
				'status'  => 'unchanged',
				'message' => __( 'This package is already managed.', 'ran-booster' ),
			);
		}
		if ( ! $this->isActionable( $item ) ) {
			return array(
				'status'  => 'skipped',
				'message' => __( 'This package changed or cannot be applied. Review the Transporter Blueprint again.', 'ran-booster' ),
			);
		}
		if ( ! $canInstall ) {
			return array(
				'status'  => 'failed',
				'message' => __( 'You do not have permission to apply this package type.', 'ran-booster' ),
			);
		}
		if ( TargetPackageAction::ADOPT === $item->action && ! $adopt ) {
			return array(
				'status'  => 'skipped',
				'message' => __( 'This installed package was not selected for adoption.', 'ran-booster' ),
			);
		}

		$credentialId = $this->credentialId( $blueprint, $credential, $source, $targetCredentialId );
		if ( null === $repositoryPrivate ) {
			throw new InvalidArgumentException();
		}
		$operation = PackageOperation::fromInput(
			'install-' . $item->package->type,
			$this->operationInput( $item, $credentialId, $repositoryPrivate )
		);
		$result    = $this->operations->execute( $operation );
		if ( TargetPackageAction::ADOPT === $item->action ) {
			$this->assertDisabledResult( $result, $item->package, $credentialId, $repositoryPrivate );

			return array(
				'status'  => 'adopted',
				'message' => __( 'Adopted: deployment disabled', 'ran-booster' ),
			);
		}
		if ( 'succeeded' === ( $result['status'] ?? null ) ) {
			$this->assertDisabledResult( $result, $item->package, $credentialId, $repositoryPrivate );
		}

		return $this->deploymentResult( $result, $item->package );
	}

	/**
	 * @param array<string, mixed> $result
	 */
	private function assertDisabledResult(
		array $result,
		BlueprintPackage $blueprintPackage,
		?string $credentialId,
		bool $repositoryPrivate
	): void {
		$package = $result['package'] ?? null;
		if ( ! $package instanceof Package
			|| ! $this->targetVerified( $package, $blueprintPackage, $credentialId, $repositoryPrivate ) ) {
			throw new \RuntimeException( 'The Blueprint package was not persisted with the expected disabled management configuration.' );
		}
	}

	private function targetVerified(
		Package $package,
		BlueprintPackage $blueprintPackage,
		?string $credentialId,
		bool $repositoryPrivate
	): bool {
		return ! ( ( 'plugin' === $blueprintPackage->type && ! $package instanceof Plugin )
			|| ( 'theme' === $blueprintPackage->type && ! $package instanceof Theme )
			|| PackageSource::BRANCH !== $package->getSource()
			|| ! $blueprintPackage->sameManagementAs( BlueprintPackage::fromManagedPackage( $blueprintPackage->type, $package ) )
			|| ( $credentialId ?? '' ) !== $package->getCredentialId()
			|| $repositoryPrivate !== (bool) $package->isPrivate()
			|| DeploymentPolicy::DISABLED !== $package->getDeploymentPolicy() );
	}

	/**
	 * @return array{review:PortabilityReviewResult,package:BlueprintPackage|null,private:bool|null}
	 */
	private function candidateContext( PortabilityCandidate $candidate ): array {
		$resolved = $this->verifier->resolveCandidate( $candidate );
		$package  = $resolved['package'];
		$private  = $resolved['private'];
		if ( ! $package instanceof BlueprintPackage || ! is_bool( $private ) ) {
			$reason = $resolved['reason'];

			return array(
				'review'  => PortabilityReviewResult::fromResolved(
					$candidate,
					PortabilityReviewResult::BLOCKED,
					$reason->value,
					$reason->message(),
					null,
					null
				),
				'package' => null,
				'private' => null,
			);
		}

		$managed = null;
		$item    = $this->reviewer->reviewPackage( $package, $managed );
		if ( TargetPackageAction::INSTALL === $item->action ) {
			$item = new BlueprintPlanItem( $package, TargetPackageAction::BLOCKED, TargetPackageReason::DESTINATION_CONFLICT );
		} elseif ( TargetPackageAction::MANAGED === $item->action
			&& ( ! $managed instanceof Package
				|| ! $this->targetVerified( $managed, $package, $candidate->credentialId, $private ) ) ) {
			$item = new BlueprintPlanItem( $package, TargetPackageAction::PROTECTED, TargetPackageReason::MANAGEMENT_CONFLICT );
		}

		return array(
			'review'  => PortabilityReviewResult::fromResolved(
				$candidate,
				$item->action->value,
				$item->reason->value,
				$item->reason->message(),
				$package->providerRepositoryId,
				$private
			),
			'package' => $package,
			'private' => $private,
		);
	}

	/**
	 * @param array<string, mixed> $result
	 * @return array{status:string,message:string}
	 */
	private function deploymentResult( array $result, BlueprintPackage $package ): array {
		if ( 'succeeded' === ( $result['status'] ?? null ) ) {
			return array(
				'status'  => 'installed',
				'message' => __( 'Package installed and managed by Booster with deployment disabled. Re-enable deployment deliberately when this site is ready.', 'ran-booster' ),
			);
		}

		$outcomeCode = is_string( $result['outcome_code'] ?? null ) ? $result['outcome_code'] : '';
		$reference   = is_string( $result['correlation_id'] ?? null ) && 1 === preg_match( '/^[a-f0-9]{32}$/D', $result['correlation_id'] )
			? $result['correlation_id']
			: null;
		$message     = \RAN\Admin\DeploymentOutcomeMessage::forCode( $outcomeCode );
		if ( null !== $reference ) {
			$message = sprintf(
				/* translators: 1: safe deployment failure reason, 2: random support reference. */
				__( '%1$s Reference: %2$s.', 'ran-booster' ),
				$message,
				$reference
			);
		}
		$packageType = 'plugin' === $package->type ? __( 'Plugin', 'ran-booster' ) : __( 'Theme', 'ran-booster' );

		return array(
			'status'  => 'failed',
			'message' => sprintf(
				/* translators: 1: package type, 2: package display name, 3: safe deployment failure reason and optional support reference. */
				__( '%1$s “%2$s”: %3$s', 'ran-booster' ),
				$packageType,
				$package->displayName,
				$message
			),
		);
	}

	private function credentialId(
		PackageBlueprint $blueprint,
		?BlueprintCredential $credential,
		?string $source,
		?string $targetCredentialId
	): ?string {
		if ( 'target' === $source ) {
			return $targetCredentialId;
		}
		if ( 'transferred' !== $source || null === $credential ) {
			return null;
		}

		$this->assertLocalSecretStoreReady();

		return $this->secrets->importCredentialsIfAbsent( $blueprint, $credential )[0] ?? throw new InvalidArgumentException();
	}

	/** @return array<string, string> */
	private function operationInput( BlueprintPlanItem $item, ?string $credentialId, bool $repositoryPrivate ): array {
		$package = $item->package;
		$input   = array(
			'provider'                            => $package->provider,
			'repository'                          => $package->repository,
			'branch'                              => $package->branch,
			'provider_repository_id'              => $package->providerRepositoryId,
			'provider_repository_identity_source' => 'resolved',
			'credential_id'                       => $credentialId ?? '',
			'private'                             => $repositoryPrivate ? '1' : '0',
			'deployment_policy'                   => DeploymentPolicy::DISABLED->value,
			'package_slug'                        => 'plugin' === $package->type ? explode( '/', $package->identifier, 2 )[0] : $package->identifier,
			'subdirectory'                        => $package->subdirectory ?? '',
		);
		if ( TargetPackageAction::ADOPT === $item->action ) {
			$input['dry-run']          = '1';
			$input['exact_identifier'] = '1';
			$input[ 'plugin' === $package->type ? 'file' : 'stylesheet' ] = $package->identifier;
		}

		return $input;
	}

	private function credentialFor( PackageBlueprint $blueprint, BlueprintPlanItem $item ): ?BlueprintCredential {
		foreach ( $blueprint->credentials as $credential ) {
			if ( $credential->provider === $item->package->provider
				&& in_array(
					array(
						'type'       => $item->package->type,
						'identifier' => $item->package->identifier,
					),
					$credential->packages,
					true
				) ) {
				return $credential;
			}
		}

		return null;
	}

	private function credentialStorageReady( PackageBlueprint $blueprint ): bool {
		if ( array() === $blueprint->credentials ) {
			return true;
		}
		try {
			$this->secrets->assertManagedStorageReady();

			return true;
		} catch ( Throwable ) {
			return false;
		}
	}

	private function assertLocalSecretStoreReady(): void {
		try {
			$this->secrets->assertManagedStorageReady();
		} catch ( Throwable $failure ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The typed exception is caught by the controller and never rendered directly.
			throw LocalSecretStoreUnavailable::forPortability( $failure );
		}
	}

	private function isActionable( BlueprintPlanItem $item ): bool {
		return in_array( $item->action, array( TargetPackageAction::INSTALL, TargetPackageAction::ADOPT ), true );
	}
}
