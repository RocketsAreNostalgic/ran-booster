<?php

declare(strict_types=1);

namespace RAN\RepositoryProvider;

use RAN\Provider\ProviderCapability;

/**
 * Provider-neutral release workflow lifecycle.
 *
 * API 2 is deliberately separate from the Core-bound API 1 interface so a
 * provider implementing this facet does not load Core release-tracking types.
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
