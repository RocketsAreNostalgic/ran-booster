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
		string $description = ''
	) {
		$code              = MetadataRules::identifier( $code );
		$label             = MetadataRules::requiredText( $label, MetadataRules::LABEL_LENGTH );
		$targetLabel       = MetadataRules::optionalText( $targetLabel, MetadataRules::LABEL_LENGTH );
		$targetPlaceholder = MetadataRules::optionalText( $targetPlaceholder, MetadataRules::DETAIL_LENGTH );
		$description       = MetadataRules::optionalText( $description, MetadataRules::DETAIL_LENGTH );

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
