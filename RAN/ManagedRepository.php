<?php

declare(strict_types=1);

namespace RAN;

use RAN\RepositoryProvider\ProviderCode;
use RAN\RepositoryProvider\RepositoryReference;

/** Immutable identity and local deployment settings for one managed package. */
final readonly class ManagedRepository {

	public ProviderCode $provider;
	public RepositoryReference $reference;
	public string $branch;

	public function __construct(
		ProviderCode|string $provider,
		string $locator,
		string $providerRepositoryId,
		string $branch,
		bool $private = false,
		?string $credentialId = null
	) {
		$credentialId    = null === $credentialId || '' === trim( $credentialId ) ? null : $credentialId;
		$this->provider  = is_string( $provider ) ? ProviderCode::parse( $provider ) : $provider;
		$this->reference = new RepositoryReference( $locator, $providerRepositoryId, $private, $credentialId );
		$this->branch    = '' === $branch ? 'main' : $branch;
	}

	public function __toString(): string {
		return $this->reference->locator;
	}
}
