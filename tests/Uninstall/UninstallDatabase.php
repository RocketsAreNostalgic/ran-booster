<?php

declare(strict_types=1);

namespace RAN\Tests\Uninstall;

final class UninstallDatabase extends \RAN\Storage\SchemaConnection {

	public string $prefix      = 'wp_';
	public string $base_prefix = 'wp_';
	public string $options     = 'wp_options';
	public string $usermeta    = 'wp_usermeta';

	/** @var list<string> */
	public array $tables = array();

	/** @var array<string, list<int>> */
	public array $user_meta = array();

	public ?string $failure_contains      = null;
	public ?string $read_failure_contains = null;
	/** @var list<array{state:?string,resolved_at:mixed,resolved_by:mixed,provider:string}> */
	public array $attempts = array();
	/** @var list<string> */
	public array $reads = array();

	public function get_charset_collate(): string {
		return '';
	}
	public function esc_like( string $value ): string {
		return $value;
	}
	public function get_var( string $query ): mixed {
		$this->reads[] = $query;
		$this->last_error = '';
		if ( null !== $this->read_failure_contains && str_contains( $query, $this->read_failure_contains ) ) {
			$this->last_error = 'synthetic read failure';
			return null;
		}
		if ( preg_match( "/^SELECT 1 FROM `wp_options` WHERE option_name = '([^']+)' LIMIT 1$/", $query, $matches ) === 1 ) {
			$name = stripslashes( $matches[1] );
			return array_key_exists( $name, $GLOBALS['ran_booster_uninstall_options'] ) ? '1' : null;
		}
		return null;
	}
	public function get_row( string $query ): mixed {
		unset( $query );
		return (object) array( 'Engine' => 'InnoDB' );
	}

	public function get_results( string $query ): mixed {
		$this->reads[] = $query;
		if ( null !== $this->read_failure_contains && str_contains( $query, $this->read_failure_contains ) ) {
			$this->last_error = 'synthetic read failure';
			return null;
		}
		if ( str_starts_with( $query, 'SHOW COLUMNS' ) ) {
			return array_map(
				static fn ( string $name ): object => (object) array(
					'Field'   => $name,
					'Type'    => 'longtext',
					'Null'    => 'NO',
					'Default' => null,
					'Extra'   => '',
				),
				array( 'option_name', 'option_value', 'autoload' )
			);
		}
		if ( str_starts_with( $query, 'SHOW INDEX' ) ) {
			return array(
				(object) array(
					'Key_name'     => 'option_name',
					'Non_unique'   => 0,
					'Seq_in_index' => 1,
					'Column_name'  => 'option_name',
					'Sub_part'     => null,
				),
			);
		}
		$options = $GLOBALS['ran_booster_uninstall_options'];
		if ( preg_match( "/^SELECT option_name FROM `wp_options` WHERE BINARY option_name = BINARY '([^']+)' LIMIT 1$/", $query, $matches ) === 1 ) {
			$name = stripslashes( $matches[1] );
			return array_key_exists( $name, $options ) ? array( (object) array( 'option_name' => $name ) ) : array();
		}
		if ( str_starts_with( $query, 'SELECT option_name FROM ' ) ) {
			$names = array( \RAN\Storage\Database::VERSION_OPTION, \RAN\Storage\GitHubSelectionStore::OPTION );
			return array_map( static fn ( string $name ): object => (object) array( 'option_name' => $name ), array_values( array_filter( $names, static fn ( string $name ): bool => array_key_exists( $name, $options ) ) ) );
		}
		if ( str_starts_with( $query, 'SELECT option_name, option_value, autoload' ) ) {
			$name = \RAN\Storage\GitHubSelectionStore::OPTION;
			return isset( $options[ $name ] ) ? array(
				(object) array(
					'option_name'  => $name,
					'option_value' => $options[ $name ],
					'autoload'     => 'no',
				),
			) : array();
		}
		if ( str_contains( $query, 'information_schema.TABLES' ) ) {
			$table = $this->prefix . 'ran_booster_deployment_attempts';
			return in_array( $table, $this->tables, true ) ? array( (object) array( 'table_name' => $table ) ) : array();
		}
		if ( str_starts_with( $query, 'SELECT id FROM ' ) ) {
			$expected = "SELECT id FROM `{$this->prefix}ran_booster_deployment_attempts` WHERE state IS NULL OR BINARY state NOT IN (BINARY 'succeeded', BINARY 'failed', BINARY 'needs_attention') OR (BINARY state = BINARY 'needs_attention' AND (resolved_at IS NULL OR resolved_by IS NULL)) LIMIT 1";
			if ( $expected !== $query ) {
				throw new \LogicException( 'Unexpected custody predicate.' ); }
			foreach ( $this->attempts as $attempt ) {
				if ( ! in_array( $attempt['state'], array( 'succeeded', 'failed', 'needs_attention' ), true ) || ( 'needs_attention' === $attempt['state'] && ( null === $attempt['resolved_at'] || null === $attempt['resolved_by'] ) ) ) {
					return array( (object) array( 'id' => 1 ) );
				}
			}
			return array();
		}
		throw new \LogicException( 'Unexpected cleanup read.' );
	}

	public function prepare( string $query, mixed ...$arguments ): string {
		foreach ( $arguments as $argument ) {
			$placeholder = substr( $query, (int) strpos( $query, '%' ), 2 );
			$replacement = '%i' === $placeholder
				? '`' . str_replace( '`', '``', (string) $argument ) . '`'
				: "'" . addslashes( (string) $argument ) . "'";
			$query       = (string) preg_replace( '/%[is]/', $replacement, $query, 1 );
		}

		return $query;
	}

	public function query( string $query ): int|false {
		if ( null !== $this->failure_contains && str_contains( $query, $this->failure_contains ) ) {
			return false;
		}
		if ( 1 === preg_match( '/^DROP TABLE IF EXISTS `([^`]+)`$/', $query, $matches ) ) {
			$this->tables = array_values( array_diff( $this->tables, array( $matches[1] ) ) );
			return 1;
		}
		if ( 1 === preg_match( "/^DELETE FROM `wp_usermeta` WHERE meta_key = '([^']+)'$/", $query, $matches ) ) {
			unset( $this->user_meta[ stripslashes( $matches[1] ) ] );
			return 1;
		}

		return 0;
	}
}
