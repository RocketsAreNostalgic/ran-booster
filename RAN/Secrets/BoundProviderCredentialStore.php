<?php

declare(strict_types=1);

namespace RAN\Secrets;

use RAN\RepositoryProvider\ProviderCode;
use RAN\RepositoryProvider\ProviderCredentialStore;
use RAN\RepositoryProvider\ProviderSecretPolicyCatalog;

/**
 * Restricts an adapter to its own credential namespace, and hands it material
 * only while its provider code has a registered credential policy.
 */
final readonly class BoundProviderCredentialStore implements ProviderCredentialStore {

	public function __construct(
		private SecretsFile $secrets,
		private ProviderCode $provider,
		private ProviderSecretPolicyCatalog $policies
	) {
	}

	public function credential_profiles(): array {
		return $this->secrets->credential_profiles( $this->provider );
	}

	public function credential_material( ?string $id = null ): ?array {
		// Checked on every read: policies register after the factory runs, and a refused registration registers none.
		$this->policies->credential_policy( $this->provider );
		return $this->secrets->credential_material( $this->provider, $id );
	}

	public function has_webhook_profile(): bool {
		return array() !== $this->secrets->webhook_profiles( $this->provider );
	}
}
