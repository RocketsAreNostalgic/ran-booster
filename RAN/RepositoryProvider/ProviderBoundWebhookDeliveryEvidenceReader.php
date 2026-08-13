<?php

declare(strict_types=1);

namespace RAN\RepositoryProvider;

use Closure;

/** Core-owned adapter that binds an evidence read to one provider. */
final readonly class ProviderBoundWebhookDeliveryEvidenceReader implements AuthenticatedWebhookDeliveryEvidenceReader {

	private Closure $read;

	/** @param callable(ProviderCode): ?AuthenticatedWebhookDeliveryEvidence $read */
	public function __construct( private ProviderCode $provider, callable $read ) {
		$this->read = Closure::fromCallable( $read );
	}

	public function latestAuthenticatedDelivery(): ?AuthenticatedWebhookDeliveryEvidence {
		$evidence = ( $this->read )( $this->provider );

		if ( null !== $evidence && ! $evidence->provider->equals( $this->provider ) ) {
			throw new \RuntimeException( 'Authenticated webhook delivery evidence does not match its provider binding.' );
		}

		return $evidence;
	}
}
