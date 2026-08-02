<?php

declare(strict_types=1);

namespace RAN\Admin;

use RAN\RepositoryProvider\ProviderRegistry;

/**
 * Builds provider-owned documentation without loading credentials or profiles.
 */
final readonly class ProviderDocumentationPresenter {

	public function __construct( private ProviderRegistry $providers ) {
	}

	/**
	 * Use the same deterministic order as every other provider-facing surface.
	 *
	 * @return list<array{
	 *     code: string,
	 *     label: string,
	 *     setup_available: bool,
	 *     credentials: array{summary: string, links: list<array{label: string, url: string}>},
	 *     webhook: array{location: string, event: string, documentation_url: string, delivery_documentation_url: string}|null
	 * }>
	 */
	public function build(): array {
		$documentation = array();

		foreach ( $this->providers->orderedMetadata() as $metadata ) {
			$setup = null === $metadata->admin ? null : $metadata->admin->setup;

			$documentation[] = array(
				'code'            => $metadata->code->value,
				'label'           => $metadata->label,
				'setup_available' => null !== $setup,
				'credentials'     => array(
					'summary' => null === $setup ? $metadata->label . ' setup guidance is not available yet.' : $setup->credentialSummary,
					'links'   => null === $setup ? array() : $setup->credentialLinks,
				),
				'webhook'         => null === $setup ? null : array(
					'location'                   => $setup->webhookLocation,
					'event'                      => $setup->webhookEvent,
					'documentation_url'          => $setup->webhookDocumentationUrl,
					'delivery_documentation_url' => $setup->deliveryDocumentationUrl,
				),
			);
		}

		return $documentation;
	}
}
