<?php

declare(strict_types=1);

namespace RAN\Portability;

/** A closed, non-secret explanation for a target package action. */
enum TargetPackageReason: string {
	case NONE                             = 'none';
	case ALREADY_MANAGED                  = 'already_managed';
	case MANAGEMENT_CONFLICT              = 'management_conflict';
	case STALE_MANAGEMENT                 = 'stale_management';
	case MALFORMED_MANAGEMENT             = 'malformed_management';
	case CREDENTIAL_REQUIRED              = 'credential_required';
	case LOCAL_SECRET_STORE_UNAVAILABLE   = 'local_secret_store_unavailable';
	case REPOSITORY_ACCESS_FAILED         = 'repository_access_failed';
	case REPOSITORY_IDENTITY_MISMATCH     = 'repository_identity_mismatch';
	case DESTINATION_CONFLICT             = 'destination_conflict';
	case PROVIDER_UNAVAILABLE             = 'provider_unavailable';
	case PROVIDER_TEMPORARILY_UNAVAILABLE = 'provider_temporarily_unavailable';

	public function message(): string {
		return match ( $this ) {
			self::NONE => __( 'Ready to migrate; all checks passed.', 'ran-booster' ),
			self::ALREADY_MANAGED => __( 'Managed by Booster', 'ran-booster' ),
			self::MANAGEMENT_CONFLICT => __( 'Existing Booster management differs, so this package is protected.', 'ran-booster' ),
			self::STALE_MANAGEMENT => __( 'A stale Booster management record exists, so this package is protected.', 'ran-booster' ),
			self::MALFORMED_MANAGEMENT => __( 'Booster could not safely read existing management, so this package is protected.', 'ran-booster' ),
			self::CREDENTIAL_REQUIRED => __( 'Repository access needs a target credential.', 'ran-booster' ),
			self::LOCAL_SECRET_STORE_UNAVAILABLE => __( 'Target encrypted credential storage is unavailable. Configure secure storage before applying this credential-bearing row.', 'ran-booster' ),
			self::REPOSITORY_ACCESS_FAILED => __( 'Booster could not confirm repository access.', 'ran-booster' ),
			self::REPOSITORY_IDENTITY_MISMATCH => __( 'The repository does not match this Transporter Blueprint.', 'ran-booster' ),
			self::DESTINATION_CONFLICT => __( 'The destination package conflicts with this Transporter Blueprint.', 'ran-booster' ),
			self::PROVIDER_UNAVAILABLE => __( 'This repository provider is not available on the target.', 'ran-booster' ),
			self::PROVIDER_TEMPORARILY_UNAVAILABLE => __( 'The repository provider is temporarily unavailable.', 'ran-booster' ),
		};
	}
}
