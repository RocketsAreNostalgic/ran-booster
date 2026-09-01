<?php

declare(strict_types=1);

namespace RAN\AddOn\ReleaseTracking;

use InvalidArgumentException;

/**
 * Local, non-network eligibility for a managed package's release source.
 */
final readonly class ReleaseTrackingEligibility {

	public const ELIGIBLE                        = 'eligible';
	public const MISSING_UPDATE_URI              = 'missing_update_uri';
	public const MISMATCHED_UPDATE_URI           = 'mismatched_update_uri';
	public const UNSUPPORTED_PROVIDER            = 'unsupported_provider';
	public const INVALID_REPOSITORY              = 'invalid_repository';
	public const INVALID_PACKAGE_IDENTITY        = 'invalid_package_identity';
	public const SUBDIRECTORY_NOT_SUPPORTED      = 'subdirectory_not_supported';
	public const TARGET_ALREADY_USES_RAN_UPDATER = 'target_already_uses_ran_updater';

	public function __construct(
		private string $code,
		private string $expectedUpdateUri = '',
		private string $packageRoot = ''
	) {
		if ( ! in_array(
			$this->code,
			array(
				self::ELIGIBLE,
				self::MISSING_UPDATE_URI,
				self::MISMATCHED_UPDATE_URI,
				self::UNSUPPORTED_PROVIDER,
				self::INVALID_REPOSITORY,
				self::INVALID_PACKAGE_IDENTITY,
				self::SUBDIRECTORY_NOT_SUPPORTED,
				self::TARGET_ALREADY_USES_RAN_UPDATER,
			),
			true
		) || strlen( $this->expectedUpdateUri ) > 255
			|| strlen( $this->packageRoot ) > 100 ) {
			throw new InvalidArgumentException( 'Release tracking eligibility is invalid.' );
		}
	}

	public function code(): string {
		return $this->code;
	}

	public function eligible(): bool {
		return self::ELIGIBLE === $this->code;
	}

	public function expectedUpdateUri(): string {
		return $this->expectedUpdateUri;
	}

	public function packageRoot(): string {
		return $this->packageRoot;
	}
}
