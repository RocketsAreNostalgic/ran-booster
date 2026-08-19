<?php

declare(strict_types=1);

namespace RANBoosterFixtureProvider;

use RAN\RepositoryProvider\ProviderCode;
use RAN\RepositoryProvider\ProviderWebhookPolicy;
use RAN\RepositoryProvider\SignedWebhookVerification;

final readonly class WebhookPolicy implements ProviderWebhookPolicy {

	public function getProvider(): ProviderCode {
		return ProviderCode::parse( 'fixture-provider' );
	}

	public function getRetainedHeaders(): array {
		return array( 'x-fixture-event', 'x-fixture-signature' );
	}

	public function getSignatureHeader(): string {
		return 'x-fixture-signature';
	}

	public function normalizeWebhook( array $metadata, mixed $secret ): array {
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

	public function getConstantNames(): array {
		return array();
	}

	public function webhookFromConstants( array $constants ): ?array {
		unset( $constants );

		return null;
	}

	public function authorizeWebhook( SignedWebhookVerification $verification, string $repositoryAuthorityId, string $repository ): bool {
		if ( ! $verification->getProvider()->equals( $this->getProvider() ) || '' === $repositoryAuthorityId ) {
			return false;
		}

		foreach ( $verification->getProfiles() as $profile ) {
			if ( 'repository' === $profile['scope']
				&& hash_equals( $profile['authority_id'], $repositoryAuthorityId )
				&& $this->repositoryTargetMatches( $profile['target'], $repository )
			) {
				return true;
			}
		}

		return false;
	}

	public function repositoryTargetMatches( string $target, string $repositoryLocator ): bool {
		return 0 === strcasecmp( trim( $target, '/' ), trim( $repositoryLocator, '/' ) );
	}
}
