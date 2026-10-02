<?php

declare(strict_types=1);

namespace RAN\RepositoryProvider;

use InvalidArgumentException;

final readonly class RepositoryLookupRequest {

	public string $locator;

	public ?string $credential_id;

	public bool $public_only;

	public function __construct(
		string $locator,
		?string $credential_id = null,
		bool $public_only = false
	) {
		if ( null !== $credential_id ) {
			$credential_id = trim( $credential_id );
			if ( '' === $credential_id ) {
				throw new InvalidArgumentException( 'Credential IDs cannot be empty.' );
			}
		}

		$this->locator       = RepositoryLocator::require_valid( $locator );
		$this->credential_id = $credential_id;
		$this->public_only   = $public_only;
	}
}
