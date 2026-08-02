<?php

declare(strict_types=1);

namespace RAN\AddOn\Logging;

use Throwable;

/**
 * Narrow logging boundary published to Booster add-ons.
 */
interface LoggingFacade {

	/**
	 * @param array<string, mixed> $context
	 */
	public function log( string $message, array $context = array() ): void;

	/**
	 * @param array<string, mixed> $context
	 */
	public function logException( string $message, Throwable $exception, array $context = array() ): void;
}
