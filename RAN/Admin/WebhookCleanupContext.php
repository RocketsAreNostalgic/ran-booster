<?php

declare(strict_types=1);

namespace RAN\Admin;

use InvalidArgumentException;
use RAN\RepositoryProvider\ProviderCode;

/**
 * Bounded, non-secret package context for optional retained-webhook cleanup UI.
 */
final readonly class WebhookCleanupContext {

	/**
	 * @param list<string> $branchPackageReferences
	 */
	public function __construct(
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- retain public named-parameter names.
		private string $packageType,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- retain public named-parameter names.
		private string $packageIdentifier,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- retain public named-parameter names.
		private string $providerCode,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- retain public named-parameter names.
		private string $repositoryId,
		private string $repository,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- retain public named-parameter names.
		private string $localSecretCoverage,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- retain public named-parameter names.
		private bool $evidenceAvailable,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- retain public named-parameter names.
		private bool $branchEvidenceAvailable,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- retain public named-parameter names.
		private array $branchPackageReferences,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- retain public named-parameter names.
		private string $providerWebhooksUrl,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- retain public named-parameter names.
		private string $secretsUrl,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- retain public named-parameter names.
		private string $documentationUrl,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- retain public named-parameter names.
		private string $returnUrl
	) {
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- retain promoted constructor properties.
		if ( ! in_array( $this->packageType, array( 'plugin', 'theme' ), true )
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- retain promoted constructor properties.
			|| '' === trim( $this->packageIdentifier )
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- retain promoted constructor properties.
			|| strlen( $this->packageIdentifier ) > 255
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- retain promoted constructor properties.
			|| '' === trim( $this->repositoryId )
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- retain promoted constructor properties.
			|| strlen( $this->repositoryId ) > 191
			|| '' === trim( $this->repository )
			|| strlen( $this->repository ) > 255
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- retain promoted constructor properties.
			|| ! in_array( $this->localSecretCoverage, array( 'repository', 'shared', 'none', 'unknown' ), true )
		) {
			throw new InvalidArgumentException( 'Webhook cleanup contexts require bounded package evidence.' );
		}
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- retain promoted constructor properties.
		ProviderCode::parse( $this->providerCode );

		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- retain promoted constructor properties.
		foreach ( $this->branchPackageReferences as $reference ) {
			if ( ! is_string( $reference ) || '' === trim( $reference ) || strlen( $reference ) > 255 ) {
				throw new InvalidArgumentException( 'Webhook cleanup package references must be bounded.' );
			}
		}
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- retain promoted constructor properties.
		foreach ( array( $this->providerWebhooksUrl, $this->secretsUrl, $this->documentationUrl, $this->returnUrl ) as $url ) {
			if ( '' !== $url && ! $this->safe_url( $url ) ) {
				throw new InvalidArgumentException( 'Webhook cleanup links must be safe absolute URLs.' );
			}
		}
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Retain the public/protected caller contract.
	public function packageType(): string {
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- retain promoted constructor properties.
		return $this->packageType;
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Retain the public/protected caller contract.
	public function packageIdentifier(): string {
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- retain promoted constructor properties.
		return $this->packageIdentifier;
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Retain the public/protected caller contract.
	public function providerCode(): string {
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- retain promoted constructor properties.
		return $this->providerCode;
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Retain the public/protected caller contract.
	public function repositoryId(): string {
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- retain promoted constructor properties.
		return $this->repositoryId;
	}

	public function repository(): string {
		return $this->repository;
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Retain the public/protected caller contract.
	public function localSecretCoverage(): string {
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- retain promoted constructor properties.
		return $this->localSecretCoverage;
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Retain the public/protected caller contract.
	public function evidenceAvailable(): bool {
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- retain promoted constructor properties.
		return $this->evidenceAvailable;
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Retain the public/protected caller contract.
	public function branchEvidenceAvailable(): bool {
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- retain promoted constructor properties.
		return $this->branchEvidenceAvailable;
	}

	/** @return list<string> */
	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Retain the public/protected caller contract.
	public function branchPackageReferences(): array {
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- retain promoted constructor properties.
		return $this->branchPackageReferences;
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Retain the public/protected caller contract.
	public function cleanupAllowed(): bool {
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- retain promoted constructor properties.
		return $this->branchEvidenceAvailable && array() === $this->branchPackageReferences;
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Retain the public/protected caller contract.
	public function providerWebhooksUrl(): string {
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- retain promoted constructor properties.
		return $this->providerWebhooksUrl;
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Retain the public/protected caller contract.
	public function secretsUrl(): string {
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- retain promoted constructor properties.
		return $this->secretsUrl;
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Retain the public/protected caller contract.
	public function documentationUrl(): string {
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- retain promoted constructor properties.
		return $this->documentationUrl;
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Retain the public/protected caller contract.
	public function returnUrl(): string {
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- retain promoted constructor properties.
		return $this->returnUrl;
	}

	private function safe_url( string $url ): bool {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Value object may load before WordPress URL helpers.
		$parts = parse_url( $url );

		$fragment = is_array( $parts ) && isset( $parts['fragment'] ) ? $parts['fragment'] : '';

		return is_array( $parts )
			&& isset( $parts['scheme'], $parts['host'] )
			&& in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true )
			&& ! isset( $parts['user'] )
			&& ! isset( $parts['pass'] )
			&& ( '' === $fragment || 1 === preg_match( '/^[A-Za-z][A-Za-z0-9_-]{0,127}$/', $fragment ) );
	}
}
