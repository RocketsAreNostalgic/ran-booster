<?php

declare(strict_types=1);

namespace RAN\Storage;

use RAN\RepositoryProvider\ProviderCode;
use RuntimeException;

/**
 * Fail-closed, display-safe reads of managed packages using one credential.
 */
final class CredentialUsageReader {

	private const DISPLAY_LIMIT = 20;

	private Database $databaseLifecycle;

	public function __construct(
		private ?object $database = null,
		private ?string $tableName = null,
		?Database $databaseLifecycle = null
	) {
		$this->databaseLifecycle = $databaseLifecycle ?? new Database( $database );
	}

	/**
	 * @return array{total: int, packages: list<array{type: string, identifier: string, installed: bool}>}
	 */
	public function read( ProviderCode|string $provider, string $credentialId ): array {
		try {
			$this->databaseLifecycle->requireReady();
		} catch ( DatabaseCompatibilityFailure | DatabaseLifecycleFailure ) {
			throw new RuntimeException( 'Booster could not verify repository credential usage because database storage is unavailable.' );
		}

		$providerCode = $provider instanceof ProviderCode ? $provider : ProviderCode::parse( $provider );
		if ( 1 !== preg_match( '/^[A-Za-z0-9_-]{3,64}$/D', $credentialId ) ) {
			throw new RuntimeException( 'The repository credential identity is invalid.' );
		}

		$database = $this->database;
		if ( null === $database ) {
			global $wpdb;
			$database = $wpdb;
		}
		if ( ! is_object( $database ) ) {
			throw new RuntimeException( 'Booster could not verify repository credential usage.' );
		}
		$table      = $this->tableName ?? ran_booster_table_name();
		$where      = ' WHERE provider = %s AND credential_id = %s';
		$countQuery = $database->prepare( 'SELECT COUNT(*) FROM %i' . $where, $table, $providerCode->value, $credentialId );
		if ( ! is_string( $countQuery ) ) {
			throw new RuntimeException( 'Booster could not verify repository credential usage.' );
		}
		// This safety-critical check must read current references immediately before deletion.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		$count = $database->get_var( $countQuery );
		$this->assertQuerySucceeded( $database );
		if ( ! is_int( $count ) && ( ! is_string( $count ) || 1 !== preg_match( '/^(0|[1-9][0-9]*)$/D', $count ) ) ) {
			throw new RuntimeException( 'Booster could not verify repository credential usage.' );
		}

		$total = (int) $count;
		if ( $total < 0 || ( is_string( $count ) && (string) $total !== $count ) ) {
			throw new RuntimeException( 'Booster could not verify repository credential usage.' );
		}
		if ( 0 === $total ) {
			return array(
				'total'    => 0,
				'packages' => array(),
			);
		}

		$detailQuery = $database->prepare(
			'SELECT type, package FROM %i' . $where . ' ORDER BY type ASC, package ASC, id ASC LIMIT %d',
			$table,
			$providerCode->value,
			$credentialId,
			self::DISPLAY_LIMIT
		);
		if ( ! is_string( $detailQuery ) ) {
			throw new RuntimeException( 'Booster could not verify repository credential usage.' );
		}
		// This bounded list explains the live references that block credential deletion.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		$rows = $database->get_results( $detailQuery );
		$this->assertQuerySucceeded( $database );
		if ( ! is_array( $rows ) || count( $rows ) !== min( $total, self::DISPLAY_LIMIT ) ) {
			throw new RuntimeException( 'Booster could not verify repository credential usage.' );
		}

		$packages = array();
		foreach ( $rows as $row ) {
			if ( ! is_object( $row ) || ! isset( $row->type, $row->package ) || ! is_string( $row->package ) ) {
				throw new RuntimeException( 'Booster could not verify repository credential usage.' );
			}
			$typeValue = $row->type;
			if ( ! ( is_int( $typeValue ) && in_array( $typeValue, array( 1, 2 ), true ) )
				&& ! ( is_string( $typeValue ) && in_array( $typeValue, array( '1', '2' ), true ) ) ) {
				throw new RuntimeException( 'Booster could not verify repository credential usage.' );
			}
			$type       = (string) $typeValue;
			$identifier = $row->package;
			if ( ! in_array( $type, array( '1', '2' ), true ) || '' === $identifier || trim( $identifier ) !== $identifier || strlen( $identifier ) > 255 || preg_match( '/[\x00-\x1F\x7F]/', $identifier ) ) {
				throw new RuntimeException( 'Booster could not verify repository credential usage.' );
			}

			$packages[] = array(
				'type'       => '1' === $type ? 'plugin' : 'theme',
				'identifier' => $identifier,
				'installed'  => $this->isInstalled( $type, $identifier ),
			);
		}

		return array(
			'total'    => $total,
			'packages' => $packages,
		);
	}

	private function isInstalled( string $type, string $identifier ): bool {
		if ( '1' === $type ) {
			$segments = explode( '/', $identifier );
			if ( ! defined( 'WP_PLUGIN_DIR' )
				|| array_intersect( $segments, array( '.', '..' ) )
				|| 1 !== preg_match( '#^(?:[A-Za-z0-9._-]+/)*[A-Za-z0-9._-]+\.php$#D', $identifier ) ) {
				return false;
			}

			return is_file( WP_PLUGIN_DIR . '/' . $identifier );
		}

		if ( ! function_exists( 'get_theme_root' ) || in_array( $identifier, array( '.', '..' ), true ) || 1 !== preg_match( '/^[A-Za-z0-9._-]+$/D', $identifier ) ) {
			return false;
		}

		return is_dir( get_theme_root() . '/' . $identifier );
	}

	private function assertQuerySucceeded( object $database ): void {
		$error = property_exists( $database, 'last_error' ) ? trim( (string) $database->last_error ) : '';
		if ( '' !== $error ) {
			throw new RuntimeException( 'Booster could not verify repository credential usage.' );
		}
	}
}
