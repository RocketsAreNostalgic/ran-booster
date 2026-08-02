<?php

declare(strict_types=1);

namespace RAN\RepositoryProvider;

interface AuthenticatedWebhookDeliveryEvidenceReader {

	public function latestAuthenticatedDelivery( ProviderCode $provider ): ?AuthenticatedWebhookDeliveryEvidence;
}
