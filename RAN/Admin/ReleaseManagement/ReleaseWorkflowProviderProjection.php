<?php

declare(strict_types=1);

namespace RAN\Admin\ReleaseManagement;

use RAN\AddOn\ReleaseTracking\ReleaseTrackingPreflight;
use RAN\AddOn\ReleaseTracking\ReleaseTrackingStatus;
use RAN\RepositoryProvider\RepositoryReleaseWorkflowPreflight;
use RAN\RepositoryProvider\RepositoryReleaseWorkflowTarget;

/** Maps Booster release-tracking state onto the provider API boundary. */
final class ReleaseWorkflowProviderProjection {
	public static function target( ReleaseTrackingStatus $status ): RepositoryReleaseWorkflowTarget {
		return new RepositoryReleaseWorkflowTarget(
			$status->type(),
			$status->identifier(),
			$status->sourceRevision(),
			$status->providerRepositoryId(),
			$status->packageRoot(),
			$status->installedVersion(),
			$status->eligibility()->expectedUpdateUri()
		);
	}

	public static function preflight( ReleaseTrackingPreflight $preflight ): RepositoryReleaseWorkflowPreflight {
		return new RepositoryReleaseWorkflowPreflight( $preflight->code(), $preflight->reasonCode() );
	}
}
