<?php

declare(strict_types=1);

namespace RANBoosterFixtureProvider;

use RAN\RepositoryProvider\Admin\CredentialFieldMetadata;
use RAN\RepositoryProvider\Admin\CredentialKindMetadata;
use RAN\RepositoryProvider\Admin\ProviderAdminMetadata;
use RAN\RepositoryProvider\ArchiveRequest;
use RAN\RepositoryProvider\CredentialValidationResult;
use RAN\RepositoryProvider\CredentialValidator;
use RAN\RepositoryProvider\PreparedArchive as PreparedArchiveContract;
use RAN\RepositoryProvider\ProviderCode;
use RAN\RepositoryProvider\ProviderCredentialPolicy;
use RAN\RepositoryProvider\ProviderCredentialPolicySupplier;
use RAN\RepositoryProvider\ProviderCredentialStore;
use RAN\RepositoryProvider\ProviderDiagnostics;
use RAN\RepositoryProvider\ProviderMetadata;
use RAN\RepositoryProvider\RepositoryDescriptor;
use RAN\RepositoryProvider\RepositoryLookupRequest;
use RAN\RepositoryProvider\RepositoryProvider;
use RAN\RepositoryProvider\StaleDeployment;
use RuntimeException;

final readonly class Provider implements RepositoryProvider, ProviderCredentialPolicySupplier, CredentialValidator {

	private ProviderCode $code;
	private Client $client;
	private CredentialPolicy $credentialPolicy;
	private Diagnostics $diagnostics;

	public function __construct( private ProviderCredentialStore $credentials ) {
		$this->code             = ProviderCode::parse( 'fixture-provider' );
		$this->client           = new Client();
		$this->credentialPolicy = new CredentialPolicy();
		$this->diagnostics      = new Diagnostics( $this->client, $credentials );
	}

	public function getMetadata(): ProviderMetadata {
		return new ProviderMetadata(
			$this->code,
			'Fixture provider',
			'https://fixtures.example.test/',
			'Fixture namespace',
			new ProviderAdminMetadata(
				array(
					new CredentialKindMetadata(
						'api-key',
						'Fixture API key',
						'API key',
						'',
						array( new CredentialFieldMetadata( 'tenant', 'Tenant', 'text', true ) )
					),
				),
				array()
			)
		);
	}

	public function getProviderDiagnostics(): ProviderDiagnostics {
		return $this->diagnostics;
	}

	public function getCredentialPolicy(): ProviderCredentialPolicy {
		return $this->credentialPolicy;
	}

	public function validateCredential( string $credentialId ): CredentialValidationResult {
		return $this->client->validateCredential( $this->credentials->credentialMaterial( $credentialId ) )
			? CredentialValidationResult::valid()
			: CredentialValidationResult::invalid();
	}

	public function resolveRepository( RepositoryLookupRequest $request ): RepositoryDescriptor {
		$credentialId = $request->credentialId;
		if ( null !== $credentialId && ! $this->validateCredential( $credentialId )->isValid() ) {
			throw new RuntimeException( 'The fixture credential is unavailable.' );
		}

		$repository = $this->client->repository( $request->locator );

		return new RepositoryDescriptor(
			$this->code,
			$repository['locator'],
			$repository['package_slug'],
			$repository['provider_repository_id'],
			null !== $credentialId,
			$repository['default_branch'],
			$credentialId
		);
	}

	public function prepareArchive( ArchiveRequest $request ): PreparedArchiveContract {
		$repository     = $request->repository;
		$locator        = $repository->locator;
		$expectedBranch = $request->expectedBranch;
		$resolvedRef    = null === $expectedBranch
			? $this->client->resolveRef( $locator, $request->ref )
			: strtolower( $request->ref );

		if ( 1 !== preg_match( '/^[0-9a-f]{40}$/', $resolvedRef ) ) {
			throw new RuntimeException( 'The fixture deployment does not contain a valid commit.', 400 );
		}

		if ( null !== $expectedBranch ) {
			$head = $this->client->branchHead( $locator, $expectedBranch );
			if ( ! hash_equals( $resolvedRef, $head ) ) {
				throw new StaleDeployment( 'The fixture deployment is stale because the configured branch has moved.', 409 );
			}
		}

		$encodedLocator = implode( '/', array_map( 'rawurlencode', explode( '/', $locator ) ) );
		$headVerifier   = null;
		if ( null !== $expectedBranch ) {
			$headVerifier = function () use ( $locator, $expectedBranch, $resolvedRef ): void {
				if ( ! hash_equals( $resolvedRef, $this->client->branchHead( $locator, $expectedBranch ) ) ) {
					throw new StaleDeployment( 'The fixture deployment is stale because the configured branch has moved.', 409 );
				}
			};
		}

		return new PreparedArchive(
			'https://fixtures.example.test/' . $encodedLocator . '/' . $resolvedRef . '.zip',
			$resolvedRef,
			$headVerifier
		);
	}

	public function getClient(): Client {
		return $this->client;
	}
}
