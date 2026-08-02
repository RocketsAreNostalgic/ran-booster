<?php

declare(strict_types=1);

namespace RAN\RepositoryProvider;

interface RepositoryProvider {

	public function getMetadata(): ProviderMetadata;

	public function getProviderDiagnostics(): ProviderDiagnostics;

	public function resolveRepository( RepositoryLookupRequest $request ): RepositoryDescriptor;

	/**
	 * Prepare an immutable archive request.
	 *
	 * Implementations must fail closed when an expected branch is supplied and
	 * the requested commit cannot be proven to be that branch's current head.
	 *
	 * @throws StaleDeployment When the immutable ref is no longer the branch head.
	 */
	public function prepareArchive( ArchiveRequest $request ): PreparedArchive;
}
