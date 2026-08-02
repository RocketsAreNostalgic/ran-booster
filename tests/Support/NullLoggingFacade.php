<?php

declare(strict_types=1);

namespace Tests\Support;

use RAN\AddOn\Logging\LoggingFacade;
use Throwable;

/**
 * Explicit no-op for tests that do not exercise logging behaviour.
 */
final class NullLoggingFacade implements LoggingFacade {

	/**
	 * @param array<string, mixed> $context
	 */
	public function log( string $message, array $context = array() ): void {
		unset( $message, $context );
	}

	/**
	 * @param array<string, mixed> $context
	 */
	public function logException( string $message, Throwable $exception, array $context = array() ): void {
		unset( $message, $exception, $context );
	}
}
