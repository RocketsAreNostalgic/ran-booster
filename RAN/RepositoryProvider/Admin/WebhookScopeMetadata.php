<?php

declare(strict_types=1);

namespace RAN\RepositoryProvider\Admin;

use InvalidArgumentException;

final readonly class WebhookScopeMetadata {

	public string $code;
	public string $label;
	public string $targetLabel;
	public string $targetPlaceholder;
	public string $description;

	public function __construct(
		string $code,
		string $label,
		public bool $requiresTarget,
		string $targetLabel = '',
		string $targetPlaceholder = '',
		string $description = '',
		public bool $requiresManagedTarget = false
	) {
		$code              = MetadataRules::identifier( $code );
		$label             = MetadataRules::required_text( $label, MetadataRules::LABEL_LENGTH );
		$targetLabel       = MetadataRules::optional_text( $targetLabel, MetadataRules::LABEL_LENGTH );
		$targetPlaceholder = MetadataRules::optional_text( $targetPlaceholder, MetadataRules::DETAIL_LENGTH );
		$description       = MetadataRules::optional_text( $description, MetadataRules::DETAIL_LENGTH );

		if ( ! in_array( $code, array( 'owner', 'repository' ), true ) ) {
			throw new InvalidArgumentException( 'Webhook scope codes must be owner or repository.' );
		}

		if ( $this->requiresTarget && '' === $targetLabel ) {
			throw new InvalidArgumentException( 'Webhook scopes that require a target must provide a target label.' );
		}

		$this->code              = $code;
		$this->label             = $label;
		$this->targetLabel       = $targetLabel;
		$this->targetPlaceholder = $targetPlaceholder;
		$this->description       = $description;
	}
}
