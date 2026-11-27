<?php

declare(strict_types=1);

namespace RAN\WordPress;

use RAN\Deployment\DeploymentStorageFailure;
use RAN\Logging\BoosterLogger;
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
	// The Branch updater's token grammar: WordPress's integer timestamp, optionally with its 27-digit ownership fraction.
	private const TOKEN_PATTERN = '/\A[0-9]+(?:\.[0-9]{27})?\z/D';
	// One Booster record names the operation holding the lock: token|operation|start time (#1180, PU-039).
	public const DEFAULT_OPERATION = 'package_operation';
	public const OWNER_OPTION      = 'ran_booster_updater_lock_owner';
	public const OWNER_TOKEN       = '/\A[0-9]+\.[0-9]{27}\z/D';
	private const OWNER_PATTERN    = '/\A([0-9]+\.[0-9]{27})\|([a-z][a-z0-9_]{0,63})\|([0-9]{1,10})\z/D';

	private static ?string $refused_by = null;

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
		?string $release_failure_message = null,
		string $owner_operation = self::DEFAULT_OPERATION
	): mixed {
		try {
			$token = $this->acquire( $owner_operation );
		} catch ( Throwable $failure ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal lock failures are not rendered; retain the public named-argument contract.
			throw null === $acquire_failure_message ? $failure : new RuntimeException( $acquire_failure_message, 0, $failure );
		}

		$operation_failure = null;
		try {
			return $operation();
		} catch ( Throwable $failure ) {
			$operation_failure = $failure;
			throw $failure;
		} finally {
			$release_failure = null;
			try {
				$released = $this->release( $token );
			} catch ( Throwable $failure ) {
				$released        = false;
				$release_failure = $failure;
			}
			if ( ! $released ) {
				BoosterLogger::log(
					'updater lock release failed',
					array(
						'step'         => 'updater_lock_release',
						'event'        => null === $release_failure ? 'not_released' : 'release_failed',
						'outcome_code' => null === $operation_failure ? 'operation_returned' : 'operation_failed',
					)
				);
				if ( null !== $release_failure && null === $operation_failure && null === $release_failure_message ) {
					throw $release_failure;
				}
				// A failed operation stays the cause, as in the Branch updater's run(); the release failure is the outcome.
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal lock failures are not rendered; retain the public named-argument contract.
				throw new RuntimeException( $release_failure_message ?? 'The WordPress updater lock could not be released.', 0, $operation_failure ?? $release_failure );
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

	public function try_acquire_without_reclaim( string $owner_operation = self::DEFAULT_OPERATION ): ?string {
		self::$refused_by = null;
		// The read that finds a row present refuses and names its holder; an insert lost to a racing owner names none.
		$holder = $this->read_lock_value( $this->require_options_table() );
		if ( null === $holder ) {
			try {
				$token = parent::try_acquire_without_reclaim();
			} catch ( BranchDeploymentLockStorageFailure ) {
				throw DeploymentStorageFailure::unavailable();
			}
			$token = null === $token ? null : $this->recorded( $token, $owner_operation );
			if ( null !== $token ) {
				return $token;
			}
		}
		self::$refused_by = $holder;
		return null;
	}

	public function acquire( string $owner_operation = self::DEFAULT_OPERATION ): string {
		self::$refused_by = null;
		// A live or malformed row is refused on this read, which names its holder; free and expired rows take the Branch updater's path.
		$holder  = $this->read_lock_value( $this->require_options_table() );
		$expired = self::valid_token( $holder ) && ! self::live_token( $holder, $this->now() );
		if ( null !== $holder && ! $expired ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Fixed internal contention failure without database or request input.
			throw $this->contention_failure( $holder );
		}
		try {
			$token = parent::acquire();
		} catch ( BranchDeploymentLockStorageFailure ) {
			throw DeploymentStorageFailure::unavailable();
		}
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Fixed internal contention failure without database or request input.
		return $this->recorded( $token, $owner_operation ) ?? throw $this->contention_failure();
	}

	public function release( string $token ): bool {
		try {
			$released = parent::release( $token );
		} catch ( BranchDeploymentLockStorageFailure ) {
			throw DeploymentStorageFailure::unavailable();
		}
		if ( $released ) {
			$this->forget_owner( $token );
		}
		return $released;
	}

	/**
	 * Atomically take over an interrupted operation's updater lock (or an absent lock row) using a
	 * fresh WordPress-compatible timestamp token guaranteed to differ from $expected_owner so
	 * concurrent recovery requests against the same reviewed hold cannot mistake the active recovery
	 * lock for the interrupted owner's lock.
	 */
	public function acquire_replacing_owner( string $expected_owner, string $owner_operation = self::DEFAULT_OPERATION ): string {
		self::$refused_by = null;
		$options_table    = $this->require_options_table();
		$now              = $this->now();
		do {
			$token = $this->new_token();
		} while ( $token === $expected_owner );

		if ( $this->replace_lock_value( $options_table, $expected_owner, $token ) || $this->insert_lock_value( $options_table, $token ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Fixed internal contention failure without database or request input.
			return $this->recorded( $token, $owner_operation ) ?? throw $this->contention_failure();
		}

		$stored = $this->read_lock_value( $options_table );
		if ( ! self::valid_token( $stored ) || self::live_token( $stored, $now ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Fixed internal contention failure without database or request input.
			throw $this->contention_failure( $stored );
		}
		if ( ! $this->replace_lock_value( $options_table, $stored, $token ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Fixed internal contention failure without database or request input.
			throw $this->contention_failure();
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Fixed internal contention failure without database or request input.
		return $this->recorded( $token, $owner_operation ) ?? throw $this->contention_failure();
	}

	/**
	 * The Booster operation holding the lock, only while its record names the exact current lock row. Elapsed
	 * time is never evidence that the operation stopped.
	 *
	 * @return array{token: string, operation: string, started_at: int}|null
	 * @phpstan-impure Reads the lock row and its owner record.
	 */
	public function recorded_owner(): ?array {
		$options_table = $this->require_options_table();
		$stored        = $this->read_lock_value( $options_table );
		$record        = $this->read_lock_value( $options_table, self::OWNER_OPTION );
		if ( null === $stored || null === $record || 1 !== preg_match( self::OWNER_PATTERN, $record, $matches ) || ! hash_equals( $stored, $matches[1] ) ) {
			return null;
		}
		return array(
			'token'      => $matches[1],
			'operation'  => $matches[2],
			'started_at' => (int) $matches[3],
		);
	}

	/** Release a lock the operator confirmed stopped, only while the Booster record still names this exact token. */
	public function release_recorded_owner( string $token ): bool {
		$owner    = 1 === preg_match( self::OWNER_TOKEN, $token ) ? $this->recorded_owner() : null;
		$released = null !== $owner && hash_equals( $owner['token'], $token ) && $this->release( $token );
		BoosterLogger::log(
			'updater lock owner release',
			array(
				'step'      => 'updater_lock_owner_release',
				'operation' => $owner['operation'] ?? null,
				'event'     => $released ? 'released' : 'not_released',
			)
		);
		return $released;
	}

	/**
	 * Read the active updater lock token strictly through the caller's pinned single-session SQL
	 * executor inside an active transaction on the verified same connection.
	 *
	 * @param callable(object, string): bool                                                                   $same_connection
	 * @param callable(string, list<int|string>, 'affected'|'var'|'results'): (int|string|list<object>|null) $execute
	 */
	public function current_token_sql( callable $same_connection, callable $execute ): ?string {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! isset( $wpdb->options ) || ! is_string( $wpdb->options ) || '' === $wpdb->options || true !== $same_connection( $wpdb, $wpdb->options ) ) {
			throw DeploymentStorageFailure::unavailable();
		}
		try {
			$stored = $execute(
				'SELECT option_value FROM %i WHERE option_name = %s',
				array( $wpdb->options, self::OPTION_NAME ),
				'var'
			);
		} catch ( DeploymentStorageFailure $failure ) {
			throw $failure;
		} catch ( Throwable ) {
			throw DeploymentStorageFailure::unavailable();
		}
		if ( null !== $stored && ! is_string( $stored ) ) {
			throw DeploymentStorageFailure::unavailable();
		}
		return self::valid_token( $stored ) && self::live_token( $stored, $this->now() ) ? $stored : null;
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
	public function acquire_or_reclaim_expired_sql( callable $same_connection, callable $execute, string $owner_operation = self::DEFAULT_OPERATION ): ?string {
		global $wpdb;
		self::$refused_by = null;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! isset( $wpdb->options ) || ! is_string( $wpdb->options ) || '' === $wpdb->options || true !== $same_connection( $wpdb, $wpdb->options ) ) {
			throw DeploymentStorageFailure::unavailable();
		}
		$options_table = $wpdb->options;
		$now           = $this->now();
		$token         = $this->new_token();
		$owner         = $this->owner_value( $token, $owner_operation );
		$record        = static function () use ( $execute, $options_table, $owner, $token ): void {
			$written = $execute( 'INSERT INTO %i (option_name, option_value, autoload) SELECT %s, %s, %s FROM %i AS held WHERE held.option_name = %s AND CAST(held.option_value AS BINARY) = CAST(%s AS BINARY) FOR UPDATE ON DUPLICATE KEY UPDATE option_value = %s, autoload = %s', array( $options_table, self::OWNER_OPTION, $owner, 'off', $options_table, self::OPTION_NAME, $token, $owner, 'off' ), 'affected' );
			if ( ! is_int( $written ) || $written < 1 ) {
				throw DeploymentStorageFailure::unavailable();
			}
		};

		try {
			$inserted = $execute(
				'INSERT IGNORE INTO %i (option_name, option_value, autoload) VALUES (%s, %s, %s)',
				array( $options_table, self::OPTION_NAME, $token, 'off' ),
				'affected'
			);
			if ( ! is_int( $inserted ) ) {
				throw DeploymentStorageFailure::unavailable();
			}
			if ( 1 === $inserted ) {
				$record();
				return $token;
			}

			$stored = $execute(
				'SELECT option_value FROM %i WHERE option_name = %s FOR UPDATE',
				array( $options_table, self::OPTION_NAME ),
				'var'
			);
			if ( ! self::valid_token( $stored ) || self::live_token( $stored, $now ) ) {
				self::$refused_by = is_string( $stored ) ? $stored : null;
				return null;
			}

			$deleted = $execute(
				'DELETE FROM %i WHERE option_name = %s AND CAST(option_value AS BINARY) = CAST(%s AS BINARY)',
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
				array( $options_table, self::OPTION_NAME, $token, 'off' ),
				'affected'
			);
			if ( ! is_int( $reinserted ) ) {
				throw DeploymentStorageFailure::unavailable();
			}
			if ( 1 === $reinserted ) {
				$record();
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

	protected function now(): int {
		return time();
	}

	/** A token in the Branch updater's format: the integer timestamp WordPress compares, plus a random ownership fraction. */
	private function new_token(): string {
		return sprintf( '%d.%09d%09d%09d', $this->now(), random_int( 0, 999999999 ), random_int( 0, 999999999 ), random_int( 0, 999999999 ) );
	}

	private function owner_value( string $token, string $operation ): string {
		return sprintf( '%s|%s|%d', $token, self::operation_code( $operation ), $this->now() );
	}

	/** The operation code a record and its log carry; anything else becomes the generic code. */
	private static function operation_code( string $operation ): string {
		return 1 === preg_match( '/\A[a-z][a-z0-9_]{0,63}\z/D', $operation ) ? $operation : self::DEFAULT_OPERATION;
	}

	/**
	 * Record the Booster operation that took this lock. Null when the lock row no longer holds this token: the request
	 * does not own the lock and must not proceed. A failed write keeps the lock and falls back to the hour-old reclaim.
	 */
	private function recorded( string $token, string $operation ): ?string {
		global $wpdb;
		$owner = $this->owner_value( $token, $operation );
		try {
			$options_table = $this->require_options_table();
			// The locking read holds the lock row until the record is written, so a replacing owner cannot slip between them under READ COMMITTED.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Uncached owner record beside the uncached auto_updater.lock row.
			$written = $wpdb->query( $wpdb->prepare( 'INSERT INTO %i (option_name, option_value, autoload) SELECT %s, %s, %s FROM %i AS held WHERE held.option_name = %s AND CAST(held.option_value AS BINARY) = CAST(%s AS BINARY) FOR UPDATE ON DUPLICATE KEY UPDATE option_value = %s, autoload = %s', $options_table, self::OWNER_OPTION, $owner, 'off', $options_table, self::OPTION_NAME, $token, $owner, 'off' ) );
		} catch ( Throwable ) {
			$written = false;
		}
		if ( is_int( $written ) && $written > 0 ) {
			return $token;
		}
		// Zero rows without a database error: the lock row no longer holds this token.
		$lost = 0 === $written && ! ( is_object( $wpdb ) && property_exists( $wpdb, 'last_error' ) && '' !== trim( (string) $wpdb->last_error ) );
		BoosterLogger::log(
			'updater lock owner not recorded',
			array(
				'step'      => 'updater_lock_owner',
				'operation' => self::operation_code( $operation ),
				'event'     => $lost ? 'ownership_lost' : 'write_failed',
			)
		);
		return $lost ? null : $token;
	}

	/** Remove the released token's record; a record left behind names no lock row, so it is never shown. */
	private function forget_owner( string $token ): void {
		global $wpdb;
		try {
			$options_table = $this->require_options_table();
			$record        = $this->read_lock_value( $options_table, self::OWNER_OPTION );
			if ( null !== $record && str_starts_with( $record, $token . '|' ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Byte-exact removal of the uncached owner record.
				$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE option_name = %s AND CAST(option_value AS BINARY) = CAST(%s AS BINARY)', $options_table, self::OWNER_OPTION, $record ) );
			}
		} catch ( Throwable ) {
			return;
		}
	}

	/** @phpstan-assert-if-true string $token */
	private static function valid_token( mixed $token ): bool {
		return is_string( $token ) && 1 === preg_match( self::TOKEN_PATTERN, $token );
	}

	/**
	 * The Branch updater's own expiry test, so both agree on which rows are live: the numeric token is compared
	 * with the cutoff as beta.20 compares it, never through an integer cast that drops the fraction.
	 */
	private static function live_token( string $token, int $now ): bool {
		return $token > $now - self::STALE_SECONDS;
	}

	/** The holder is the token read by the observation that refused this request, or null when that observation named none. */
	protected function contention_failure( ?string $holder = null ): RuntimeException {
		self::$refused_by = $holder;
		return new RuntimeException( 'The WordPress updater is already running.' );
	}

	/** The lock token that refused a Booster action in this request since the last call, or null; the screen names only that owner. */
	public static function take_refusal(): ?string {
		$holder           = self::$refused_by;
		self::$refused_by = null;
		return $holder;
	}

	private function require_options_table(): string {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! isset( $wpdb->options ) || ! is_string( $wpdb->options ) || '' === $wpdb->options ) {
			throw DeploymentStorageFailure::unavailable();
		}
		if ( ! method_exists( $wpdb, 'prepare' ) || ! method_exists( $wpdb, 'query' ) || ! method_exists( $wpdb, 'get_var' ) ) {
			throw DeploymentStorageFailure::unavailable();
		}
		return $wpdb->options;
	}

	private function replace_lock_value( string $options_table, string $expected, string $replacement ): bool {
		global $wpdb;
		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'prepare' ) || ! method_exists( $wpdb, 'query' ) ) {
			throw DeploymentStorageFailure::unavailable();
		}
		try {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic byte-exact compare-and-set handoff for the uncached auto_updater.lock row.
			$result = $wpdb->query( $wpdb->prepare( 'UPDATE %i SET option_value = %s WHERE option_name = %s AND CAST(option_value AS BINARY) = CAST(%s AS BINARY)', $options_table, $replacement, self::OPTION_NAME, $expected ) );
		} catch ( Throwable ) {
			throw DeploymentStorageFailure::unavailable();
		}
		if ( false === $result ) {
			throw DeploymentStorageFailure::unavailable();
		}
		if ( 1 === $result ) {
			$this->invalidate_lock_cache();
			return true;
		}
		return false;
	}

	private function insert_lock_value( string $options_table, string $token ): bool {
		global $wpdb;
		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'prepare' ) || ! method_exists( $wpdb, 'query' ) ) {
			throw DeploymentStorageFailure::unavailable();
		}
		try {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic absent-row acquisition for the uncached auto_updater.lock row.
			$result = $wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO %i (option_name, option_value, autoload) VALUES (%s, %s, %s)', $options_table, self::OPTION_NAME, $token, 'off' ) );
		} catch ( Throwable ) {
			throw DeploymentStorageFailure::unavailable();
		}
		if ( false === $result ) {
			throw DeploymentStorageFailure::unavailable();
		}
		if ( 1 === $result ) {
			$this->invalidate_lock_cache();
			return true;
		}
		return false;
	}

	private function read_lock_value( string $options_table, string $option_name = self::OPTION_NAME ): ?string {
		global $wpdb;
		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'prepare' ) || ! method_exists( $wpdb, 'get_var' ) ) {
			throw DeploymentStorageFailure::unavailable();
		}
		try {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Authoritative uncached read of auto_updater.lock after contention.
			$stored = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $options_table, $option_name ) );
		} catch ( Throwable ) {
			throw DeploymentStorageFailure::unavailable();
		}
		if ( property_exists( $wpdb, 'last_error' ) && '' !== trim( (string) $wpdb->last_error ) ) {
			throw DeploymentStorageFailure::unavailable();
		}
		return is_string( $stored ) ? $stored : null;
	}
}
