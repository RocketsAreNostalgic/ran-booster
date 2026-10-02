<?php

declare(strict_types=1);

namespace RANBoosterFixtureProvider;

use RAN\RepositoryProvider\InvalidCredentialInput;
use RAN\RepositoryProvider\ProviderCode;
use RAN\RepositoryProvider\ProviderCredentialPolicy;
use RAN\RepositoryProvider\SubmittedCredentialValidator;
use RuntimeException;

final readonly class CredentialPolicy implements ProviderCredentialPolicy, SubmittedCredentialValidator {

	public function get_provider(): ProviderCode {
		return ProviderCode::parse( 'fixture-provider' );
	}

	public function normalize_credential( array $metadata, mixed $secret ): array {
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

	public function get_constant_names(): array {
		return array();
	}

	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundInImplementedInterfaceBeforeLastUsed -- The fixture implementation of validate_submitted_credential retains the production method contract; these inputs do not affect this controlled result.
	public function validate_submitted_credential( array $metadata, #[\SensitiveParameter] string $secret ): void {
		if ( ! str_starts_with( $secret, 'fixture_' ) ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Core revalidates this conformance fixture's fixed safe copy.
			throw new InvalidCredentialInput(
				InvalidCredentialInput::INVALID_SECRET_SHAPE,
				'Fixture API keys must begin with fixture_.'
			);
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
	}

	public function credential_from_constants( array $constants ): ?array {
		return null;
	}
}
