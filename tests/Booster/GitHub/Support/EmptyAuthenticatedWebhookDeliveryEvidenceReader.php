<?php

declare(strict_types=1);

namespace Tests\Booster\GitHub\Support;

use RAN\RepositoryProvider\AuthenticatedWebhookDeliveryEvidence;
use RAN\RepositoryProvider\AuthenticatedWebhookDeliveryEvidenceReader;

final class EmptyAuthenticatedWebhookDeliveryEvidenceReader implements AuthenticatedWebhookDeliveryEvidenceReader {

	public function latestAuthenticatedDelivery(): ?AuthenticatedWebhookDeliveryEvidence {
		return null;
	}
}
