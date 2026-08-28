<?php

declare(strict_types=1);

namespace RAN\RepositoryProvider;

use RAN\AddOn\ReleaseTracking\ReleaseTrackingPreflight;
use RAN\AddOn\ReleaseTracking\ReleaseTrackingStatus;
use RAN\Provider\ProviderCapability;

/** Optional provider-owned release workflow lifecycle. */
interface RepositoryReleaseWorkflowManagement extends ProviderCapability {
	public const RELEASE_WORKFLOW_API_VERSION = 1;

	public function workflowStatus( ReleaseTrackingStatus $status ): RepositoryReleaseWorkflowStatus;

	public function workflowPreview( ReleaseTrackingStatus $status, string $key ): ?RepositoryReleaseWorkflowPreview;

	public function workflowInspect( ReleaseTrackingStatus $status, string $channel, ReleaseTrackingPreflight $preflight, ?string $credentialId ): RepositoryReleaseWorkflowResult;

	public function workflowSetup( ReleaseTrackingStatus $status, string $key, string $confirmation, ReleaseTrackingPreflight $preflight, ?string $credentialId ): RepositoryReleaseWorkflowResult;

	public function workflowOutcome( ReleaseTrackingStatus $status, ?string $credentialId ): RepositoryReleaseWorkflowResult;

	public function workflowInspectUpdate( ReleaseTrackingStatus $status, ?string $credentialId ): RepositoryReleaseWorkflowResult;

	public function workflowSetupUpdate( ReleaseTrackingStatus $status, string $key, string $confirmation, ?string $credentialId ): RepositoryReleaseWorkflowResult;
}
