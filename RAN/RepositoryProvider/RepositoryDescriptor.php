<?php

declare(strict_types=1);

namespace RAN\RepositoryProvider;

use InvalidArgumentException;
use RAN\PackageSubdirectory;

final readonly class RepositoryDescriptor {
	public string $locator;
	public string $package_slug;

	public function __construct(
		public ProviderCode $provider,
		string $locator,
		string $package_slug,
		public string $provider_repository_id,
		public bool $private,
		public string $default_branch,
		public ?string $credential_id
	) {
		$this->locator      = RepositoryLocator::require_valid( $locator );
		$this->package_slug = PackageSubdirectory::normalize_slug( $package_slug );
		if ( strlen( $this->package_slug ) > 191 ) {
			throw new InvalidArgumentException( 'The provider package slug is invalid.' );
		}
		$this->assert_provider_repository_id( $provider_repository_id );
		$this->require_value( $default_branch );
		if ( null !== $credential_id ) {
			$this->require_value( $credential_id );
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
	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public methods retain the existing connected caller contract.
	public function to_array(): array {
		return array(
			'provider'               => $this->provider->value,
			'locator'                => $this->locator,
			'package_slug'           => $this->package_slug,
			'provider_repository_id' => $this->provider_repository_id,
			'private'                => $this->private,
			'default_branch'         => $this->default_branch,
			'credential_id'          => $this->credential_id,
		);
	}

	private function assert_provider_repository_id( string $provider_repository_id ): void {
		if ( '' === $provider_repository_id
			|| strlen( $provider_repository_id ) > 191
			|| preg_match( '/[\x00-\x1F\x7F]/', $provider_repository_id ) ) {
			throw new InvalidArgumentException( 'The provider repository identity is invalid.' );
		}
	}

	private function require_value( string $value ): void {
		if ( '' === trim( $value ) ) {
			throw new InvalidArgumentException( 'Required repository data cannot be empty.' );
		}
	}
}
