<?php

declare(strict_types=1);

namespace RAN\RepositoryProvider;

use RAN\Provider\ProviderCapability;

interface RepositoryReleaseCandidateListing extends ProviderCapability {
	/** Return at most eight candidates in provider-preferred inspection order. */
	public function list_release_candidates(
		string $package_type,
		RepositoryReference $repository,
		string $channel
	): RepositoryReleaseCandidateList;
}
