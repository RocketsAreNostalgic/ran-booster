<?php

declare(strict_types=1);

namespace RAN\Secrets;

/**
 * A successful edit is provisional until WordPress loads the constant again.
 */
final readonly class WpConfigPathWriteResult {

	public const STATUS_PENDING_VERIFICATION = 'pending_verification';

	public function status(): string {
		return self::STATUS_PENDING_VERIFICATION;
	}

	public function requiresNextRequestVerification(): bool {
		return true;
	}
}
