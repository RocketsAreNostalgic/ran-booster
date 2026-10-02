<?php

declare(strict_types=1);

namespace RAN\RepositoryProvider;

use RuntimeException;

final class ProviderSecretPolicyCatalog {
	/** @var array<string, array{credential: ProviderCredentialPolicy|null, webhook: ProviderWebhookPolicy|null}> */
	private array $policies = array();

	public function register(
		ProviderCode $provider,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public provider policy API preserves established method and named-argument contracts.
		?ProviderCredentialPolicy $credential_policy,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public provider policy API preserves established method and named-argument contracts.
		?ProviderWebhookPolicy $webhook_policy
	): void {
		$code = $provider->value;

		if ( isset( $this->policies[ $code ] ) ) {
			throw InvalidProviderPolicy::duplicate_provider();
		}

		try {
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public provider policy API preserves established method and named-argument contracts.
			$credential_provider = null === $credential_policy ? null : $credential_policy->get_provider();
		} catch ( \Throwable ) {
			throw InvalidProviderPolicy::unavailable_credential_policy();
		}

		try {
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public provider policy API preserves established method and named-argument contracts.
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

		$this->policies[ $code ] = array(
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public provider policy API preserves established method and named-argument contracts.
			'credential' => $credential_policy,
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public provider policy API preserves established method and named-argument contracts.
			'webhook'    => $webhook_policy,
		);
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public provider policy API preserves established method and named-argument contracts.
	public function credential_policy( ProviderCode|string $provider ): ProviderCredentialPolicy {
		$provider = $this->normalize_code( $provider );
		$policy   = $this->policies[ $provider->value ]['credential'] ?? null;

		if ( null === $policy ) {
			throw new RuntimeException( 'Credential provider is not supported.' );
		}

		return $policy;
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public provider policy API preserves established method and named-argument contracts.
	public function find_credential_policy( ProviderCode|string $provider ): ?ProviderCredentialPolicy {
		$provider = $this->normalize_code( $provider );

		return $this->policies[ $provider->value ]['credential'] ?? null;
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public provider policy API preserves established method and named-argument contracts.
	public function webhook_policy( ProviderCode|string $provider ): ProviderWebhookPolicy {
		$provider = $this->normalize_code( $provider );
		$policy   = $this->policies[ $provider->value ]['webhook'] ?? null;

		if ( null === $policy ) {
			throw new RuntimeException( 'Webhook provider is not supported.' );
		}

		return $policy;
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public provider policy API preserves established method and named-argument contracts.
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
