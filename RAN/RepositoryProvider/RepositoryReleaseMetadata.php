<?php

declare(strict_types=1);

namespace RAN\RepositoryProvider;

use RAN\Provider\ProviderCapability;

interface RepositoryReleaseMetadata extends ProviderCapability {
	public function expectedUpdateUri( RepositoryReference $repository ): string;

	public function releaseDetailsUrl( RepositoryReference $repository, string $tag ): string;
}
