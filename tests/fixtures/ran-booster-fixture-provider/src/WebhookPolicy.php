<?php

declare(strict_types=1);

namespace RANBoosterFixtureProvider;

use RAN\RepositoryProvider\ProviderCode;
use RAN\RepositoryProvider\ProviderWebhookPolicy;
use RAN\RepositoryProvider\SignedWebhookVerification;

final readonly class WebhookPolicy implements ProviderWebhookPolicy {

	public function get_provider(): ProviderCode {
		return ProviderCode::parse( 'fixture-provider' );
	}

	public function get_retained_headers(): array {
		return array( 'x-fixture-event', 'x-fixture-signature' );
	}

	public function get_signature_header(): string {
		return 'x-fixture-signature';
	}

	public function normalize_webhook( array $metadata, mixed $secret ): array {
		if ( ! is_string( $metadata['label'] ?? null ) || '' === trim( $metadata['label'] )
			|| ! is_string( $metadata['scope'] ?? null ) || 'repository' !== $metadata['scope']
			|| ! is_string( $metadata['target'] ?? null ) || '' === trim( $metadata['target'] )
			|| ! is_string( $metadata['authority_id'] ?? null ) || '' === trim( $metadata['authority_id'] )
			|| ! is_string( $secret ) || strlen( $secret ) < 32
		) {
			throw new \RuntimeException( 'Fixture webhook configuration is invalid.' );
		}

		return array(
			'label'        => trim( $metadata['label'] ),
			'scope'        => 'repository',
			'target'       => trim( $metadata['target'], " \t\n\r\0\x0B/" ),
			'authority_id' => trim( $metadata['authority_id'] ),
			'secret'       => $secret,
		);
	}

	public function get_constant_names(): array {
		return array();
	}

	public function webhook_from_constants( array $constants ): ?array {
		unset( $constants );

		return null;
	}

	public function authorize_webhook( SignedWebhookVerification $verification, string $repository_authority_id, string $repository ): bool {
		if ( ! $verification->get_provider()->equals( $this->get_provider() ) || '' === $repository_authority_id ) {
			return false;
		}

		foreach ( $verification->get_profiles() as $profile ) {
			if ( 'repository' === $profile['scope']
				&& hash_equals( $profile['authority_id'], $repository_authority_id )
				&& $this->repository_target_matches( $profile['target'], $repository )
			) {
				return true;
			}
		}

		return false;
	}

	public function repository_target_matches( string $target, string $repository_locator ): bool {
		return 0 === strcasecmp( trim( $target, '/' ), trim( $repository_locator, '/' ) );
	}
}
