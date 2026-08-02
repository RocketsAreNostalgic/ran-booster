<?php

declare(strict_types=1);

namespace RAN\RepositoryProvider\Admin;

use InvalidArgumentException;

/**
 * Provider-owned, display-safe onboarding guidance.
 */
final readonly class ProviderSetupMetadata {
	public string $credentialSummary;
	public string $webhookLocation;
	public string $webhookEvent;
	public string $webhookDocumentationUrl;
	public string $deliveryDocumentationUrl;

	/**
	 * @var list<array{label: string, url: string}>
	 */
	public array $credentialLinks;

	/**
	 * @param list<array{label: string, url: string}> $credentialLinks Official credential documentation.
	 */
	public function __construct(
		string $credentialSummary,
		array $credentialLinks,
		string $webhookLocation,
		string $webhookEvent,
		string $webhookDocumentationUrl,
		string $deliveryDocumentationUrl
	) {
		$this->credentialSummary        = MetadataRules::requiredText( $credentialSummary, MetadataRules::SUMMARY_LENGTH );
		$this->webhookLocation          = MetadataRules::requiredText( $webhookLocation, MetadataRules::DETAIL_LENGTH );
		$this->webhookEvent             = MetadataRules::requiredText( $webhookEvent, MetadataRules::DETAIL_LENGTH );
		$this->webhookDocumentationUrl  = MetadataRules::httpsUrl( $webhookDocumentationUrl );
		$this->deliveryDocumentationUrl = MetadataRules::httpsUrl( $deliveryDocumentationUrl );

		$links = array();
		foreach ( $credentialLinks as $link ) {
			if ( ! is_array( $link ) || ! isset( $link['label'], $link['url'] ) || ! is_string( $link['label'] ) || ! is_string( $link['url'] ) ) {
				throw new InvalidArgumentException( 'Provider credential links require labels and URLs.' );
			}

			$links[] = array(
				'label' => MetadataRules::requiredText( $link['label'], MetadataRules::LABEL_LENGTH ),
				'url'   => MetadataRules::httpsUrl( $link['url'] ),
			);
		}

		if ( array() === $links ) {
			throw new InvalidArgumentException( 'Provider setup guidance requires official HTTPS documentation.' );
		}

		$this->credentialLinks = $links;
	}
}
