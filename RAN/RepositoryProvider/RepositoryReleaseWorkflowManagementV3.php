<?php

declare(strict_types=1);

namespace RAN\RepositoryProvider;

use RAN\Provider\ProviderCapability;

/**
 * Provider-neutral initial release workflow setup.
 *
 * API 3 is the current pre-1.0 baseline. Its provider-neutral inputs keep Core
 * release-tracking types out of external provider runtimes.
 */
interface RepositoryReleaseWorkflowManagementV3 extends ProviderCapability {
	public const RELEASE_WORKFLOW_API_VERSION = 3;

	public function workflowStatus( RepositoryReleaseWorkflowTarget $target ): RepositoryReleaseWorkflowStatus;

	public function workflowPreview( RepositoryReleaseWorkflowTarget $target, string $key ): ?RepositoryReleaseWorkflowPreview;

	public function workflowInspect( RepositoryReleaseWorkflowTarget $target, string $channel, RepositoryReleaseWorkflowPreflight $preflight, ?string $credentialId ): RepositoryReleaseWorkflowResult;

	public function workflowSetup( RepositoryReleaseWorkflowTarget $target, string $key, string $confirmation, RepositoryReleaseWorkflowPreflight $preflight, ?string $credentialId ): RepositoryReleaseWorkflowResult;

	public function workflowOutcome( RepositoryReleaseWorkflowTarget $target, ?string $credentialId ): RepositoryReleaseWorkflowResult;
}
