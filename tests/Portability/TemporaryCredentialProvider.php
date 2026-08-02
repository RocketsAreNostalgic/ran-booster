<?php

declare(strict_types=1);

namespace Tests\Portability;

use RAN\RepositoryProvider\ArchiveRequest;
use RAN\RepositoryProvider\GitHubCredentialPolicy;
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
		private bool $private = false
	) {
	}

	public function getMetadata(): ProviderMetadata {
		return new ProviderMetadata( ProviderCode::parse( 'gh' ), 'GitHub', 'https://github.com/', 'Owner' );
	}

	public function getCredentialPolicy(): ProviderCredentialPolicy {
		return new GitHubCredentialPolicy();
	}

	public function resolveRepository( RepositoryLookupRequest $request ): RepositoryDescriptor {
		$this->credentialIds[] = $request->credentialId;
		if ( null === $request->credentialId && 0 !== $this->anonymousFailure ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Test-only provider error has fixed public text.
			throw new RuntimeException( 'Repository access failed.', $this->anonymousFailure );
		}
		if ( null !== $request->credentialId ) {
			$material = $this->credentials->credentialMaterial( $request->credentialId );
			if ( ! is_array( $material ) || 'sentinel-portability-token' !== ( $material['secret'] ?? null ) ) {
				throw new RuntimeException( 'Repository access failed.', 401 );
			}
			$this->temporaryCredentialId = $request->credentialId;
		}

		return new RepositoryDescriptor(
			ProviderCode::parse( 'gh' ),
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
