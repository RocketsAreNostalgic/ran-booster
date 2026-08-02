<?php

declare(strict_types=1);

namespace RAN\RepositoryProvider;

use RAN\RepositoryProvider\Admin\MetadataRules;
use RAN\RepositoryProvider\Admin\ProviderAdminMetadata;

final readonly class ProviderMetadata {

	public string $label;
	public string $repositoryUrlBase;
	public string $ownerLabel;

	public function __construct(
		public ProviderCode $code,
		string $label,
		string $repositoryUrlBase,
		string $ownerLabel,
		public ?ProviderAdminMetadata $admin = null
	) {
		try {
			$label = MetadataRules::requiredText( $label, MetadataRules::LABEL_LENGTH );
		} catch ( \InvalidArgumentException ) {
			throw InvalidProvider::emptyLabel();
		}

		try {
			$ownerLabel = MetadataRules::requiredText( $ownerLabel, MetadataRules::LABEL_LENGTH );
		} catch ( \InvalidArgumentException ) {
			throw InvalidProvider::emptyOwnerLabel();
		}

		if ( MetadataRules::containsControlCharacters( $repositoryUrlBase ) ) {
			throw InvalidProvider::invalidRepositoryUrlBase();
		}

		$repositoryUrlBase = trim( $repositoryUrlBase );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- This domain value object remains usable without WordPress runtime.
		$parts = parse_url( $repositoryUrlBase );

		if (
			strlen( $repositoryUrlBase ) > MetadataRules::URL_LENGTH
			|| false === filter_var( $repositoryUrlBase, FILTER_VALIDATE_URL )
			|| ! is_array( $parts )
			|| 'https' !== strtolower( $parts['scheme'] ?? '' )
			|| '' === ( $parts['host'] ?? '' )
			|| array_intersect_key( $parts, array_flip( array( 'user', 'pass', 'query', 'fragment' ) ) )
		) {
			throw InvalidProvider::invalidRepositoryUrlBase();
		}

		$path = '/' . trim( $parts['path'] ?? '', '/' );
		$path = '/' === $path ? $path : $path . '/';
		$port = isset( $parts['port'] ) ? ':' . $parts['port'] : '';

		$this->label             = $label;
		$this->ownerLabel        = $ownerLabel;
		$this->repositoryUrlBase = 'https://' . strtolower( $parts['host'] ) . $port . $path;

		if ( null !== $this->admin && null !== $this->admin->navigation ) {
			$this->admin->navigation->assertProvider( $this->code );
		}
	}

	/**
	 * @return array{code: string, label: string, repository_url_base: string, owner_label: string}
	 */
	public function toArray(): array {
		return array(
			'code'                => $this->code->value,
			'label'               => $this->label,
			'repository_url_base' => $this->repositoryUrlBase,
			'owner_label'         => $this->ownerLabel,
		);
	}
}
