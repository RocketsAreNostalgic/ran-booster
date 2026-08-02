<?php

declare(strict_types=1);

namespace RAN\RepositoryProvider;

use InvalidArgumentException;

final readonly class RepositoryLookupRequest {

	public string $locator;

	public ?string $credentialId;

	public bool $publicOnly;

	public function __construct(
		string $locator,
		?string $credentialId = null,
		bool $publicOnly = false
	) {
		if ( null !== $credentialId ) {
			$credentialId = trim( $credentialId );
			if ( '' === $credentialId ) {
				throw new InvalidArgumentException( 'Credential IDs cannot be empty.' );
			}
		}

		$this->locator      = RepositoryLocator::requireValid( $locator );
		$this->credentialId = $credentialId;
		$this->publicOnly   = $publicOnly;
	}
}
