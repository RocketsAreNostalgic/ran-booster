<?php

declare(strict_types=1);

namespace RAN\RepositoryProvider;

interface ProviderCredentialPolicy {

	public function get_provider(): ProviderCode;

	/**
	 * @param array<string, mixed> $metadata Non-secret credential metadata.
	 * @return array{label: string, kind: string, configuration: array<string, mixed>, secret: string}
	 */
	public function normalize_credential( array $metadata, mixed $secret ): array;

	/**
	 * @return list<string>
	 */
	public function get_constant_names(): array;

	/**
	 * @param array<string, mixed> $constants Raw values for the declared constant names.
	 * @return array{label: string, kind: string, configuration: array<string, mixed>, secret: string}|null
	 */
	public function credential_from_constants( array $constants ): ?array;
}
