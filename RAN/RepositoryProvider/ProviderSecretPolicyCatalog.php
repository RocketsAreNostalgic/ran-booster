<?php

declare(strict_types=1);

namespace RAN\RepositoryProvider;

use RuntimeException;

final class ProviderSecretPolicyCatalog {
	/** @var array<string, array{credential: ProviderCredentialPolicy|null, webhook: ProviderWebhookPolicy|null}> */
	private array $policies = array();

	/** @param callable(): void|null $before_publish Host guard after every provider callback, before any policy becomes visible. */
	public function register(
		ProviderCode $provider,
		?ProviderCredentialPolicy $credential_policy,
		?ProviderWebhookPolicy $webhook_policy,
		?callable $before_publish = null
	): void {
		$code = $provider->value;

		if ( isset( $this->policies[ $code ] ) ) {
			throw InvalidProviderPolicy::duplicate_provider();
		}

		try {
			$credential_provider = null === $credential_policy ? null : $credential_policy->get_provider();
		} catch ( \Throwable ) {
			throw InvalidProviderPolicy::unavailable_credential_policy();
		}

		try {
			$webhook_provider = null === $webhook_policy ? null : $webhook_policy->get_provider();
		} catch ( \Throwable ) {
			throw InvalidProviderPolicy::unavailable_webhook_policy();
		}

		if ( null !== $credential_provider && ! $credential_provider->equals( $provider ) ) {
			throw InvalidProviderPolicy::mismatched_provider();
		}

		if ( null !== $webhook_provider && ! $webhook_provider->equals( $provider ) ) {
			throw InvalidProviderPolicy::mismatched_provider();
		}

		if ( null !== $before_publish ) {
			$before_publish();
		}

		$this->policies[ $code ] = array(
			'credential' => $credential_policy,
			'webhook'    => $webhook_policy,
		);
	}


	public function credential_policy( ProviderCode|string $provider ): ProviderCredentialPolicy {
		$provider = $this->normalize_code( $provider );
		$policy   = $this->policies[ $provider->value ]['credential'] ?? null;

		if ( null === $policy ) {
			throw new RuntimeException( 'Credential provider is not supported.' );
		}

		return $policy;
	}


	public function find_credential_policy( ProviderCode|string $provider ): ?ProviderCredentialPolicy {
		$provider = $this->normalize_code( $provider );

		return $this->policies[ $provider->value ]['credential'] ?? null;
	}


	public function webhook_policy( ProviderCode|string $provider ): ProviderWebhookPolicy {
		$provider = $this->normalize_code( $provider );
		$policy   = $this->policies[ $provider->value ]['webhook'] ?? null;

		if ( null === $policy ) {
			throw new RuntimeException( 'Webhook provider is not supported.' );
		}

		return $policy;
	}


	public function find_webhook_policy( ProviderCode|string $provider ): ?ProviderWebhookPolicy {
		$provider = $this->normalize_code( $provider );

		return $this->policies[ $provider->value ]['webhook'] ?? null;
	}

	private function normalize_code( ProviderCode|string $provider ): ProviderCode {
		try {
			return $provider instanceof ProviderCode ? $provider : ProviderCode::parse( $provider );
		} catch ( InvalidProviderCode ) {
			throw new RuntimeException( 'Credential provider is not supported.' );
		}
	}
}
