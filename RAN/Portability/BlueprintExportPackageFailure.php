<?php

declare(strict_types=1);

namespace RAN\Portability;

use InvalidArgumentException;

/** A display-safe selected package reason that prevents an all-or-nothing Blueprint export. */
final readonly class BlueprintExportPackageFailure {

	public const PUBLISHED_RELEASES = 'published_releases';

	public function __construct(
		public string $type,
		public string $display_name,
		public string $reason
	) {
		if ( ! in_array( $type, array( 'plugin', 'theme' ), true )
			|| '' === $display_name || strlen( $display_name ) > 191 || 1 !== preg_match( '//u', $display_name ) || preg_match( '/[\x00-\x1F\x7F]/', $display_name )
			|| ! in_array( $reason, array( self::PUBLISHED_RELEASES ), true ) ) {
			throw new InvalidArgumentException( 'The Blueprint export package failure is invalid.' );
		}
	}
}
