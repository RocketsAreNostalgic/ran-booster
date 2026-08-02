<?php

declare(strict_types=1);

namespace RAN\RepositoryProvider;

interface ProviderCredentialPolicy {

	public function getProvider(): ProviderCode;

	/**
	 * @param array<string, mixed> $metadata Non-secret credential metadata.
	 * @return array{label: string, kind: string, configuration: array<string, mixed>, secret: string}
	 */
	public function normalizeCredential( array $metadata, mixed $secret ): array;

	/**
	 * @return list<string>
	 */
	public function getConstantNames(): array;

	/**
	 * @param array<string, mixed> $constants Raw values for the declared constant names.
	 * @return array{label: string, kind: string, configuration: array<string, mixed>, secret: string}|null
	 */
	public function credentialFromConstants( array $constants ): ?array;
}
