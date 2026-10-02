<?php

declare(strict_types=1);

namespace RAN\Admin\Interaction;

use InvalidArgumentException;

/**
 * Core-internal transport description shared by the public add-on facade and
 * Core-owned administration mutations.
 *
 * @internal
 */
final readonly class SignedAdminInteractionRequest {

	public function __construct(
		public string $operation,
		public string $target_key,
		public string $target_selector,
		public string $canonical_url,
		public string $error_region_id
	) {
		if ( 1 !== preg_match( '/^[a-z][a-z0-9-]{0,63}:[a-z][a-z0-9-]{0,63}$/', $operation ) ) {
			throw new InvalidArgumentException( 'Administration interaction operations require a bounded namespaced key.' );
		}
		if ( 1 !== preg_match( '/^[a-z][a-z0-9_]{0,63}$/', $target_key )
			|| 1 !== preg_match( '/^#[A-Za-z][A-Za-z0-9_-]{0,127}$/', $target_selector ) ) {
			throw new InvalidArgumentException( 'Administration interaction targets are invalid.' );
		}
		if ( '' === $canonical_url
			|| strlen( $canonical_url ) > 2048
			|| 1 === preg_match( '/[\x00-\x1F\x7F]/', $canonical_url ) ) {
			throw new InvalidArgumentException( 'Administration interaction return URLs are invalid.' );
		}
		if ( 1 !== preg_match( '/^[A-Za-z][A-Za-z0-9_-]{0,127}$/', $error_region_id ) ) {
			throw new InvalidArgumentException( 'Administration interaction error regions require a bounded element ID.' );
		}
	}

	public function target_element_id(): string {
		return substr( $this->target_selector, 1 );
	}
}
