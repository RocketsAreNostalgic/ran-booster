<?php

declare(strict_types=1);

namespace Tests\RepositoryProvider\Support;

use RAN\RepositoryProvider\ProviderCode;
use RAN\RepositoryProvider\ProviderWebhookPolicy;
use RAN\RepositoryProvider\SignedWebhookVerification;
use RuntimeException;

final readonly class InertWebhookPolicy implements ProviderWebhookPolicy {

	/** @param list<string> $headers */
	public function __construct( private ProviderCode $provider, private array $headers = array() ) {
	}

	public function getProvider(): ProviderCode {
		return $this->provider;
	}

	public function getRetainedHeaders(): array {
		return $this->headers;
	}

	public function getSignatureHeader(): string {
		return 'x-fixture-signature';
	}

	public function normalizeWebhook( array $metadata, mixed $secret ): array {
		throw new RuntimeException( 'The inert webhook policy cannot store secrets.' );
	}

	public function getConstantNames(): array {
		return array();
	}

	public function webhookFromConstants( array $constants ): ?array {
		return null;
	}

	public function authorizeWebhook( SignedWebhookVerification $verification, string $repositoryAuthorityId, string $repository ): bool {
		return true;
	}

	public function repositoryTargetMatches( string $target, string $repositoryLocator ): bool {
		return $target === $repositoryLocator;
	}
}
