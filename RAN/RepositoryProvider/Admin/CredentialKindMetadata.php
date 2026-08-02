<?php

declare(strict_types=1);

namespace RAN\RepositoryProvider\Admin;

use InvalidArgumentException;

final readonly class CredentialKindMetadata {

	public string $code;
	public string $label;
	public string $secretLabel;
	public string $secretPlaceholder;

	/**
	 * @var list<CredentialFieldMetadata>
	 */
	public array $fields;

	/**
	 * @param list<CredentialFieldMetadata> $fields
	 */
	public function __construct(
		string $code,
		string $label,
		string $secretLabel,
		string $secretPlaceholder = '',
		array $fields = array()
	) {
		$code              = MetadataRules::identifier( $code );
		$label             = MetadataRules::requiredText( $label, MetadataRules::LABEL_LENGTH );
		$secretLabel       = MetadataRules::requiredText( $secretLabel, MetadataRules::LABEL_LENGTH );
		$secretPlaceholder = MetadataRules::optionalText( $secretPlaceholder, MetadataRules::DETAIL_LENGTH );

		$indexedFields = array();

		foreach ( $fields as $field ) {
			if ( ! $field instanceof CredentialFieldMetadata ) {
				throw new InvalidArgumentException( 'Credential fields must be credential field metadata.' );
			}

			if ( isset( $indexedFields[ $field->key ] ) ) {
				throw new InvalidArgumentException( 'Credential field keys must be unique within a credential kind.' );
			}

			$indexedFields[ $field->key ] = $field;
		}

		$this->code              = $code;
		$this->label             = $label;
		$this->secretLabel       = $secretLabel;
		$this->secretPlaceholder = $secretPlaceholder;
		$this->fields            = array_values( $indexedFields );
	}
}
