<?php

declare(strict_types=1);

namespace RAN\RepositoryProvider;

use RAN\Provider\ProviderCapability;

interface RepositoryReleaseInspector extends ProviderCapability {
	/** @throws RepositoryReleaseInspectionRejected When the exact release is absent or invalid. */
	public function inspectRelease(
		string $packageType,
		RepositoryReference $repository,
		string $providerReleaseId,
		string $tag,
		string $channel
	): RepositoryReleaseInspection;
}
