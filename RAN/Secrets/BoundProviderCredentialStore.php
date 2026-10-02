<?php

declare(strict_types=1);

namespace RAN\Secrets;

use RAN\RepositoryProvider\ProviderCode;
use RAN\RepositoryProvider\ProviderCredentialStore;

/**
 * Restricts an adapter to its own credential namespace.
 */
final readonly class BoundProviderCredentialStore implements ProviderCredentialStore {

	public function __construct(
		private SecretsFile $secrets,
		private ProviderCode $provider
	) {
	}

	public function credential_profiles(): array {
		return $this->secrets->credential_profiles( $this->provider );
	}

	public function credential_material( ?string $id = null ): ?array {
		return $this->secrets->credential_material( $this->provider, $id );
	}

	public function has_webhook_profile(): bool {
		return array() !== $this->secrets->webhook_profiles( $this->provider );
	}
}
