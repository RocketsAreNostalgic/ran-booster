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

	public function get_provider(): ProviderCode {
		return $this->provider;
	}

	public function get_retained_headers(): array {
		return $this->headers;
	}

	public function get_signature_header(): string {
		return 'x-fixture-signature';
	}

	public function normalize_webhook( array $metadata, mixed $secret ): array {
		throw new RuntimeException( 'The inert webhook policy cannot store secrets.' );
	}

	public function get_constant_names(): array {
		return array();
	}

	public function webhook_from_constants( array $constants ): ?array {
		return null;
	}

	public function authorize_webhook( SignedWebhookVerification $verification, string $repository_authority_id, string $repository ): bool {
		return true;
	}

	public function repository_target_matches( string $target, string $repository_locator ): bool {
		return $target === $repository_locator;
	}
}
