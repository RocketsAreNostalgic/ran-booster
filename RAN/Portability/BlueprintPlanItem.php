<?php

declare(strict_types=1);

namespace RAN\Portability;

use InvalidArgumentException;

final readonly class BlueprintPlanItem {
	/** @param array<string,mixed>|null $provider_expectation */
	public function __construct(
		public BlueprintPackage $package,
		public TargetPackageAction $action,
		public TargetPackageReason $reason,
		public ?array $provider_expectation = null
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
			),
		};

		if ( ! in_array( $reason, $valid_reasons, true ) ) {
			throw new InvalidArgumentException( 'The portability plan action and reason are incompatible.' );
		}
	}
}
