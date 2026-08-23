<?php

declare(strict_types=1);

namespace RAN\RepositoryProvider;

use RAN\Provider\ProviderCapability;

/** Optional provider capability for checking a normalized path at an immutable ref. */
interface RepositoryPathInspector extends ProviderCapability {

	public function repositoryPathExists( RepositoryReference $repository, string $ref, string $path ): bool;
}
