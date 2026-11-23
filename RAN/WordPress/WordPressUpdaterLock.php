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
	private const OPTION_NAME   = 'auto_updater.lock';
	private const STALE_SECONDS = 3600;

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

	public function try_acquire_without_reclaim(): ?string {
		try {
			return parent::try_acquire_without_reclaim();
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

	/**
	 * Acquire a free lock or reclaim an expired (> 1 hour) row strictly through the caller's
	 * pinned single-session SQL executor inside an active transaction on the verified same
	 * connection. Callers must invoke invalidate_lock_cache() after committing when a token
	 * is returned.
	 *
	 * @param callable(object, string): bool                                       $same_connection
	 * @param callable(string, list<string>, 'affected'|'var'): (int|string|null) $execute
	 * @phpstan-impure Reads and mutates the auto_updater.lock database row.
	 */
	public function acquire_or_reclaim_expired_sql( callable $same_connection, callable $execute ): ?string {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! isset( $wpdb->options ) || ! is_string( $wpdb->options ) || '' === $wpdb->options || true !== $same_connection( $wpdb, $wpdb->options ) ) {
			throw DeploymentStorageFailure::unavailable();
		}
		$options_table = $wpdb->options;
		$token         = (string) time();

		try {
			$inserted = $execute(
				'INSERT IGNORE INTO %i (option_name, option_value, autoload) VALUES (%s, %s, %s)',
				array( $options_table, self::OPTION_NAME, $token, 'no' ),
				'affected'
			);
			if ( ! is_int( $inserted ) ) {
				throw DeploymentStorageFailure::unavailable();
			}
			if ( 1 === $inserted ) {
				return $token;
			}

			$stored = $execute(
				'SELECT option_value FROM %i WHERE option_name = %s FOR UPDATE',
				array( $options_table, self::OPTION_NAME ),
				'var'
			);
			if ( ! is_string( $stored ) || 1 !== preg_match( '/^\d+$/D', $stored ) || (int) $stored > time() - self::STALE_SECONDS ) {
				return null;
			}

			$deleted = $execute(
				'DELETE FROM %i WHERE option_name = %s AND option_value = %s',
				array( $options_table, self::OPTION_NAME, $stored ),
				'affected'
			);
			if ( ! is_int( $deleted ) ) {
				throw DeploymentStorageFailure::unavailable();
			}
			if ( 1 !== $deleted ) {
				return null;
			}

			$reinserted = $execute(
				'INSERT IGNORE INTO %i (option_name, option_value, autoload) VALUES (%s, %s, %s)',
				array( $options_table, self::OPTION_NAME, $token, 'no' ),
				'affected'
			);
			if ( ! is_int( $reinserted ) ) {
				throw DeploymentStorageFailure::unavailable();
			}
		} catch ( DeploymentStorageFailure $failure ) {
			throw $failure;
		} catch ( Throwable ) {
			throw DeploymentStorageFailure::unavailable();
		}

		if ( 1 !== $reinserted ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Fixed internal contention failure triggers caller transaction rollback.
			throw $this->contention_failure();
		}

		return $token;
	}

	public function invalidate_lock_cache(): void {
		if ( function_exists( 'wp_cache_delete' ) ) {
			\wp_cache_delete( self::OPTION_NAME, 'options' );
			\wp_cache_delete( 'notoptions', 'options' );
		}
	}

	protected function contention_failure(): RuntimeException {
		return new RuntimeException( 'The WordPress updater is already running.' );
	}
}
