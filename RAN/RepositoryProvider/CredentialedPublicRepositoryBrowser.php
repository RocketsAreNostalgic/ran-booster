<?php

declare(strict_types=1);

namespace RAN\RepositoryProvider;

/**
 * Optional capability for public repository browsing with one selected profile.
 *
 * Implementations must keep results public-only. The selected profile belongs
 * to the current browse/save-verification transaction and is not a package
 * credential.
 */
interface CredentialedPublicRepositoryBrowser extends RepositoryBrowser {

	public function getPublicRepositoryBrowseMetadata(): PublicRepositoryBrowseMetadata;
}
