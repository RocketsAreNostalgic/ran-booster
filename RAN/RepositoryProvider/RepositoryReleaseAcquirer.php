<?php

declare(strict_types=1);

namespace RAN\RepositoryProvider;

use RAN\Provider\ProviderCapability;

interface RepositoryReleaseAcquirer extends ProviderCapability {
	/** @throws RepositoryReleaseAcquisitionRejected When acquisition rejects the release or cannot clean up its bytes. */
	public function acquireRelease(
		string $packageType,
		RepositoryReference $repository,
		string $providerReleaseId,
		string $tag,
		string $expectedFingerprint,
		string $channel
	): RepositoryReleaseArtifact;
}
