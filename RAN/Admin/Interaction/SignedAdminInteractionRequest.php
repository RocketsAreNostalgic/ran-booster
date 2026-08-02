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
		public string $targetKey,
		public string $targetSelector,
		public string $canonicalUrl,
		public string $errorRegionId
	) {
		if ( 1 !== preg_match( '/^[a-z][a-z0-9-]{0,63}:[a-z][a-z0-9-]{0,63}$/', $operation ) ) {
			throw new InvalidArgumentException( 'Administration interaction operations require a bounded namespaced key.' );
		}
		if ( 1 !== preg_match( '/^[a-z][a-z0-9_]{0,63}$/', $targetKey )
			|| 1 !== preg_match( '/^#[A-Za-z][A-Za-z0-9_-]{0,127}$/', $targetSelector ) ) {
			throw new InvalidArgumentException( 'Administration interaction targets are invalid.' );
		}
		if ( '' === $canonicalUrl
			|| strlen( $canonicalUrl ) > 2048
			|| 1 === preg_match( '/[\x00-\x1F\x7F]/', $canonicalUrl ) ) {
			throw new InvalidArgumentException( 'Administration interaction return URLs are invalid.' );
		}
		if ( 1 !== preg_match( '/^[A-Za-z][A-Za-z0-9_-]{0,127}$/', $errorRegionId ) ) {
			throw new InvalidArgumentException( 'Administration interaction error regions require a bounded element ID.' );
		}
	}

	public function targetElementId(): string {
		return substr( $this->targetSelector, 1 );
	}
}
