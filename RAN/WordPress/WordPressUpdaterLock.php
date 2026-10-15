<?php

declare(strict_types=1);

namespace RAN\WordPress;

use RAN\Deployment\DeploymentStorageFailure;
use RAN\WPBranchUpdater\V1\Runtime\BranchDeploymentLockStorageFailure;
use RAN\WPBranchUpdater\V1\WordPress\WordPressUpdaterLock as BranchWordPressUpdaterLock;
use RuntimeException;
use Throwable;

/**
 * Own WordPress's shared package-mutation lock for one synchronous operation.
 */
class WordPressUpdaterLock extends BranchWordPressUpdaterLock {

	/**
	 * Run one synchronous operation while this exact lock token is held.
	 *
	 * @template T
	 * @param callable(): T $operation
	 * @return T
	 */
	final public function run(
		callable $operation,
		?string $acquire_failure_message = null,
		?string $release_failure_message = null
	): mixed {
		try {
			$token = $this->acquire();
		} catch ( Throwable $failure ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal lock failures are not rendered; retain the public named-argument contract.
			throw null === $acquire_failure_message ? $failure : new RuntimeException( $acquire_failure_message, 0, $failure );
		}

		try {
			return $operation();
		} finally {
			try {
				$released = $this->release( $token );
			} catch ( Throwable $failure ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal lock failures are not rendered; retain the public named-argument contract.
				throw null === $release_failure_message ? $failure : new RuntimeException( $release_failure_message, 0, $failure );
			}
			if ( ! $released ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The caller supplies an internal diagnostic via the public named-argument contract.
				throw new RuntimeException( $release_failure_message ?? 'The WordPress updater lock could not be released.' );
			}
		}
	}

	public function current_token(): ?string {
		try {
			return parent::current_token();
		} catch ( BranchDeploymentLockStorageFailure ) {
			throw DeploymentStorageFailure::unavailable();
		}
	}

	public function acquire(): string {
		try {
			return parent::acquire();
		} catch ( BranchDeploymentLockStorageFailure ) {
			throw DeploymentStorageFailure::unavailable();
		}
	}

	public function release( string $token ): bool {
		try {
			return parent::release( $token );
		} catch ( BranchDeploymentLockStorageFailure ) {
			throw DeploymentStorageFailure::unavailable();
		}
	}

	protected function contention_failure(): RuntimeException {
		return new RuntimeException( 'The WordPress updater is already running.' );
	}
}
