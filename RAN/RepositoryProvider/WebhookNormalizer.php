<?php

declare(strict_types=1);

namespace RAN\RepositoryProvider;

use RAN\Provider\ProviderCapability;

interface WebhookNormalizer extends ProviderCapability {

	public function get_webhook_policy(): ProviderWebhookPolicy;

	/**
	 * Report this provider's local webhook configuration and any retained,
	 * authenticated delivery evidence without making a remote request.
	 */
	public function diagnose_webhook_readiness(): ProviderDiagnosticResult;

	public function normalize_webhook( WebhookRequest $request ): WebhookEnvelope;
}
