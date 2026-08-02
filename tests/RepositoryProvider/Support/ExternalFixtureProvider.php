<?php

declare(strict_types=1);

namespace Tests\RepositoryProvider\Support;

use RAN\RepositoryProvider\Admin\ProviderAdminMetadata;
use RAN\RepositoryProvider\Admin\CredentialFieldMetadata;
use RAN\RepositoryProvider\Admin\CredentialKindMetadata;
use RAN\RepositoryProvider\ArchiveRequest;
use RAN\RepositoryProvider\CredentialValidationResult;
use RAN\RepositoryProvider\CredentialValidator;
use RAN\RepositoryProvider\PreparedArchive;
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

final readonly class ExternalFixtureProvider implements RepositoryProvider, ProviderCredentialPolicySupplier, CredentialValidator {

	private ProviderCode $code;
	private ExternalFixtureClient $client;
	private ProviderDiagnostics $diagnostics;
	private ProviderCredentialPolicy $credentialPolicy;

	public function __construct( string $code = 'fixture', private ?ProviderCredentialStore $credentials = null ) {
		$this->code             = ProviderCode::parse( $code );
		$this->client           = new ExternalFixtureClient( $this->code );
		$this->diagnostics      = new ExternalFixtureDiagnostics( $this->client );
		$this->credentialPolicy = new ExternalFixtureCredentialPolicy( $this->code );
	}

	public function getMetadata(): ProviderMetadata {
		return new ProviderMetadata(
			$this->code,
			'Fixture provider',
			'https://fixtures.example.test/',
			'Owner',
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
		$material = null !== $this->credentials
			? $this->credentials->credentialMaterial( $credentialId )
			: null;

		return is_array( $material ) && 'api-key' === ( $material['kind'] ?? null )
			? CredentialValidationResult::valid()
			: CredentialValidationResult::invalid();
	}

	public function resolveRepository( RepositoryLookupRequest $request ): RepositoryDescriptor {
		$credentialId = $request->credentialId;
		if ( null !== $credentialId && '' !== $credentialId && ! $this->validateCredential( $credentialId )->isValid() ) {
			throw new RuntimeException( 'The fixture credential is unavailable.' );
		}

		$repository = $this->client->repository( $request->locator );

		return new RepositoryDescriptor(
			$this->code,
			$repository->locator,
			$repository->packageSlug,
			$repository->providerRepositoryId,
			null !== $credentialId,
			$repository->defaultBranch,
			$credentialId
		);
	}

	public function prepareArchive( ArchiveRequest $request ): PreparedArchive {
		$repository     = $request->repository;
		$locator        = $repository->locator;
		$expectedBranch = $request->expectedBranch;
		$resolvedRef    = null === $expectedBranch
			? $this->client->resolveRef( $locator, $request->ref )
			: strtolower( $request->ref );

		if ( null !== $expectedBranch ) {
			$head = $this->client->branchHead( $locator, $expectedBranch );
			if ( ! hash_equals( $resolvedRef, $head ) ) {
				throw new StaleDeployment( 'The fixture deployment is stale because the configured branch has moved.', 409 );
			}
		}

		$headVerifier = null;
		if ( null !== $expectedBranch ) {
			$headVerifier = function () use ( $locator, $expectedBranch, $resolvedRef ): void {
				if ( ! hash_equals( $resolvedRef, $this->client->branchHead( $locator, $expectedBranch ) ) ) {
					throw new StaleDeployment( 'The fixture deployment is stale because the configured branch has moved.', 409 );
				}
			};
		}

		return new ExternalFixturePreparedArchive(
			'https://fixtures.example.test/' . $locator . '/' . $resolvedRef . '.zip',
			$resolvedRef,
			$headVerifier
		);
	}

	public function getClient(): ExternalFixtureClient {
		return $this->client;
	}
}
