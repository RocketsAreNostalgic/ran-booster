<?php

declare(strict_types=1);

namespace RAN\RepositoryProvider\Admin;

use InvalidArgumentException;

final readonly class CredentialFieldMetadata {

	private const TYPES = array( 'text', 'email' );
	public string $key;
	public string $label;
	public string $placeholder;
	public string $description;

	public function __construct(
		string $key,
		string $label,
		public string $type = 'text',
		public bool $required = false,
		string $placeholder = '',
		string $description = ''
	) {
		$key         = MetadataRules::identifier( $key );
		$label       = MetadataRules::requiredText( $label, MetadataRules::LABEL_LENGTH );
		$placeholder = MetadataRules::optionalText( $placeholder, MetadataRules::DETAIL_LENGTH );
		$description = MetadataRules::optionalText( $description, MetadataRules::DETAIL_LENGTH );

		if ( ! in_array( $this->type, self::TYPES, true ) ) {
			throw new InvalidArgumentException( 'Credential field types must be text or email.' );
		}

		$this->key         = $key;
		$this->label       = $label;
		$this->placeholder = $placeholder;
		$this->description = $description;
	}
}
