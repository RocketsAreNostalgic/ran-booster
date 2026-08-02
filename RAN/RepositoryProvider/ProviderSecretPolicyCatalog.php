<?php

declare(strict_types=1);

namespace RAN\RepositoryProvider;

use RuntimeException;

final class ProviderSecretPolicyCatalog {
	/** @var array<string, array{credential: ProviderCredentialPolicy|null, webhook: ProviderWebhookPolicy|null}> */
	private array $policies = array();

	public function register(
		ProviderCode $provider,
		?ProviderCredentialPolicy $credentialPolicy,
		?ProviderWebhookPolicy $webhookPolicy
	): void {
		$code = $provider->value;

		if ( isset( $this->policies[ $code ] ) ) {
			throw InvalidProviderPolicy::duplicateProvider();
		}

		try {
			$credentialProvider = null === $credentialPolicy ? null : $credentialPolicy->getProvider();
		} catch ( \Throwable ) {
			throw InvalidProviderPolicy::unavailableCredentialPolicy();
		}

		try {
			$webhookProvider = null === $webhookPolicy ? null : $webhookPolicy->getProvider();
		} catch ( \Throwable ) {
			throw InvalidProviderPolicy::unavailableWebhookPolicy();
		}

		if ( null !== $credentialProvider && ! $credentialProvider->equals( $provider ) ) {
			throw InvalidProviderPolicy::mismatchedProvider();
		}

		if ( null !== $webhookProvider && ! $webhookProvider->equals( $provider ) ) {
			throw InvalidProviderPolicy::mismatchedProvider();
		}

		$this->policies[ $code ] = array(
			'credential' => $credentialPolicy,
			'webhook'    => $webhookPolicy,
		);
	}

	public function credentialPolicy( ProviderCode|string $provider ): ProviderCredentialPolicy {
		$provider = $this->normalizeCode( $provider );
		$policy   = $this->policies[ $provider->value ]['credential'] ?? null;

		if ( null === $policy ) {
			throw new RuntimeException( 'Credential provider is not supported.' );
		}

		return $policy;
	}

	public function findCredentialPolicy( ProviderCode|string $provider ): ?ProviderCredentialPolicy {
		$provider = $this->normalizeCode( $provider );

		return $this->policies[ $provider->value ]['credential'] ?? null;
	}

	public function webhookPolicy( ProviderCode|string $provider ): ProviderWebhookPolicy {
		$provider = $this->normalizeCode( $provider );
		$policy   = $this->policies[ $provider->value ]['webhook'] ?? null;

		if ( null === $policy ) {
			throw new RuntimeException( 'Webhook provider is not supported.' );
		}

		return $policy;
	}

	public function findWebhookPolicy( ProviderCode|string $provider ): ?ProviderWebhookPolicy {
		$provider = $this->normalizeCode( $provider );

		return $this->policies[ $provider->value ]['webhook'] ?? null;
	}

	private function normalizeCode( ProviderCode|string $provider ): ProviderCode {
		try {
			return $provider instanceof ProviderCode ? $provider : ProviderCode::parse( $provider );
		} catch ( InvalidProviderCode ) {
			throw new RuntimeException( 'Credential provider is not supported.' );
		}
	}
}
