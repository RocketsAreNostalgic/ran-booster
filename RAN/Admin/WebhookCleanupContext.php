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
		private string $packageType,
		private string $packageIdentifier,
		private string $providerCode,
		private string $repositoryId,
		private string $repository,
		private string $localSecretCoverage,
		private bool $evidenceAvailable,
		private bool $branchEvidenceAvailable,
		private array $branchPackageReferences,
		private string $providerWebhooksUrl,
		private string $secretsUrl,
		private string $documentationUrl,
		private string $returnUrl
	) {
		if ( ! in_array( $this->packageType, array( 'plugin', 'theme' ), true )
			|| '' === trim( $this->packageIdentifier )
			|| strlen( $this->packageIdentifier ) > 255
			|| '' === trim( $this->repositoryId )
			|| strlen( $this->repositoryId ) > 191
			|| '' === trim( $this->repository )
			|| strlen( $this->repository ) > 255
			|| ! in_array( $this->localSecretCoverage, array( 'repository', 'shared', 'none', 'unknown' ), true )
		) {
			throw new InvalidArgumentException( 'Webhook cleanup contexts require bounded package evidence.' );
		}
		ProviderCode::parse( $this->providerCode );

		foreach ( $this->branchPackageReferences as $reference ) {
			if ( ! is_string( $reference ) || '' === trim( $reference ) || strlen( $reference ) > 255 ) {
				throw new InvalidArgumentException( 'Webhook cleanup package references must be bounded.' );
			}
		}
		foreach ( array( $this->providerWebhooksUrl, $this->secretsUrl, $this->documentationUrl, $this->returnUrl ) as $url ) {
			if ( '' !== $url && ! $this->safeUrl( $url ) ) {
				throw new InvalidArgumentException( 'Webhook cleanup links must be safe absolute URLs.' );
			}
		}
	}

	public function packageType(): string {
		return $this->packageType;
	}

	public function packageIdentifier(): string {
		return $this->packageIdentifier;
	}

	public function providerCode(): string {
		return $this->providerCode;
	}

	public function repositoryId(): string {
		return $this->repositoryId;
	}

	public function repository(): string {
		return $this->repository;
	}

	public function localSecretCoverage(): string {
		return $this->localSecretCoverage;
	}

	public function evidenceAvailable(): bool {
		return $this->evidenceAvailable;
	}

	public function branchEvidenceAvailable(): bool {
		return $this->branchEvidenceAvailable;
	}

	/** @return list<string> */
	public function branchPackageReferences(): array {
		return $this->branchPackageReferences;
	}

	public function cleanupAllowed(): bool {
		return $this->branchEvidenceAvailable && array() === $this->branchPackageReferences;
	}

	public function providerWebhooksUrl(): string {
		return $this->providerWebhooksUrl;
	}

	public function secretsUrl(): string {
		return $this->secretsUrl;
	}

	public function documentationUrl(): string {
		return $this->documentationUrl;
	}

	public function returnUrl(): string {
		return $this->returnUrl;
	}

	private function safeUrl( string $url ): bool {
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
