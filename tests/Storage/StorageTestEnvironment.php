<?php

declare(strict_types=1);

// Focused WordPress function and database doubles necessarily use global fixtures.

// phpcs:ignore Universal.Namespaces.DisallowCurlyBraceSyntax.Forbidden, Universal.Namespaces.DisallowDeclarationWithoutName.Forbidden -- Global namespace is required for the WordPress function doubles in this isolated fixture.
namespace {

	if ( ! defined( 'ABSPATH' ) ) {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Exercise the WordPress-owned constant without changing its runtime identity.
		define( 'ABSPATH', dirname( __DIR__ ) . '/fixtures/wordpress/' );
	}

	if ( ! function_exists( 'sanitize_text_field' ) ) {
		/**
		 * @param mixed $value
		 * @return string
		 */
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WordPress function double must retain the host-owned name.
		function sanitize_text_field( $value ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- Minimal isolated sanitize_text_field double intentionally uses native tag removal.
			return trim( strip_tags( (string) $value ) );
		}
	}

	if ( ! function_exists( '__' ) ) {
		/**
		 * @param string $text
		 * @param string $domain
		 * @return string
		 */
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WordPress function double must retain the host-owned name.
		function __( $text, $domain = 'default' ) {
			return $GLOBALS['ran_booster_package_view_translations'][ $domain ][ $text ]
				?? $GLOBALS['ran_booster_admin_test_translations'][ $domain ][ $text ]
				?? (string) $text;
		}
	}

	if ( ! function_exists( 'ran_booster_table_name' ) ) {
		/**
		 * @return string
		 */
		function ran_booster_table_name() {
			return 'wp_ran_booster_packages';
		}
	}

	if ( ! function_exists( 'get_option' ) ) {
		/**
		 * @param string $option
		 * @param mixed $default
		 * @return mixed
		 */
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound, Universal.NamingConventions.NoReservedKeywordParameterNames.defaultFound -- Preserve the WordPress get_option parameter signature. WordPress function double must retain the host-owned name.
		function get_option( $option, $default = false ) {
			global $ran_booster_storage_test_options;

			if ( isset( $GLOBALS['ran_booster_storage_test_option_read'] ) ) {
				$GLOBALS['ran_booster_storage_test_option_read']( $option );
			}

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
		/**
		 * @param string $option
		 * @param mixed $value
		 * @param bool|string|null $autoload
		 * @return bool
		 */
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound, Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Preserve the WordPress update_option signature; this in-memory fixture does not model autoload storage. WordPress function double must retain the host-owned name.
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
		/**
		 * @param string $sql
		 * @return array{}
		 */
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound, WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- Exact WordPress dbDelta function signature is replaced by this isolated test double. WordPress function double must retain the host-owned name.
		function dbDelta( $sql ) {
			global $wpdb;

			$wpdb->schemas[] = (string) $sql;
			if ( method_exists( $wpdb, 'install_schema' ) ) {
				$wpdb->install_schema( (string) $sql );
			}

			return array();
		}
	}
}

// phpcs:ignore Universal.Namespaces.OneDeclarationPerFile.MultipleFound, Universal.Namespaces.DisallowCurlyBraceSyntax.Forbidden -- The global WordPress doubles and namespaced database fake are loaded together by this fixture.
namespace RAN\Tests\Storage {

	// phpcs:ignore Universal.Files.SeparateFunctionsFromOO.Mixed -- The fixture pairs WordPress function doubles with its database class for one isolated test environment.
	final class StorageTestWpdb {

		public string $prefix      = 'wp_';
		public string $base_prefix = 'wp_';
		public string $options     = 'wp_options';
		public string $last_error  = '';

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
		public array $schema_tables   = array();
		public string $schema_engine  = 'InnoDB';
		public string $options_engine = 'InnoDB';
		public string $server_info    = '8.4.6';
		public string $innodb_support = 'DEFAULT';
		public int $capability_reads  = 0;

		/** @var list<string> */
		public array $queries                              = array();
		public ?string $query_failure_contains             = null;
		public bool $keep_dropped_tables                   = false;
		public ?int $successful_table_reads_before_failure = null;

		/** @var list<array<string, mixed>> */
		public array $rows = array();

		/** @var list<array{0: string, 1: array<string, mixed>}> */
		public array $inserts = array();

		/** @var list<array{0: string, 1: array<string, mixed>, 2: array<string, mixed>}> */
		public array $updates = array();

		/** @var list<array{0: string, 1: array<string, mixed>}> */
		public array $deletes = array();

		public ?object $row                  = null;
		public int|false|null $insert_result = null;
		/** @var array<string, mixed>|null */
		public ?array $insert_race_row               = null;
		public int|false|null $update_result         = null;
		public ?int $fail_update_number              = null;
		public int|false|null $delete_result         = null;
		public bool $read_failure                    = false;
		public ?int $successful_reads_before_failure = null;
		public bool $apply_writes                    = true;
		public bool $coerce_private_as_mysql_tinyint = false;
		/** @var list<array<string, mixed>>|null */
		private ?array $transaction_rows = null;
		private int $update_calls        = 0;
		/** @var list<mixed>|null */
		public ?array $forced_results = null;

		public function get_charset_collate(): string {
			return 'DEFAULT CHARACTER SET utf8mb4';
		}

		public function db_server_info(): string {
			return $this->server_info;
		}

		public function prepare( string $query, mixed ...$arguments ): string {
			foreach ( $arguments as $argument ) {
				$query = (string) preg_replace_callback(
					'/%[dis]/',
					static function ( array $matches ) use ( $argument ): string {
						if ( '%i' === $matches[0] ) {
							return '`' . str_replace( '`', '``', (string) $argument ) . '`';
						}

						if ( '%d' === $matches[0] ) {
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
			if ( null !== $this->query_failure_contains && str_contains( $query, $this->query_failure_contains ) ) {
				return false;
			}
			if ( 1 === preg_match( '/^DROP TABLE IF EXISTS `([^`]+)`$/', $query, $matches ) ) {
				if ( ! $this->keep_dropped_tables ) {
					unset( $this->schema_tables[ $matches[1] ] );
				}

				return 1;
			}
			if ( 1 === preg_match( '/^ALTER TABLE `([^`]+)`\s+(.+)$/s', $query, $matches )
				&& isset( $this->schema_tables[ $matches[1] ] )
			) {
				preg_match_all( '/DROP INDEX ([a-z][a-z0-9_]*)/i', $matches[2], $index_matches );
				foreach ( $index_matches[1] as $index ) {
					unset( $this->schema_tables[ $matches[1] ]['indexes'][ $index ] );
				}
				preg_match_all( '/DROP COLUMN ([a-z][a-z0-9_]*)/i', $matches[2], $column_matches );
				foreach ( $column_matches[1] as $column ) {
					unset(
						$this->schema_tables[ $matches[1] ]['columns'][ $column ],
						$this->schema_tables[ $matches[1] ]['columnMetadata'][ $column ]
					);
				}

				return 1;
			}
			if ( 'SET TRANSACTION ISOLATION LEVEL SERIALIZABLE' === $query ) {
				return 1;
			}
			if ( 'START TRANSACTION' === $query ) {
				$this->transaction_rows = $this->rows;

				return 1;
			}
			if ( 'COMMIT' === $query ) {
				$this->transaction_rows = null;

				return 1;
			}
			if ( 'ROLLBACK' === $query ) {
				if ( null !== $this->transaction_rows ) {
					$this->rows = $this->transaction_rows;
				}
				$this->transaction_rows = null;

				return 1;
			}
			return 0;
		}

		public function get_var( string $query ): int|string|null {
			if ( $this->read_failure ) {
				$this->last_error = 'database details must not escape';
				return null;
			}

			if ( 1 === preg_match( "/^SHOW TABLES LIKE '(.+)'$/", $query, $matches ) ) {
				if ( 0 === $this->successful_table_reads_before_failure ) {
					$this->last_error = 'database details must not escape';
					return null;
				}
				if ( null !== $this->successful_table_reads_before_failure ) {
					--$this->successful_table_reads_before_failure;
				}
				$table = stripslashes( str_replace( array( '\\_', '\\%' ), array( '_', '%' ), $matches[1] ) );

				return isset( $this->schema_tables[ $table ] ) ? $table : null;
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
			if ( null !== $this->insert_race_row ) {
				$this->rows[]          = $this->insert_race_row;
				$this->insert_race_row = null;
			}

			if ( ! $this->apply_writes || ( null !== $this->insert_result && $this->insert_result <= 0 ) ) {
				return $this->insert_result ?? 0;
			}

			$stored_data = $data;
			if ( $this->coerce_private_as_mysql_tinyint && array_key_exists( 'private', $stored_data ) ) {
				$stored_data['private'] = (string) (int) $stored_data['private'];
			}
			$stored_data['id'] = count( $this->rows ) + 1;
			$this->rows[]      = $stored_data;

			return $this->insert_result ?? 1;
		}

		/**
		 * @param array<string, mixed> $data
		 * @param array<string, mixed> $where
		 */
		public function update( string $table, array $data, array $where ): int|false {
			++$this->update_calls;
			$this->updates[] = array( $table, $data, $where );

			if ( $this->update_calls === $this->fail_update_number
				|| ! $this->apply_writes
				|| ( null !== $this->update_result && $this->update_result <= 0 ) ) {
				return $this->update_result ?? 0;
			}

			$updated     = 0;
			$stored_data = $data;
			if ( $this->coerce_private_as_mysql_tinyint && array_key_exists( 'private', $stored_data ) ) {
				$stored_data['private'] = (string) (int) $stored_data['private'];
			}

			foreach ( $this->rows as &$row ) {
				if ( $this->matches( $row, $where ) ) {
					$row = array_merge( $row, $stored_data );
					++$updated;
				}
			}

			unset( $row );

			return $this->update_result ?? $updated;
		}

		public function get_row( string $query ): ?object {
			if ( 1 === preg_match( "/^SHOW TABLE STATUS WHERE Name = '([^']+)'$/", $query, $matches ) ) {
				$table = stripslashes( $matches[1] );
				if ( $this->options === $table ) {
					return (object) array( 'Engine' => $this->options_engine );
				}

				return isset( $this->schema_tables[ $table ] )
					? (object) array( 'Engine' => $this->schema_tables[ $table ]['engine'] )
					: null;
			}

			return $this->row;
		}

		/** @return list<object>|null */
		public function get_results( string $query ): ?array {
			if ( 'SHOW ENGINES' === $query ) {
				++$this->capability_reads;

				return array(
					(object) array(
						'Engine'  => 'InnoDB',
						'Support' => $this->innodb_support,
					),
				);
			}

			if ( $this->read_failure || 0 === $this->successful_reads_before_failure ) {
				$this->last_error = 'database details must not escape';
				return null;
			}

			if ( null !== $this->successful_reads_before_failure ) {
				--$this->successful_reads_before_failure;
			}

			if ( null !== $this->forced_results ) {
				return $this->forced_results;
			}

			if ( 1 === preg_match( '/^SHOW COLUMNS FROM `([^`]+)`$/', $query, $matches ) ) {
				if ( ! isset( $this->schema_tables[ $matches[1] ] ) ) {
					return null;
				}

				$rows = array();
				foreach ( $this->schema_tables[ $matches[1] ]['columns'] as $column => $type ) {
					$metadata = $this->schema_tables[ $matches[1] ]['columnMetadata'][ $column ];
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
				if ( ! isset( $this->schema_tables[ $matches[1] ] ) ) {
					return null;
				}

				$rows = array();
				foreach ( $this->schema_tables[ $matches[1] ]['indexes'] as $name => $index ) {
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
					if ( preg_match( "/provider = '([^']+)'/", $query, $provider_matches ) === 1
						&& (string) ( $row['provider'] ?? '' ) !== stripslashes( $provider_matches[1] )
					) {
						return false;
					}

					if ( preg_match( "/BINARY provider_repository_id = BINARY '([^']+)'/", $query, $repository_id_matches ) === 1
						&& (string) ( $row['provider_repository_id'] ?? '' ) !== stripslashes( $repository_id_matches[1] )
					) {
						return false;
					}

					if ( preg_match( '/type = (\d+)/', $query, $type_matches ) === 1
						&& (int) ( $row['type'] ?? 0 ) !== (int) $type_matches[1]
					) {
						return false;
					}

					if ( preg_match( "/package = '([^']+)'/", $query, $package_matches ) === 1
						&& (string) ( $row['package'] ?? '' ) !== stripslashes( $package_matches[1] )
					) {
						return false;
					}

					if ( preg_match( "/source = '([^']+)'/", $query, $source_matches ) === 1
						&& (string) ( $row['source'] ?? '' ) !== stripslashes( $source_matches[1] )
					) {
						return false;
					}

					if ( preg_match( "/id = '([^']+)'/", $query, $id_matches ) === 1
						&& (string) ( $row['id'] ?? '' ) !== stripslashes( $id_matches[1] )
					) {
						return false;
					}

					return true;
				}
			);

			return array_values( array_map( static fn ( array $row ): object => (object) $row, $rows ) );
		}

		public function install_schema( string $sql ): void {
			if ( 1 !== preg_match( '/CREATE TABLE ([^\s(]+)\s*\((.*)\) ENGINE=/s', $sql, $matches ) ) {
				return;
			}

			$table           = trim( $matches[1], '`' );
			$columns         = array();
			$column_metadata = array();
			$indexes         = array();
			$schema_lines    = preg_split( '/\R/', $matches[2] );
			foreach ( $schema_lines ? $schema_lines : array() as $line ) {
				$line = trim( $line, " \t\n\r\0\x0B," );
				if ( 1 === preg_match( '/^([a-z][a-z0-9_]*)\s+([a-z]+(?:\([^)]+\))?(?:\s+unsigned)?)(.*)$/', $line, $column ) ) {
					$columns[ $column[1] ] = strtolower( $column[2] );
					$default               = null;
					if ( 1 === preg_match( "/\\bDEFAULT\\s+'([^']*)'/i", $column[3], $default_match ) ) {
						$default = $default_match[1];
					}
					$column_metadata[ $column[1] ] = array(
						'nullable' => 1 !== preg_match( '/\bNOT NULL\b/i', $column[3] ),
						'default'  => $default,
						'extra'    => 1 === preg_match( '/\bAUTO_INCREMENT\b/i', $column[3] ) ? 'auto_increment' : '',
					);
					continue;
				}

				if ( str_starts_with( $line, 'PRIMARY KEY' ) ) {
					preg_match( '/\(([^)]+)\)/', $line, $columns_match );
					$indexes['PRIMARY'] = array(
						'unique'   => true,
						'columns'  => $this->index_columns( $columns_match[1] ?? '' ),
						'prefixes' => array_fill( 0, count( $this->index_columns( $columns_match[1] ?? '' ) ), null ),
					);
					continue;
				}

				if ( 1 === preg_match( '/^(UNIQUE )?KEY\s+([a-z][a-z0-9_]*)\s+\(([^)]+)\)/i', $line, $index ) ) {
					$index_columns        = $this->index_columns( $index[3] );
					$indexes[ $index[2] ] = array(
						'unique'   => '' !== $index[1],
						'columns'  => $index_columns,
						'prefixes' => array_fill( 0, count( $index_columns ), null ),
					);
				}
			}

			$existing    = $this->schema_tables[ $table ] ?? array(
				'engine'         => $this->schema_engine,
				'columns'        => array(),
				'columnMetadata' => array(),
				'indexes'        => array(),
			);
			$new_columns = array_diff_key( $columns, $existing['columns'] );
			if ( 'wp_ran_booster_packages' === $table && array() !== $new_columns ) {
				foreach ( $this->rows as &$row ) {
					foreach ( array_keys( $new_columns ) as $name ) {
						if ( ! array_key_exists( $name, $row ) ) {
							$row[ $name ] = $column_metadata[ $name ]['default'];
						}
					}
				}
				unset( $row );
			}
			$this->schema_tables[ $table ] = array(
				'engine'         => $existing['engine'],
				'columns'        => $existing['columns'] + $columns,
				'columnMetadata' => $existing['columnMetadata'] + $column_metadata,
				'indexes'        => $existing['indexes'] + $indexes,
			);
		}

		/** @return list<string> */
		private function index_columns( string $columns ): array {
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

			if ( ! $this->apply_writes || ( null !== $this->delete_result && $this->delete_result <= 0 ) ) {
				return $this->delete_result ?? 0;
			}

			$before     = count( $this->rows );
			$this->rows = array_values(
				array_filter( $this->rows, fn ( array $row ): bool => ! $this->matches( $row, $where ) )
			);
			$deleted    = $before - count( $this->rows );

			return $this->delete_result ?? $deleted;
		}

		/**
		 * @param array<string, mixed> $row
		 * @param array<string, mixed> $where
		 */
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
