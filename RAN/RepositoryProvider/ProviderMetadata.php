<?php

declare(strict_types=1);

namespace RAN\RepositoryProvider;

use RAN\RepositoryProvider\Admin\MetadataRules;
use RAN\RepositoryProvider\Admin\ProviderAdminMetadata;

final readonly class ProviderMetadata {

	public string $label;
	public string $repository_url_base;
	public string $owner_label;

	public function __construct(
		public ProviderCode $code,
		string $label,
		string $repository_url_base,
		string $owner_label,
		public ?ProviderAdminMetadata $admin = null
	) {
		try {
			$label = MetadataRules::required_text( $label, MetadataRules::LABEL_LENGTH );
		} catch ( \InvalidArgumentException ) {
			throw InvalidProvider::empty_label();
		}

		try {
			$owner_label = MetadataRules::required_text( $owner_label, MetadataRules::LABEL_LENGTH );
		} catch ( \InvalidArgumentException ) {
			throw InvalidProvider::empty_owner_label();
		}

		if ( MetadataRules::contains_control_characters( $repository_url_base ) ) {
			throw InvalidProvider::invalid_repository_url_base();
		}

		$repository_url_base = trim( $repository_url_base );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- This domain value object remains usable without WordPress runtime.
		$parts = parse_url( $repository_url_base );

		if (
			strlen( $repository_url_base ) > MetadataRules::URL_LENGTH
			|| false === filter_var( $repository_url_base, FILTER_VALIDATE_URL )
			|| ! is_array( $parts )
			|| 'https' !== strtolower( $parts['scheme'] ?? '' )
			|| '' === ( $parts['host'] ?? '' )
			|| array_intersect_key( $parts, array_flip( array( 'user', 'pass', 'query', 'fragment' ) ) )
		) {
			throw InvalidProvider::invalid_repository_url_base();
		}

		$path = '/' . trim( $parts['path'] ?? '', '/' );
		$path = '/' === $path ? $path : $path . '/';
		$port = isset( $parts['port'] ) ? ':' . $parts['port'] : '';

		$this->label               = $label;
		$this->owner_label         = $owner_label;
		$this->repository_url_base = 'https://' . strtolower( $parts['host'] ) . $port . $path;
	}

	/**
	 * @return array{code: string, label: string, repository_url_base: string, owner_label: string}
	 */
	public function to_array(): array {
		return array(
			'code'                => $this->code->value,
			'label'               => $this->label,
			'repository_url_base' => $this->repository_url_base,
			'owner_label'         => $this->owner_label,
		);
	}
}
