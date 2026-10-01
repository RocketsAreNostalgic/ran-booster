<?php

declare(strict_types=1);

// API-12 method spellings deliberately cannot implement the renamed API-13 contract.
final class RANBoosterApiTwelveProvider implements \RAN\RepositoryProvider\RepositoryProvider {

	public function getMetadata(): \RAN\RepositoryProvider\ProviderMetadata {
		throw new \LogicException( 'The API-12 provider must remain unloaded.' );
	}

	public function getProviderDiagnostics(): \RAN\RepositoryProvider\ProviderDiagnostics {
		throw new \LogicException( 'The API-12 provider must remain unloaded.' );
	}

	public function resolveRepository( \RAN\RepositoryProvider\RepositoryLookupRequest $request ): \RAN\RepositoryProvider\RepositoryDescriptor {
		throw new \LogicException( 'The API-12 provider must remain unloaded.' );
	}

	public function prepareArchive( \RAN\RepositoryProvider\ArchiveRequest $request ): \RAN\RepositoryProvider\PreparedArchive {
		throw new \LogicException( 'The API-12 provider must remain unloaded.' );
	}
}
