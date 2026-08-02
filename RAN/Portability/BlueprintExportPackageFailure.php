<?php

declare(strict_types=1);

namespace RAN\Portability;

use InvalidArgumentException;

/** A display-safe selected package reason that prevents an all-or-nothing Blueprint export. */
final readonly class BlueprintExportPackageFailure {

	public const PUBLISHED_RELEASES = 'published_releases';

	public function __construct(
		public string $type,
		public string $displayName,
		public string $reason
	) {
		if ( ! in_array( $type, array( 'plugin', 'theme' ), true )
			|| '' === $displayName || strlen( $displayName ) > 191 || 1 !== preg_match( '//u', $displayName ) || preg_match( '/[\x00-\x1F\x7F]/', $displayName )
			|| ! in_array( $reason, array( self::PUBLISHED_RELEASES ), true ) ) {
			throw new InvalidArgumentException( 'The Blueprint export package failure is invalid.' );
		}
	}
}
