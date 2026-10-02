<?php

declare(strict_types=1);

namespace RAN\RepositoryProvider\Admin;

use InvalidArgumentException;

final readonly class CredentialKindMetadata {

	public string $code;
	public string $label;
	public string $secret_label;
	public string $secret_placeholder;
	public string $short_label;

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
		string $secret_label,
		string $secret_placeholder = '',
		array $fields = array(),
		string $short_label = ''
	) {
		$code               = MetadataRules::identifier( $code );
		$label              = MetadataRules::required_text( $label, MetadataRules::LABEL_LENGTH );
		$secret_label       = MetadataRules::required_text( $secret_label, MetadataRules::LABEL_LENGTH );
		$secret_placeholder = MetadataRules::optional_text( $secret_placeholder, MetadataRules::DETAIL_LENGTH );
		$short_label        = MetadataRules::optional_text( $short_label, MetadataRules::LABEL_LENGTH );

		$indexed_fields = array();

		foreach ( $fields as $field ) {
			if ( ! $field instanceof CredentialFieldMetadata ) {
				throw new InvalidArgumentException( 'Credential fields must be credential field metadata.' );
			}

			if ( isset( $indexed_fields[ $field->key ] ) ) {
				throw new InvalidArgumentException( 'Credential field keys must be unique within a credential kind.' );
			}

			$indexed_fields[ $field->key ] = $field;
		}

		$this->code               = $code;
		$this->label              = $label;
		$this->secret_label       = $secret_label;
		$this->secret_placeholder = $secret_placeholder;
		$this->short_label        = '' === $short_label ? $label : $short_label;
		$this->fields             = array_values( $indexed_fields );
	}
}
