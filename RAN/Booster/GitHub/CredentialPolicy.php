<?php

declare(strict_types=1);

namespace RAN\Booster\GitHub;

use RAN\RepositoryProvider\InvalidCredentialInput;
use RAN\RepositoryProvider\ProviderCode;
use RAN\RepositoryProvider\ProviderCredentialPolicy;
use RAN\RepositoryProvider\SubmittedCredentialValidator;
use RuntimeException;

final readonly class CredentialPolicy implements ProviderCredentialPolicy, SubmittedCredentialValidator {

	private const TOKEN_CONSTANT  = 'RAN_BOOSTER_GITHUB_TOKEN';
	private const MIN_TOKEN_BYTES = 40;
	private const MAX_TOKEN_BYTES = 255;

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
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Core revalidates the closed reason and safe fixed copy.
			throw new InvalidCredentialInput(
				InvalidCredentialInput::INVALID_CONFIGURATION,
				'Enter the GitHub user or organisation selected as the token\'s resource owner, not an email address.'
			);
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
		return array(
			'label'         => $label,
			'kind'          => $kind,
			'configuration' => array( 'owner' => 'classic' === $kind ? '' : $owner ),
			'secret'        => $secret,
		);
	}

	public function validateSubmittedCredential(
		array $metadata,
		#[\SensitiveParameter] string $secret
	): void {
		$kind = $metadata['kind'] ?? '';
		if ( 'classic' === $kind && ! str_starts_with( $secret, 'ghp_' ) ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Core revalidates the closed reason and safe fixed copy.
			throw new InvalidCredentialInput(
				InvalidCredentialInput::CREDENTIAL_KIND_MISMATCH,
				'Classic personal access tokens must begin with ghp_. Choose Fine-grained personal access token if the token begins with github_pat_.'
			);
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
		if ( 'fine-grained' === $kind && ! str_starts_with( $secret, 'github_pat_' ) ) {
			$message = str_starts_with( $secret, 'ghp_' )
				? 'This token begins with ghp_, which identifies a classic personal access token. Choose Classic personal access token or paste a fine-grained token.'
				: 'Fine-grained personal access tokens must begin with github_pat_. Choose Classic personal access token if the token begins with ghp_.';
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Core revalidates the closed reason and one of two fixed safe messages.
			throw new InvalidCredentialInput( InvalidCredentialInput::CREDENTIAL_KIND_MISMATCH, $message );
		}

		$length = strlen( $secret );
		if ( $length < self::MIN_TOKEN_BYTES
			|| $length > self::MAX_TOKEN_BYTES
			|| 1 !== preg_match( '/\A[A-Za-z0-9_]+\z/D', $secret )
		) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Core revalidates the closed reason and safe fixed copy.
			throw new InvalidCredentialInput(
				InvalidCredentialInput::INVALID_SECRET_SHAPE,
				'Enter a GitHub personal access token containing 40 to 255 letters, numbers, or underscores.'
			);
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
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
