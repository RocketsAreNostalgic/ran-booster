<?php

declare(strict_types=1);

namespace RAN\RepositoryProvider;

use RuntimeException;

final readonly class GitHubCredentialPolicy implements ProviderCredentialPolicy {

	private const TOKEN_CONSTANT = 'RAN_BOOSTER_GITHUB_TOKEN';

	public function getProvider(): ProviderCode {
		return ProviderCode::parse( 'gh' );
	}

	public function normalizeCredential( array $metadata, #[\SensitiveParameter] mixed $secret ): array {
		$label         = $this->requiredString( $metadata['label'] ?? null, 'Credential label' );
		$kind          = $this->requiredString( $metadata['kind'] ?? null, 'Credential kind' );
		$configuration = $metadata['configuration'] ?? array();
		$secret        = $this->requiredString( $secret, 'Credential secret' );

		if ( ! is_array( $configuration ) ) {
			throw new RuntimeException( 'Credential configuration must be a record.' );
		}

		$this->assertOnlyKeys( $configuration, array( 'owner' ) );
		if ( ! in_array( $kind, array( 'classic', 'fine-grained' ), true ) ) {
			throw new RuntimeException( 'GitHub credential kind must be classic or fine-grained.' );
		}

		$owner = isset( $configuration['owner'] ) && is_string( $configuration['owner'] )
			? trim( $configuration['owner'] )
			: '';

		if ( 'fine-grained' === $kind && ! $this->isOwner( $owner ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Closed reason maps to fixed administrator-safe copy.
			throw new InvalidCredentialInput( InvalidCredentialInput::INVALID_RESOURCE_OWNER );
		}
		if ( 'fine-grained' === $kind && str_starts_with( $secret, 'ghp_' ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Closed reason maps to fixed administrator-safe copy.
			throw new InvalidCredentialInput( InvalidCredentialInput::LOOKS_CLASSIC );
		}

		return array(
			'label'         => $label,
			'kind'          => $kind,
			'configuration' => array( 'owner' => 'classic' === $kind ? '' : $owner ),
			'secret'        => $secret,
		);
	}

	public function getConstantNames(): array {
		return array( self::TOKEN_CONSTANT );
	}

	public function credentialFromConstants( array $constants ): ?array {
		$token = $constants[ self::TOKEN_CONSTANT ] ?? '';
		if ( ! is_string( $token ) || '' === trim( $token ) ) {
			return null;
		}

		return array(
			'label'         => 'Deployment configuration',
			'kind'          => 'classic',
			'configuration' => array( 'owner' => '' ),
			'secret'        => trim( $token ),
		);
	}

	private function requiredString( mixed $value, string $name ): string {
		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Provider policy errors are mapped at the admin boundary.
			throw new RuntimeException( $name . ' must be a non-empty string.' );
		}

		return trim( $value );
	}

	/** @param array<string, mixed> $configuration */
	private function assertOnlyKeys( array $configuration, array $allowed ): void {
		if ( array() !== array_diff( array_keys( $configuration ), $allowed ) ) {
			throw new RuntimeException( 'GitHub credential configuration contains unsupported fields.' );
		}
	}

	private function isOwner( string $owner ): bool {
		return 1 === preg_match( '/^[A-Za-z0-9](?:[A-Za-z0-9_-]{0,62}[A-Za-z0-9])?$/', $owner );
	}
}
