<?php

declare(strict_types=1);

namespace RAN\Tests\Uninstall;

final class BoundaryAvailability {
	public static function for_confirmed_uninstall( string $path ): never {
		unset( $path );
		throw new \RuntimeException( $GLOBALS['ran_booster_boundary_message'] );
	}
}
