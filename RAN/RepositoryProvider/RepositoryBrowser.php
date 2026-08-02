<?php

declare(strict_types=1);

namespace RAN\RepositoryProvider;

use RAN\Provider\ProviderCapability;

interface RepositoryBrowser extends ProviderCapability {

	public function browseRepositories( RepositoryBrowseRequest $request ): RepositoryBrowseResult;
}
