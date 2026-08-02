<?php

declare(strict_types=1);

namespace RAN\RepositoryProvider;

use RAN\Provider\ProviderCapability;

/**
 * Supplies the remote webhook-settings URL for a provider-owned repository locator.
 */
interface RepositoryWebhookSettingsLink extends ProviderCapability {

	public function repositoryWebhookSettingsUrl( string $locator ): string;
}
