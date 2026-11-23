<?php

declare(strict_types=1);

namespace RAN\PackageRemoval;

use RuntimeException;
use Throwable;

/**
 * The native install hold was confirmed cleared, but releasing WordPress's updater lock failed.
 */
final class NativeHoldLockReleaseFailure extends RuntimeException {
	public function __construct( string $message, ?Throwable $previous = null ) {
		parent::__construct( $message, 0, $previous );
	}
}
