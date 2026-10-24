<?php

declare(strict_types=1);

namespace RAN\Portability;

use InvalidArgumentException;

final readonly class BlueprintPlanItem {
	/** @param array<string,mixed>|null $provider_expectation
	 *  @param array{release_id:string,tag:string,version:string,channel:string,fingerprint:string,package_root:string,main_file:string}|null $release_review */
	public function __construct(
		public BlueprintPackage $package,
		public TargetPackageAction $action,
		public TargetPackageReason $reason,
		public ?array $provider_expectation = null,
		public ?array $release_review = null
	) {
		$valid_reasons = match ( $action ) {
			TargetPackageAction::INSTALL,
			TargetPackageAction::ADOPT => array( TargetPackageReason::NONE ),
			TargetPackageAction::MANAGED => array( TargetPackageReason::ALREADY_MANAGED ),
			TargetPackageAction::PROTECTED => array(
				TargetPackageReason::MANAGEMENT_CONFLICT,
				TargetPackageReason::STALE_MANAGEMENT,
				TargetPackageReason::MALFORMED_MANAGEMENT,
			),
			TargetPackageAction::BLOCKED => array(
				TargetPackageReason::PREVIEW_RATE_LIMITED,
				TargetPackageReason::PREVIEW_BUDGET_EXHAUSTED,
				TargetPackageReason::UNSUPPORTED_BLUEPRINT_OPERATION,
				TargetPackageReason::CREDENTIAL_REQUIRED,
				TargetPackageReason::LOCAL_SECRET_STORE_UNAVAILABLE,
				TargetPackageReason::REPOSITORY_ACCESS_FAILED,
				TargetPackageReason::REPOSITORY_IDENTITY_MISMATCH,
				TargetPackageReason::DESTINATION_CONFLICT,
				TargetPackageReason::PROVIDER_UNAVAILABLE,
				TargetPackageReason::PROVIDER_SELECTION_CHANGED,
				TargetPackageReason::PROVIDER_TEMPORARILY_UNAVAILABLE,
				TargetPackageReason::RELEASE_UNAVAILABLE,
				TargetPackageReason::RELEASE_REVIEW_CHANGED,
			),
		};

		if ( ! in_array( $reason, $valid_reasons, true ) ) {
			throw new InvalidArgumentException( 'The portability plan action and reason are incompatible.' );
		}
		if ( null !== $release_review && ( TargetPackageAction::INSTALL !== $action || null === $package->release_configuration
			|| array_keys( $release_review ) !== array( 'release_id', 'tag', 'version', 'channel', 'fingerprint', 'package_root', 'main_file' )
			|| ! $this->valid_release_review( $release_review ) ) ) {
			throw new InvalidArgumentException( 'The portability release review is invalid.' );
		}
	}

	/** @param array<string,mixed> $release_review */
	private function valid_release_review( array $release_review ): bool {
		foreach ( $release_review as $value ) {
			if ( ! is_string( $value ) || '' === $value || strlen( $value ) > 191 || 1 === preg_match( '/[\x00-\x1F\x7F]/', $value ) ) {
				return false;
			}
		}
		return in_array( $release_review['channel'], array( 'stable', 'prerelease' ), true )
			&& 1 === preg_match( '/\Av2:[a-f0-9]{64}\z/D', $release_review['fingerprint'] );
	}
}
