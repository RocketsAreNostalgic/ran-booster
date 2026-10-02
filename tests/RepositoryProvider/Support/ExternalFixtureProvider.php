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
	private ProviderCredentialPolicy $credential_policy;

	public function __construct( string $code = 'fixture', private ?ProviderCredentialStore $credentials = null ) {
		$this->code              = ProviderCode::parse( $code );
		$this->client            = new ExternalFixtureClient( $this->code );
		$this->diagnostics       = new ExternalFixtureDiagnostics( $this->client );
		$this->credential_policy = new ExternalFixtureCredentialPolicy( $this->code );
	}

	public function get_metadata(): ProviderMetadata {
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

	public function get_provider_diagnostics(): ProviderDiagnostics {
		return $this->diagnostics;
	}

	public function get_credential_policy(): ProviderCredentialPolicy {
		return $this->credential_policy;
	}

	public function validate_credential( string $credential_id ): CredentialValidationResult {
		$material = null !== $this->credentials
			? $this->credentials->credential_material( $credential_id )
			: null;

		return is_array( $material ) && 'api-key' === ( $material['kind'] ?? null )
			? CredentialValidationResult::valid()
			: CredentialValidationResult::invalid();
	}

	public function resolve_repository( RepositoryLookupRequest $request ): RepositoryDescriptor {
		$credential_id = $request->credential_id;
		if ( null !== $credential_id && '' !== $credential_id && ! $this->validate_credential( $credential_id )->is_valid() ) {
			throw new RuntimeException( 'The fixture credential is unavailable.' );
		}

		$repository = $this->client->repository( $request->locator );

		return new RepositoryDescriptor(
			$this->code,
			$repository->locator,
			$repository->package_slug,
			$repository->provider_repository_id,
			null !== $credential_id,
			$repository->default_branch,
			$credential_id
		);
	}

	public function prepare_archive( ArchiveRequest $request ): PreparedArchive {
		$repository      = $request->repository;
		$locator         = $repository->locator;
		$expected_branch = $request->expected_branch;
		$resolved_ref    = null === $expected_branch
			? $this->client->resolve_ref( $locator, $request->ref )
			: strtolower( $request->ref );

		if ( null !== $expected_branch ) {
			$head = $this->client->branch_head( $locator, $expected_branch );
			if ( ! hash_equals( $resolved_ref, $head ) ) {
				throw new StaleDeployment( 'The fixture deployment is stale because the configured branch has moved.', 409 );
			}
		}

		$head_verifier = null;
		if ( null !== $expected_branch ) {
			$head_verifier = function () use ( $locator, $expected_branch, $resolved_ref ): void {
				if ( ! hash_equals( $resolved_ref, $this->client->branch_head( $locator, $expected_branch ) ) ) {
					throw new StaleDeployment( 'The fixture deployment is stale because the configured branch has moved.', 409 );
				}
			};
		}

		return new ExternalFixturePreparedArchive(
			'https://fixtures.example.test/' . $locator . '/' . $resolved_ref . '.zip',
			$resolved_ref,
			$head_verifier
		);
	}

	public function get_client(): ExternalFixtureClient {
		return $this->client;
	}
}
