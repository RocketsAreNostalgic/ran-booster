<?php

declare(strict_types=1);

namespace RAN\Admin\ReleaseManagement;

use RAN\AddOn\ReleaseTracking\ReleaseTrackingPreflight;
use RAN\RepositoryProvider\RepositoryReleaseCandidateList;

/** @internal Core-admin-only read capability for managed release candidates. */
interface ManagedReleaseBrowser {
	public function list_candidates( string $type, string $identifier, int $revision, string $channel, string $nonce ): ?RepositoryReleaseCandidateList;

	/** The provider whose anonymous public lookup was rate-limited in the last listing or inspection, if any. */
	public function anonymous_rate_limit_provider(): ?string;

	public function lookup_rate_limited(): bool;

	/** @return array{provider:string,retry_at:int|null}|null */
	public function lookup_rate_limit(): ?array;

	public function inspect_candidate( string $type, string $identifier, int $revision, string $release_id, string $tag, string $channel, string $nonce ): ?ReleaseTrackingPreflight;
}
