<?php

declare(strict_types=1);

namespace RAN\RepositoryProvider;

use RAN\Activity\ActivityProgress;
use RAN\Provider\ProviderCapability;

/**
 * Provider-neutral initial release workflow setup.
 *
 * API 4 supplies progress-only reporting. Core enriches operational status
 * with its durable assessment and failure history. Provider-neutral inputs keep Core
 * release-tracking types out of external provider runtimes.
 */
interface RepositoryReleaseWorkflowManagementV4 extends ProviderCapability {
	public const RELEASE_WORKFLOW_API_VERSION = 4;

	public function workflow_status( RepositoryReleaseWorkflowTarget $target ): RepositoryReleaseWorkflowStatus;

	public function workflow_preview( RepositoryReleaseWorkflowTarget $target, string $key ): ?RepositoryReleaseWorkflowPreview;

	public function workflow_inspect( RepositoryReleaseWorkflowTarget $target, string $channel, RepositoryReleaseWorkflowPreflight $preflight, ?string $credential_id, ?ActivityProgress $progress = null ): RepositoryReleaseWorkflowResult;

	public function workflow_setup( RepositoryReleaseWorkflowTarget $target, string $key, string $confirmation, RepositoryReleaseWorkflowPreflight $preflight, ?string $credential_id, ?ActivityProgress $progress = null ): RepositoryReleaseWorkflowResult;

	public function workflow_outcome( RepositoryReleaseWorkflowTarget $target, ?string $credential_id, ?ActivityProgress $progress = null ): RepositoryReleaseWorkflowResult;
}
