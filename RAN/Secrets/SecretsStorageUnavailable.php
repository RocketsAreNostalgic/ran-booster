<?php

declare(strict_types=1);

namespace RAN\Secrets;

use RuntimeException;

/**
 * Safe, typed boundary for local encrypted-store availability failures.
 */
final class SecretsStorageUnavailable extends RuntimeException {

	public const DIAGNOSTIC_ID = 'local_secret_store_unavailable';

	public function getDiagnosticId(): string {
		return self::DIAGNOSTIC_ID;
	}
}
