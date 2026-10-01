<?php

declare(strict_types=1);

namespace RAN\RepositoryProvider;

use RAN\Provider\ProviderCapability;

interface RepositoryBrowser extends ProviderCapability {

	public function browse_repositories( RepositoryBrowseRequest $request ): RepositoryBrowseResult;
}
