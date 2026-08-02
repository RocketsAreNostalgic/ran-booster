<?php

declare(strict_types=1);

namespace RAN\RepositoryProvider;

/**
 * Stable, open provider identifier.
 *
 * Provider IDs are deliberately not an enum: trusted extensions may register
 * providers without changing Booster core.
 */
final readonly class ProviderCode {

	public string $value;

	private function __construct( string $value ) {
		$this->value = $value;
	}

	public static function parse( string $value ): self {
		if ( in_array( $value, array( 'overview', 'portability', 'documentation', 'troubleshooting' ), true )
			|| 1 !== preg_match( '/\A[a-z][a-z0-9-]{0,31}\z/', $value )
		) {
			throw InvalidProviderCode::forValue();
		}

		return new self( $value );
	}

	public function equals( self|string $other ): bool {
		return $this->value === ( $other instanceof self ? $other->value : $other );
	}

	public function __toString(): string {
		return $this->value;
	}
}
