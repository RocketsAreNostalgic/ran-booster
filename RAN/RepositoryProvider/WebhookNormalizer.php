<?php

declare(strict_types=1);

namespace RAN\RepositoryProvider;

use RAN\Provider\ProviderCapability;

interface WebhookNormalizer extends ProviderCapability {

	public function getWebhookPolicy(): ProviderWebhookPolicy;

	/**
	 * Report this provider's local webhook configuration and any retained,
	 * authenticated delivery evidence without making a remote request.
	 */
	public function diagnoseWebhookReadiness(): ProviderDiagnosticResult;

	public function normalizeWebhook( WebhookRequest $request ): WebhookEnvelope;
}
