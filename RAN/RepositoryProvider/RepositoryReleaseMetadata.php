<?php

declare(strict_types=1);

namespace RAN\RepositoryProvider;

use RAN\Provider\ProviderCapability;

interface RepositoryReleaseMetadata extends ProviderCapability {
	public function expected_update_uri( RepositoryReference $repository ): string;

	public function release_details_url( RepositoryReference $repository, string $tag ): string;
}
