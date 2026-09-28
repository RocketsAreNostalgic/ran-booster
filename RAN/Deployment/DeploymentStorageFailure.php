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
	private ?array $active_attempt = null;

	public static function unavailable(): self {
		return new self( 'RAN Booster could not safely store deployment state.' );
	}

	public static function inconsistent(): self {
		return new self( 'RAN Booster could not verify persisted deployment state.' );
	}

	/** @param array<string, bool|int|string|null> $active_attempt */
	public static function contention( array $active_attempt ): self {
		if ( ! is_int( $active_attempt['id'] ?? null )
			|| $active_attempt['id'] < 1
			|| ! is_string( $active_attempt['correlation_id'] ?? null )
			|| preg_match( '/^[a-f0-9]{32}$/D', $active_attempt['correlation_id'] ) !== 1
			|| ! is_string( $active_attempt['state'] ?? null )
			|| ! in_array( $active_attempt['state'], array( 'queued', 'running', 'needs_attention' ), true )
			|| ! is_string( $active_attempt['package_type'] ?? null )
			|| ! in_array( $active_attempt['package_type'], array( 'plugin', 'theme' ), true )
			|| ! is_string( $active_attempt['package_slug'] ?? null )
		) {
			return self::inconsistent();
		}
		$failure                 = new self( 'Another RAN Booster deployment is already running.' );
		$failure->active_attempt = array_intersect_key(
			$active_attempt,
			array_flip( array( 'id', 'correlation_id', 'state', 'package_type', 'package_slug' ) )
		);

		return $failure;
	}

	public static function invalid_record(): self {
		return new self( 'RAN Booster found an invalid deployment record.' );
	}

	public static function not_found(): self {
		return new self( 'The deployment attempt is no longer available.' );
	}

	public static function delivery_conflict(): self {
		return new self( 'The provider delivery ID was reused with different authenticated content.', self::DELIVERY_CONFLICT_CODE );
	}

	public static function transaction_commit_failed(): self {
		return new self( 'RAN Booster could not commit deployment state.', self::COMMIT_FAILURE_CODE );
	}

	public static function unsupported_database(): self {
		return new self( 'RAN Booster deployment storage is unavailable because the database is unsupported.', self::DATABASE_UNSUPPORTED_CODE );
	}

	public static function capacity_exhausted(): self {
		return new self(
			'RAN Booster deployment history is full. Resolve queued, running, or needs-attention deployments before retrying.',
			self::CAPACITY_EXHAUSTED_CODE
		);
	}

	public function is_delivery_conflict(): bool {
		return self::DELIVERY_CONFLICT_CODE === $this->getCode();
	}

	public function is_database_unsupported(): bool {
		return self::DATABASE_UNSUPPORTED_CODE === $this->getCode();
	}

	public function is_capacity_exhausted(): bool {
		return self::CAPACITY_EXHAUSTED_CODE === $this->getCode();
	}

	public function get_active_correlation_id(): ?string {
		$correlation_id = $this->active_attempt['correlation_id'] ?? null;

		return is_string( $correlation_id ) ? $correlation_id : null;
	}

	/** @return array<string, bool|int|string|null>|null */
	public function get_active_attempt(): ?array {
		return $this->active_attempt;
	}
}
