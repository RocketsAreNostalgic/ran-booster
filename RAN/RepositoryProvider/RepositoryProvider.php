<?php

declare(strict_types=1);

namespace RAN\RepositoryProvider;

interface RepositoryProvider {

	public function get_metadata(): ProviderMetadata;

	public function get_provider_diagnostics(): ProviderDiagnostics;

	public function resolve_repository( RepositoryLookupRequest $request ): RepositoryDescriptor;

	/**
	 * Prepare an immutable archive request.
	 *
	 * Implementations must fail closed when an expected branch is supplied and
	 * the requested commit cannot be proven to be that branch's current head.
	 *
	 * @throws StaleDeployment When the immutable ref is no longer the branch head.
	 */
	public function prepare_archive( ArchiveRequest $request ): PreparedArchive;
}
