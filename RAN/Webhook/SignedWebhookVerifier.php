<?php

declare(strict_types=1);

namespace RAN\Webhook;

use RAN\RepositoryProvider\ProviderWebhookPolicy;
use RAN\RepositoryProvider\SignedWebhookVerification;
use RAN\RepositoryProvider\WebhookRejected;
use RAN\RepositoryProvider\WebhookRequest;
use RAN\Secrets\SecretsFile;
use Throwable;

final readonly class SignedWebhookVerifier {

	private const MIN_SECRET_BYTES = 32;
	private const MAX_SECRET_BYTES = 512;

	public function __construct( private SecretsFile $secrets ) {
	}

	public function verify( WebhookRequest $request, ProviderWebhookPolicy $policy ): SignedWebhookVerification {
		$signature = $this->signature( $request, $policy );

		try {
			$materials = $this->secrets->webhookMaterials( $request->getProvider() );
		} catch ( Throwable ) {
			throw $this->authenticationFailed();
		}

		if ( array() === $materials || count( $materials ) > SecretsFile::MAX_WEBHOOK_PROFILES ) {
			throw $this->authenticationFailed();
		}

		$matches = array();
		foreach ( $materials as $id => $material ) {
			$profile  = $this->profile( $id, $material );
			$expected = 'sha256=' . hash_hmac( 'sha256', $request->getBody(), $profile['secret'] );
			if ( hash_equals( $expected, $signature ) ) {
				unset( $profile['secret'] );
				$matches[] = $profile;
			}
		}

		if ( array() === $matches ) {
			throw $this->authenticationFailed();
		}
		usort(
			$matches,
			static function ( array $left, array $right ): int {
				$priority = array(
					'repository' => 0,
					'owner'      => 1,
				);

				$scopeOrder = ( $priority[ $left['scope'] ] ?? 2 ) <=> ( $priority[ $right['scope'] ] ?? 2 );

				return 0 !== $scopeOrder ? $scopeOrder : strcmp( $left['id'], $right['id'] );
			}
		);

		return new SignedWebhookVerification( $request->getProvider(), $matches );
	}

	private function signature( WebhookRequest $request, ProviderWebhookPolicy $policy ): string {
		$values = $request->getRawHeaderValues( $policy->getSignatureHeader() );
		if ( 1 !== count( $values ) || 1 !== preg_match( '/\Asha256=[a-f0-9]{64}\z/D', $values[0] ) ) {
			throw $this->authenticationFailed();
		}

		return $values[0];
	}

	/**
	 * @return array{id: string, scope: string, target: string, authority_id: string, secret: string}
	 */
	private function profile( mixed $id, mixed $material ): array {
		if ( ! is_string( $id ) || '' === $id || ! is_array( $material )
			|| ! is_string( $material['scope'] ?? null )
			|| ! is_string( $material['target'] ?? null )
			|| ! is_string( $material['secret'] ?? null )
		) {
			throw $this->authenticationFailed();
		}

		$secret = $material['secret'];
		if ( strlen( $secret ) < self::MIN_SECRET_BYTES
			|| strlen( $secret ) > self::MAX_SECRET_BYTES
			|| 1 === preg_match( '/[\x00-\x1F\x7F]/', $secret )
		) {
			throw $this->authenticationFailed();
		}

		$authorityId = $material['authority_id'] ?? '';
		if ( ! is_string( $authorityId ) ) {
			throw $this->authenticationFailed();
		}

		return array(
			'id'           => $id,
			'scope'        => $material['scope'],
			'target'       => $material['target'],
			'authority_id' => $authorityId,
			'secret'       => $secret,
		);
	}

	private function authenticationFailed(): WebhookRejected {
		return new WebhookRejected( 401, 'Webhook authentication failed.' );
	}
}
