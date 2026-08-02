<?php

declare(strict_types=1);

namespace Tests\RepositoryProvider\Support;

use RAN\RepositoryProvider\ProviderCode;
use RAN\RepositoryProvider\ProviderCredentialPolicy;
use RuntimeException;

final readonly class ExternalFixtureCredentialPolicy implements ProviderCredentialPolicy {

	public function __construct( private ProviderCode $code ) {
	}

	public function getProvider(): ProviderCode {
		return $this->code;
	}

	public function normalizeCredential( array $metadata, mixed $secret ): array {
		$configuration = $metadata['configuration'] ?? null;

		if ( 'api-key' !== ( $metadata['kind'] ?? null )
			|| ! is_string( $metadata['label'] ?? null )
			|| '' === trim( $metadata['label'] )
			|| ! is_array( $configuration )
			|| array( 'tenant' ) !== array_keys( $configuration )
			|| ! is_string( $configuration['tenant'] )
			|| 1 !== preg_match( '/\A[a-z0-9-]{2,32}\z/D', $configuration['tenant'] )
			|| ! is_string( $secret )
			|| '' === trim( $secret )
		) {
			throw new RuntimeException( 'Fixture credentials are invalid.' );
		}

		return array(
			'label'         => trim( $metadata['label'] ),
			'kind'          => 'api-key',
			'configuration' => array( 'tenant' => $configuration['tenant'] ),
			'secret'        => trim( $secret ),
		);
	}

	public function getConstantNames(): array {
		return array();
	}

	public function credentialFromConstants( array $constants ): ?array {
		return null;
	}
}
