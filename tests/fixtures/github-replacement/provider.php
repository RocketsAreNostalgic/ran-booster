<?php

declare(strict_types=1);

namespace RAN_Booster_GitHubReplacementFixture;

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

/** Real replacement aggregate, using the host-bound credential callable. */
final readonly class Provider implements RepositoryProvider, ProviderDiagnostics, RepositoryPathInspector, ProviderCredentialPolicySupplier {
	public function __construct( private string $identity, private ProviderCredentialStore $credentials, private ProviderRegistrationContext $context ) {}

	public function get_metadata(): ProviderMetadata {
		$archive_api = 'unsupported' === getenv( 'RAN_BOOSTER_GITHUB_HISTORICAL_MODE' ) ? 1 : 2;
		return new ProviderMetadata( ProviderCode::parse( 'gh' ), 'Installed GitHub fixture ' . $this->identity, 'https://github.com/', 'Owner', archive_api_version: $archive_api );
	}

	public function get_credential_policy(): ProviderCredentialPolicy {
		return new \RAN\BoosterGitHubProvider\V1\CredentialPolicy();
	}

	public function get_provider_diagnostics(): ProviderDiagnostics {
		return $this;
	}

	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundInImplementedInterface -- The fixture has no remote diagnostics, but preserves the real diagnostics interface.
	public function diagnose( ProviderDiagnosticRequest $request ): array {
		return array();
	}

	public function resolve_repository( RepositoryLookupRequest $request ): RepositoryDescriptor {
		$this->invoke( $request->credential_id );
		$parts = explode( '/', $request->locator );
		return new RepositoryDescriptor( ProviderCode::parse( 'gh' ), $request->locator, (string) end( $parts ), 'replacement-fixture:' . $request->locator, null !== $request->credential_id, 'main', $request->credential_id );
	}

	public function repository_path_exists( RepositoryReference $repository, string $ref, string $path ): bool {
		$this->invoke( $repository->credential_id );
		return 1 === preg_match( '/\A[a-f0-9]{40}\z/D', $ref ) && ! str_contains( $path, '..' );
	}

	public function prepare_archive( ArchiveRequest $request ): PreparedArchive {
		$this->invoke( $request->repository->credential_id );
		$mode = getenv( 'RAN_BOOSTER_GITHUB_HISTORICAL_MODE' );
		if ( ! in_array( $mode, array( 'success', 'wrong-commit' ), true ) || str_repeat( 'a', 40 ) !== $request->selected_commit ) {
			throw new \RuntimeException( 'Only the explicit historical installed proof can acquire fixture archives.' );
		}
		update_option(
			'ran_booster_historical_fixture_archive',
			array(
				'ref'                 => $request->ref,
				'selected_commit'     => $request->selected_commit,
				'expected_branch'     => $request->expected_branch,
				'current_branch_head' => str_repeat( 'b', 40 ),
				'cleaned'             => false,
				'verified'            => false,
			),
			false
		);
		return new class( 'wrong-commit' === $mode ? str_repeat( 'b', 40 ) : $request->selected_commit, $this->context ) implements PreparedArchive {
			public function __construct( private string $ref, private ProviderRegistrationContext $context ) {}
			public function get_url(): string {
				return 'https://github.com/ran-fixtures/historical/archive.zip';
			}
			public function get_resolved_ref(): string {
				return $this->ref;
			}
			public function verify_current_head(): void {
				if ( ! $this->context->operation_allowed() ) {
					throw new \RuntimeException( 'Historical fixture selection changed.' );
				}
				// Historical exact selection deliberately does not follow current_branch_head.
				$this->record( 'verified' );
			}
			public function cleanup(): void {
				$this->record( 'cleaned' );
			}
			private function record( string $key ): void {
				Fixture::guard();
				$state = get_option( 'ran_booster_historical_fixture_archive', array() );
				if ( ! is_array( $state ) ) {
					throw new \RuntimeException( 'Historical archive observation is invalid.' );
				}
				$state[ $key ] = true;
				update_option( 'ran_booster_historical_fixture_archive', $state, false );
			}
		};
	}

	private function invoke( ?string $credential_id ): void {
		Fixture::observe( $this->identity, 'provider_calls' );
		if ( ! $this->context->operation_allowed() ) {
			throw new \RuntimeException( 'Replacement fixture selection is stale.' );
		}
		if ( null !== $credential_id ) {
			Fixture::observe( $this->identity, 'credential_calls' );
			$material = $this->credentials->credential_material( $credential_id );
			if ( null === $material ) {
				throw new \RuntimeException( 'Replacement fixture credential is unavailable.' );
			}
			Fixture::observe( $this->identity, 'credential_material_returns' );
			unset( $material );
		}
	}
}
