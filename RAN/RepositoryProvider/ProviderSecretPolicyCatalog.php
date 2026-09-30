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
		?ProviderCredentialPolicy $credentialPolicy,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public provider policy API preserves established method and named-argument contracts.
		?ProviderWebhookPolicy $webhookPolicy
	): void {
		$code = $provider->value;

		if ( isset( $this->policies[ $code ] ) ) {
			throw InvalidProviderPolicy::duplicateProvider();
		}

		try {
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public provider policy API preserves established method and named-argument contracts.
			$credential_provider = null === $credentialPolicy ? null : $credentialPolicy->getProvider();
		} catch ( \Throwable ) {
			throw InvalidProviderPolicy::unavailableCredentialPolicy();
		}

		try {
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public provider policy API preserves established method and named-argument contracts.
			$webhook_provider = null === $webhookPolicy ? null : $webhookPolicy->getProvider();
		} catch ( \Throwable ) {
			throw InvalidProviderPolicy::unavailableWebhookPolicy();
		}

		if ( null !== $credential_provider && ! $credential_provider->equals( $provider ) ) {
			throw InvalidProviderPolicy::mismatchedProvider();
		}

		if ( null !== $webhook_provider && ! $webhook_provider->equals( $provider ) ) {
			throw InvalidProviderPolicy::mismatchedProvider();
		}

		$this->policies[ $code ] = array(
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public provider policy API preserves established method and named-argument contracts.
			'credential' => $credentialPolicy,
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public provider policy API preserves established method and named-argument contracts.
			'webhook'    => $webhookPolicy,
		);
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public provider policy API preserves established method and named-argument contracts.
	public function credentialPolicy( ProviderCode|string $provider ): ProviderCredentialPolicy {
		$provider = $this->normalize_code( $provider );
		$policy   = $this->policies[ $provider->value ]['credential'] ?? null;

		if ( null === $policy ) {
			throw new RuntimeException( 'Credential provider is not supported.' );
		}

		return $policy;
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public provider policy API preserves established method and named-argument contracts.
	public function findCredentialPolicy( ProviderCode|string $provider ): ?ProviderCredentialPolicy {
		$provider = $this->normalize_code( $provider );

		return $this->policies[ $provider->value ]['credential'] ?? null;
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public provider policy API preserves established method and named-argument contracts.
	public function webhookPolicy( ProviderCode|string $provider ): ProviderWebhookPolicy {
		$provider = $this->normalize_code( $provider );
		$policy   = $this->policies[ $provider->value ]['webhook'] ?? null;

		if ( null === $policy ) {
			throw new RuntimeException( 'Webhook provider is not supported.' );
		}

		return $policy;
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public provider policy API preserves established method and named-argument contracts.
	public function findWebhookPolicy( ProviderCode|string $provider ): ?ProviderWebhookPolicy {
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
