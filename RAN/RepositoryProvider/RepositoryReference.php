<?php

declare(strict_types=1);

namespace RAN\RepositoryProvider;

use InvalidArgumentException;

final readonly class RepositoryReference {
	public string $locator;

	public function __construct(
		string $locator,
		public ?string $provider_repository_id,
		public bool $private,
		public ?string $credential_id
	) {
		$this->assert_provider_repository_id( $provider_repository_id );
		$this->locator = RepositoryLocator::require_valid( $locator );
		$this->reject_empty_value( $credential_id );
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public methods retain the existing connected caller contract.
	public static function from_descriptor( RepositoryDescriptor $repository ): self {
		return new self(
			$repository->locator,
			$repository->provider_repository_id,
			$repository->private,
			$repository->credential_id
		);
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase,WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public methods and named parameters retain the existing caller contract.
	public function with_credential( ?string $credential_id ): self {
		return new self(
			$this->locator,
			$this->provider_repository_id,
			$this->private,
			$credential_id
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

	private function require_value( string $value ): void {
		if ( '' === trim( $value ) ) {
			throw new InvalidArgumentException( 'Required repository data cannot be empty.' );
		}
	}

	private function reject_empty_value( ?string $value ): void {
		if ( null !== $value ) {
			$this->require_value( $value );
		}
	}
}
