<?php

declare(strict_types=1);

namespace RAN\Booster\Bitbucket;

use RAN\RepositoryProvider\ArchiveRequest;
use RAN\RepositoryProvider\PreparedArchive;
use RAN\RepositoryProvider\ProviderCode;
use RAN\RepositoryProvider\ProviderDiagnostics;
use RAN\RepositoryProvider\ProviderMetadata;
use RAN\RepositoryProvider\RepositoryDescriptor;
use RAN\RepositoryProvider\RepositoryLookupRequest;
use RAN\RepositoryProvider\RepositoryProvider;

/** Test-only identity for the existing component-catalogue join. */
final class BitbucketProvider implements RepositoryProvider {
	public function get_metadata(): ProviderMetadata {
		return new ProviderMetadata( ProviderCode::parse( 'bb' ), 'Bitbucket', 'https://bitbucket.org/', 'Workspace' );
	}

	public function get_provider_diagnostics(): ProviderDiagnostics {
		return ( new \RAN\Tests\RepositoryProvider\Support\ExternalFixtureProvider( 'bb' ) )->get_provider_diagnostics();
	}

	public function resolve_repository( RepositoryLookupRequest $request ): RepositoryDescriptor {
		throw new \LogicException( 'Passive source projection must not resolve repositories.' );
	}

	public function prepare_archive( ArchiveRequest $request ): PreparedArchive {
		throw new \LogicException( 'Passive source projection must not prepare archives.' );
	}
}
