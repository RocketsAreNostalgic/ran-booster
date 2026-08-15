<?php

declare(strict_types=1);

namespace RAN\RepositoryProvider\Admin;

/**
 * Provider-owned presentation for Booster's optional webhook-management seam.
 *
 * Core renders the shared repository surface and reserved disabled action. A
 * compatible provider adapter may hydrate that action and append bounded status
 * details.
 */
final readonly class ProviderWebhookManagementMetadata {

	public string $actionKey;
	public string $actionLabel;
	public string $inactiveHeading;
	public string $inactiveDescription;
	public string $activeHeading;
	public string $activeDescription;

	public function __construct(
		string $actionLabel,
		string $inactiveHeading,
		string $inactiveDescription,
		string $activeHeading,
		string $activeDescription
	) {
		$this->actionKey           = 'core:webhook-management';
		$this->actionLabel         = MetadataRules::requiredText( $actionLabel, MetadataRules::LABEL_LENGTH );
		$this->inactiveHeading     = MetadataRules::requiredText( $inactiveHeading, MetadataRules::LABEL_LENGTH );
		$this->inactiveDescription = MetadataRules::requiredText( $inactiveDescription, MetadataRules::SUMMARY_LENGTH );
		$this->activeHeading       = MetadataRules::requiredText( $activeHeading, MetadataRules::LABEL_LENGTH );
		$this->activeDescription   = MetadataRules::requiredText( $activeDescription, MetadataRules::SUMMARY_LENGTH );
	}
}
