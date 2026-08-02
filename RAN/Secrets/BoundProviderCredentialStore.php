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

	public function credentialProfiles(): array {
		return $this->secrets->credentialProfiles( $this->provider );
	}

	public function credentialMaterial( ?string $id = null ): ?array {
		return $this->secrets->credentialMaterial( $this->provider, $id );
	}

	public function hasWebhookProfile(): bool {
		return array() !== $this->secrets->webhookProfiles( $this->provider );
	}
}
