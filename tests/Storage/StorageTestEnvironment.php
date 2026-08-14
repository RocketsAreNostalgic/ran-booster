<?php

declare(strict_types=1);

// Focused WordPress function and database doubles necessarily use global fixtures.
// phpcs:disable

namespace {

	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', dirname( __DIR__ ) . '/fixtures/wordpress/' );
	}

	if ( ! function_exists( 'sanitize_text_field' ) ) {
		function sanitize_text_field( $value ) {
			return trim( strip_tags( (string) $value ) );
		}
	}

	if ( ! function_exists( '__' ) ) {
		function __( $text, $domain = 'default' ) {
			return (string) $text;
		}
	}

	if ( ! function_exists( 'ran_booster_table_name' ) ) {
		function ran_booster_table_name() {
			return 'wp_ran_booster_packages';
		}
	}

	if ( ! function_exists( 'get_option' ) ) {
		function get_option( $option, $default = false ) {
			global $ran_booster_storage_test_options;

			if ( array_key_exists( $option, $ran_booster_storage_test_options ) ) {
				return $ran_booster_storage_test_options[ $option ];
			}
			if ( \RAN\Storage\Database::VERSION_OPTION === $option
				&& ! ( $GLOBALS['ran_booster_storage_test_schema_unset'] ?? false ) ) {
				return \RAN\Storage\Database::$booster_db_version;
			}

			return $default;
		}
	}

	if ( ! function_exists( 'update_option' ) ) {
		function update_option( $option, $value, $autoload = null ) {
			global $ran_booster_storage_test_option_apply_write,
				$ran_booster_storage_test_option_write_result,
				$ran_booster_storage_test_options;

			if ( $ran_booster_storage_test_option_apply_write ?? true ) {
				$ran_booster_storage_test_options[ $option ] = $value;
			}

			return $ran_booster_storage_test_option_write_result ?? true;
		}
	}

	if ( ! function_exists( 'dbDelta' ) ) {
		function dbDelta( $sql ) {
			global $wpdb;

			$wpdb->schemas[] = (string) $sql;
			if ( method_exists( $wpdb, 'installSchema' ) ) {
				$wpdb->installSchema( (string) $sql );
			}

			return array();
		}
	}
}

namespace Tests\Storage {

	final class StorageTestWpdb {

		public string $prefix = 'wp_';
		public string $base_prefix = 'wp_';
		public string $options = 'wp_options';
		public string $last_error = '';

		/** @var list<string> */
		public array $schemas = array();
		/**
		 * @var array<string, array{
		 *     engine: string,
		 *     columns: array<string, string>,
		 *     columnMetadata: array<string, array{nullable: bool, default: ?string, extra: string}>,
		 *     indexes: array<string, array{unique: bool, columns: list<string>, prefixes: list<?int>}>
		 * }>
		 */
		public array $schemaTables = array();
		public string $schemaEngine = 'InnoDB';
		public string $optionsEngine = 'InnoDB';
		public string $serverInfo = '8.4.6';
		public string $innodbSupport = 'DEFAULT';
		public int $capabilityReads = 0;

		/** @var list<string> */
		public array $queries = array();
		public ?string $queryFailureContains = null;

		/** @var list<array<string, mixed>> */
		public array $rows = array();

		/** @var list<array{0: string, 1: array<string, mixed>}> */
		public array $inserts = array();

		/** @var list<array{0: string, 1: array<string, mixed>, 2: array<string, mixed>}> */
		public array $updates = array();

		/** @var list<array{0: string, 1: array<string, mixed>}> */
		public array $deletes = array();

		public ?object $row = null;
		public int|false|null $insertResult = null;
		/** @var array<string, mixed>|null */
		public ?array $insertRaceRow = null;
		public int|false|null $updateResult = null;
		public ?int $failUpdateNumber = null;
		public int|false|null $deleteResult = null;
		public bool $readFailure = false;
		public ?int $successfulReadsBeforeFailure = null;
		public bool $applyWrites = true;
		public bool $coercePrivateAsMysqlTinyint = false;
		/** @var list<array<string, mixed>>|null */
		private ?array $transactionRows = null;
		private int $updateCalls = 0;
		/** @var list<mixed>|null */
		public ?array $forcedResults = null;

		public function get_charset_collate(): string {
			return 'DEFAULT CHARACTER SET utf8mb4';
		}

		public function db_server_info(): string {
			return $this->serverInfo;
		}

		public function prepare( string $query, mixed ...$arguments ): string {
			foreach ( $arguments as $argument ) {
				$query = (string) preg_replace_callback(
					'/%[dis]/',
					static function ( array $match ) use ( $argument ): string {
						if ( '%i' === $match[0] ) {
							return '`' . str_replace( '`', '``', (string) $argument ) . '`';
						}

						if ( '%d' === $match[0] ) {
							return (string) (int) $argument;
						}

						return "'" . addslashes( (string) $argument ) . "'";
					},
					$query,
					1
				);
			}

			return $query;
		}

		public function esc_like( string $value ): string {
			return addcslashes( $value, '_%\\' );
		}

		public function query( string $query ): int|false {
			$this->queries[] = $query;
			if ( null !== $this->queryFailureContains && str_contains( $query, $this->queryFailureContains ) ) {
				return false;
			}
			if ( 1 === preg_match( '/^DROP TABLE IF EXISTS `([^`]+)`$/', $query, $matches ) ) {
				unset( $this->schemaTables[ $matches[1] ] );

				return 1;
			}
			if ( 1 === preg_match( '/^ALTER TABLE `([^`]+)`\s+(.+)$/s', $query, $matches )
				&& isset( $this->schemaTables[ $matches[1] ] )
			) {
				preg_match_all( '/DROP INDEX ([a-z][a-z0-9_]*)/i', $matches[2], $indexMatches );
				foreach ( $indexMatches[1] as $index ) {
					unset( $this->schemaTables[ $matches[1] ]['indexes'][ $index ] );
				}
				preg_match_all( '/DROP COLUMN ([a-z][a-z0-9_]*)/i', $matches[2], $columnMatches );
				foreach ( $columnMatches[1] as $column ) {
					unset(
						$this->schemaTables[ $matches[1] ]['columns'][ $column ],
						$this->schemaTables[ $matches[1] ]['columnMetadata'][ $column ]
					);
				}

				return 1;
			}
			if ( 'SET TRANSACTION ISOLATION LEVEL SERIALIZABLE' === $query ) {
				return 1;
			}
			if ( 'START TRANSACTION' === $query ) {
				$this->transactionRows = $this->rows;

				return 1;
			}
			if ( 'COMMIT' === $query ) {
				$this->transactionRows = null;

				return 1;
			}
			if ( 'ROLLBACK' === $query ) {
				if ( null !== $this->transactionRows ) {
					$this->rows = $this->transactionRows;
				}
				$this->transactionRows = null;

				return 1;
			}
			return 0;
		}

		public function get_var( string $query ): int|false|string|null {
			if ( $this->readFailure ) {
				$this->last_error = 'database details must not escape';
				return null;
			}

			if ( 1 === preg_match( "/^SHOW TABLES LIKE '(.+)'$/", $query, $matches ) ) {
				$table = stripslashes( str_replace( array( '\\_', '\\%' ), array( '_', '%' ), $matches[1] ) );

				return isset( $this->schemaTables[ $table ] ) ? $table : null;
			}

			if ( preg_match( "/type = (\\d+) AND package = '([^']+)'/", $query, $matches ) !== 1 ) {
				return 0;
			}

			return count(
				array_filter(
					$this->rows,
					static fn ( array $row ): bool => (int) ( $row['type'] ?? 0 ) === (int) $matches[1]
						&& (string) ( $row['package'] ?? '' ) === stripslashes( $matches[2] )
				)
			);
		}

		/** @param array<string, mixed> $data */
		public function insert( string $table, array $data ): int|false {
			$this->inserts[] = array( $table, $data );
			if ( null !== $this->insertRaceRow ) {
				$this->rows[]          = $this->insertRaceRow;
				$this->insertRaceRow   = null;
			}

			if ( ! $this->applyWrites || ( null !== $this->insertResult && $this->insertResult <= 0 ) ) {
				return $this->insertResult ?? 0;
			}

			$storedData = $data;
			if ( $this->coercePrivateAsMysqlTinyint && array_key_exists( 'private', $storedData ) ) {
				$storedData['private'] = (string) (int) $storedData['private'];
			}
			$storedData['id'] = count( $this->rows ) + 1;
			$this->rows[]     = $storedData;

			return $this->insertResult ?? 1;
		}

		/**
		 * @param array<string, mixed> $data
		 * @param array<string, mixed> $where
		 */
		public function update( string $table, array $data, array $where ): int|false {
			++$this->updateCalls;
			$this->updates[] = array( $table, $data, $where );

			if ( $this->updateCalls === $this->failUpdateNumber
				|| ! $this->applyWrites
				|| ( null !== $this->updateResult && $this->updateResult <= 0 ) ) {
				return $this->updateResult ?? 0;
			}

			$updated    = 0;
			$storedData = $data;
			if ( $this->coercePrivateAsMysqlTinyint && array_key_exists( 'private', $storedData ) ) {
				$storedData['private'] = (string) (int) $storedData['private'];
			}

			foreach ( $this->rows as &$row ) {
				if ( $this->matches( $row, $where ) ) {
					$row = array_merge( $row, $storedData );
					++$updated;
				}
			}

			unset( $row );

			return $this->updateResult ?? $updated;
		}

		public function get_row( string $query ): ?object {
			if ( 1 === preg_match( "/^SHOW TABLE STATUS WHERE Name = '([^']+)'$/", $query, $matches ) ) {
				$table = stripslashes( $matches[1] );
				if ( $this->options === $table ) {
					return (object) array( 'Engine' => $this->optionsEngine );
				}

				return isset( $this->schemaTables[ $table ] )
					? (object) array( 'Engine' => $this->schemaTables[ $table ]['engine'] )
					: null;
			}

			return $this->row;
		}

		/** @return list<object>|null */
		public function get_results( string $query ): ?array {
			if ( 'SHOW ENGINES' === $query ) {
				++$this->capabilityReads;

				return array(
					(object) array(
						'Engine'  => 'InnoDB',
						'Support' => $this->innodbSupport,
					),
				);
			}

			if ( $this->readFailure || 0 === $this->successfulReadsBeforeFailure ) {
				$this->last_error = 'database details must not escape';
				return null;
			}

			if ( null !== $this->successfulReadsBeforeFailure ) {
				--$this->successfulReadsBeforeFailure;
			}

			if ( null !== $this->forcedResults ) {
				return $this->forcedResults;
			}

			if ( 1 === preg_match( '/^SHOW COLUMNS FROM `([^`]+)`$/', $query, $matches ) ) {
				if ( ! isset( $this->schemaTables[ $matches[1] ] ) ) {
					return null;
				}

				$rows = array();
				foreach ( $this->schemaTables[ $matches[1] ]['columns'] as $column => $type ) {
					$metadata = $this->schemaTables[ $matches[1] ]['columnMetadata'][ $column ];
					$rows[]   = (object) array(
						'Field'   => $column,
						'Type'    => $type,
						'Null'    => $metadata['nullable'] ? 'YES' : 'NO',
						'Default' => $metadata['default'],
						'Extra'   => $metadata['extra'],
					);
				}

				return $rows;
			}

			if ( 1 === preg_match( '/^SHOW INDEX FROM `([^`]+)`$/', $query, $matches ) ) {
				if ( ! isset( $this->schemaTables[ $matches[1] ] ) ) {
					return null;
				}

				$rows = array();
				foreach ( $this->schemaTables[ $matches[1] ]['indexes'] as $name => $index ) {
					foreach ( $index['columns'] as $offset => $column ) {
						$rows[] = (object) array(
							'Key_name'     => $name,
							'Non_unique'   => $index['unique'] ? 0 : 1,
							'Seq_in_index' => $offset + 1,
							'Column_name'  => $column,
							'Sub_part'     => $index['prefixes'][ $offset ],
						);
					}
				}

				return $rows;
			}

			if ( null !== $this->row ) {
				return array( $this->row );
			}

			$rows = array_filter(
				$this->rows,
				function ( array $row ) use ( $query ): bool {
					if ( preg_match( '/type = (\d+)/', $query, $typeMatches ) === 1
						&& (int) ( $row['type'] ?? 0 ) !== (int) $typeMatches[1]
					) {
						return false;
					}

					if ( preg_match( "/package = '([^']+)'/", $query, $packageMatches ) === 1
						&& (string) ( $row['package'] ?? '' ) !== stripslashes( $packageMatches[1] )
					) {
						return false;
					}

					if ( preg_match( "/source = '([^']+)'/", $query, $sourceMatches ) === 1
						&& (string) ( $row['source'] ?? '' ) !== stripslashes( $sourceMatches[1] )
					) {
						return false;
					}

					if ( preg_match( "/id = '([^']+)'/", $query, $idMatches ) === 1
						&& (string) ( $row['id'] ?? '' ) !== stripslashes( $idMatches[1] )
					) {
						return false;
					}

					return true;
				}
			);

			return array_values( array_map( static fn ( array $row ): object => (object) $row, $rows ) );
		}

		public function installSchema( string $sql ): void {
			if ( 1 !== preg_match( '/CREATE TABLE ([^\s(]+)\s*\((.*)\) ENGINE=/s', $sql, $matches ) ) {
				return;
			}

			$table          = trim( $matches[1], '`' );
			$columns        = array();
			$columnMetadata = array();
			$indexes        = array();
			foreach ( preg_split( '/\R/', $matches[2] ) ?: array() as $line ) {
				$line = trim( $line, " \t\n\r\0\x0B," );
				if ( 1 === preg_match( '/^([a-z][a-z0-9_]*)\s+([a-z]+(?:\([^)]+\))?(?:\s+unsigned)?)(.*)$/', $line, $column ) ) {
					$columns[ $column[1] ] = strtolower( $column[2] );
					$default              = null;
					if ( 1 === preg_match( "/\\bDEFAULT\\s+'([^']*)'/i", $column[3], $defaultMatch ) ) {
						$default = $defaultMatch[1];
					}
					$columnMetadata[ $column[1] ] = array(
						'nullable' => 1 !== preg_match( '/\bNOT NULL\b/i', $column[3] ),
						'default'  => $default,
						'extra'    => 1 === preg_match( '/\bAUTO_INCREMENT\b/i', $column[3] ) ? 'auto_increment' : '',
					);
					continue;
				}

				if ( str_starts_with( $line, 'PRIMARY KEY' ) ) {
					preg_match( '/\(([^)]+)\)/', $line, $columnsMatch );
					$indexes['PRIMARY'] = array(
						'unique'  => true,
						'columns' => $this->indexColumns( $columnsMatch[1] ?? '' ),
						'prefixes' => array_fill( 0, count( $this->indexColumns( $columnsMatch[1] ?? '' ) ), null ),
					);
					continue;
				}

				if ( 1 === preg_match( '/^(UNIQUE )?KEY\s+([a-z][a-z0-9_]*)\s+\(([^)]+)\)/i', $line, $index ) ) {
					$indexColumns = $this->indexColumns( $index[3] );
					$indexes[ $index[2] ] = array(
						'unique'  => '' !== $index[1],
						'columns' => $indexColumns,
						'prefixes' => array_fill( 0, count( $indexColumns ), null ),
					);
				}
			}

			$existing   = $this->schemaTables[ $table ] ?? array(
				'engine'         => $this->schemaEngine,
				'columns'        => array(),
				'columnMetadata' => array(),
				'indexes'        => array(),
			);
			$newColumns = array_diff_key( $columns, $existing['columns'] );
			if ( 'wp_ran_booster_packages' === $table && array() !== $newColumns ) {
				foreach ( $this->rows as &$row ) {
					foreach ( array_keys( $newColumns ) as $name ) {
						if ( ! array_key_exists( $name, $row ) ) {
							$row[ $name ] = $columnMetadata[ $name ]['default'];
						}
					}
				}
				unset( $row );
			}
			$this->schemaTables[ $table ] = array(
				'engine'         => $existing['engine'],
				'columns'        => $existing['columns'] + $columns,
				'columnMetadata' => $existing['columnMetadata'] + $columnMetadata,
				'indexes'        => $existing['indexes'] + $indexes,
			);
		}

		/** @return list<string> */
		private function indexColumns( string $columns ): array {
			return array_values(
				array_map(
					static fn ( string $column ): string => trim( $column, " `\t\n\r\0\x0B" ),
					explode( ',', $columns )
				)
			);
		}

		/** @param array<string, mixed> $where */
		public function delete( string $table, array $where ): int|false {
			$this->deletes[] = array( $table, $where );

			if ( ! $this->applyWrites || ( null !== $this->deleteResult && $this->deleteResult <= 0 ) ) {
				return $this->deleteResult ?? 0;
			}

			$before     = count( $this->rows );
			$this->rows = array_values(
				array_filter( $this->rows, fn ( array $row ): bool => ! $this->matches( $row, $where ) )
			);
			$deleted    = $before - count( $this->rows );

			return $this->deleteResult ?? $deleted;
		}

		/** @param array<string, mixed> $where */
		private function matches( array $row, array $where ): bool {
			foreach ( $where as $key => $value ) {
				if ( (string) ( $row[ $key ] ?? '' ) !== (string) $value ) {
					return false;
				}
			}

			return true;
		}
	}
}
