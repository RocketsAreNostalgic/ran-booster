<?php

declare(strict_types=1);

namespace RAN\RepositoryProvider;

/**
 * Reads authenticated delivery evidence already bound to the registering provider.
 */
interface AuthenticatedWebhookDeliveryEvidenceReader {

	public function latestAuthenticatedDelivery(): ?AuthenticatedWebhookDeliveryEvidence;
}
