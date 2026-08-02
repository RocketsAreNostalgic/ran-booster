<?php

declare(strict_types=1);

namespace RAN\RepositoryProvider;

/**
 * Read-only, provider-bound webhook configuration presence for diagnostics.
 *
 * This deliberately reveals neither webhook records nor signing material.
 */
interface ProviderWebhookProfileReader {

	public function hasWebhookProfile(): bool;
}
