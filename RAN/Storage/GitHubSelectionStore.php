<?php

declare(strict_types=1);

namespace RAN\Storage;

use RAN\RepositoryProvider\GitHubReplacementDeclaration;

/**
 * One durable authority row. Callers own transactions and acquire this row before
 * adoption/attempt locks. Ordinary reads/CAS never own or repair transactions;
 * selection_transaction() is the explicit non-nesting selection lifecycle owner.
 * Transaction owners must invalidate_cache() after COMMIT/ROLLBACK or unknown outcomes.
 * Inside an existing transaction, authority reads require read(true), because
 * nonlocking reads can inherit an older REPEATABLE READ snapshot.
 * Native-marker clearing requires the caller's exact verified completion or protected recovery.
 * The options table's shape (InnoDB, whole-column unique option_name) is verified before the
 * first read or write and again after every invalidate_cache(), so after every write made
 * through this store: exact locking and compare-and-swap depend on that shape at a write.
 */
final class GitHubSelectionStore {
	public const OPTION                      = 'ran_booster_github_selection';
	private const NON_AUTOLOAD_VALUES        = array( 'no', 'off' );
	private bool $commit_uncertain           = false;
	private bool $transaction_open_uncertain = false;
	private bool $storage_verified           = false;
	private bool $in_selection_transaction   = false;
	// phpcs:ignore WordPress.DB.RestrictedClasses.mysql__mysqli -- Pin the active wpdb mysqli handle through COMMIT so wpdb::query() cannot invoke query filters or reconnect retries.
	private ?\mysqli $pinned_dbh      = null;
	private ?string $pinned_thread_id = null;

	public function __construct( private \wpdb|SchemaConnection $connection, private string $table ) {
	}

	public function uses_connection( object $connection, ?string $table = null ): bool {
		return $this->connection === $connection && ( null === $table || $this->table === $table );
	}

	public function has_unclosed_transaction(): bool {
		return $this->transaction_open_uncertain;
	}

	public function has_uncertain_commit(): bool {
		return $this->commit_uncertain;
	}

	/**
	 * Authoritative current read: option filters and object caches are not authority.
	 * Only WordPress's canonical serialized integer-keyed array of valid plugin basenames is accepted.
	 *
	 * @return list<string>
	 */
	public function active_plugins(): array {
		$pinned = $this->pinned_dbh;
		if ( null !== $pinned ) {
			$this->assert_pinned_session();
			$sql        = $this->format_pinned_sql( $pinned, 'SELECT option_name, option_value FROM %i WHERE option_name = %s LIMIT 2 FOR UPDATE', array( $this->table, 'active_plugins' ) );
			$rows       = $this->pinned_mysqli_objects( $pinned, $sql, 'active_plugins_read_failed' );
			$query_fail = false;
		} else {
			$this->require_storage();
			$this->connection->last_error = '';
			$query                        = $this->connection->prepare( 'SELECT option_name, option_value FROM %i WHERE option_name = %s LIMIT 2 FOR UPDATE', $this->table, 'active_plugins' );
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared current read bypasses REPEATABLE READ snapshots as well as WordPress caches.
			$rows       = $this->connection->get_results( $query );
			$query_fail = $this->read_failed();
		}
		if ( $query_fail || ! is_array( $rows ) || 1 !== count( $rows ) || ! is_object( $rows[0] ) || 'active_plugins' !== ( $rows[0]->option_name ?? null ) || ! is_string( $rows[0]->option_value ?? null ) ) {
			throw new DatabaseLifecycleFailure( 'active_plugins_read_failed' );
		}
		$bytes = $rows[0]->option_value;
		if ( ! str_starts_with( $bytes, 'a:' ) ) {
			throw new DatabaseLifecycleFailure( 'active_plugins_invalid' );
		}
		// Ensure the failure class is loaded before blocking autoloaders during unserialize().
		class_exists( DatabaseLifecycleFailure::class );
		$deny_autoload = static function (): never {
			throw new DatabaseLifecycleFailure( 'active_plugins_invalid' );
		};
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Suppress native unserialize notices, warnings, and deprecations on malformed stored bytes just as maybe_unserialize() does.
		set_error_handler( static fn (): bool => true, E_NOTICE | E_WARNING | E_DEPRECATED );
		spl_autoload_register( $deny_autoload, true, true );
		try {
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- Decode authoritative active_plugins option bytes with class instantiation and autoloading disabled plus canonical round-trip verification.
			$decoded = unserialize( $bytes, array( 'allowed_classes' => false ) );
		} finally {
			spl_autoload_unregister( $deny_autoload );
			restore_error_handler();
		}
		if ( ! is_array( $decoded ) ) {
			throw new DatabaseLifecycleFailure( 'active_plugins_invalid' );
		}
		$plugins = array();
		$seen    = array();
		foreach ( $decoded as $key => $basename ) {
			if ( ! is_int( $key ) || $key < 0 || ! is_string( $basename ) || ! GitHubReplacementDeclaration::is_valid_plugin_basename( $basename ) || isset( $seen[ $basename ] ) ) {
				throw new DatabaseLifecycleFailure( 'active_plugins_invalid' );
			}
			$seen[ $basename ] = true;
			$plugins[]         = $basename;
		}
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Verify canonical serialized array bytes with no trailing data, duplicate keys, or coerced key forms.
		if ( serialize( $decoded ) !== $bytes ) {
			throw new DatabaseLifecycleFailure( 'active_plugins_invalid' );
		}
		sort( $plugins, SORT_STRING );
		return $plugins;
	}

	/** @return array<string, mixed> */
	public function read( bool $for_update = false ): array {
		$pinned = $this->pinned_dbh;
		if ( null !== $pinned ) {
			$this->assert_pinned_session();
			$sql  = $this->format_pinned_sql(
				$pinned,
				'SELECT option_name, option_value, autoload FROM %i WHERE option_name = %s LIMIT 2' . ( $for_update ? ' FOR UPDATE' : '' ),
				array( $this->table, self::OPTION )
			);
			$rows = $this->pinned_mysqli_objects( $pinned, $sql, 'selection_read_failed' );
			return $this->decode_selection_rows( $rows );
		}
		$this->require_storage();
		$this->connection->last_error = '';
		$query                        = $this->connection->prepare(
			'SELECT option_name, option_value, autoload FROM %i WHERE option_name = %s LIMIT 2' . ( $for_update ? ' FOR UPDATE' : '' ),
			$this->table,
			self::OPTION
		);
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared identifiers and values; locking suffix is a closed local boolean choice.
		$rows = $this->connection->get_results( $query );
		if ( $this->read_failed() ) {
			throw new DatabaseLifecycleFailure( 'selection_read_failed' );
		}
		return $this->decode_selection_rows( $rows );
	}

	/** @return array<string, mixed> */
	private function decode_selection_rows( mixed $rows ): array {
		if ( ! is_array( $rows ) || 1 !== count( $rows ) ) {
			throw new DatabaseLifecycleFailure( 'selection_read_failed' );
		}
		$row = $rows[0];
		if ( ! is_object( $row ) || ( $row->option_name ?? null ) !== self::OPTION || ! in_array( $row->autoload ?? null, self::NON_AUTOLOAD_VALUES, true ) || ! is_string( $row->option_value ?? null ) || strlen( $row->option_value ) > 16384 ) {
			throw new DatabaseLifecycleFailure( 'selection_invalid' );
		}
		try {
			$state = json_decode( $row->option_value, true, 8, JSON_THROW_ON_ERROR );
			if ( ! is_array( $state ) || $row->option_value !== $this->encode( $state ) ) {
				throw new \InvalidArgumentException();
			}
			return $state;
		} catch ( \Throwable ) {
			throw new DatabaseLifecycleFailure( 'selection_invalid' );
		}
	}

	/**
	 * Explicit install-only seam. The lifecycle must verify this result before
	 * advancing its version; ordinary runtime must never call this to repair absence.
	 * An interrupted initialization preserves any existing valid selection.
	 *
	 * @return array<string, mixed>
	 */
	public function initialize_bundled( GitHubReplacementDeclaration $declaration ): array {
		$manifest = get_object_vars( $declaration );
		unset( $manifest['implementation_fingerprint'] );
		$initial = array(
			'schema'               => 1,
			'selection_generation' => 0,
			'selected'             => 'bundled',
			'declaration'          => $manifest,
			'approval'             => 'approved',
			'approved_api_formats' => $declaration->api_credential_formats,
		);
		$this->require_storage();
		$query = $this->connection->prepare( 'INSERT IGNORE INTO %i (option_name, option_value, autoload) VALUES (%s, %s, %s)', $this->table, self::OPTION, $this->encode( $initial ), 'off' );
		try {
			$this->connection->last_error = '';
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Every identifier and value is bound by prepare().
			$result = $this->connection->query( $query );
			if ( ! in_array( $result, array( 0, 1 ), true ) || $this->read_failed() ) {
				throw new DatabaseLifecycleFailure( 'selection_write_failed' );
			}
			$stored = $this->read( true );
			if ( 1 === $result && $stored !== $initial ) {
				throw new DatabaseLifecycleFailure( 'selection_verification_failed' );
			}
			return $stored;
		} finally {
			$this->invalidate_cache();
		}
	}

	/**
	 * Caller holds the selection row lock in its outer transaction. False means a
	 * competing state won; errors/unknown outcomes must pause, never retry adoption.
	 *
	 * @param array<string, mixed> $expected
	 * @param array<string, mixed> $next
	 */
	public function compare_and_swap( array $expected, array $next ): bool {
		$old = $this->encode( $expected );
		$new = $this->encode( $next );
		$this->validate_transition( $expected, $next );
		$pinned = $this->pinned_dbh;
		if ( null === $pinned ) {
			$this->require_storage();
		}
		try {
			if ( null !== $pinned ) {
				$this->assert_pinned_session();
				$sql    = $this->format_pinned_sql(
					$pinned,
					'UPDATE %i SET option_value = %s WHERE BINARY option_name = BINARY %s AND BINARY option_value = BINARY %s AND ( BINARY autoload = BINARY %s OR BINARY autoload = BINARY %s )',
					array( $this->table, $new, self::OPTION, $old, self::NON_AUTOLOAD_VALUES[0], self::NON_AUTOLOAD_VALUES[1] )
				);
				$result = $this->pinned_mysqli_affected( $pinned, $sql, 'selection_write_failed' );
			} else {
				$query                        = $this->connection->prepare(
					'UPDATE %i SET option_value = %s WHERE BINARY option_name = BINARY %s AND BINARY option_value = BINARY %s AND ( BINARY autoload = BINARY %s OR BINARY autoload = BINARY %s )',
					$this->table,
					$new,
					self::OPTION,
					$old,
					self::NON_AUTOLOAD_VALUES[0],
					self::NON_AUTOLOAD_VALUES[1]
				);
				$this->connection->last_error = '';
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Exact byte CAS with prepared table and values.
				$result = $this->connection->query( $query );
				if ( $this->read_failed() ) {
					throw new DatabaseLifecycleFailure( 'selection_write_failed' );
				}
			}
			if ( ! in_array( $result, array( 0, 1 ), true ) ) {
				throw new DatabaseLifecycleFailure( 'selection_write_failed' );
			}
			if ( 0 === $result ) {
				return false;
			}
			if ( $this->read( true ) !== $next ) {
				throw new DatabaseLifecycleFailure( 'selection_verification_failed' );
			}
			return true;
		} finally {
			if ( $this->in_selection_transaction ) {
				$this->storage_verified = false;
			} else {
				$this->invalidate_cache();
			}
		}
	}

	/** @param array<string, mixed> $state */
	private function encode( array $state ): string {
		$keys = array( 'schema', 'selection_generation', 'selected', 'declaration', 'approval', 'approved_api_formats' );
		if ( isset( $state['native_mutation'] ) ) {
			$keys[] = 'native_mutation';
		}
		if ( array_keys( $state ) !== $keys || 1 !== $state['schema'] || ! is_int( $state['selection_generation'] ) || $state['selection_generation'] < 0 || ! in_array( $state['selected'], array( 'bundled', 'replacement' ), true ) || ! in_array( $state['approval'], array( 'approved', 'revoked' ), true ) || ! is_array( $state['declaration'] ) ) {
			throw new \InvalidArgumentException( 'Invalid GitHub selection.' );
		}
		$declaration = new GitHubReplacementDeclaration( $state['declaration'] );
		$manifest    = get_object_vars( $declaration );
		unset( $manifest['implementation_fingerprint'] );
		if ( $manifest !== $state['declaration'] || $state['approved_api_formats'] !== $declaration->api_credential_formats ) {
			throw new \InvalidArgumentException( 'Invalid approved GitHub contract.' );
		}
		if ( isset( $state['native_mutation'] ) ) {
			$marker = $state['native_mutation'];
			if ( ! is_array( $marker ) || array_keys( $marker ) !== array( 'selection_generation', 'owner_tag', 'target_type', 'installed_identity', 'phase' ) || ! is_int( $marker['selection_generation'] ) || $marker['selection_generation'] < 0 || $marker['selection_generation'] > $state['selection_generation'] || ! is_string( $marker['owner_tag'] ) || 1 !== preg_match( '/\A[a-f0-9]{32}\z/D', $marker['owner_tag'] ) || ! in_array( $marker['target_type'], array( 'plugin', 'theme' ), true ) || ! is_string( $marker['installed_identity'] ) || '' === $marker['installed_identity'] || strlen( $marker['installed_identity'] ) > ( 'plugin' === $marker['target_type'] ? 255 : 191 ) || preg_match( '/[\x00-\x1f\x7f]/', $marker['installed_identity'] ) || ! in_array( $marker['phase'], array( 'started', 'finished' ), true ) ) {
				throw new \InvalidArgumentException( 'Invalid native mutation owner.' );
			}
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Canonical authority bytes must throw on invalid data, not sanitize it.
		return json_encode( $state, JSON_THROW_ON_ERROR );
	}

	/**
	 * @param array<string, mixed> $old
	 * @param array<string, mixed> $next
	 */
	private function validate_transition( array $old, array $next ): void {
		$before = $old;
		$after  = $next;
		unset( $before['native_mutation'], $after['native_mutation'] );
		$marker      = $old['native_mutation'] ?? null;
		$next_marker = $next['native_mutation'] ?? null;
		if ( $before === $after && $marker !== $next_marker ) {
			if ( null === $next_marker || ( null === $marker && is_array( $next_marker ) && 'started' === $next_marker['phase'] && $next_marker['selection_generation'] === $old['selection_generation'] && 'approved' === $old['approval'] ) ) {
				return;
			}
			// The owning request records that it ended; nothing else about the hold may change.
			if ( is_array( $marker ) && 'started' === $marker['phase'] && array_replace( $marker, array( 'phase' => 'finished' ) ) === $next_marker ) {
				return;
			}
		}
		if ( is_int( $old['selection_generation'] ) && $old['selection_generation'] < PHP_INT_MAX && $next['selection_generation'] === $old['selection_generation'] + 1 ) {
			if ( null === $marker && null === $next_marker ) {
				return;
			}
			$before['selection_generation'] = $next['selection_generation'];
			$before['approval']             = 'revoked';
			if ( 'approved' === $old['approval'] && $before === $after && $marker === $next_marker ) {
				return;
			}
		}
		throw new \InvalidArgumentException( 'Invalid GitHub selection transition.' );
	}

	/**
	 * Internal selection transitions and native markers; never nest inside an admission transaction.
	 * SET TRANSACTION rejects an already active transaction without committing it.
	 * On real wpdb, pin the active mysqli handle through COMMIT so WordPress query filters and
	 * transparent reconnect retries cannot execute inside or succeed on a fresh session after
	 * rollback. Invoke $on_committed only after COMMIT succeeds, and defer cache callbacks until
	 * COMMIT or ROLLBACK closes the transaction while clearing storage_verified unconditionally.
	 *
	 * @template T
	 * @param callable(\Closure(string, list<int|string>, 'affected'|'var'|'results'): (int|string|list<object>|null)): T $operation No credentials, provider I/O or filesystem mutation.
	 * @param (callable(T): void)|null                                                                                     $on_committed
	 * @return T
	 */
	public function selection_transaction( callable $operation, ?callable $on_committed = null ): mixed {
		if ( $this->commit_uncertain || $this->in_selection_transaction ) {
			throw new DatabaseLifecycleFailure( 'selection_transaction_failed' );
		}
		$wpdb = $this->connection;
		if ( $wpdb instanceof \wpdb ) {
			if ( '' === $this->table || str_contains( $this->table, "\0" ) ) {
				throw new DatabaseLifecycleFailure( 'selection_transaction_failed' );
			}
			$this->require_storage();
			list( $pinned_dbh, $pinned_thread_id ) = $this->pin_wpdb_session( $wpdb );
			$this->pinned_mysqli_query( $pinned_dbh, 'SET TRANSACTION ISOLATION LEVEL SERIALIZABLE', 'selection_transaction_failed' );
		} else {
			$pinned_dbh       = null;
			$pinned_thread_id = null;
			$this->transaction_boundary( 'SET TRANSACTION ISOLATION LEVEL SERIALIZABLE', 'selection_transaction_failed' );
		}
		$transaction_closed = false;
		try {
			$this->in_selection_transaction = true;
			$this->pinned_dbh               = $pinned_dbh;
			$this->pinned_thread_id         = $pinned_thread_id;
			try {
				$this->commit_uncertain = true;
				if ( null !== $pinned_dbh ) {
					$this->pinned_mysqli_query( $pinned_dbh, 'START TRANSACTION', 'selection_transaction_failed' );
				} else {
					$this->transaction_boundary( 'START TRANSACTION', 'selection_transaction_failed' );
				}
				$this->commit_uncertain = false;
				$execute                = $this->transaction_executor( $pinned_dbh );
				$result                 = $operation( $execute );
				$this->assert_pinned_session();
				// Latch before COMMIT: thrown errors and false replies are equally uncertain.
				$this->commit_uncertain = true;
				if ( null !== $pinned_dbh ) {
					$this->pinned_mysqli_query( $pinned_dbh, 'COMMIT', 'selection_commit_uncertain' );
				} else {
					$this->transaction_boundary( 'COMMIT', 'selection_commit_uncertain' );
				}
				$this->commit_uncertain = false;
				$transaction_closed     = true;
			} catch ( \Throwable $failure ) {
				try {
					if ( null !== $pinned_dbh ) {
						$this->pinned_mysqli_query( $pinned_dbh, 'ROLLBACK', 'selection_rollback_uncertain' );
					} else {
						$this->transaction_boundary( 'ROLLBACK', 'selection_rollback_uncertain' );
					}
					$transaction_closed = true;
				} catch ( \Throwable ) {
					$this->commit_uncertain = true;
				}
				throw $failure;
			} finally {
				$this->in_selection_transaction = false;
				$this->pinned_dbh               = null;
				$this->pinned_thread_id         = null;
			}

			if ( null !== $on_committed ) {
				$on_committed( $result );
			}
			return $result;
		} finally {
			$this->storage_verified = false;
			if ( $transaction_closed ) {
				$this->invalidate_cache();
			} else {
				$this->transaction_open_uncertain = true;
			}
		}
	}

	/**
	 * Run a callback-free SQL operation inside one single-session transaction when no
	 * native_mutation hold is present. On real wpdb, pin the active mysqli handle through
	 * COMMIT so WordPress query filters and transparent reconnect retries cannot run inside
	 * or escape the open transaction. Invoke $on_committed only after COMMIT succeeds, and
	 * defer cache callbacks until COMMIT or ROLLBACK closes the transaction while clearing
	 * storage_verified unconditionally.
	 *
	 * @template T
	 * @param callable(callable(string, list<string>, 'affected'|'var'): (int|string|null)): T $operation
	 * @param (callable(T): void)|null $on_committed
	 * @return T|null Null when a native_mutation hold exists; otherwise the operation result.
	 * @phpstan-impure Reads and may mutate database state inside the transaction.
	 */
	public function without_native_mutation_hold( callable $operation, ?callable $on_committed = null ): mixed {
		if ( $this->commit_uncertain || $this->in_selection_transaction || '' === $this->table || str_contains( $this->table, "\0" ) ) {
			throw new DatabaseLifecycleFailure( 'selection_transaction_failed' );
		}
		$this->require_storage();
		if ( $this->connection instanceof \wpdb ) {
			return $this->without_native_mutation_hold_pinned_wpdb( $this->connection, $operation, $on_committed );
		}
		return $this->without_native_mutation_hold_schema_connection( $operation, $on_committed );
	}

	/**
	 * @template T
	 * @param callable(callable(string, list<string>, 'affected'|'var'): (int|string|null)): T $operation
	 * @param (callable(T): void)|null $on_committed
	 * @return T|null
	 */
	private function without_native_mutation_hold_pinned_wpdb( \wpdb $wpdb, callable $operation, ?callable $on_committed ): mixed {
		list( $dbh, $pinned_thread_id ) = $this->pin_wpdb_session( $wpdb );
		$this->pinned_mysqli_query( $dbh, 'SET TRANSACTION ISOLATION LEVEL SERIALIZABLE', 'selection_transaction_failed' );
		$transaction_closed = false;
		try {
			$this->in_selection_transaction = true;
			$this->pinned_dbh               = $dbh;
			$this->pinned_thread_id         = $pinned_thread_id;
			try {
				$this->commit_uncertain = true;
				$this->pinned_mysqli_query( $dbh, 'START TRANSACTION', 'selection_transaction_failed' );
				$this->commit_uncertain = false;

				$selection_sql = $this->format_pinned_sql(
					$dbh,
					'SELECT option_name, option_value, autoload FROM %i WHERE option_name = %s LIMIT 2 FOR UPDATE',
					array( $this->table, self::OPTION )
				);
				$rows          = $this->pinned_mysqli_objects( $dbh, $selection_sql, 'selection_read_failed' );
				$state         = $this->decode_selection_rows( $rows );
				if ( array_key_exists( 'native_mutation', $state ) ) {
					$this->assert_pinned_session();
					$this->commit_uncertain = true;
					$this->pinned_mysqli_query( $dbh, 'COMMIT', 'selection_commit_uncertain' );
					$this->commit_uncertain = false;
					$transaction_closed     = true;
					return null;
				}

				$execute = $this->scalar_transaction_executor( $dbh );
				$result  = $operation( $execute );
				$this->assert_pinned_session();
				$this->commit_uncertain = true;
				$this->pinned_mysqli_query( $dbh, 'COMMIT', 'selection_commit_uncertain' );
				$this->commit_uncertain = false;
				$transaction_closed     = true;
			} catch ( \Throwable $failure ) {
				try {
					$this->pinned_mysqli_query( $dbh, 'ROLLBACK', 'selection_rollback_uncertain' );
					$transaction_closed = true;
				} catch ( \Throwable ) {
					$this->commit_uncertain = true;
				}
				throw $failure;
			} finally {
				$this->in_selection_transaction = false;
				$this->pinned_dbh               = null;
				$this->pinned_thread_id         = null;
			}

			if ( null !== $on_committed ) {
				$on_committed( $result );
			}
			return $result;
		} finally {
			$this->storage_verified = false;
			if ( $transaction_closed ) {
				$this->invalidate_cache();
			} else {
				$this->transaction_open_uncertain = true;
			}
		}
	}

	/**
	 * @template T
	 * @param callable(callable(string, list<string>, 'affected'|'var'): (int|string|null)): T $operation
	 * @param (callable(T): void)|null $on_committed
	 * @return T|null
	 */
	private function without_native_mutation_hold_schema_connection( callable $operation, ?callable $on_committed ): mixed {
		$this->transaction_boundary( 'SET TRANSACTION ISOLATION LEVEL SERIALIZABLE', 'selection_transaction_failed' );
		$transaction_closed = false;
		try {
			$this->in_selection_transaction = true;
			try {
				$this->commit_uncertain = true;
				$this->transaction_boundary( 'START TRANSACTION', 'selection_transaction_failed' );
				$this->commit_uncertain = false;

				$state = $this->read( true );
				if ( array_key_exists( 'native_mutation', $state ) ) {
					$this->commit_uncertain = true;
					$this->transaction_boundary( 'COMMIT', 'selection_commit_uncertain' );
					$this->commit_uncertain = false;
					$transaction_closed     = true;
					return null;
				}

				$execute                = $this->scalar_transaction_executor( null );
				$result                 = $operation( $execute );
				$this->commit_uncertain = true;
				$this->transaction_boundary( 'COMMIT', 'selection_commit_uncertain' );
				$this->commit_uncertain = false;
				$transaction_closed     = true;
			} catch ( \Throwable $failure ) {
				try {
					$this->transaction_boundary( 'ROLLBACK', 'selection_rollback_uncertain' );
					$transaction_closed = true;
				} catch ( \Throwable ) {
					$this->commit_uncertain = true;
				}
				throw $failure;
			} finally {
				$this->in_selection_transaction = false;
			}

			if ( null !== $on_committed ) {
				$on_committed( $result );
			}
			return $result;
		} finally {
			$this->storage_verified = false;
			if ( $transaction_closed ) {
				$this->invalidate_cache();
			} else {
				$this->transaction_open_uncertain = true;
			}
		}
	}

	/**
	 * @return \Closure(string, list<int|string>, 'affected'|'var'|'results'): (int|string|list<object>|null)
	 */
	// phpcs:ignore WordPress.DB.RestrictedClasses.mysql__mysqli -- Execute prepared single-session SQL on the pinned mysqli handle or SchemaConnection inside an active transaction.
	private function transaction_executor( ?\mysqli $dbh ): \Closure {
		return function ( string $query, array $arguments, string $mode ) use ( $dbh ): int|string|array|null {
			if ( null !== $dbh ) {
				$this->assert_pinned_session();
				$sql = $this->format_pinned_sql( $dbh, $query, array_values( $arguments ) );
				if ( 'results' === $mode ) {
					return $this->pinned_mysqli_objects( $dbh, $sql, 'selection_read_failed' );
				}
				if ( 'var' === $mode ) {
					$result = $this->pinned_mysqli_query( $dbh, $sql, 'selection_read_failed' );
					// phpcs:ignore WordPress.DB.RestrictedClasses.mysql__mysqli -- Read single-scalar row from buffered mysqli result inside pinned transaction.
					if ( ! $result instanceof \mysqli_result ) {
						throw new DatabaseLifecycleFailure( 'selection_read_failed' );
					}
					try {
						$row = $result->fetch_row();
						return is_array( $row ) && array_key_exists( 0, $row ) && is_string( $row[0] ) ? $row[0] : null;
					} finally {
						$result->free();
					}
				}
				if ( 'affected' === $mode ) {
					return $this->pinned_mysqli_affected( $dbh, $sql, 'selection_write_failed' );
				}
				throw new \InvalidArgumentException( 'Unsupported pinned SQL mode.' );
			}

			$sql = $this->connection->prepare( $query, ...$arguments );
			if ( ! is_string( $sql ) || '' === $sql ) {
				throw new DatabaseLifecycleFailure( 'selection_transaction_failed' );
			}
			$this->connection->last_error = '';
			if ( 'results' === $mode ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared above on SchemaConnection.
				$rows = $this->connection->get_results( $sql );
				if ( ! is_array( $rows ) || $this->read_failed() ) {
					throw new DatabaseLifecycleFailure( 'selection_read_failed' );
				}
				$objects = array();
				foreach ( $rows as $row ) {
					if ( ! is_object( $row ) ) {
						throw new DatabaseLifecycleFailure( 'selection_read_failed' );
					}
					$objects[] = $row;
				}
				return $objects;
			}
			if ( 'var' === $mode ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared above on SchemaConnection.
				$value = $this->connection->get_var( $sql );
				if ( $this->read_failed() ) {
					throw new DatabaseLifecycleFailure( 'selection_read_failed' );
				}
				return is_string( $value ) ? $value : null;
			}
			if ( 'affected' === $mode ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared above on SchemaConnection.
				$affected = $this->connection->query( $sql );
				if ( ! is_int( $affected ) || $affected < 0 || $this->read_failed() ) {
					throw new DatabaseLifecycleFailure( 'selection_write_failed' );
				}
				return $affected;
			}
			throw new \InvalidArgumentException( 'Unsupported pinned SQL mode.' );
		};
	}

	/**
	 * @return \Closure(string, list<string>, 'affected'|'var'): (int|string|null)
	 */
	// phpcs:ignore WordPress.DB.RestrictedClasses.mysql__mysqli -- Narrow the shared SQL executor to scalar lock queries without changing the outer callable contract.
	private function scalar_transaction_executor( ?\mysqli $dbh ): \Closure {
		$execute = $this->transaction_executor( $dbh );
		return static function ( string $query, array $arguments, string $mode ) use ( $execute ): int|string|null {
			if ( ! in_array( $mode, array( 'affected', 'var' ), true ) ) {
				throw new \InvalidArgumentException( 'Unsupported pinned SQL mode.' );
			}
			$bound = array();
			foreach ( $arguments as $argument ) {
				if ( ! is_string( $argument ) ) {
					throw new \InvalidArgumentException( 'Unsupported pinned SQL argument type.' );
				}
				$bound[] = $argument;
			}
			$result = $execute( $query, $bound, $mode );
			if ( is_array( $result ) ) {
				throw new \InvalidArgumentException( 'Unsupported pinned SQL mode.' );
			}
			return $result;
		};
	}

	/**
	 * @return array{0: \mysqli, 1: string}
	 */
	// phpcs:ignore WordPress.DB.RestrictedClasses.mysql__mysqli -- Capture the active wpdb mysqli handle and server connection ID before opening a pinned transaction.
	private function pin_wpdb_session( \wpdb $wpdb ): array {
		$dbh = $wpdb->__get( 'dbh' );
		// phpcs:ignore WordPress.DB.RestrictedClasses.mysql__mysqli -- Pin the active wpdb mysqli handle through COMMIT so wpdb::query() cannot invoke query filters or reconnect into a new autocommit session.
		if ( ! $dbh instanceof \mysqli ) {
			throw new DatabaseLifecycleFailure( 'selection_transaction_failed' );
		}
		$thread_id = $this->valid_mysqli_thread_id( $dbh );
		if ( null === $thread_id ) {
			throw new DatabaseLifecycleFailure( 'selection_transaction_failed' );
		}
		return array( $dbh, $thread_id );
	}

	private function assert_pinned_session(): void {
		$pinned_dbh = $this->pinned_dbh;
		if ( null === $pinned_dbh || null === $this->pinned_thread_id || ! $this->connection instanceof \wpdb ) {
			return;
		}
		$current = $this->connection->__get( 'dbh' );
		// phpcs:ignore WordPress.DB.RestrictedClasses.mysql__mysqli -- Fail closed if wpdb reconnected or closed the mysqli handle mid-transaction.
		if ( $current !== $pinned_dbh || $this->valid_mysqli_thread_id( $pinned_dbh ) !== $this->pinned_thread_id ) {
			throw new DatabaseLifecycleFailure( 'selection_transaction_failed' );
		}
	}

	// phpcs:ignore WordPress.DB.RestrictedClasses.mysql__mysqli -- Read and validate the pinned mysqli server connection ID.
	private function valid_mysqli_thread_id( \mysqli $dbh ): ?string {
		$thread_id = (string) $dbh->thread_id;
		return 1 === preg_match( '/\A[1-9]\d*\z/D', $thread_id ) ? $thread_id : null;
	}

	/**
	 * @return list<object>
	 */
	// phpcs:ignore WordPress.DB.RestrictedClasses.mysql__mysqli -- Fetch object rows directly from the pinned mysqli handle inside an open transaction.
	private function pinned_mysqli_objects( \mysqli $dbh, string $sql, string $reason ): array {
		$selected = $this->pinned_mysqli_query( $dbh, $sql, $reason );
		// phpcs:ignore WordPress.DB.RestrictedClasses.mysql__mysqli -- Verify buffered mysqli result set before reading rows.
		if ( ! $selected instanceof \mysqli_result ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- DatabaseLifecycleFailure displays fixed REQUIREMENT text; private helper supplies only internal reason codes.
			throw new DatabaseLifecycleFailure( $reason );
		}
		try {
			$rows = array();
			$row  = $selected->fetch_object();
			while ( is_object( $row ) ) {
				$rows[] = $row;
				$row    = $selected->fetch_object();
			}
			return $rows;
		} finally {
			$selected->free();
		}
	}

	// phpcs:ignore WordPress.DB.RestrictedClasses.mysql__mysqli -- Execute write SQL directly on the pinned mysqli handle and read exact affected rows.
	private function pinned_mysqli_affected( \mysqli $dbh, string $sql, string $reason ): int {
		$result = $this->pinned_mysqli_query( $dbh, $sql, $reason );
		// phpcs:ignore WordPress.DB.RestrictedClasses.mysql__mysqli -- Reject result sets returned by write queries.
		if ( $result instanceof \mysqli_result ) {
			$result->free();
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- DatabaseLifecycleFailure displays fixed REQUIREMENT text; private helper supplies only internal reason codes.
			throw new DatabaseLifecycleFailure( $reason );
		}
		// phpcs:ignore WordPress.DB.RestrictedClasses.mysql__mysqli -- Read affected_rows directly from the pinned mysqli handle without triggering wpdb reconnects.
		$affected = $dbh->affected_rows;
		if ( ! is_int( $affected ) || $affected < 0 ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- DatabaseLifecycleFailure displays fixed REQUIREMENT text; private helper supplies only internal reason codes.
			throw new DatabaseLifecycleFailure( $reason );
		}
		return $affected;
	}

	// phpcs:ignore WordPress.DB.RestrictedClasses.mysql__mysqli -- Run raw SQL directly on the pinned mysqli handle so wpdb::query() cannot invoke query filters or reconnect retries inside an active transaction.
	private function pinned_mysqli_query( \mysqli $dbh, string $sql, string $reason ): \mysqli_result|bool {
		try {
			$result = $dbh->query( $sql );
		} catch ( \Throwable ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- DatabaseLifecycleFailure displays fixed REQUIREMENT text; private helper supplies only internal reason codes.
			throw new DatabaseLifecycleFailure( $reason );
		}
		if ( false === $result || 0 !== $dbh->errno ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- DatabaseLifecycleFailure displays fixed REQUIREMENT text; private helper supplies only internal reason codes.
			throw new DatabaseLifecycleFailure( $reason );
		}
		return $result;
	}

	/**
	 * @param list<mixed> $arguments
	 */
	// phpcs:ignore WordPress.DB.RestrictedClasses.mysql__mysqli -- Escape literals against the pinned mysqli handle so wpdb::prepare() never invokes external state inside the open transaction.
	private function format_pinned_sql( \mysqli $dbh, string $query, array $arguments ): string {
		$index     = 0;
		$formatted = preg_replace_callback(
			'/%([A-Za-z])?/',
			static function ( array $matches ) use ( $dbh, $arguments, &$index ): string {
				if ( ! isset( $matches[1] ) || ! in_array( $matches[1], array( 'd', 'i', 's' ), true ) || ! array_key_exists( $index, $arguments ) ) {
					throw new \InvalidArgumentException( 'Unsupported pinned SQL placeholder.' );
				}
				$value = $arguments[ $index ];
				++$index;
				if ( 'd' === $matches[1] ) {
					if ( ! is_int( $value ) ) {
						throw new \InvalidArgumentException( 'Unsupported pinned SQL argument type.' );
					}
					return (string) $value;
				}
				if ( ! is_string( $value ) ) {
					throw new \InvalidArgumentException( 'Unsupported pinned SQL argument type.' );
				}
				if ( 'i' === $matches[1] ) {
					if ( '' === $value || str_contains( $value, "\0" ) ) {
						throw new DatabaseLifecycleFailure( 'selection_transaction_failed' );
					}
					return '`' . str_replace( '`', '``', $value ) . '`';
				}
				try {
					$escaped = $dbh->real_escape_string( $value );
				} catch ( \Throwable ) {
					throw new DatabaseLifecycleFailure( 'selection_transaction_failed' );
				}
				return "'" . $escaped . "'";
			},
			$query
		);
		if ( ! is_string( $formatted ) || count( $arguments ) !== $index ) {
			throw new \InvalidArgumentException( 'Unexpected pinned SQL argument count.' );
		}
		return $formatted;
	}

	private function transaction_boundary( string $statement, string $reason ): void {
		$this->connection->last_error = '';
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Only fixed transaction statements supplied by the private lifecycle above.
		if ( false === $this->connection->query( $statement ) || $this->read_failed() ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- DatabaseLifecycleFailure displays fixed REQUIREMENT text; this private caller supplies only internal reason codes.
			throw new DatabaseLifecycleFailure( $reason );
		}
	}

	/**
	 * Verify the options table's shape unless it has passed since the last
	 * invalidate_cache(). A failed check sets nothing and refuses again on the
	 * next call. The row itself is always read fresh.
	 */
	private function require_storage(): void {
		if ( ! $this->storage_verified ) {
			( new Database( $this->connection ) )->require_selection_storage( $this->table );
			$this->storage_verified = true;
		}
	}

	/** Read the mutable connection status after each database operation. */
	private function read_failed(): bool {
		return '' !== $this->connection->last_error;
	}

	/**
	 * Evict again after the caller finishes its transaction, including uncertain outcomes.
	 * Invalidating also forces the next read or write to verify the options table's shape
	 * again: a write is where exactness depends on that shape, so it is re-checked after each.
	 */
	public function invalidate_cache(): void {
		$this->storage_verified = false;
		wp_cache_delete( self::OPTION, 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
	}
}
