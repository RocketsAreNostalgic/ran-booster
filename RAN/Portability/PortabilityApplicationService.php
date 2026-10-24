<?php

declare(strict_types=1);

namespace RAN\Portability;

use InvalidArgumentException;
use RAN\AddOn\Portability\PortabilityApplyResult;
use RAN\AddOn\Portability\PortabilityCandidate;
use RAN\AddOn\Portability\PortabilityReviewResult;
use RAN\AddOn\ReleaseTracking\NativeReleaseTrackingFacade;
use RAN\AddOn\ReleaseTracking\ProspectiveReleaseFacade;
use RAN\Deployment\DeploymentPolicy;
use RAN\Package;
use RAN\PackageOperation;
use RAN\PackageOperationService;
use RAN\PackageSource;
use RAN\Plugin;
use RAN\Secrets\SecretsFile;
use RAN\Theme;
use RAN\WordPress\ManagedReleaseStore;
use Throwable;

/**
 * Canonical review and Apply behavior shared by Transporter adapters.
 *
 * @phpstan-import-type RequirementsReport from BlueprintRequirements
 */
final readonly class PortabilityApplicationService {

	public function __construct(
		private BlueprintReviewer $reviewer,
		private BlueprintRepositoryVerifier $verifier,
		private PackageOperationService $operations,
		private SecretsFile $secrets,
		private ?NativeReleaseTrackingFacade $release_tracking = null,
		private ManagedReleaseStore $release_store = new ManagedReleaseStore(),
		private ?ProspectiveReleaseFacade $prospective_releases = null
	) {
	}

	/** @return array{state:string,current:?array<string,mixed>,requested:array<string,mixed>,differences:list<string>,requested_selection:?array<string,mixed>,installed_equality:string,source_access:string,package_inspection:string} */
	public function compare_local( BlueprintPackage $package ): array {
		return $this->reviewer->compare_local( $package );
	}

	/** @return array{requirements:RequirementsReport,repository_conflicts:array<int,true>} */
	public function local_report( PackageBlueprint $blueprint ): array {
		return array(
			'requirements'         => ( new BlueprintRequirements() )->assess( $blueprint ),
			'repository_conflicts' => $this->reviewer->repository_conflicts( $blueprint ),
		);
	}

	/**
	 * @param array<int, array{action:BlueprintCredentialAction,target_id:?string}> $credential_decisions
	 * @param array<int, string> $target_credential_ids
	 * @return list<BlueprintPlanItem>
	 */
	public function review( PackageBlueprint $blueprint, array $credential_decisions = array(), array $target_credential_ids = array() ): array {
		$items    = $this->reviewer->review( $blueprint );
		$verified = array();
		$verifier = $this->verifier->for_preview();

		foreach ( $items as $index => $item ) {
			if ( ! $this->selected_operation_supported( $item ) ) {
				$verified[] = new BlueprintPlanItem( $item->package, TargetPackageAction::BLOCKED, TargetPackageReason::UNSUPPORTED_BLUEPRINT_OPERATION );
				continue;
			}
			$expectation = $verifier->provider_expectation( $item->package->provider );
			if ( 'gh' === $item->package->provider && null === $expectation ) {
				$verified[] = new BlueprintPlanItem( $item->package, TargetPackageAction::BLOCKED, TargetPackageReason::PROVIDER_UNAVAILABLE );
				continue;
			}
			$item       = new BlueprintPlanItem( $item->package, $item->action, $item->reason, $expectation );
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
				: $verifier->verify(
					$item,
					$credential,
					$action,
					$decision['target_id'] ?? ( null === $credential ? ( $target_credential_ids[ $index ] ?? null ) : null ),
					$repository_private,
					fn ( BlueprintPlanItem $verified, ?string $credential_id, bool $repository_private ): BlueprintPlanItem => $this->review_release_install( $verified, $credential_id, $repository_private )
				);
		}

		return $verified;
	}

	/** Runs only for a repository-verified Release INSTALL preview. */
	private function review_release_install( BlueprintPlanItem $item, ?string $credential_id, bool $repository_private ): BlueprintPlanItem {
		$configuration = $item->package->release_configuration;
		if ( TargetPackageAction::INSTALL !== $item->action || null === $configuration ) {
			return $item;
		}
		if ( null === $this->prospective_releases ) {
			return new BlueprintPlanItem( $item->package, TargetPackageAction::BLOCKED, TargetPackageReason::PROVIDER_TEMPORARILY_UNAVAILABLE );
		}
		$refusal = $this->verifier->provider_review_refusal( $item->package->provider, $item->provider_expectation );
		if ( null !== $refusal ) {
			return new BlueprintPlanItem( $item->package, TargetPackageAction::BLOCKED, TargetPackageReason::from( $refusal ) );
		}
		$request = $this->release_repository_request( $item, $credential_id, $repository_private, DeploymentPolicy::MANUAL );
		$channel = $configuration->channel();
		if ( ! in_array( $channel, array( 'stable', 'prerelease' ), true ) ) {
			return new BlueprintPlanItem( $item->package, TargetPackageAction::BLOCKED, TargetPackageReason::RELEASE_UNAVAILABLE );
		}
		try {
			$list = $this->prospective_releases->list_candidates(
				$item->package->type,
				$request,
				$channel,
				wp_create_nonce( $this->prospective_releases->nonce_action( 'list_candidates', $item->package->type, null ) )
			);
		} catch ( Throwable ) {
			return new BlueprintPlanItem( $item->package, TargetPackageAction::BLOCKED, TargetPackageReason::PROVIDER_TEMPORARILY_UNAVAILABLE );
		}
		if ( ! $list->successful() ) {
			return new BlueprintPlanItem( $item->package, TargetPackageAction::BLOCKED, 'unsupported_provider' === $list->code() ? TargetPackageReason::UNSUPPORTED_BLUEPRINT_OPERATION : ( 'no_releases' === $list->code() ? TargetPackageReason::RELEASE_UNAVAILABLE : TargetPackageReason::PROVIDER_TEMPORARILY_UNAVAILABLE ) );
		}
		$candidates = $list->data()['candidates'] ?? null;
		if ( ! is_array( $candidates ) || count( $candidates ) > 8 || ! isset( $candidates[0] ) || ! is_array( $candidates[0] )
			|| ! is_string( $candidates[0]['release_id'] ?? null ) || ! is_string( $candidates[0]['tag'] ?? null ) || ! is_string( $candidates[0]['version'] ?? null ) ) {
			return new BlueprintPlanItem( $item->package, TargetPackageAction::BLOCKED, TargetPackageReason::RELEASE_UNAVAILABLE );
		}
		$candidate = $candidates[0];
		$refusal   = $this->verifier->provider_review_refusal( $item->package->provider, $item->provider_expectation );
		if ( null !== $refusal ) {
			return new BlueprintPlanItem( $item->package, TargetPackageAction::BLOCKED, TargetPackageReason::from( $refusal ) );
		}
		try {
			$inspection = $this->prospective_releases->inspect(
				$item->package->type,
				$request,
				$candidate['release_id'],
				$candidate['tag'],
				$channel,
				wp_create_nonce( $this->prospective_releases->nonce_action( 'inspect', $item->package->type, null ) )
			);
		} catch ( Throwable ) {
			return new BlueprintPlanItem( $item->package, TargetPackageAction::BLOCKED, TargetPackageReason::PROVIDER_TEMPORARILY_UNAVAILABLE );
		}
		$refusal = $this->verifier->provider_review_refusal( $item->package->provider, $item->provider_expectation );
		if ( null !== $refusal ) {
			return new BlueprintPlanItem( $item->package, TargetPackageAction::BLOCKED, TargetPackageReason::from( $refusal ) );
		}
		if ( ! $inspection->successful() ) {
			return new BlueprintPlanItem( $item->package, TargetPackageAction::BLOCKED, 'unsupported_provider' === $inspection->code() ? TargetPackageReason::UNSUPPORTED_BLUEPRINT_OPERATION : ( in_array( $inspection->code(), array( 'no_releases', 'release_unavailable', 'release_invalid' ), true ) ? TargetPackageReason::RELEASE_UNAVAILABLE : TargetPackageReason::PROVIDER_TEMPORARILY_UNAVAILABLE ) );
		}
		$data = $inspection->data();
		if ( ! is_string( $data['release_id'] ?? null ) || ! hash_equals( $candidate['release_id'], $data['release_id'] )
			|| ! is_string( $data['tag'] ?? null ) || ! hash_equals( $candidate['tag'], $data['tag'] )
			|| ! is_string( $data['version'] ?? null ) || ! hash_equals( $candidate['version'], $data['version'] )
			|| ! is_string( $data['channel'] ?? null ) || ! hash_equals( $channel, $data['channel'] )
			|| ! is_string( $data['package_root'] ?? null ) || ! hash_equals( $configuration->package_root(), $data['package_root'] )
			|| ! is_string( $data['main_file'] ?? null ) || ! hash_equals( $configuration->metadata_file(), $data['main_file'] )
			|| ! is_string( $data['fingerprint'] ?? null ) ) {
			return new BlueprintPlanItem( $item->package, TargetPackageAction::BLOCKED, TargetPackageReason::RELEASE_UNAVAILABLE );
		}
		return new BlueprintPlanItem(
			$item->package,
			$item->action,
			$item->reason,
			$item->provider_expectation,
			array(
				'release_id'   => $candidate['release_id'],
				'tag'          => $candidate['tag'],
				'version'      => $candidate['version'],
				'channel'      => $channel,
				'fingerprint'  => $data['fingerprint'],
				'package_root' => $configuration->package_root(),
				'main_file'    => $configuration->metadata_file(),
			)
		);
	}

	public function review_candidate( PortabilityCandidate $candidate ): PortabilityReviewResult {
		return $this->candidate_context( $candidate )['review'];
	}

	/** @param array<string,mixed>|null $provider_expectation */
	public function apply_candidate(
		PortabilityCandidate $candidate,
		string $expected_fingerprint,
		?array $provider_expectation
	): PortabilityApplyResult {
		$refusal = $this->verifier->provider_review_refusal( $candidate->provider_code, $provider_expectation );
		if ( null !== $refusal ) {
			$reason = TargetPackageReason::from( $refusal );
			return new PortabilityApplyResult( PortabilityApplyResult::BLOCKED, $refusal, $reason->message(), false );
		}
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
		$item      = new BlueprintPlanItem( $package, TargetPackageAction::ADOPT, TargetPackageReason::NONE, $provider_expectation );
		$refusal   = $this->verifier->provider_review_refusal( $candidate->provider_code, $provider_expectation );
		if ( null !== $refusal ) {
			$reason = TargetPackageReason::from( $refusal );
			return new PortabilityApplyResult( PortabilityApplyResult::BLOCKED, $refusal, $reason->message(), false );
		}
		$result = $this->apply_item(
			$blueprint,
			$item,
			null,
			null === $candidate->credential_id ? null : 'target',
			$candidate->credential_id,
			$context['private']
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
	 * @param array<array-key,mixed>|null $selected_rows Untrusted request selection, validated before local reads.
	 * @return array{status:string,message:string,credential_state:string}
	 * @param array<string,mixed>|null $provider_expectation
	 * @param array{release_id:string,tag:string,version:string,channel:string,fingerprint:string,package_root:string,main_file:string}|null $release_review
	 */
	public function apply(
		PackageBlueprint $blueprint,
		int $row,
		?string $expected_action,
		array $credential_decisions,
		?string $target_credential_id,
		bool $adopt,
		bool $can_install,
		?array $selected_rows,
		?array $provider_expectation,
		?array $release_review = null
	): array {
		$package = $blueprint->packages[ $row ] ?? null;
		if ( ! $package instanceof BlueprintPackage ) {
			throw new InvalidArgumentException();
		}
		$refusal = $this->verifier->provider_review_refusal( $package->provider, $provider_expectation );
		if ( null !== $refusal ) {
			return $this->result( 'skipped', TargetPackageReason::from( $refusal )->message() );
		}
		$selected_rows ??= array( $row );
		if ( ! array_is_list( $selected_rows ) || array() === $selected_rows
			|| count( $selected_rows ) > PackageBlueprint::MAX_PACKAGES || ! in_array( $row, $selected_rows, true ) ) {
			throw new InvalidArgumentException( 'The selected package rows are invalid.' );
		}
		$selected = array();
		foreach ( $selected_rows as $selected_row ) {
			if ( ! is_int( $selected_row ) || isset( $selected[ $selected_row ] ) || ! isset( $blueprint->packages[ $selected_row ] ) ) {
				throw new InvalidArgumentException( 'The selected package rows are invalid.' );
			}
			$selected[ $selected_row ] = $blueprint->packages[ $selected_row ];
		}
		if ( array() !== $this->reviewer->repository_conflicts( new PackageBlueprint( array_values( $selected ) ) ) ) {
			return $this->result( 'skipped', __( 'The selected packages conflict: a repository can supply multiple branch packages or one release package, but not both. Select a compatible subset and review again.', 'ran-booster' ) );
		}
		$item = $this->reviewer->review_package( $package );
		if ( TargetPackageReason::UNSUPPORTED_BLUEPRINT_OPERATION === $item->reason || ! $this->selected_operation_supported( $item ) ) {
			return $this->result( 'skipped', TargetPackageReason::UNSUPPORTED_BLUEPRINT_OPERATION->message() );
		}
		$item            = new BlueprintPlanItem( $package, $item->action, $item->reason, $provider_expectation );
		$release_install = TargetPackageAction::INSTALL === $item->action && null !== $package->release_configuration;
		if ( $release_install ) {
			if ( null === $release_review ) {
				return $this->result( 'skipped', TargetPackageReason::RELEASE_REVIEW_CHANGED->message() );
			}
			try {
				new BlueprintPlanItem( $package, TargetPackageAction::INSTALL, TargetPackageReason::NONE, null, $release_review );
			} catch ( InvalidArgumentException ) {
				return $this->result( 'skipped', TargetPackageReason::RELEASE_REVIEW_CHANGED->message() );
			}
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
		if ( BlueprintCredentialAction::IMPORT === $credential_action && ! $release_install ) {
			$this->assert_local_secret_store_ready();
		}
		$repository_private = null;
		$item               = $this->verifier->verify(
			$item,
			$credential,
			$credential_action,
			$target_credential_id,
			$repository_private,
			$release_install ? function ( BlueprintPlanItem $verified, ?string $credential_id, bool $repository_private ) use ( $release_review ): BlueprintPlanItem {
				$reviewed = $this->review_release_install( $verified, $credential_id, $repository_private );
				return $release_review === $reviewed->release_review
					? $reviewed
					: new BlueprintPlanItem( $verified->package, TargetPackageAction::BLOCKED, TargetPackageReason::RELEASE_REVIEW_CHANGED );
			} : null
		);
		if ( TargetPackageReason::RELEASE_REVIEW_CHANGED === $item->reason ) {
			return $this->result( 'skipped', TargetPackageReason::RELEASE_REVIEW_CHANGED->message() );
		}
		if ( $expected_action !== $item->action->value ) {
			return $this->result(
				'skipped',
				__( 'This package changed since review. Review the Transporter Blueprint again.', 'ran-booster' ),
				null !== $credential && in_array( $credential_action, array( BlueprintCredentialAction::IMPORT, BlueprintCredentialAction::TARGET ), true ) ? 'unavailable' : 'none'
			);
		}
		if ( $release_install && TargetPackageAction::INSTALL === $item->action ) {
			$managed = null;
			$fresh   = $this->reviewer->review_package( $package, $managed );
			if ( TargetPackageAction::INSTALL !== $fresh->action ) {
				return $this->result( 'skipped', __( 'This package appeared on the target during review. Review the Transporter Blueprint again before making any change.', 'ran-booster' ) );
			}
			if ( BlueprintCredentialAction::IMPORT === $credential_action ) {
				$this->assert_local_secret_store_ready();
			}
		}

		$source = $credential_action->value ?? ( null !== $target_credential_id && '' !== $target_credential_id ? 'target' : null );

		return $this->apply_item( $blueprint, $item, $credential, $source, $target_credential_id, $repository_private );
	}

	/** @return array{status:string,message:string,credential_state:string} */
	private function apply_item(
		PackageBlueprint $blueprint,
		BlueprintPlanItem $item,
		?BlueprintCredential $credential,
		?string $source,
		?string $target_credential_id,
		?bool $repository_private
	): array {
		$refusal = $this->verifier->provider_review_refusal( $item->package->provider, $item->provider_expectation );
		if ( null !== $refusal ) {
			throw new InvalidArgumentException( 'Fresh provider review is required.' );
		}
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
		$credential_state = in_array( $source, array( 'import', 'target' ), true ) ? 'unavailable' : 'none';
		try {
			$credential_id    = $this->credential_id( $blueprint, $credential, $source, $target_credential_id );
			$credential_state = 'import' === $source ? 'transferred_available' : ( 'target' === $source ? 'target_selected' : 'none' );
			if ( null === $repository_private ) {
				throw new InvalidArgumentException();
			}
			if ( null !== $this->verifier->provider_review_refusal( $item->package->provider, $item->provider_expectation ) ) {
				throw new InvalidArgumentException( 'Fresh provider review is required.' );
			}
			if ( TargetPackageAction::INSTALL === $item->action && null !== $item->package->release_configuration ) {
				return $this->install_release( $item, $credential_id, $repository_private, $credential_state );
			}
			$operation = PackageOperation::from_input(
				'install-' . $item->package->type,
				$this->operation_input( $item, $credential_id, $repository_private )
			);
			if ( null !== $this->verifier->provider_review_refusal( $item->package->provider, $item->provider_expectation ) ) {
				throw new InvalidArgumentException( 'Fresh provider review is required.' );
			}
			$result = null !== $item->package->release_configuration
				? $this->adopt_release( $operation, $item )
				: $this->operations->execute( $operation );
			if ( TargetPackageAction::ADOPT === $item->action ) {
				$this->assert_disabled_result( $result, $item->package, $credential_id, $repository_private );
				if ( 'uncertain' === ( $result['status'] ?? null ) ) {
					return $this->result( 'failed', __( 'Booster could not confirm the release adoption outcome. Review the Transporter Blueprint again before making another change.', 'ran-booster' ), $credential_state );
				}

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

	/** @return array{status:string,message:string,credential_state:string} */
	private function install_release( BlueprintPlanItem $item, ?string $credential_id, bool $repository_private, string $credential_state ): array {
		if ( null === $this->prospective_releases || null === $item->release_review ) {
			return $this->result( 'failed', __( 'Booster could not install the reviewed release. Review the Transporter Blueprint again.', 'ran-booster' ), $credential_state );
		}
		$review  = $item->release_review;
		$channel = $review['channel'];
		if ( ! in_array( $channel, array( 'stable', 'prerelease' ), true ) ) {
			return $this->result( 'failed', __( 'The reviewed release candidate is no longer valid. Review the Transporter Blueprint before making another change.', 'ran-booster' ), $credential_state );
		}
		try {
			$result = $this->prospective_releases->install(
				$item->package->type,
				$this->release_repository_request( $item, $credential_id, $repository_private, DeploymentPolicy::DISABLED ),
				$review['release_id'],
				$review['tag'],
				$review['fingerprint'],
				$channel,
				wp_create_nonce( $this->prospective_releases->nonce_action( 'install', $item->package->type, $item->provider_expectation, $item->package->identifier, DeploymentPolicy::DISABLED ) ),
				$item->provider_expectation,
				$item->package->identifier,
				DeploymentPolicy::DISABLED
			);
		} catch ( Throwable ) {
			return $this->result( 'failed', __( 'Booster could not install the reviewed release. Review the Transporter Blueprint again.', 'ran-booster' ), $credential_state );
		}
		if ( ! $result->successful() ) {
			$message = match ( $result->code() ) {
				'installed_but_unmanaged' => __( 'The release files may have been installed, but Booster management was not established. Review the Transporter Blueprint before making another change.', 'ran-booster' ),
				'management_state_uncertain' => __( 'Booster could not determine the release management state. Review the Transporter Blueprint before making another change.', 'ran-booster' ),
				'installation_cleanup_failed' => __( 'Booster could not confirm cleanup after installing the reviewed release. Review the Transporter Blueprint before making another change.', 'ran-booster' ),
				'package_already_exists' => __( 'The target package appeared during installation, so Booster refused to manage it. Review the Transporter Blueprint before making another change.', 'ran-booster' ),
				'release_repository_conflict' => __( 'The target has a conflicting managed package source, so Booster refused installation. Review the Transporter Blueprint before making another change.', 'ran-booster' ),
				'release_invalid', 'package_identity_mismatch' => __( 'The reviewed release candidate is no longer valid. Review the Transporter Blueprint before making another change.', 'ran-booster' ),
				default => __( 'Booster could not confirm installation of the reviewed release. Review the Transporter Blueprint again before making another change.', 'ran-booster' ),
			};
			return $this->result( 'failed', $message, $credential_state );
		}
		if ( null !== $this->verifier->provider_review_refusal( $item->package->provider, $item->provider_expectation ) ) {
			return $this->result( 'failed', __( 'Booster could not confirm installation of the reviewed release. Review the Transporter Blueprint again before making another change.', 'ran-booster' ), $credential_state );
		}
		$managed = null;
		$this->reviewer->review_package( $item->package, $managed );
		if ( ! $managed instanceof Package || ! $this->target_verified( $managed, $item->package, $credential_id, $repository_private ) ) {
			return $this->result( 'failed', __( 'Booster could not confirm installation of the reviewed release. Review the Transporter Blueprint again before making another change.', 'ran-booster' ), $credential_state );
		}
		return $this->result( 'installed', __( 'Package installed and managed by Booster with deployment disabled. Re-enable deployment deliberately when this site is ready.', 'ran-booster' ), $credential_state );
	}

	/** @return array<string,mixed> */
	private function release_repository_request( BlueprintPlanItem $item, ?string $credential_id, bool $repository_private, DeploymentPolicy $policy ): array {
		return array(
			'provider'               => $item->package->provider,
			'repository'             => $item->package->repository,
			'provider_repository_id' => $item->package->provider_repository_id,
			'credential_id'          => $credential_id,
			'private'                => $repository_private,
			'deployment_policy'      => $policy->value,
		);
	}

	/** @return array{status: string, package: Package} */
	private function adopt_release( PackageOperation $operation, BlueprintPlanItem $item ): array {
		$package = $item->package;
		if ( null === $this->release_tracking || null === $package->release_configuration ) {
			throw new InvalidArgumentException( 'Published release adoption is unavailable.' );
		}

		return $this->operations->adopt_release(
			$operation,
			$package->release_configuration,
			function ( Package $installed ) use ( $item, $package ): bool {
				if ( null !== $this->verifier->provider_review_refusal( $package->provider, $item->provider_expectation ) ) {
					return false;
				}
				$ready = $this->release_tracking->release_adoption_ready(
					$package->type,
					$installed,
					$package->release_configuration
				);

				if ( ! $ready ) {
					return false;
				}
				$credential_id = $installed->get_credential_id();
				$private       = null;
				$reverified    = $this->verifier->verify( $item, null, null, '' === $credential_id ? null : $credential_id, $private );
				$expectation   = $this->verifier->provider_expectation( $package->provider );

				return TargetPackageAction::ADOPT === $reverified->action
					&& is_bool( $private )
					&& $private === (bool) $installed->is_private()
					&& $expectation === $item->provider_expectation
					&& null === $this->verifier->provider_review_refusal( $package->provider, $expectation );
			}
		);
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
		$release = $blueprint_package->release_configuration;
		if ( null !== $release ) {
			try {
				$stored = $this->release_store->configuration( $blueprint_package->type, $blueprint_package->identifier );
			} catch ( Throwable ) {
				return false;
			}
			if ( null === $stored ) {
				return false;
			}
			return ! ( ( 'plugin' === $blueprint_package->type && ! $package instanceof Plugin )
				|| ( 'theme' === $blueprint_package->type && ! $package instanceof Theme )
				|| PackageSource::RELEASE_ASSET !== $package->get_source()
				|| ! $blueprint_package->same_management_as( BlueprintPackage::from_managed_package( $blueprint_package->type, $package, $stored ) )
				|| ( $credential_id ?? '' ) !== $package->get_credential_id()
				|| $repository_private !== (bool) $package->is_private()
				|| DeploymentPolicy::DISABLED !== $package->get_deployment_policy() );
		}

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
		$expectation = $this->verifier->provider_expectation( $candidate->provider_code );
		$refusal     = 'gh' === $candidate->provider_code && null === $expectation ? 'provider_unavailable'
			: $this->verifier->provider_review_refusal( $candidate->provider_code, $expectation );
		$resolved    = null === $refusal ? $this->verifier->resolve_candidate( $candidate )
			: array(
				'package' => null,
				'private' => null,
				'reason'  => TargetPackageReason::from( $refusal ),
			);
		if ( null !== $expectation ) {
			$refusal = $this->verifier->provider_review_refusal( $candidate->provider_code, $expectation );
			if ( null !== $refusal ) {
				$resolved = array(
					'package' => null,
					'private' => null,
					'reason'  => TargetPackageReason::from( $refusal ),
				);
			}
		}
		$package = $resolved['package'];
		$private = $resolved['private'];
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

		$refusal = $this->verifier->provider_review_refusal( $candidate->provider_code, $expectation );
		if ( null !== $refusal ) {
			$item = new BlueprintPlanItem( $package, TargetPackageAction::BLOCKED, TargetPackageReason::from( $refusal ) );
		}

		return array(
			'review'  => PortabilityReviewResult::from_resolved(
				$candidate,
				$item->action->value,
				$item->reason->value,
				$item->reason->message(),
				$package->provider_repository_id,
				$private,
				$expectation
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

	private function selected_operation_supported( BlueprintPlanItem $item ): bool {
		if ( null === $item->package->selection || TargetPackageAction::INSTALL !== $item->action ) {
			return true;
		}
		try {
			// Validate requested identity before any credential import or provider work.
			PackageOperation::from_input( 'install-' . $item->package->type, $this->operation_input( $item, null, false ) );
			return true;
		} catch ( InvalidArgumentException ) {
			return false;
		}
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
		if ( TargetPackageAction::INSTALL === $item->action && null !== $package->selection ) {
			$input['selected_commit']     = (string) $package->selection['commit'];
			$input['expected_identifier'] = $package->identifier;
		}
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
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The typed exception is caught by the controller and never rendered directly.
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
