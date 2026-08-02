<?php

declare(strict_types=1);

namespace RAN\RepositoryProvider;

use InvalidArgumentException;
use RAN\PackageSubdirectory;

final readonly class RepositoryDescriptor {
	public string $locator;
	public string $packageSlug;

	public function __construct(
		public ProviderCode $provider,
		string $locator,
		string $packageSlug,
		public string $providerRepositoryId,
		public bool $private,
		public string $defaultBranch,
		public ?string $credentialId
	) {
		$this->locator     = RepositoryLocator::requireValid( $locator );
		$this->packageSlug = PackageSubdirectory::normalizeSlug( $packageSlug );
		if ( strlen( $this->packageSlug ) > 191 ) {
			throw new InvalidArgumentException( 'The provider package slug is invalid.' );
		}
		$this->assertProviderRepositoryId( $providerRepositoryId );
		$this->requireValue( $defaultBranch, 'Default branch' );

		if ( null !== $credentialId ) {
			$this->requireValue( $credentialId, 'Credential ID' );
		}
	}

	/**
	 * @return array{
	 *     provider: string,
	 *     locator: string,
	 *     package_slug: string,
	 *     provider_repository_id: string,
	 *     private: bool,
	 *     default_branch: string,
	 *     credential_id: string|null
	 * }
	 */
	public function toArray(): array {
		return array(
			'provider'               => $this->provider->value,
			'locator'                => $this->locator,
			'package_slug'           => $this->packageSlug,
			'provider_repository_id' => $this->providerRepositoryId,
			'private'                => $this->private,
			'default_branch'         => $this->defaultBranch,
			'credential_id'          => $this->credentialId,
		);
	}

	private function assertProviderRepositoryId( string $providerRepositoryId ): void {
		if ( '' === $providerRepositoryId
			|| strlen( $providerRepositoryId ) > 191
			|| preg_match( '/[\x00-\x1F\x7F]/', $providerRepositoryId ) ) {
			throw new InvalidArgumentException( 'The provider repository identity is invalid.' );
		}
	}

	private function requireValue( string $value, string $label ): void {
		if ( '' === trim( $value ) ) {
			throw new InvalidArgumentException( 'Required repository data cannot be empty.' );
		}
	}
}
