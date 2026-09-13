<?php

declare(strict_types=1);

namespace RAN;

use InvalidArgumentException;

/**
 * Resolve Booster's effective acquired-artifact ceiling for one managed package.
 *
 * Package-specific policy wins over the legacy site-wide default. Updater
 * packages receive only the resolved integer and never depend on Booster's
 * configuration names.
 */
final class PackageArtifactLimit {
	public const DEFAULT_MAXIMUM_ARTIFACT_BYTES = 52428800;
	public const MINIMUM_ARTIFACT_BYTES         = 1048576;
	public const MAXIMUM_ARTIFACT_BYTES         = 536870911;

	public static function resolve( ?int $packageOverride ): int {
		if ( null !== $packageOverride ) {
			return self::requireValid( $packageOverride );
		}

		if ( defined( 'RAN_BOOSTER_MAX_ARCHIVE_BYTES' ) ) {
			return self::requireValid( constant( 'RAN_BOOSTER_MAX_ARCHIVE_BYTES' ) );
		}

		return self::requireValid( self::DEFAULT_MAXIMUM_ARTIFACT_BYTES );
	}

	public static function requireValid( mixed $value ): int {
		if ( ! is_int( $value )
			|| $value < self::MINIMUM_ARTIFACT_BYTES
			|| $value > self::MAXIMUM_ARTIFACT_BYTES ) {
			throw new InvalidArgumentException( 'The maximum artifact byte limit is invalid.' );
		}

		return $value;
	}
}
