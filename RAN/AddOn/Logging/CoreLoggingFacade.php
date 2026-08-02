<?php

declare(strict_types=1);

namespace RAN\AddOn\Logging;

use RAN\Logging\BoosterLogger;
use Throwable;

/**
 * Core implementation of the public add-on logging boundary.
 */
final class CoreLoggingFacade implements LoggingFacade {

	/**
	 * @param array<string, mixed> $context
	 */
	public function log( string $message, array $context = array() ): void {
		BoosterLogger::log( $message, $context );
	}

	/**
	 * @param array<string, mixed> $context
	 */
	public function logException( string $message, Throwable $exception, array $context = array() ): void {
		BoosterLogger::logException( $message, $exception, $context );
	}
}
