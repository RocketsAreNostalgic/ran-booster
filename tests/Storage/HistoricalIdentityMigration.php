<?php

declare(strict_types=1);

namespace RAN\Tests\Storage;

use RAN\AdoptionIdentity;
use RAN\Storage\Database;
use RAN\Storage\DatabaseLifecycleFailure;
use RAN\Storage\SchemaConnection;

/** Historical schema-13 algorithm retained from Core82ba6dc; never loaded by production. */
final class HistoricalIdentityMigration {
	public function __construct( private Database $database ) {}

	/** Upgrade only the exact supported predecessor, including interrupted DDL. */
	public function migrate_adoption_identities( string $package_table, string $attempt_table ): void {
		$wpdb = $this->schema_connection();
		$this->verify_table( $attempt_table, $this->attempt_columns(), $this->attempt_indexes() );
		$actual              = $this->inspect_table( $package_table );
		$predecessor         = $this->package_columns();
		$predecessor['type'] = $predecessor['package_type'];
		unset( $predecessor['package_type'] );
		ksort( $predecessor, SORT_STRING );
		$without_token = $predecessor;
		unset( $without_token['adoption_token'] );
		$old_indexes                 = $this->package_indexes();
		$old_indexes['package_type'] = array( true, array( 'type', 'package' ) );
		if ( $actual['indexes'] === $old_indexes
			&& in_array( $actual['columns'], array( $without_token, $predecessor ), true ) ) {
			// Fence already-running schema-13 writers, which all name the mandatory type column.
			// The token-bearing predecessor is an interrupted migration from the unpublished candidate.
			$query = $actual['columns'] === $without_token
				? $wpdb->prepare( 'ALTER TABLE %i CHANGE COLUMN type package_type tinyint(3) unsigned NOT NULL, ADD adoption_token varchar(643) DEFAULT NULL', $package_table )
				: $wpdb->prepare( 'ALTER TABLE %i CHANGE COLUMN type package_type tinyint(3) unsigned NOT NULL', $package_table );
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The exact predecessor table is validated above and its identifier is prepared.
			if ( false === $wpdb->query( $query ) ) {
				throw new DatabaseLifecycleFailure( 'identity_schema_write_failed' );
			}
		} elseif ( $actual['columns'] !== $this->package_columns() || $actual['indexes'] !== $this->package_indexes() ) {
			throw new DatabaseLifecycleFailure( 'incompatible_schema' );
		}
		$this->verify_table( $package_table, $this->package_columns(), $this->package_indexes() );
		if ( false === $wpdb->query( 'SET TRANSACTION ISOLATION LEVEL SERIALIZABLE' ) || false === $wpdb->query( 'START TRANSACTION' ) ) {
			throw new DatabaseLifecycleFailure( 'identity_transaction_failed' );
		}
		try {
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id FOR UPDATE', $package_table ) );
			if ( '' !== $wpdb->last_error || ! is_array( $rows ) ) {
				throw new DatabaseLifecycleFailure( 'identity_read_failed' );
			}
			foreach ( $rows as $row ) {
				if ( ! is_object( $row ) || ! property_exists( $row, 'adoption_token' ) ) {
					throw new DatabaseLifecycleFailure( 'identity_read_failed' );
				}
				if ( null !== $row->adoption_token ) {
					continue;
				}
				$provider = $row->provider ?? null;
				$id       = $row->provider_repository_id ?? null;
				if ( ! is_string( $provider ) || ! is_string( $id ) ) {
					continue;
				}
				try {
					$token = AdoptionIdentity::create( $provider, $id );
				} catch ( \InvalidArgumentException ) {
					continue;
				}
				$row_id = $row->id ?? null;
				if ( ( ! is_int( $row_id ) && ! is_string( $row_id ) ) || 1 !== preg_match( '/\A[1-9][0-9]*\z/', (string) $row_id ) ) {
					throw new DatabaseLifecycleFailure( 'identity_read_failed' );
				}
				$query = $wpdb->prepare( 'UPDATE %i SET adoption_token = %s WHERE id = %s AND adoption_token IS NULL AND BINARY provider = BINARY %s AND BINARY provider_repository_id = BINARY %s', $package_table, $token, (string) $row_id, $provider, $id );
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- All values are prepared immediately above; the row remains locked.
				if ( 1 !== $wpdb->query( $query ) ) {
					throw new DatabaseLifecycleFailure( 'identity_write_failed' );
				}
				$saved = $wpdb->get_var( $wpdb->prepare( 'SELECT adoption_token FROM %i WHERE id = %s', $package_table, (string) $row_id ) );
				if ( $this->identity_read_failed() || $saved !== $token ) {
					throw new DatabaseLifecycleFailure( 'identity_verification_failed' );
				}
			}
			if ( false === $wpdb->query( 'COMMIT' ) ) {
				throw new DatabaseLifecycleFailure( 'identity_commit_failed' );
			}
		} catch ( \Throwable $failure ) {
			$wpdb->query( 'ROLLBACK' );
			throw $failure;
		}
	}

	/** @phpstan-impure */
	private function identity_read_failed(): bool {
		return '' !== $this->schema_connection()->last_error;
	}

	private function schema_connection(): \wpdb|SchemaConnection {
		$value = $this->invoke( 'schema_connection' );
		if ( ! $value instanceof \wpdb && ! $value instanceof SchemaConnection ) {
			throw new \LogicException();
		}
		return $value;
	}

	/** @return array<string,mixed> */
	private function inspect_table( string $table ): array {
		$value = $this->invoke( 'inspect_table', $table );
		if ( ! is_array( $value ) ) {
			throw new \LogicException();
		}
		return $value;
	}

	/** @return array<string,mixed> */
	private function package_columns(): array {
		$value = $this->invoke( 'package_columns' );
		if ( ! is_array( $value ) ) {
			throw new \LogicException();
		}
		return $value;
	}

	/** @return array<string,mixed> */
	private function package_indexes(): array {
		$value = $this->invoke( 'package_indexes' );
		if ( ! is_array( $value ) ) {
			throw new \LogicException();
		}
		return $value;
	}

	/** @return array<string,mixed> */
	private function attempt_columns(): array {
		$value = $this->invoke( 'attempt_columns' );
		if ( ! is_array( $value ) ) {
			throw new \LogicException();
		}
		return $value;
	}

	/** @return array<string,mixed> */
	private function attempt_indexes(): array {
		$value = $this->invoke( 'attempt_indexes' );
		if ( ! is_array( $value ) ) {
			throw new \LogicException();
		}
		return $value;
	}

	/** @param array<string,mixed> $columns
	 * @param array<string,mixed> $indexes
	 */
	private function verify_table( string $table, array $columns, array $indexes ): void {
		$this->invoke( 'verify_table', $table, $columns, $indexes );
	}

	private function invoke( string $method, mixed ...$arguments ): mixed {
		return ( new \ReflectionMethod( Database::class, $method ) )->invoke( $this->database, ...$arguments );
	}
}
