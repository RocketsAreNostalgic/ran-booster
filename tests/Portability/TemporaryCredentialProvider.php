<?php

declare(strict_types=1);

namespace Tests\Portability;

use RAN\RepositoryProvider\ArchiveRequest;
use RAN\BoosterGitHubProvider\V1\CredentialPolicy as GitHubCredentialPolicy;
use RAN\RepositoryProvider\PreparedArchive;
use RAN\RepositoryProvider\ProviderCode;
use RAN\RepositoryProvider\ProviderCredentialPolicy;
use RAN\RepositoryProvider\ProviderCredentialPolicySupplier;
use RAN\RepositoryProvider\ProviderCredentialStore;
use RAN\RepositoryProvider\ProviderMetadata;
use RAN\RepositoryProvider\RepositoryDescriptor;
use RAN\RepositoryProvider\RepositoryLookupRequest;
use RAN\RepositoryProvider\RepositoryProvider;
use RuntimeException;

final class TemporaryCredentialProvider implements RepositoryProvider, ProviderCredentialPolicySupplier {

	use \Tests\RepositoryProvider\Support\SuppliesProviderDiagnostics;

	/** @var list<string|null> */
	public array $credential_ids            = array();
	public ?string $temporary_credential_id = null;

	public function __construct(
		private ProviderCredentialStore $credentials,
		private int $anonymous_failure,
		private string $provider_repository_id,
		private bool $is_private = false,
		private string $provider_code = 'gh',
		private string $provider_label = 'GitHub',
		private string $accepted_secret = 'sentinel-portability-token'
	) {
	}

	public function get_metadata(): ProviderMetadata {
		return new ProviderMetadata( ProviderCode::parse( $this->provider_code ), $this->provider_label, 'https://provider.example.test/', 'Owner' );
	}

	public function get_credential_policy(): ProviderCredentialPolicy {
		return 'gh' === $this->provider_code
			? new GitHubCredentialPolicy()
			: new TemporaryProviderCredentialPolicy( ProviderCode::parse( $this->provider_code ) );
	}

	public function resolve_repository( RepositoryLookupRequest $request ): RepositoryDescriptor {
		$this->credential_ids[] = $request->credential_id;
		if ( null === $request->credential_id && 0 !== $this->anonymous_failure ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Test-only provider error has fixed public text.
			throw new RuntimeException( 'Repository access failed.', $this->anonymous_failure );
		}
		if ( null !== $request->credential_id ) {
			$material = $this->credentials->credential_material( $request->credential_id );
			if ( ! is_array( $material ) || ( $material['secret'] ?? null ) !== $this->accepted_secret ) {
				throw new RuntimeException( 'Repository access failed.', 401 );
			}
			$this->temporary_credential_id = $request->credential_id;
		}

		return new RepositoryDescriptor(
			ProviderCode::parse( $this->provider_code ),
			'owner/repository',
			'example',
			$this->provider_repository_id,
			$this->is_private,
			'main',
			$request->credential_id
		);
	}

	public function prepare_archive( ArchiveRequest $request ): PreparedArchive {
		throw new RuntimeException( 'Archive preparation is not used by this test.' );
	}
}

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- Focused test provider policy belongs with the provider double.
final readonly class TemporaryProviderCredentialPolicy implements ProviderCredentialPolicy {

	public function __construct( private ProviderCode $provider ) {
	}

	public function get_provider(): ProviderCode {
		return $this->provider;
	}

	public function normalize_credential( array $metadata, mixed $secret ): array {
		$label         = $metadata['label'] ?? null;
		$kind          = $metadata['kind'] ?? null;
		$configuration = $metadata['configuration'] ?? null;
		if ( ! is_string( $label ) || '' === trim( $label ) || ! is_string( $kind ) || '' === trim( $kind )
			|| ! is_array( $configuration ) || ! is_string( $secret ) || '' === trim( $secret ) ) {
			throw new RuntimeException( 'The temporary provider credential is invalid.' );
		}

		return array(
			'label'         => trim( $label ),
			'kind'          => trim( $kind ),
			'configuration' => $configuration,
			'secret'        => trim( $secret ),
		);
	}

	public function get_constant_names(): array {
		return array();
	}

	public function credential_from_constants( array $constants ): ?array {
		return null;
	}
}
