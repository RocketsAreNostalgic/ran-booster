<?php

declare(strict_types=1);

namespace RAN\Tests\Storage;

use RAN\Storage\SchemaConnection;

/** Deterministic SQL boundary double; installed tests prove real database semantics. */
final class SelectionDatabase extends SchemaConnection {
	/** @var list<object{option_name:string, option_value:string, autoload:string}&\stdClass> */
	public array $rows = array();
	/** @var list<string> */
	public array $queries          = array();
	public string $engine          = 'InnoDB';
	public bool $unique            = true;
	public bool $prefix            = false;
	public bool $fail_write        = false;
	public bool $corrupt_readback  = false;
	public bool $fail_commit       = false;
	public bool $commit_applied    = false;
	public bool $throw_commit      = false;
	public bool $fail_rollback     = false;
	public bool $throw_rollback    = false;
	public ?string $boundary_error = null;
	/** @var list<object{option_name:string, option_value:string, autoload:string}&\stdClass>|null */
	public ?array $transaction_rows = null;

	public function query( string $query ): int|bool {
		$this->queries[] = $query;
		if ( $this->boundary_error === $query ) {
			$this->last_error = 'boundary failed';
		}
		if ( 'SET TRANSACTION ISOLATION LEVEL SERIALIZABLE' === $query ) {
			return null === $this->transaction_rows;
		}
		if ( 'START TRANSACTION' === $query ) {
			$this->transaction_rows = array_map( static fn ( object $row ): object => clone $row, $this->rows );
			return true;
		}
		if ( 'COMMIT' === $query ) {
			if ( ( ! $this->fail_commit && ! $this->throw_commit ) || $this->commit_applied ) {
				$this->transaction_rows = null;
			}
			if ( $this->throw_commit ) {
				throw new \RuntimeException( 'commit threw' );
			}
			return ! $this->fail_commit;
		}
		if ( 'ROLLBACK' === $query ) {
			if ( $this->throw_rollback ) {
				throw new \RuntimeException( 'rollback threw' );
			}
			if ( $this->fail_rollback ) {
				return false;
			}
			$this->rows             = $this->transaction_rows ?? $this->rows;
			$this->transaction_rows = null;
			return true;
		}
		$data = $this->decode( $query );
		if ( str_starts_with( $data[0], 'INSERT' ) ) {
			$result = 0;
			if ( array() === $this->rows ) {
				$this->rows[] = (object) array(
					'option_name'  => (string) $data[1][1],
					'option_value' => (string) $data[1][2],
					'autoload'     => (string) $data[1][3],
				);
				$result       = 1;
			}
		} elseif ( str_starts_with( $data[0], 'UPDATE' ) ) {
			$result = 0;
			foreach ( $this->rows as $row ) {
				if ( $row->option_name === $data[1][2] && $row->option_value === $data[1][3] && $row->autoload === $data[1][4] ) {
					$row->option_value = (string) $data[1][1];
					++$result;
				}
			}
		} else {
			throw new \LogicException( 'Unexpected transaction or write.' );
		}
		if ( $this->corrupt_readback && $this->rows ) {
			$this->rows[0]->option_value = '{}';
		}
		return $this->fail_write ? false : $result;
	}

	public function get_charset_collate(): string {
		return '';
	}
	public function esc_like( string $value ): string {
		return $value;
	}
	public function prepare( string $query, mixed ...$arguments ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Deterministic SQL-double payload, not WordPress data.
		return json_encode( array( $query, $arguments ), JSON_THROW_ON_ERROR );
	}
	public function get_var( string $query ): mixed {
		throw new \LogicException( $query );
	}
	public function get_row( string $query ): object {
		$this->queries[] = $query;
		return (object) array( 'Engine' => $this->engine );
	}

	/** @return list<object> */
	public function get_results( string $query ): array {
		$this->queries[] = $query;
		$sql             = $this->decode( $query )[0];
		if ( str_starts_with( $sql, 'SHOW COLUMNS' ) ) {
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
		if ( str_starts_with( $sql, 'SHOW INDEX' ) ) {
			return array(
				(object) array(
					'Key_name'     => 'option_name',
					'Non_unique'   => $this->unique ? 0 : 1,
					'Seq_in_index' => 1,
					'Column_name'  => 'option_name',
					'Sub_part'     => $this->prefix ? 10 : null,
				),
			);
		}
		if ( str_starts_with( $sql, 'SELECT' ) ) {
			return array_map( static fn ( object $row ): object => clone $row, $this->rows );
		}
		throw new \LogicException( $query );
	}

	/** @return array{string, list<scalar|null>} */
	private function decode( string $query ): array {
		$data = json_decode( $query, true, 8, JSON_THROW_ON_ERROR );
		if ( ! is_array( $data ) || ! is_string( $data[0] ?? null ) || ! is_array( $data[1] ?? null ) || ! array_is_list( $data[1] ) ) {
			throw new \LogicException();
		}
		foreach ( $data[1] as $value ) {
			if ( ! is_scalar( $value ) && null !== $value ) {
				throw new \LogicException();
			}
		}
		return array( $data[0], $data[1] );
	}
}
