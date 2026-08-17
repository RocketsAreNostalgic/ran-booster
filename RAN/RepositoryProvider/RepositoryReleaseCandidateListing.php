<?php

declare(strict_types=1);

namespace RAN\RepositoryProvider;

use RAN\Provider\ProviderCapability;

interface RepositoryReleaseCandidateListing extends ProviderCapability {
	public function listReleaseCandidates(
		string $packageType,
		RepositoryReference $repository,
		string $channel
	): RepositoryReleaseCandidateList;
}
