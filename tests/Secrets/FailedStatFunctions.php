<?php

declare(strict_types=1);

namespace RAN\Secrets;

/** Loaded only by the process-isolated failed-stat regression. */
function lstat( string $path ): false {
	unset( $path );
	return false;
}
