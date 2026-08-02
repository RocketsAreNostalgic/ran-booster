<?php

declare(strict_types=1);

namespace RAN\Portability;

use RuntimeException;
use Throwable;

/**
 * Typed, non-secret portability failure for target-local encrypted storage.
 */
final class LocalSecretStoreUnavailable extends RuntimeException {

	public const CATEGORY = 'local_secret_store_unavailable';

	public static function forPortability( ?Throwable $previous = null ): self {
		return new self(
			'The local encrypted credential store is unavailable for portability.',
			0,
			$previous
		);
	}
}
