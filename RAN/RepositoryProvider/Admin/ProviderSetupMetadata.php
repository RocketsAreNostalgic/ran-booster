<?php

declare(strict_types=1);

namespace RAN\RepositoryProvider\Admin;

use InvalidArgumentException;

/**
 * Provider-owned, display-safe onboarding guidance.
 */
final readonly class ProviderSetupMetadata {
	public string $credential_summary;
	public string $webhook_location;
	public string $webhook_event;
	public string $webhook_documentation_url;
	public string $delivery_documentation_url;

	/**
	 * @var list<array{label: string, url: string}>
	 */
	public array $credential_links;

	/**
	 * @param list<array{label: string, url: string}> $credential_links Official credential documentation.
	 */
	public function __construct(
		string $credential_summary,
		array $credential_links,
		string $webhook_location,
		string $webhook_event,
		string $webhook_documentation_url,
		string $delivery_documentation_url
	) {
		$this->credential_summary        = MetadataRules::required_text( $credential_summary, MetadataRules::SUMMARY_LENGTH );
		$this->webhook_location          = MetadataRules::required_text( $webhook_location, MetadataRules::DETAIL_LENGTH );
		$this->webhook_event             = MetadataRules::required_text( $webhook_event, MetadataRules::DETAIL_LENGTH );
		$this->webhook_documentation_url  = MetadataRules::https_url( $webhook_documentation_url );
		$this->delivery_documentation_url = MetadataRules::https_url( $delivery_documentation_url );

		$links = array();
		foreach ( $credential_links as $link ) {
			if ( ! is_array( $link ) || ! isset( $link['label'], $link['url'] ) || ! is_string( $link['label'] ) || ! is_string( $link['url'] ) ) {
				throw new InvalidArgumentException( 'Provider credential links require labels and URLs.' );
			}

			$links[] = array(
				'label' => MetadataRules::required_text( $link['label'], MetadataRules::LABEL_LENGTH ),
				'url'   => MetadataRules::https_url( $link['url'] ),
			);
		}

		if ( array() === $links ) {
			throw new InvalidArgumentException( 'Provider setup guidance requires official HTTPS documentation.' );
		}

		$this->credential_links = $links;
	}
}
