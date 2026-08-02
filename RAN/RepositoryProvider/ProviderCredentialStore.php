<?php

declare(strict_types=1);

namespace RAN\RepositoryProvider;

/**
 * Read-only credentials bound to the provider being registered.
 */
interface ProviderCredentialStore extends ProviderWebhookProfileReader {

	/**
	 * Return display-safe credential profiles for this provider.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function credentialProfiles(): array;

	/**
	 * Return one secret-bearing credential, or the provider default when ID is null.
	 *
	 * @return array<string, mixed>|null
	 */
	public function credentialMaterial( ?string $id = null ): ?array;
}
