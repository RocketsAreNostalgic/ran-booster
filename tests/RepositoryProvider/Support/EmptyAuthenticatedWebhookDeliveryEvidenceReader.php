<?php

declare(strict_types=1);

namespace RAN\Tests\RepositoryProvider\Support;

use RAN\RepositoryProvider\AuthenticatedWebhookDeliveryEvidence;
use RAN\RepositoryProvider\AuthenticatedWebhookDeliveryEvidenceReader;

final class EmptyAuthenticatedWebhookDeliveryEvidenceReader implements AuthenticatedWebhookDeliveryEvidenceReader {

	public function latest_authenticated_delivery(): ?AuthenticatedWebhookDeliveryEvidence {
		return null;
	}
}
