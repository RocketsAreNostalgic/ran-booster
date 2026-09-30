<?php

declare(strict_types=1);

namespace RAN\RepositoryProvider;

use InvalidArgumentException;
use RAN\PackageSubdirectory;

final readonly class RepositoryDescriptor {
	public string $locator;
	// phpcs:ignore WordPress.NamingConventions.ValidVariableName.PropertyNotSnakeCase -- Public named parameters and DTO fields retain the existing caller contract.
	public string $packageSlug;

	public function __construct(
		public ProviderCode $provider,
		string $locator,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public named parameters and DTO fields retain the existing caller contract.
		string $packageSlug,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public named parameters and DTO fields retain the existing caller contract.
		public string $providerRepositoryId,
		public bool $private,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public named parameters and DTO fields retain the existing caller contract.
		public string $defaultBranch,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public named parameters and DTO fields retain the existing caller contract.
		public ?string $credentialId
	) {
		$this->locator = RepositoryLocator::requireValid( $locator );
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase,WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public named parameters and DTO fields retain the existing caller contract.
		$this->packageSlug = PackageSubdirectory::normalize_slug( $packageSlug );
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Public named parameters and DTO fields retain the existing caller contract.
		if ( strlen( $this->packageSlug ) > 191 ) {
			throw new InvalidArgumentException( 'The provider package slug is invalid.' );
		}
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public named parameters and DTO fields retain the existing caller contract.
		$this->assert_provider_repository_id( $providerRepositoryId );
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public named parameters and DTO fields retain the existing caller contract.
		$this->require_value( $defaultBranch );

		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public named parameters and DTO fields retain the existing caller contract.
		if ( null !== $credentialId ) {
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public named parameters and DTO fields retain the existing caller contract.
			$this->require_value( $credentialId );
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
	public function toArray(): array {
		return array(
			'provider'               => $this->provider->value,
			'locator'                => $this->locator,
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Public named parameters and DTO fields retain the existing caller contract.
			'package_slug'           => $this->packageSlug,
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Public named parameters and DTO fields retain the existing caller contract.
			'provider_repository_id' => $this->providerRepositoryId,
			'private'                => $this->private,
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Public named parameters and DTO fields retain the existing caller contract.
			'default_branch'         => $this->defaultBranch,
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Public named parameters and DTO fields retain the existing caller contract.
			'credential_id'          => $this->credentialId,
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
