<?php

declare(strict_types=1);

namespace RAN\RepositoryProvider;

final class GitReferenceSyntax {

	public static function isValidNamedReference( string $reference ): bool {
		if ( '' === $reference
			|| $reference !== trim( $reference )
			|| strlen( $reference ) > 255
			|| '@' === $reference
			|| str_starts_with( $reference, '-' )
			|| str_starts_with( $reference, '/' )
			|| str_ends_with( $reference, '/' )
			|| str_ends_with( $reference, '.' )
			|| str_contains( $reference, '..' )
			|| str_contains( $reference, '@{' )
			|| str_contains( $reference, '//' )
			|| 1 === preg_match( '/[\x00-\x20\x7F~^:?*\[\\\\%]/', $reference )
		) {
			return false;
		}

		foreach ( explode( '/', $reference ) as $segment ) {
			if ( '' === $segment
				|| str_starts_with( $segment, '.' )
				|| str_ends_with( strtolower( $segment ), '.lock' )
			) {
				return false;
			}
		}

		return true;
	}
}
