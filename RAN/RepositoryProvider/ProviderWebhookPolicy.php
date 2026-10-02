<?php

declare(strict_types=1);

namespace RAN\RepositoryProvider;

interface ProviderWebhookPolicy {

	public function get_provider(): ProviderCode;

	/**
	 * @return list<string>
	 */
	public function get_retained_headers(): array;

	public function get_signature_header(): string;

	/**
	 * @param array<string, mixed> $metadata Non-secret webhook metadata.
	 * @return array{label: string, scope: string, target: string, authority_id: string, secret: string}
	 */
	public function normalize_webhook( array $metadata, mixed $secret ): array;

	/**
	 * @return list<string>
	 */
	public function get_constant_names(): array;

	/**
	 * @param array<string, mixed> $constants Raw values for the declared constant names.
	 * @return array{label: string, scope: string, target: string, authority_id: string, secret: string}|null
	 */
	public function webhook_from_constants( array $constants ): ?array;

	public function authorize_webhook(
		SignedWebhookVerification $verification,
		string $repositoryAuthorityId,
		string $repository
	): bool;

	public function repository_target_matches( string $target, string $repositoryLocator ): bool;
}
