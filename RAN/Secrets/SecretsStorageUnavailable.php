<?php

declare(strict_types=1);

namespace RAN\Secrets;

use RuntimeException;

/**
 * Safe, typed boundary for local encrypted-store availability failures.
 */
final class SecretsStorageUnavailable extends RuntimeException {

	public const DIAGNOSTIC_ID  = 'local_secret_store_unavailable';
	public const REASON_GENERIC = 'storage_unavailable';

	public function __construct(
		string $message,
		private readonly string $reason = self::REASON_GENERIC
	) {
		parent::__construct( $message );
	}

	public function getDiagnosticId(): string {
		return self::DIAGNOSTIC_ID;
	}

	public function reason(): string {
		return $this->reason;
	}
}
