<?php

declare(strict_types=1);

namespace RAN\RepositoryProvider;

use RAN\Provider\ProviderCapability;

interface RepositoryReleaseInspector extends ProviderCapability {
	/** @throws RepositoryReleaseInspectionRejected When the exact release is absent, invalid or package-incompatible. */
	public function inspect_release(
		string $package_type,
		RepositoryReference $repository,
		string $provider_release_id,
		string $tag,
		string $channel
	): RepositoryReleaseInspection;
}
