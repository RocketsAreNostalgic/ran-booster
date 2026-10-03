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
	 * @param array<int, array{action:BlueprintCredentialAction,target_id:?string}> $credential_decisions
	 * @param array<int, string> $target_credential_ids
	 * @return list<BlueprintPlanItem>
	 */
	public function review( PackageBlueprint $blueprint, array $credential_decisions = array(), array $target_credential_ids = array() ): array {
		$items    = $this->reviewer->review( $blueprint );
		$verified = array();

		foreach ( $items as $index => $item ) {
			$ordinal    = null;
			$credential = $this->credential_for( $blueprint, $item, $ordinal );
			$decision   = null === $ordinal ? null : ( $credential_decisions[ $ordinal ] ?? null );
			$action     = $decision['action'] ?? null;
			$verified[] = BlueprintCredentialAction::IMPORT === $action && $this->can_import_credential( $item ) && ! $this->credential_storage_ready()
				? new BlueprintPlanItem(
					$item->package,
					TargetPackageAction::BLOCKED,
					TargetPackageReason::LOCAL_SECRET_STORE_UNAVAILABLE
				)
				: $this->verifier->verify(
					$item,
					$credential,
					$action,
					$decision['target_id'] ?? ( null === $credential ? ( $target_credential_ids[ $index ] ?? null ) : null )
				);
		}

		return $verified;
	}

	public function review_candidate( PortabilityCandidate $candidate ): PortabilityReviewResult {
		return $this->candidate_context( $candidate )['review'];
	}

	public function apply_candidate(
		PortabilityCandidate $candidate,
		string $expected_fingerprint
	): PortabilityApplyResult {
		$context = $this->candidate_context( $candidate );
		$review  = $context['review'];
		if ( ! hash_equals( $review->fingerprint, $expected_fingerprint ) ) {
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
		$result    = $this->apply_item(
			$blueprint,
			$item,
			null,
			null === $candidate->credential_id ? null : 'target',
			$candidate->credential_id,
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
	 * @param array<int, array{action:BlueprintCredentialAction,target_id:?string}> $credential_decisions
	 * @return array{status:string,message:string,credential_state:string}
	 */
	public function apply(
		PackageBlueprint $blueprint,
		int $row,
		?string $expected_action,
		array $credential_decisions,
		?string $target_credential_id,
		bool $adopt,
		bool $can_install
	): array {
		$item = $this->reviewer->review( $blueprint )[ $row ] ?? null;
		if ( ! $item instanceof BlueprintPlanItem ) {
			throw new InvalidArgumentException();
		}
		$ordinal              = null;
		$credential           = $this->credential_for( $blueprint, $item, $ordinal );
		$decision             = null === $ordinal ? null : ( $credential_decisions[ $ordinal ] ?? null );
		$credential_action    = $decision['action'] ?? null;
		$target_credential_id = $decision['target_id'] ?? ( null === $credential ? $target_credential_id : null );
		if ( $expected_action !== $item->action->value ) {
			return $this->result( 'skipped', __( 'This package changed since review. Review the Transporter Blueprint again.', 'ran-booster' ) );
		}
		if ( null !== $credential && ( null === $credential_action || BlueprintCredentialAction::LEAVE === $credential_action ) ) {
			return $this->result( 'skipped', __( 'This package was left unchanged by the repository credential decision.', 'ran-booster' ) );
		}
		$credential_only = TargetPackageAction::MANAGED === $item->action
			&& null !== $credential
			&& BlueprintCredentialAction::IMPORT === $credential_action;
		if ( ! $credential_only && ! $can_install ) {
			return $this->result( 'failed', __( 'You do not have permission to apply this package type.', 'ran-booster' ) );
		}
		if ( TargetPackageAction::ADOPT === $item->action && ! $adopt ) {
			return $this->result( 'skipped', __( 'This installed package was not selected for adoption.', 'ran-booster' ) );
		}
		if ( BlueprintCredentialAction::IMPORT === $credential_action ) {
			$this->assert_local_secret_store_ready();
		}
		$repository_private = null;
		$item               = $this->verifier->verify( $item, $credential, $credential_action, $target_credential_id, $repository_private );
		if ( $expected_action !== $item->action->value ) {
			return $this->result(
				'skipped',
				__( 'This package changed since review. Review the Transporter Blueprint again.', 'ran-booster' ),
				null !== $credential && in_array( $credential_action, array( BlueprintCredentialAction::IMPORT, BlueprintCredentialAction::TARGET ), true ) ? 'unavailable' : 'none'
			);
		}

		return $this->apply_item( $blueprint, $item, $credential, $credential_action?->value, $target_credential_id, $repository_private, $adopt, $can_install );
	}

	/** @return array{status:string,message:string,credential_state:string} */
	private function apply_item(
		PackageBlueprint $blueprint,
		BlueprintPlanItem $item,
		?BlueprintCredential $credential,
		?string $source,
		?string $target_credential_id,
		?bool $repository_private,
		bool $adopt,
		bool $can_install
	): array {
		if ( TargetPackageAction::MANAGED === $item->action ) {
			if ( 'import' !== $source || null === $credential || ! is_bool( $repository_private ) ) {
				return $this->result( 'unchanged', __( 'This package is already managed.', 'ran-booster' ) );
			}

			try {
				$this->credential_id( $blueprint, $credential, $source, $target_credential_id );

				return $this->result(
					'credential_available',
					__( 'The repository credential is available on this site. The managed package settings were not changed.', 'ran-booster' ),
					'transferred_available'
				);
			} catch ( Throwable ) {
				return $this->result(
					'failed',
					__( 'Booster could not import this repository credential. Review the Transporter Blueprint again and check repository access.', 'ran-booster' ),
					'unavailable'
				);
			}
		}
		if ( ! $this->is_actionable( $item ) ) {
			return $this->result( 'skipped', __( 'This package changed or cannot be applied. Review the Transporter Blueprint again.', 'ran-booster' ) );
		}
		if ( ! $can_install ) {
			return $this->result( 'failed', __( 'You do not have permission to apply this package type.', 'ran-booster' ) );
		}
		if ( TargetPackageAction::ADOPT === $item->action && ! $adopt ) {
			return $this->result( 'skipped', __( 'This installed package was not selected for adoption.', 'ran-booster' ) );
		}

		$credential_state = in_array( $source, array( 'import', 'target' ), true ) ? 'unavailable' : 'none';
		try {
			$credential_id    = $this->credential_id( $blueprint, $credential, $source, $target_credential_id );
			$credential_state = 'import' === $source ? 'transferred_available' : ( 'target' === $source ? 'target_selected' : 'none' );
			if ( null === $repository_private ) {
				throw new InvalidArgumentException();
			}
			$operation = PackageOperation::from_input(
				'install-' . $item->package->type,
				$this->operation_input( $item, $credential_id, $repository_private )
			);
			$result    = $this->operations->execute( $operation );
			if ( TargetPackageAction::ADOPT === $item->action ) {
				$this->assert_disabled_result( $result, $item->package, $credential_id, $repository_private );

				return $this->result( 'adopted', __( 'Adopted: deployment disabled', 'ran-booster' ), $credential_state );
			}
			if ( 'succeeded' === ( $result['status'] ?? null ) ) {
				$this->assert_disabled_result( $result, $item->package, $credential_id, $repository_private );
			}

			return $this->deployment_result( $result ) + array( 'credential_state' => $credential_state );
		} catch ( Throwable $failure ) {
			if ( null === $credential ) {
				throw $failure;
			}
			return $this->result(
				'failed',
				__( 'Booster could not apply this package. Review the Transporter Blueprint again and check repository access.', 'ran-booster' ),
				$credential_state
			);
		}
	}

	/**
	 * @param array<string, mixed> $result
	 */
	private function assert_disabled_result(
		array $result,
		BlueprintPackage $blueprint_package,
		?string $credential_id,
		bool $repository_private
	): void {
		$package = $result['package'] ?? null;
		if ( ! $package instanceof Package
			|| ! $this->target_verified( $package, $blueprint_package, $credential_id, $repository_private ) ) {
			throw new \RuntimeException( 'The Blueprint package was not persisted with the expected disabled management configuration.' );
		}
	}

	private function target_verified(
		Package $package,
		BlueprintPackage $blueprint_package,
		?string $credential_id,
		bool $repository_private
	): bool {
		return ! ( ( 'plugin' === $blueprint_package->type && ! $package instanceof Plugin )
			|| ( 'theme' === $blueprint_package->type && ! $package instanceof Theme )
			|| PackageSource::BRANCH !== $package->get_source()
			|| ! $blueprint_package->same_management_as( BlueprintPackage::from_managed_package( $blueprint_package->type, $package ) )
			|| ( $credential_id ?? '' ) !== $package->get_credential_id()
			|| $repository_private !== (bool) $package->is_private()
			|| DeploymentPolicy::DISABLED !== $package->get_deployment_policy() );
	}

	/**
	 * @return array{review:PortabilityReviewResult,package:BlueprintPackage|null,private:bool|null}
	 */
	private function candidate_context( PortabilityCandidate $candidate ): array {
		$resolved = $this->verifier->resolve_candidate( $candidate );
		$package  = $resolved['package'];
		$private  = $resolved['private'];
		if ( ! $package instanceof BlueprintPackage || ! is_bool( $private ) ) {
			$reason = $resolved['reason'];

			return array(
				'review'  => PortabilityReviewResult::from_resolved(
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
		$item    = $this->reviewer->review_package( $package, $managed );
		if ( TargetPackageAction::INSTALL === $item->action ) {
			$item = new BlueprintPlanItem( $package, TargetPackageAction::BLOCKED, TargetPackageReason::DESTINATION_CONFLICT );
		} elseif ( TargetPackageAction::MANAGED === $item->action
			&& ( ! $managed instanceof Package
				|| ! $this->target_verified( $managed, $package, $candidate->credential_id, $private ) ) ) {
			$item = new BlueprintPlanItem( $package, TargetPackageAction::PROTECTED, TargetPackageReason::MANAGEMENT_CONFLICT );
		}

		return array(
			'review'  => PortabilityReviewResult::from_resolved(
				$candidate,
				$item->action->value,
				$item->reason->value,
				$item->reason->message(),
				$package->provider_repository_id,
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
	private function deployment_result( array $result ): array {
		if ( 'succeeded' === ( $result['status'] ?? null ) ) {
			return array(
				'status'  => 'installed',
				'message' => __( 'Package installed and managed by Booster with deployment disabled. Re-enable deployment deliberately when this site is ready.', 'ran-booster' ),
			);
		}

		$outcome_code = is_string( $result['outcome_code'] ?? null ) ? $result['outcome_code'] : '';
		$reference    = is_string( $result['correlation_id'] ?? null ) && 1 === preg_match( '/^[a-f0-9]{32}$/D', $result['correlation_id'] )
			? $result['correlation_id']
			: null;
		$message      = \RAN\Admin\DeploymentOutcomeMessage::for_code( $outcome_code );
		if ( null !== $reference ) {
			$message = sprintf(
				/* translators: 1: safe deployment failure reason, 2: random support reference. */
				__( '%1$s Reference: %2$s.', 'ran-booster' ),
				$message,
				$reference
			);
		}
		return array(
			'status'  => 'failed',
			'message' => $message,
		);
	}

	private function credential_id(
		PackageBlueprint $blueprint,
		?BlueprintCredential $credential,
		?string $source,
		?string $target_credential_id
	): ?string {
		if ( 'target' === $source ) {
			return $target_credential_id;
		}
		if ( 'import' !== $source || null === $credential ) {
			return null;
		}

		$this->assert_local_secret_store_ready();

		return $this->secrets->import_credentials_if_absent( $blueprint, $credential )[0] ?? throw new InvalidArgumentException();
	}

	/** @return array<string, string> */
	private function operation_input( BlueprintPlanItem $item, ?string $credential_id, bool $repository_private ): array {
		$package = $item->package;
		$input   = array(
			'provider'                            => $package->provider,
			'repository'                          => $package->repository,
			'branch'                              => $package->branch,
			'provider_repository_id'              => $package->provider_repository_id,
			'provider_repository_identity_source' => 'resolved',
			'credential_id'                       => $credential_id ?? '',
			'private'                             => $repository_private ? '1' : '0',
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

	private function credential_for( PackageBlueprint $blueprint, BlueprintPlanItem $item, ?int &$ordinal = null ): ?BlueprintCredential {
		$ordinal = null;
		foreach ( $blueprint->credentials as $index => $credential ) {
			if ( $credential->provider === $item->package->provider
				&& in_array(
					array(
						'type'       => $item->package->type,
						'identifier' => $item->package->identifier,
					),
					$credential->packages,
					true
				) ) {
				$ordinal = $index;

				return $credential;
			}
		}

		return null;
	}

	private function credential_storage_ready(): bool {
		try {
			$this->secrets->assert_managed_storage_ready();

			return true;
		} catch ( Throwable ) {
			return false;
		}
	}

	private function assert_local_secret_store_ready(): void {
		try {
			$this->secrets->assert_managed_storage_ready();
		} catch ( Throwable $failure ) {
			// The typed exception is caught by the controller and never rendered directly.
			throw LocalSecretStoreUnavailable::for_portability( $failure );
		}
	}

	private function is_actionable( BlueprintPlanItem $item ): bool {
		return in_array( $item->action, array( TargetPackageAction::INSTALL, TargetPackageAction::ADOPT ), true );
	}

	private function can_import_credential( BlueprintPlanItem $item ): bool {
		return TargetPackageAction::MANAGED === $item->action || $this->is_actionable( $item );
	}

	/** @return array{status:string,message:string,credential_state:string} */
	private function result( string $status, string $message, string $credential_state = 'none' ): array {
		return array(
			'status'           => $status,
			'message'          => $message,
			'credential_state' => $credential_state,
		);
	}
}
