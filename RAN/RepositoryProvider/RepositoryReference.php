<?php

declare(strict_types=1);

namespace RAN\RepositoryProvider;

use InvalidArgumentException;

final readonly class RepositoryReference {
	public string $locator;

	public function __construct(
		string $locator,
		public ?string $providerRepositoryId,
		public bool $private,
		public ?string $credentialId
	) {
		$this->assertProviderRepositoryId( $providerRepositoryId );
		$this->locator = RepositoryLocator::requireValid( $locator );
		$this->rejectEmptyValue( $credentialId, 'Credential ID' );
	}

	public static function fromDescriptor( RepositoryDescriptor $repository ): self {
		return new self(
			$repository->locator,
			$repository->providerRepositoryId,
			$repository->private,
			$repository->credentialId
		);
	}

	private function assertProviderRepositoryId( ?string $providerRepositoryId ): void {
		if ( null !== $providerRepositoryId
			&& ( '' === $providerRepositoryId
				|| strlen( $providerRepositoryId ) > 191
				|| preg_match( '/[\x00-\x1F\x7F]/', $providerRepositoryId ) ) ) {
			throw new InvalidArgumentException( 'The provider repository identity is invalid.' );
		}
	}

	private function requireValue( string $value, string $label ): void {
		if ( '' === trim( $value ) ) {
			throw new InvalidArgumentException( 'Required repository data cannot be empty.' );
		}
	}

	private function rejectEmptyValue( ?string $value, string $label ): void {
		if ( null !== $value ) {
			$this->requireValue( $value, $label );
		}
	}
}
