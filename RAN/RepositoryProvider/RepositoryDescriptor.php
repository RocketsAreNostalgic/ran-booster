<?php

declare(strict_types=1);

namespace RAN\RepositoryProvider;

use InvalidArgumentException;
use RAN\PackageSubdirectory;

final readonly class RepositoryDescriptor {
	public string $locator;
	// phpcs:ignore WordPress.NamingConventions.ValidVariableName.PropertyNotSnakeCase -- Public named parameters and DTO fields retain the existing caller contract.
	public string $package_slug;

	public function __construct(
		public ProviderCode $provider,
		string $locator,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public named parameters and DTO fields retain the existing caller contract.
		string $package_slug,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public named parameters and DTO fields retain the existing caller contract.
		public string $provider_repository_id,
		public bool $private,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public named parameters and DTO fields retain the existing caller contract.
		public string $default_branch,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public named parameters and DTO fields retain the existing caller contract.
		public ?string $credential_id
	) {
		$this->locator = RepositoryLocator::require_valid( $locator );
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase,WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public named parameters and DTO fields retain the existing caller contract.
		$this->package_slug = PackageSubdirectory::normalize_slug( $package_slug );
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Public named parameters and DTO fields retain the existing caller contract.
		if ( strlen( $this->package_slug ) > 191 ) {
			throw new InvalidArgumentException( 'The provider package slug is invalid.' );
		}
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public named parameters and DTO fields retain the existing caller contract.
		$this->assert_provider_repository_id( $provider_repository_id );
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public named parameters and DTO fields retain the existing caller contract.
		$this->require_value( $default_branch );

		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public named parameters and DTO fields retain the existing caller contract.
		if ( null !== $credential_id ) {
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public named parameters and DTO fields retain the existing caller contract.
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
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Public named parameters and DTO fields retain the existing caller contract.
			'package_slug'           => $this->package_slug,
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Public named parameters and DTO fields retain the existing caller contract.
			'provider_repository_id' => $this->provider_repository_id,
			'private'                => $this->private,
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Public named parameters and DTO fields retain the existing caller contract.
			'default_branch'         => $this->default_branch,
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Public named parameters and DTO fields retain the existing caller contract.
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
