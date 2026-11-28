<?php

declare(strict_types=1);

namespace RAN_Booster_GitHubOfflineProviderFixture;

use RAN\RepositoryProvider\ArchiveRequest;
use RAN\RepositoryProvider\PreparedArchive;
use RAN\RepositoryProvider\ProviderCode;
use RAN\RepositoryProvider\ProviderCredentialStore;
use RAN\RepositoryProvider\ProviderCredentialPolicy;
use RAN\RepositoryProvider\ProviderCredentialPolicySupplier;
use RAN\RepositoryProvider\ProviderDiagnosticRequest;
use RAN\RepositoryProvider\ProviderDiagnostics;
use RAN\RepositoryProvider\ProviderMetadata;
use RAN\RepositoryProvider\ProviderRegistrationContext;
use RAN\RepositoryProvider\RepositoryDescriptor;
use RAN\RepositoryProvider\RepositoryLookupRequest;
use RAN\RepositoryProvider\RepositoryPathInspector;
use RAN\RepositoryProvider\RepositoryProvider;
use RAN\RepositoryProvider\RepositoryReference;

/** Offline GitHub-shaped provider: resolves repositories locally and uses the host-bound credential callable. */
final readonly class Provider implements RepositoryProvider, ProviderDiagnostics, RepositoryPathInspector, ProviderCredentialPolicySupplier {
	public function __construct( private ProviderCredentialStore $credentials, private ProviderRegistrationContext $context ) {}

	public function get_metadata(): ProviderMetadata {
		return new ProviderMetadata( ProviderCode::parse( 'gh' ), 'Offline GitHub fixture', 'https://github.com/', 'Owner', archive_api_version: 2 );
	}

	// The shipped GitHub credential policy keeps Core's credential boundary real.
	public function get_credential_policy(): ProviderCredentialPolicy {
		return new \RAN\BoosterGitHubProvider\V1\CredentialPolicy();
	}

	public function get_provider_diagnostics(): ProviderDiagnostics {
		return $this;
	}

	public function diagnose( ProviderDiagnosticRequest $request ): array {
		unset( $request );
		return array();
	}

	public function resolve_repository( RepositoryLookupRequest $request ): RepositoryDescriptor {
		$this->invoke( $request->credential_id );
		$parts = explode( '/', $request->locator );
		return new RepositoryDescriptor( ProviderCode::parse( 'gh' ), $request->locator, (string) end( $parts ), 'offline-fixture:' . $request->locator, null !== $request->credential_id, 'main', $request->credential_id );
	}

	public function repository_path_exists( RepositoryReference $repository, string $ref, string $path ): bool {
		$this->invoke( $repository->credential_id );
		return 1 === preg_match( '/\A[a-f0-9]{40}\z/D', $ref ) && ! str_contains( $path, '..' );
	}

	public function prepare_archive( ArchiveRequest $request ): PreparedArchive {
		$this->invoke( $request->repository->credential_id );
		throw new \RuntimeException( 'The offline provider fixture never acquires archives.' );
	}

	private function invoke( ?string $credential_id ): void {
		Fixture::observe( 'provider_calls' );
		if ( ! $this->context->operation_allowed() ) {
			throw new \RuntimeException( 'Offline provider fixture operation is not allowed.' );
		}
		if ( null !== $credential_id ) {
			Fixture::observe( 'credential_calls' );
			$material = $this->credentials->credential_material( $credential_id );
			if ( null === $material ) {
				throw new \RuntimeException( 'Offline provider fixture credential is unavailable.' );
			}
			Fixture::observe( 'credential_material_returns' );
			unset( $material );
		}
	}
}
