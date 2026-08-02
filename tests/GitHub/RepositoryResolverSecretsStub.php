<?php

declare(strict_types=1);

namespace Tests\GitHub;

use RAN\RepositoryProvider\ProviderCode;
use RAN\Secrets\SecretsFile;

final class RepositoryResolverSecretsStub extends SecretsFile {

	/** @var list<array{string, string|null}> */
	public array $lookups = array();

	/**
	 * @param array<string, string|array{token: string, kind?: string, owner?: string}> $tokens
	 */
	public function __construct( private array $tokens = array() ) {
	}

	public function credentialMaterial( ProviderCode|string $provider, ?string $id = null ): ?array {
		$providerCode    = $provider instanceof ProviderCode ? $provider->value : $provider;
		$this->lookups[] = array( $providerCode, $id );

		if ( ProviderCode::parse( 'gh' )->value !== $providerCode || null === $id || ! isset( $this->tokens[ $id ] ) ) {
			return null;
		}

		$stored = $this->tokens[ $id ];
		$token  = is_array( $stored ) ? $stored['token'] : $stored;
		$kind   = is_array( $stored ) && is_string( $stored['kind'] ?? null ) ? $stored['kind'] : 'classic';
		$owner  = is_array( $stored ) && is_string( $stored['owner'] ?? null ) ? $stored['owner'] : '';

		return array(
			'id'            => $id,
			'provider'      => ProviderCode::parse( 'gh' )->value,
			'label'         => 'Test credential',
			'kind'          => $kind,
			'configuration' => array( 'owner' => $owner ),
			'secret'        => $token,
			'source'        => 'test',
			'immutable'     => false,
		);
	}
}
