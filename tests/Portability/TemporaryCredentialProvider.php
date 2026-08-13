<?php

declare(strict_types=1);

namespace Tests\Portability;

use RAN\RepositoryProvider\ArchiveRequest;
use RAN\Booster\GitHub\CredentialPolicy as GitHubCredentialPolicy;
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
	public array $credentialIds           = array();
	public ?string $temporaryCredentialId = null;

	public function __construct(
		private ProviderCredentialStore $credentials,
		private int $anonymousFailure,
		private string $providerRepositoryId,
		private bool $private = false,
		private string $providerCode = 'gh',
		private string $providerLabel = 'GitHub',
		private string $acceptedSecret = 'sentinel-portability-token'
	) {
	}

	public function getMetadata(): ProviderMetadata {
		return new ProviderMetadata( ProviderCode::parse( $this->providerCode ), $this->providerLabel, 'https://provider.example.test/', 'Owner' );
	}

	public function getCredentialPolicy(): ProviderCredentialPolicy {
		return 'gh' === $this->providerCode
			? new GitHubCredentialPolicy()
			: new TemporaryProviderCredentialPolicy( ProviderCode::parse( $this->providerCode ) );
	}

	public function resolveRepository( RepositoryLookupRequest $request ): RepositoryDescriptor {
		$this->credentialIds[] = $request->credentialId;
		if ( null === $request->credentialId && 0 !== $this->anonymousFailure ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Test-only provider error has fixed public text.
			throw new RuntimeException( 'Repository access failed.', $this->anonymousFailure );
		}
		if ( null !== $request->credentialId ) {
			$material = $this->credentials->credentialMaterial( $request->credentialId );
			if ( ! is_array( $material ) || $this->acceptedSecret !== ( $material['secret'] ?? null ) ) {
				throw new RuntimeException( 'Repository access failed.', 401 );
			}
			$this->temporaryCredentialId = $request->credentialId;
		}

		return new RepositoryDescriptor(
			ProviderCode::parse( $this->providerCode ),
			'owner/repository',
			'example',
			$this->providerRepositoryId,
			$this->private,
			'main',
			$request->credentialId
		);
	}

	public function prepareArchive( ArchiveRequest $request ): PreparedArchive {
		throw new RuntimeException( 'Archive preparation is not used by this test.' );
	}
}

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- Focused test provider policy belongs with the provider double.
final readonly class TemporaryProviderCredentialPolicy implements ProviderCredentialPolicy {

	public function __construct( private ProviderCode $provider ) {
	}

	public function getProvider(): ProviderCode {
		return $this->provider;
	}

	public function normalizeCredential( array $metadata, mixed $secret ): array {
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

	public function getConstantNames(): array {
		return array();
	}

	public function credentialFromConstants( array $constants ): ?array {
		return null;
	}
}
