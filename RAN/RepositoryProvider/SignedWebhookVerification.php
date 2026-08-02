<?php

declare(strict_types=1);

namespace RAN\RepositoryProvider;

use InvalidArgumentException;

final readonly class SignedWebhookVerification {

	/**
	 * @param list<array{id: string, scope: string, target: string, authority_id: string}> $profiles Matched non-secret profiles.
	 */
	public function __construct(
		private ProviderCode $provider,
		private array $profiles
	) {
		if ( array() === $profiles ) {
			throw new InvalidArgumentException( 'Signed webhook verification requires a matched profile.' );
		}
	}

	public function getProvider(): ProviderCode {
		return $this->provider;
	}

	/**
	 * @return list<array{id: string, scope: string, target: string, authority_id: string}>
	 */
	public function getProfiles(): array {
		return $this->profiles;
	}
}
