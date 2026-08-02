<?php

declare(strict_types=1);

namespace RAN\Portability;

use InvalidArgumentException;

final readonly class BlueprintPlanItem {
	public function __construct(
		public BlueprintPackage $package,
		public TargetPackageAction $action,
		public TargetPackageReason $reason
	) {
		$validReasons = match ( $action ) {
			TargetPackageAction::INSTALL,
			TargetPackageAction::ADOPT => array( TargetPackageReason::NONE ),
			TargetPackageAction::MANAGED => array( TargetPackageReason::ALREADY_MANAGED ),
			TargetPackageAction::PROTECTED => array(
				TargetPackageReason::MANAGEMENT_CONFLICT,
				TargetPackageReason::STALE_MANAGEMENT,
				TargetPackageReason::MALFORMED_MANAGEMENT,
			),
			TargetPackageAction::BLOCKED => array(
				TargetPackageReason::CREDENTIAL_REQUIRED,
				TargetPackageReason::LOCAL_SECRET_STORE_UNAVAILABLE,
				TargetPackageReason::REPOSITORY_ACCESS_FAILED,
				TargetPackageReason::REPOSITORY_IDENTITY_MISMATCH,
				TargetPackageReason::DESTINATION_CONFLICT,
				TargetPackageReason::PROVIDER_UNAVAILABLE,
				TargetPackageReason::PROVIDER_TEMPORARILY_UNAVAILABLE,
			),
		};

		if ( ! in_array( $reason, $validReasons, true ) ) {
			throw new InvalidArgumentException( 'The portability plan action and reason are incompatible.' );
		}
	}
}
