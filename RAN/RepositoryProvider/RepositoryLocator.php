<?php

declare(strict_types=1);

namespace RAN\RepositoryProvider;

use InvalidArgumentException;

/**
 * Applies Booster's provider-neutral safety bounds without rewriting provider data.
 */
final class RepositoryLocator {

	public static function requireValid( mixed $locator ): string {
		if ( ! is_string( $locator )
			|| '' === trim( $locator )
			|| strlen( $locator ) > 512
			|| preg_match( '/[\x00-\x1F\x7F]/', $locator ) ) {
			throw new InvalidArgumentException( 'The repository locator is invalid.' );
		}

		return $locator;
	}
}
