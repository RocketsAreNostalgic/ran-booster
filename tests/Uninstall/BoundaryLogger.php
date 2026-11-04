<?php

declare(strict_types=1);

namespace RAN\Tests\Uninstall;

final class BoundaryLogger {
	public static function log_exception( mixed ...$args ): void {
		unset( $args );
	}
}
