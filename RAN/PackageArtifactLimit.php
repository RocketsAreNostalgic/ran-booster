<?php

declare(strict_types=1);

namespace RAN;

use ArgumentCountError;
use InvalidArgumentException;

/**
 * Resolve and validate Booster's acquired-artifact ceiling.
 *
 * Site configuration is resolved once at the relevant Booster composition or
 * admission boundary. Durable requests retain their already-resolved integer
 * and validate it with require_valid(); there is no non-durable per-package
 * override API.
 */
final class PackageArtifactLimit {
	public const DEFAULT_MAXIMUM_ARTIFACT_BYTES = 52428800;
	public const MINIMUM_ARTIFACT_BYTES         = 1048576;
	public const MAXIMUM_ARTIFACT_BYTES         = 536870911;

	public static function resolve(): int {
		// PHP otherwise ignores extra positional arguments to user-defined methods.
		if ( func_num_args() !== 0 ) {
			throw new ArgumentCountError( 'Artifact limits resolve from site configuration without arguments.' );
		}

		if ( defined( 'RAN_BOOSTER_MAX_ARCHIVE_BYTES' ) ) {
			return self::require_valid( constant( 'RAN_BOOSTER_MAX_ARCHIVE_BYTES' ) );
		}

		return self::require_valid( self::DEFAULT_MAXIMUM_ARTIFACT_BYTES );
	}

	public static function require_valid( mixed $value ): int {
		if ( ! is_int( $value )
			|| $value < self::MINIMUM_ARTIFACT_BYTES
			|| $value > self::MAXIMUM_ARTIFACT_BYTES ) {
			throw new InvalidArgumentException( 'The maximum artifact byte limit is invalid.' );
		}

		return $value;
	}
}
