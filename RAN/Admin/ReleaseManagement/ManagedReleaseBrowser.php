<?php

declare(strict_types=1);

namespace RAN\Admin\ReleaseManagement;

use RAN\AddOn\ReleaseTracking\ReleaseTrackingPreflight;
use RAN\RepositoryProvider\RepositoryReleaseCandidateList;

/** @internal Core-admin-only read capability for managed release candidates. */
interface ManagedReleaseBrowser {
	public function list_candidates( string $type, string $identifier, int $revision, string $channel, string $nonce ): ?RepositoryReleaseCandidateList;

	public function inspect_candidate( string $type, string $identifier, int $revision, string $release_id, string $tag, string $channel, string $nonce ): ?ReleaseTrackingPreflight;
}
