<?php

declare(strict_types=1);

namespace RAN; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- Core owns the established three-character RAN namespace; WPCS requires four characters.

use InvalidArgumentException;

enum PackageSource: string {
	case BRANCH        = 'branch';
	case RELEASE_ASSET = 'release_asset';

	public static function from_database( mixed $value ): self {
		if ( ! is_string( $value ) ) {
			throw new InvalidArgumentException( 'The managed package source is invalid.' );
		}

		return self::tryFrom( $value )
			?? throw new InvalidArgumentException( 'The managed package source is invalid.' );
	}
}
