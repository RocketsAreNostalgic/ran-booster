<?php

declare(strict_types=1);

namespace RAN\Storage;

use RAN\RepositoryProvider\GitHubReplacementDeclaration;

/**
 * One durable authority row. Callers own transactions and acquire this row before
 * adoption/attempt locks. Ordinary reads/CAS never own or repair transactions;
 * native_mutation_transaction() is the explicit non-nesting native lifecycle owner.
 * Transaction owners must invalidate_cache() after COMMIT/ROLLBACK or unknown outcomes.
 * Inside an existing transaction, authority reads require read(true), because
 * nonlocking reads can inherit an older REPEATABLE READ snapshot.
 * Native-marker clearing requires the caller's exact verified completion or protected recovery.
 */
final class GitHubSelectionStore {
	public const OPTION                   = 'ran_booster_github_selection';
	private bool $native_commit_uncertain = false;

	public function __construct( private \wpdb|SchemaConnection $connection, private string $table ) {
	}

	public function uses_connection( object $connection ): bool {
		return $this->connection === $connection;
	}

	/**
	 * Authoritative current read: option filters and object caches are not authority.
	 * Only WordPress's bounded serialized list of plugin basenames is accepted.
	 *
	 * @return list<string>
	 */
	public function active_plugins(): array {
		( new Database( $this->connection ) )->require_selection_storage( $this->table );
		$this->connection->last_error = '';
		$query                        = $this->connection->prepare( 'SELECT option_name, option_value FROM %i WHERE option_name = %s LIMIT 2 FOR UPDATE', $this->table, 'active_plugins' );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared current read bypasses REPEATABLE READ snapshots as well as WordPress caches.
		$rows = $this->connection->get_results( $query );
		if ( $this->read_failed() || ! is_array( $rows ) || 1 !== count( $rows ) || ! is_object( $rows[0] ) || 'active_plugins' !== ( $rows[0]->option_name ?? null ) || ! is_string( $rows[0]->option_value ?? null ) ) {
			throw new DatabaseLifecycleFailure( 'active_plugins_read_failed' );
		}
		$bytes = $rows[0]->option_value;
		if ( strlen( $bytes ) > 262144 || ! preg_match( '/\Aa:(0|[1-9][0-9]{0,3}):\{/', $bytes, $match ) || (int) $match[1] > 1024 ) {
			throw new DatabaseLifecycleFailure( 'active_plugins_invalid' );
		}
		$count   = (int) $match[1];
		$offset  = strlen( $match[0] );
		$plugins = array();
		for ( $index = 0; $index < $count; ++$index ) {
			if ( ! preg_match( '/\Gi:' . $index . ';s:([1-9][0-9]{0,2}):"/', $bytes, $item, 0, $offset ) || (int) $item[1] > 191 ) {
				throw new DatabaseLifecycleFailure( 'active_plugins_invalid' );
			}
			$offset  += strlen( $item[0] );
			$length   = (int) $item[1];
			$basename = substr( $bytes, $offset, $length );
			$offset  += $length;
			if ( ! preg_match( '/\A(?:[a-zA-Z0-9][a-zA-Z0-9_.-]*\/)?[a-zA-Z0-9][a-zA-Z0-9_.-]*\.php\z/D', $basename ) || '";' !== substr( $bytes, $offset, 2 ) || in_array( $basename, $plugins, true ) ) {
				throw new DatabaseLifecycleFailure( 'active_plugins_invalid' );
			}
			$offset   += 2;
			$plugins[] = $basename;
		}
		if ( '}' !== substr( $bytes, $offset ) ) {
			throw new DatabaseLifecycleFailure( 'active_plugins_invalid' );
		}
		sort( $plugins, SORT_STRING );
		return $plugins;
	}

	/** @return array<string, mixed> */
	public function read( bool $for_update = false ): array {
		( new Database( $this->connection ) )->require_selection_storage( $this->table );
		$this->connection->last_error = '';
		$query                        = $this->connection->prepare(
			'SELECT option_name, option_value, autoload FROM %i WHERE option_name = %s LIMIT 2' . ( $for_update ? ' FOR UPDATE' : '' ),
			$this->table,
			self::OPTION
		);
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared identifiers and values; locking suffix is a closed local boolean choice.
		$rows = $this->connection->get_results( $query );
		if ( $this->read_failed() || ! is_array( $rows ) || 1 !== count( $rows ) ) {
			throw new DatabaseLifecycleFailure( 'selection_read_failed' );
		}
		$row = $rows[0];
		if ( ! is_object( $row ) || ( $row->option_name ?? null ) !== self::OPTION || 'no' !== ( $row->autoload ?? null ) || ! is_string( $row->option_value ?? null ) || strlen( $row->option_value ) > 16384 ) {
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
		( new Database( $this->connection ) )->require_selection_storage( $this->table );
		$query = $this->connection->prepare( 'INSERT IGNORE INTO %i (option_name, option_value, autoload) VALUES (%s, %s, %s)', $this->table, self::OPTION, $this->encode( $initial ), 'no' );
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
		( new Database( $this->connection ) )->require_selection_storage( $this->table );
		$query = $this->connection->prepare( 'UPDATE %i SET option_value = %s WHERE BINARY option_name = BINARY %s AND BINARY option_value = BINARY %s AND BINARY autoload = BINARY %s', $this->table, $new, self::OPTION, $old, 'no' );
		try {
			$this->connection->last_error = '';
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Exact byte CAS with prepared table and values.
			$result = $this->connection->query( $query );
			if ( ! in_array( $result, array( 0, 1 ), true ) || $this->read_failed() ) {
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
			$this->invalidate_cache();
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
			if ( ! is_array( $marker ) || array_keys( $marker ) !== array( 'selection_generation', 'owner_tag', 'target_type', 'installed_identity', 'phase' ) || ! is_int( $marker['selection_generation'] ) || $marker['selection_generation'] < 0 || $marker['selection_generation'] > $state['selection_generation'] || ! is_string( $marker['owner_tag'] ) || 1 !== preg_match( '/\A[a-f0-9]{32}\z/D', $marker['owner_tag'] ) || ! in_array( $marker['target_type'], array( 'plugin', 'theme' ), true ) || ! is_string( $marker['installed_identity'] ) || '' === $marker['installed_identity'] || strlen( $marker['installed_identity'] ) > 191 || preg_match( '/[\x00-\x1f\x7f]/', $marker['installed_identity'] ) || 'started' !== $marker['phase'] ) {
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
			if ( null === $next_marker || ( null === $marker && is_array( $next_marker ) && $next_marker['selection_generation'] === $old['selection_generation'] && 'approved' === $old['approval'] ) ) {
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
	 * Internal native-marker lifecycle only; never nest inside an admission transaction.
	 * SET TRANSACTION rejects an already active transaction without committing it.
	 *
	 * @template T
	 * @param callable():T $operation No credentials, provider I/O or filesystem mutation.
	 * @return T
	 */
	public function native_mutation_transaction( callable $operation ): mixed {
		if ( $this->native_commit_uncertain ) {
			throw new DatabaseLifecycleFailure( 'selection_transaction_failed' );
		}
		$this->native_transaction_boundary( 'SET TRANSACTION ISOLATION LEVEL SERIALIZABLE', 'selection_transaction_failed' );
		try {
			$this->native_commit_uncertain = true;
			$this->native_transaction_boundary( 'START TRANSACTION', 'selection_transaction_failed' );
			$this->native_commit_uncertain = false;
			$result                        = $operation();
			// Latch before COMMIT: thrown errors and false replies are equally uncertain.
			$this->native_commit_uncertain = true;
			$this->native_transaction_boundary( 'COMMIT', 'selection_commit_uncertain' );
			$this->native_commit_uncertain = false;
			return $result;
		} catch ( \Throwable $failure ) {
			try {
				$this->native_transaction_boundary( 'ROLLBACK', 'selection_rollback_uncertain' );
			} catch ( \Throwable ) {
				$this->native_commit_uncertain = true;
			}
			throw $failure;
		} finally {
			$this->invalidate_cache();
		}
	}

	private function native_transaction_boundary( string $statement, string $reason ): void {
		$this->connection->last_error = '';
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Only fixed transaction statements supplied by the private lifecycle above.
		if ( false === $this->connection->query( $statement ) || $this->read_failed() ) {
			throw new DatabaseLifecycleFailure( $reason );
		}
	}

	/** Read the mutable connection status after each database operation. */
	private function read_failed(): bool {
		return '' !== $this->connection->last_error;
	}

	/** Evict again after the caller finishes its transaction, including uncertain outcomes. */
	public function invalidate_cache(): void {
		wp_cache_delete( self::OPTION, 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
	}
}
