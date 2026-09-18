<?php

declare(strict_types=1);

namespace RAN\RepositoryProvider;

use RAN\Provider\ProviderCapability;

/**
 * Provider-neutral release workflow lifecycle.
 *
 * API 2 is the current pre-1.0 baseline. Its provider-neutral inputs keep Core
 * release-tracking types out of external provider runtimes.
 */
interface RepositoryReleaseWorkflowManagementV2 extends ProviderCapability {
	public const RELEASE_WORKFLOW_API_VERSION = 2;

	public function workflowStatus( RepositoryReleaseWorkflowTarget $target ): RepositoryReleaseWorkflowStatus;

	public function workflowPreview( RepositoryReleaseWorkflowTarget $target, string $key ): ?RepositoryReleaseWorkflowPreview;

	public function workflowInspect( RepositoryReleaseWorkflowTarget $target, string $channel, RepositoryReleaseWorkflowPreflight $preflight, ?string $credentialId ): RepositoryReleaseWorkflowResult;

	public function workflowSetup( RepositoryReleaseWorkflowTarget $target, string $key, string $confirmation, RepositoryReleaseWorkflowPreflight $preflight, ?string $credentialId ): RepositoryReleaseWorkflowResult;

	public function workflowOutcome( RepositoryReleaseWorkflowTarget $target, ?string $credentialId ): RepositoryReleaseWorkflowResult;

	public function workflowInspectUpdate( RepositoryReleaseWorkflowTarget $target, ?string $credentialId ): RepositoryReleaseWorkflowResult;

	public function workflowSetupUpdate( RepositoryReleaseWorkflowTarget $target, string $key, string $confirmation, ?string $credentialId ): RepositoryReleaseWorkflowResult;
}
