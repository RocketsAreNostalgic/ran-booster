<?php

declare(strict_types=1);

namespace RAN;

use InvalidArgumentException;

/**
 * Resolve and validate Booster's acquired-artifact ceiling.
 *
 * Site configuration is resolved once at the relevant Booster composition or
 * admission boundary. Durable requests retain their already-resolved integer
 * and validate it with requireValid(); there is no non-durable per-package
 * override API.
 */
final class PackageArtifactLimit {
	public const DEFAULT_MAXIMUM_ARTIFACT_BYTES = 52428800;
	public const MINIMUM_ARTIFACT_BYTES         = 1048576;
	public const MAXIMUM_ARTIFACT_BYTES         = 536870911;

	/**
	 * The null-only argument preserves source compatibility with callers from the
	 * removed future-override seam without accepting any package-specific limit.
	 */
	public static function resolve( null $legacyNull = null ): int {
		unset( $legacyNull );
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
