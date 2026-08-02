<?php

declare(strict_types=1);

namespace RAN\Deployment;

use RuntimeException;

/**
 * A fail-closed persistence boundary. Its message is intentionally safe to
 * show to an administrator and never includes database diagnostic text.
 */
final class DeploymentStorageFailure extends RuntimeException {
	private const COMMIT_FAILURE_CODE       = 1;
	private const DELIVERY_CONFLICT_CODE    = 2;
	private const DATABASE_UNSUPPORTED_CODE = 3;
	private const CAPACITY_EXHAUSTED_CODE   = 4;

	/** @var array<string, bool|int|string|null>|null */
	private ?array $activeAttempt = null;

	public static function unavailable(): self {
		return new self( 'RAN Booster could not safely store deployment state.' );
	}

	public static function inconsistent(): self {
		return new self( 'RAN Booster could not verify persisted deployment state.' );
	}

	/** @param array<string, bool|int|string|null> $activeAttempt */
	public static function contention( array $activeAttempt ): self {
		if ( ! is_int( $activeAttempt['id'] ?? null )
			|| $activeAttempt['id'] < 1
			|| ! is_string( $activeAttempt['correlation_id'] ?? null )
			|| preg_match( '/^[a-f0-9]{32}$/D', $activeAttempt['correlation_id'] ) !== 1
			|| ! is_string( $activeAttempt['state'] ?? null )
			|| ! in_array( $activeAttempt['state'], array( 'queued', 'running', 'needs_attention' ), true )
			|| ! is_string( $activeAttempt['package_type'] ?? null )
			|| ! in_array( $activeAttempt['package_type'], array( 'plugin', 'theme' ), true )
			|| ! is_string( $activeAttempt['package_slug'] ?? null )
		) {
			return self::inconsistent();
		}
		$failure                = new self( 'Another RAN Booster deployment is already running.' );
		$failure->activeAttempt = array_intersect_key(
			$activeAttempt,
			array_flip( array( 'id', 'correlation_id', 'state', 'package_type', 'package_slug' ) )
		);

		return $failure;
	}

	public static function invalidRecord(): self {
		return new self( 'RAN Booster found an invalid deployment record.' );
	}

	public static function notFound(): self {
		return new self( 'The deployment attempt is no longer available.' );
	}

	public static function deliveryConflict(): self {
		return new self( 'The provider delivery ID was reused with different authenticated content.', self::DELIVERY_CONFLICT_CODE );
	}

	public static function transactionCommitFailed(): self {
		return new self( 'RAN Booster could not commit deployment state.', self::COMMIT_FAILURE_CODE );
	}

	public static function unsupportedDatabase(): self {
		return new self( 'RAN Booster deployment storage is unavailable because the database is unsupported.', self::DATABASE_UNSUPPORTED_CODE );
	}

	public static function capacityExhausted(): self {
		return new self(
			'RAN Booster deployment history is full. Resolve queued, running, or needs-attention deployments before retrying.',
			self::CAPACITY_EXHAUSTED_CODE
		);
	}

	public function isDeliveryConflict(): bool {
		return self::DELIVERY_CONFLICT_CODE === $this->getCode();
	}

	public function isDatabaseUnsupported(): bool {
		return self::DATABASE_UNSUPPORTED_CODE === $this->getCode();
	}

	public function isCapacityExhausted(): bool {
		return self::CAPACITY_EXHAUSTED_CODE === $this->getCode();
	}

	public function getActiveCorrelationId(): ?string {
		$correlationId = $this->activeAttempt['correlation_id'] ?? null;

		return is_string( $correlationId ) ? $correlationId : null;
	}

	/** @return array<string, bool|int|string|null>|null */
	public function getActiveAttempt(): ?array {
		return $this->activeAttempt;
	}
}
