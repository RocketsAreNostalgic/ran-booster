<?php

declare(strict_types=1);

namespace RAN\WordPress;

use RAN\Deployment\DeploymentStorageFailure;
use RuntimeException;
use Throwable;

/**
 * Own WordPress's shared package-mutation lock for one synchronous operation.
 */
class WordPressUpdaterLock {

	private const NAME    = 'auto_updater.lock';
	private const TIMEOUT = 3600;

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
			// Internal lock failures are not rendered; retain the public named-argument contract.
			throw null === $acquire_failure_message ? $failure : new RuntimeException( $acquire_failure_message, 0, $failure );
		}

		try {
			return $operation();
		} finally {
			try {
				$released = $this->release( $token );
			} catch ( Throwable $failure ) {
				// Internal lock failures are not rendered; retain the public named-argument contract.
				throw null === $release_failure_message ? $failure : new RuntimeException( $release_failure_message, 0, $failure );
			}
			if ( ! $released ) {
				// The caller supplies an internal diagnostic via the public named-argument contract.
				throw new RuntimeException( $release_failure_message ?? 'The WordPress updater lock could not be released.' );
			}
		}
	}

	public function current_token(): ?string {
		global $wpdb;

		$stored = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT option_value FROM %i WHERE option_name = %s',
				$wpdb->options,
				self::NAME
			)
		);
		$error  = property_exists( $wpdb, 'last_error' ) ? trim( (string) $wpdb->last_error ) : '';
		if ( '' !== $error ) {
			throw DeploymentStorageFailure::unavailable();
		}

		return is_string( $stored )
			&& 1 === preg_match( '/^\d+$/D', $stored )
			&& (int) $stored > time() - self::TIMEOUT
			? $stored
			: null;
	}

	public function acquire(): string {
		global $wpdb;

		$token = (string) time();
		if ( $this->insert( $token ) ) {
			return $token;
		}

		$stored = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT option_value FROM %i WHERE option_name = %s',
				$wpdb->options,
				self::NAME
			)
		);
		$error  = property_exists( $wpdb, 'last_error' ) ? trim( (string) $wpdb->last_error ) : '';
		if ( '' !== $error ) {
			throw DeploymentStorageFailure::unavailable();
		}
		if ( ! is_string( $stored )
			|| 1 !== preg_match( '/^\d+$/D', $stored )
			|| (int) $stored > time() - self::TIMEOUT
			|| ! $this->release( $stored )
			|| ! $this->insert( $token ) ) {
			throw new RuntimeException( 'The WordPress updater is already running.' );
		}

		return $token;
	}

	public function release( string $token ): bool {
		global $wpdb;

		$result = $wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE option_name = %s AND option_value = %s',
				$wpdb->options,
				self::NAME,
				$token
			)
		);
		if ( false === $result ) {
			throw DeploymentStorageFailure::unavailable();
		}
		if ( 1 === $result ) {
			$this->invalidate_option_cache();
		}

		return 1 === $result;
	}

	private function insert( string $token ): bool {
		global $wpdb;

		$query = $wpdb->prepare(
			'INSERT IGNORE INTO %i (option_name, option_value, autoload) VALUES (%s, %s, %s)',
			$wpdb->options,
			self::NAME,
			$token,
			'no'
		);
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery -- This mirrors WordPress core's updater-lock acquisition.
		$result = $wpdb->query( $query );
		if ( false === $result ) {
			throw DeploymentStorageFailure::unavailable();
		}
		if ( 1 === $result ) {
			$this->invalidate_option_cache();
		}

		return 1 === $result;
	}

	private function invalidate_option_cache(): void {
		if ( function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( self::NAME, 'options' );
			wp_cache_delete( 'notoptions', 'options' );
		}
	}
}
