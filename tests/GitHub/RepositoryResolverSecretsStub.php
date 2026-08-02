<?php

declare(strict_types=1);

namespace Tests\GitHub;

use RAN\RepositoryProvider\ProviderCredentialStore;

final class RepositoryResolverSecretsStub implements ProviderCredentialStore {

	/** @var list<string|null> */
	public array $lookups = array();

	/**
	 * @param array<string, string|array{token: string, kind?: string, owner?: string}> $tokens
	 */
	public function __construct( private array $tokens = array() ) {
	}

	public function credentialProfiles(): array {
		return array();
	}

	public function credentialMaterial( ?string $id = null ): ?array {
		$this->lookups[] = $id;

		if ( null === $id || ! isset( $this->tokens[ $id ] ) ) {
			return null;
		}

		$stored = $this->tokens[ $id ];
		$token  = is_array( $stored ) ? $stored['token'] : $stored;
		$kind   = is_array( $stored ) && is_string( $stored['kind'] ?? null ) ? $stored['kind'] : 'classic';
		$owner  = is_array( $stored ) && is_string( $stored['owner'] ?? null ) ? $stored['owner'] : '';

		return array(
			'id'            => $id,
			'provider'      => 'gh',
			'label'         => 'Test credential',
			'kind'          => $kind,
			'configuration' => array( 'owner' => $owner ),
			'secret'        => $token,
			'source'        => 'test',
			'immutable'     => false,
		);
	}

	public function hasWebhookProfile(): bool {
		return false;
	}
}
