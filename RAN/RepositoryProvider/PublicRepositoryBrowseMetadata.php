<?php

declare(strict_types=1);

namespace RAN\RepositoryProvider;

/**
 * Provider-owned configuration for authenticated public repository browsing.
 */
final readonly class PublicRepositoryBrowseMetadata {

	public function __construct(
		public bool $supportsProviderDefaultProfile
	) {
	}
}
