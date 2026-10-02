<?php

declare(strict_types=1);

namespace RAN\RepositoryProvider\Admin;

use InvalidArgumentException;

final readonly class WebhookScopeMetadata {

	public string $code;
	public string $label;
	public string $target_label;
	public string $target_placeholder;
	public string $description;

	public function __construct(
		string $code,
		string $label,
		public bool $requires_target,
		string $target_label = '',
		string $target_placeholder = '',
		string $description = '',
		public bool $requires_managed_target = false
	) {
		$code              = MetadataRules::identifier( $code );
		$label             = MetadataRules::required_text( $label, MetadataRules::LABEL_LENGTH );
		$target_label       = MetadataRules::optional_text( $target_label, MetadataRules::LABEL_LENGTH );
		$target_placeholder = MetadataRules::optional_text( $target_placeholder, MetadataRules::DETAIL_LENGTH );
		$description       = MetadataRules::optional_text( $description, MetadataRules::DETAIL_LENGTH );

		if ( ! in_array( $code, array( 'owner', 'repository' ), true ) ) {
			throw new InvalidArgumentException( 'Webhook scope codes must be owner or repository.' );
		}

		if ( $this->requires_target && '' === $target_label ) {
			throw new InvalidArgumentException( 'Webhook scopes that require a target must provide a target label.' );
		}

		$this->code              = $code;
		$this->label             = $label;
		$this->target_label       = $target_label;
		$this->target_placeholder = $target_placeholder;
		$this->description       = $description;
	}
}
