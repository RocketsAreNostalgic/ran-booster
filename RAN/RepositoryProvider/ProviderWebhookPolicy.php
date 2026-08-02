<?php

declare(strict_types=1);

namespace RAN\RepositoryProvider;

interface ProviderWebhookPolicy {

	public function getProvider(): ProviderCode;

	/**
	 * @return list<string>
	 */
	public function getRetainedHeaders(): array;

	public function getSignatureHeader(): string;

	/**
	 * @param array<string, mixed> $metadata Non-secret webhook metadata.
	 * @return array{label: string, scope: string, target: string, authority_id: string, secret: string}
	 */
	public function normalizeWebhook( array $metadata, mixed $secret ): array;

	/**
	 * @return list<string>
	 */
	public function getConstantNames(): array;

	/**
	 * @param array<string, mixed> $constants Raw values for the declared constant names.
	 * @return array{label: string, scope: string, target: string, authority_id: string, secret: string}|null
	 */
	public function webhookFromConstants( array $constants ): ?array;

	public function authorizeWebhook(
		SignedWebhookVerification $verification,
		string $repositoryAuthorityId,
		string $repository
	): bool;

	public function repositoryTargetMatches( string $target, string $repositoryLocator ): bool;
}
