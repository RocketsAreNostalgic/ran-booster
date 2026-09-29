<?php

declare(strict_types=1);

namespace RAN\RepositoryProvider;

use InvalidArgumentException;

final readonly class RepositoryReference {
	public string $locator;

	public function __construct(
		string $locator,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public named parameters and DTO fields retain the existing caller contract.
		public ?string $providerRepositoryId,
		public bool $private,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public named parameters and DTO fields retain the existing caller contract.
		public ?string $credentialId
	) {
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public named parameters and DTO fields retain the existing caller contract.
		$this->assert_provider_repository_id( $providerRepositoryId );
		$this->locator = RepositoryLocator::requireValid( $locator );
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public named parameters and DTO fields retain the existing caller contract.
		$this->reject_empty_value( $credentialId, 'Credential ID' );
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public methods retain the existing connected caller contract.
	public static function fromDescriptor( RepositoryDescriptor $repository ): self {
		return new self(
			$repository->locator,
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Public named parameters and DTO fields retain the existing caller contract.
			$repository->providerRepositoryId,
			$repository->private,
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Public named parameters and DTO fields retain the existing caller contract.
			$repository->credentialId
		);
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase,WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public methods and named parameters retain the existing caller contract.
	public function withCredential( ?string $credentialId ): self {
		return new self(
			$this->locator,
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Public named parameters and DTO fields retain the existing caller contract.
			$this->providerRepositoryId,
			$this->private,
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public named parameters and DTO fields retain the existing caller contract.
			$credentialId
		);
	}

	private function assert_provider_repository_id( ?string $provider_repository_id ): void {
		if ( null !== $provider_repository_id
			&& ( '' === $provider_repository_id
				|| strlen( $provider_repository_id ) > 191
				|| preg_match( '/[\x00-\x1F\x7F]/', $provider_repository_id ) ) ) {
			throw new InvalidArgumentException( 'The provider repository identity is invalid.' );
		}
	}

	private function require_value( string $value, string $label ): void {
		if ( '' === trim( $value ) ) {
			throw new InvalidArgumentException( 'Required repository data cannot be empty.' );
		}
	}

	private function reject_empty_value( ?string $value, string $label ): void {
		if ( null !== $value ) {
			$this->require_value( $value, $label );
		}
	}
}
