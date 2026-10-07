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

	private Database $database_lifecycle;

	public function __construct(
		private ?object $database = null,
		private ?string $table_name = null,
		?Database $database_lifecycle = null
	) {
		$this->database_lifecycle = $database_lifecycle ?? new Database( $database );
	}

	/**
	 * @return array{total: int, packages: list<array{type: string, identifier: string, installed: bool}>}
	 */
	public function read( ProviderCode|string $provider, string $credential_id ): array {
		try {
			$this->database_lifecycle->require_ready();
		} catch ( DatabaseCompatibilityFailure | DatabaseLifecycleFailure ) {
			throw new RuntimeException( 'Booster could not verify repository credential usage because database storage is unavailable.' );
		}

		$provider_code = $provider instanceof ProviderCode ? $provider : ProviderCode::parse( $provider );
		if ( 1 !== preg_match( '/^[A-Za-z0-9_-]{3,64}$/D', $credential_id ) ) {
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
		$table       = $this->table_name ?? ran_booster_table_name();
		$where       = ' WHERE provider = %s AND credential_id = %s';
		$count_query = $database->prepare( 'SELECT COUNT(*) FROM %i' . $where, $table, $provider_code->value, $credential_id );
		if ( ! is_string( $count_query ) ) {
			throw new RuntimeException( 'Booster could not verify repository credential usage.' );
		}
		// This safety-critical check must read current references immediately before deletion.
		$count = $database->get_var( $count_query );
		$this->assert_query_succeeded( $database );
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

		$detail_query = $database->prepare(
			'SELECT type, package FROM %i' . $where . ' ORDER BY type ASC, package ASC, id ASC LIMIT %d',
			$table,
			$provider_code->value,
			$credential_id,
			self::DISPLAY_LIMIT
		);
		if ( ! is_string( $detail_query ) ) {
			throw new RuntimeException( 'Booster could not verify repository credential usage.' );
		}
		// This bounded list explains the live references that block credential deletion.
		$rows = $database->get_results( $detail_query );
		$this->assert_query_succeeded( $database );
		if ( ! is_array( $rows ) || count( $rows ) !== min( $total, self::DISPLAY_LIMIT ) ) {
			throw new RuntimeException( 'Booster could not verify repository credential usage.' );
		}

		$packages = array();
		foreach ( $rows as $row ) {
			if ( ! is_object( $row ) || ! isset( $row->type, $row->package ) || ! is_string( $row->package ) ) {
				throw new RuntimeException( 'Booster could not verify repository credential usage.' );
			}
			$type_value = $row->type;
			if ( ! ( is_int( $type_value ) && in_array( $type_value, array( 1, 2 ), true ) )
				&& ! ( is_string( $type_value ) && in_array( $type_value, array( '1', '2' ), true ) ) ) {
				throw new RuntimeException( 'Booster could not verify repository credential usage.' );
			}
			$type       = (string) $type_value;
			$identifier = $row->package;
			if ( '' === $identifier || trim( $identifier ) !== $identifier || strlen( $identifier ) > 255 || preg_match( '/[\x00-\x1F\x7F]/', $identifier ) ) {
				throw new RuntimeException( 'Booster could not verify repository credential usage.' );
			}

			$packages[] = array(
				'type'       => '1' === $type ? 'plugin' : 'theme',
				'identifier' => $identifier,
				'installed'  => $this->is_installed( $type, $identifier ),
			);
		}

		return array(
			'total'    => $total,
			'packages' => $packages,
		);
	}

	private function is_installed( string $type, string $identifier ): bool {
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

	private function assert_query_succeeded( object $database ): void {
		$error = property_exists( $database, 'last_error' ) ? trim( (string) $database->last_error ) : '';
		if ( '' !== $error ) {
			throw new RuntimeException( 'Booster could not verify repository credential usage.' );
		}
	}
}
