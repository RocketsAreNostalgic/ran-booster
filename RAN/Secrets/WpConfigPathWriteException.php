<?php

declare(strict_types=1);

namespace RAN\Secrets;

use RuntimeException;
use Throwable;

/**
 * Reports a bounded, non-sensitive reason for refusing an automatic config edit.
 */
final class WpConfigPathWriteException extends RuntimeException {

	public function __construct(
		private readonly string $reason,
		string $message,
		?Throwable $previous = null
	) {
		parent::__construct( $message, 0, $previous );
	}

	public function reason(): string {
		return $this->reason;
	}
}
