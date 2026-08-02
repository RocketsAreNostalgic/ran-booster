<?php

declare(strict_types=1);

namespace RAN\RepositoryProvider;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Safe retained proof that Booster authenticated one provider delivery.
 */
final readonly class AuthenticatedWebhookDeliveryEvidence {

	public function __construct(
		public ProviderCode $provider,
		public string $receivedAt,
		public bool $matchedManagedPackage
	) {
		$received = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $this->receivedAt );
		if ( false === $received || $received->format( 'Y-m-d H:i:s' ) !== $this->receivedAt ) {
			throw new InvalidArgumentException( 'Authenticated webhook delivery evidence requires a valid timestamp.' );
		}
	}
}
