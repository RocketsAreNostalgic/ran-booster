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

	public function workflow_status( RepositoryReleaseWorkflowTarget $target ): RepositoryReleaseWorkflowStatus;

	public function workflow_preview( RepositoryReleaseWorkflowTarget $target, string $key ): ?RepositoryReleaseWorkflowPreview;

	public function workflow_inspect( RepositoryReleaseWorkflowTarget $target, string $channel, RepositoryReleaseWorkflowPreflight $preflight, ?string $credential_id ): RepositoryReleaseWorkflowResult;

	public function workflow_setup( RepositoryReleaseWorkflowTarget $target, string $key, string $confirmation, RepositoryReleaseWorkflowPreflight $preflight, ?string $credential_id ): RepositoryReleaseWorkflowResult;

	public function workflow_outcome( RepositoryReleaseWorkflowTarget $target, ?string $credential_id ): RepositoryReleaseWorkflowResult;
}
