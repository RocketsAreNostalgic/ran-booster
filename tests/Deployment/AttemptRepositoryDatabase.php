<?php

declare(strict_types=1);

// Focused database and WordPress cache doubles.

// phpcs:ignore Universal.Namespaces.DisallowCurlyBraceSyntax.Forbidden, Universal.Namespaces.DisallowDeclarationWithoutName.Forbidden -- Global namespace is required for the WordPress function doubles in this isolated fixture.
namespace {
	$GLOBALS['ran_booster_attempt_cache_deletes'] = array();

	if ( ! function_exists( 'wp_cache_delete' ) ) {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WordPress function double must retain the host-owned name.
		function wp_cache_delete( $key, $group = '' ) {
			$GLOBALS['ran_booster_attempt_cache_deletes'][] = array( $key, $group );

			return true;
		}
	}
}

// phpcs:ignore Universal.Namespaces.OneDeclarationPerFile.MultipleFound, Universal.Namespaces.DisallowCurlyBraceSyntax.Forbidden -- The global WordPress doubles and namespaced database fake are loaded together by this fixture.
namespace RAN\Tests\Deployment {

	// phpcs:ignore Universal.Files.SeparateFunctionsFromOO.Mixed -- The fixture pairs WordPress function doubles with its database class for one isolated test environment.
	final class AttemptRepositoryDatabase {

		public string $options    = 'wp_options';
		public string $last_error = '';
		/** @var list<array<string, mixed>> */
		public array $rows = array();
		/** @var array<string, string> */
		public array $option_rows = array();
		/** @var list<string> */
		public array $queries                = array();
		public ?string $fail_query_contains  = null;
		public ?string $zero_query_contains  = null;
		public bool $fail_insert             = false;
		public ?int $fail_insert_number      = null;
		public bool $fail_reads              = false;
		public bool $fail_commit             = false;
		public ?string $tamper_insert_column = null;
		public mixed $tamper_insert_value    = null;
		public ?string $tamper_update_column = null;
		public mixed $tamper_update_value    = null;
		/** @var array{rows: list<array<string, mixed>>, options: array<string, string>}|null */
		private ?array $snapshot      = null;
		private int $insert_calls     = 0;
		public string $server_info    = '8.4.6';
		public string $innodb_support = 'DEFAULT';
		public int $capability_reads  = 0;

		public function db_server_info(): string {
			return $this->server_info;
		}

		public function prepare( string $query, mixed ...$arguments ): string {
			foreach ( $arguments as $argument ) {
				$query = (string) preg_replace_callback(
					'/%[dis]/',
					static fn ( array $matches ): string => match ( $matches[0] ) {
						'%i' => '`' . str_replace( '`', '``', (string) $argument ) . '`',
						'%d' => (string) (int) $argument,
						default => "'" . addslashes( (string) $argument ) . "'",
					},
					$query,
					1
				);
			}

			return $query;
		}

		/** @param array<string, mixed> $data */
		// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- Preserve the wpdb insert signature; this fixture owns one in-memory table.
		public function insert( string $table, array $data ): int|false {
			++$this->insert_calls;
			if ( $this->fail_insert || $this->insert_calls === $this->fail_insert_number ) {
				return false;
			}
			foreach ( $this->rows as $row ) {
				if ( ( $row['correlation_id'] ?? null ) === $data['correlation_id'] ) {
					return false;
				}
				if ( null !== $data['delivery_id']
					&& ( $row['provider'] ?? null ) === $data['provider']
					&& ( $row['delivery_id'] ?? null ) === $data['delivery_id']
					&& ( $row['package_type'] ?? null ) === $data['package_type']
					&& ( $row['package_slug'] ?? null ) === $data['package_slug'] ) {
					return false;
				}
			}
			$data['id'] = array_reduce(
				$this->rows,
				static fn ( int $maximum, array $row ): int => max( $maximum, (int) ( $row['id'] ?? 0 ) ),
				0
			) + 1;
			if ( null !== $this->tamper_insert_column ) {
				$data[ $this->tamper_insert_column ] = $this->tamper_insert_value;
			}
			$this->rows[] = $data;

			return 1;
		}

		public function query( string $query ): int|false {
			$this->queries[] = $query;
			if ( null !== $this->fail_query_contains && str_contains( $query, $this->fail_query_contains ) ) {
				$this->last_error = 'database details must not escape';

				return false;
			}
			if ( null !== $this->zero_query_contains && str_contains( $query, $this->zero_query_contains ) ) {
				return 0;
			}
			if ( 'START TRANSACTION' === $query ) {
				$this->snapshot = array(
					'rows'    => $this->rows,
					'options' => $this->option_rows,
				);

				return 1;
			}
			if ( 'COMMIT' === $query ) {
				if ( $this->fail_commit ) {
					return false;
				}
				$this->snapshot = null;

				return 1;
			}
			if ( 'ROLLBACK' === $query ) {
				if ( null !== $this->snapshot ) {
					$this->rows        = $this->snapshot['rows'];
					$this->option_rows = $this->snapshot['options'];
				}
				$this->snapshot = null;

				return 1;
			}
			if ( preg_match( "/^INSERT IGNORE INTO `wp_options` .* VALUES \('([^']+)', '([^']+)', 'no'\)$/", $query, $matches ) === 1 ) {
				if ( isset( $this->option_rows[ stripslashes( $matches[1] ) ] ) ) {
					return 0;
				}
				$this->option_rows[ stripslashes( $matches[1] ) ] = stripslashes( $matches[2] );

				return 1;
			}
			if ( preg_match( "/^DELETE FROM `wp_options` WHERE option_name = '([^']+)' AND option_value = '([^']+)'$/", $query, $matches ) === 1 ) {
				$name  = stripslashes( $matches[1] );
				$value = stripslashes( $matches[2] );
				if ( ! isset( $this->option_rows[ $name ] ) || ! hash_equals( $value, $this->option_rows[ $name ] ) ) {
					return 0;
				}
				unset( $this->option_rows[ $name ] );

				return 1;
			}
			if ( preg_match( '/^UPDATE `[^`]+` SET (.+) WHERE id = (\d+) AND state = \'([^\']+)\'$/', $query, $matches ) === 1 ) {
				$id = (int) $matches[2];
				foreach ( $this->rows as &$row ) {
					if ( (int) $row['id'] !== $id || stripslashes( $matches[3] ) !== $row['state'] ) {
						continue;
					}
					foreach ( explode( ', ', $matches[1] ) as $assignment ) {
						preg_match( '/^([a-z_]+) = (NULL|\'(.*)\')$/', $assignment, $parts );
						$row[ $parts[1] ] = 'NULL' === $parts[2] ? null : stripslashes( $parts[3] );
					}
					if ( null !== $this->tamper_update_column ) {
						$row[ $this->tamper_update_column ] = $this->tamper_update_value;
					}
					unset( $row );

					return 1;
				}
				unset( $row );

				return 0;
			}
			if ( preg_match( "/^DELETE FROM `[^`]+` WHERE id IN \\(([\\d, ]+)\\) AND \\(state IN \\('succeeded','failed'\\) OR \\(state = 'needs_attention' AND resolved_at IS NOT NULL AND resolved_by IS NOT NULL\\)\\)$/", $query, $matches ) === 1 ) {
				$ids     = array_map( 'intval', explode( ',', $matches[1] ) );
				$deleted = 0;
				foreach ( $this->rows as $index => $row ) {
					$resolved_attention = 'needs_attention' === $row['state']
						&& null !== ( $row['resolved_at'] ?? null )
						&& null !== ( $row['resolved_by'] ?? null );
					if ( in_array( (int) $row['id'], $ids, true )
						&& ( in_array( $row['state'], array( 'succeeded', 'failed' ), true ) || $resolved_attention ) ) {
						unset( $this->rows[ $index ] );
						++$deleted;
					}
				}
				$this->rows = array_values( $this->rows );

				return $deleted;
			}

			return 0;
		}

		public function get_var( string $query ): mixed {
			$this->queries[] = $query;
			if ( $this->fail_reads ) {
				$this->last_error = 'database details must not escape';

				return null;
			}
			if ( preg_match( "/FROM `wp_options` WHERE option_name = '([^']+)'/", $query, $matches ) === 1 ) {
				return $this->option_rows[ stripslashes( $matches[1] ) ] ?? null;
			}

			return null;
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
			$this->queries[] = $query;
			if ( $this->fail_reads ) {
				$this->last_error = 'database details must not escape';

				return null;
			}
			if ( str_contains( $query, 'COUNT(*) AS total' ) && str_contains( $query, 'GROUP BY state' ) ) {
				$totals = array();
				foreach ( $this->rows as $row ) {
					$state = (string) $row['state'];
					if ( in_array( $state, array( 'queued', 'running' ), true )
						|| ( 'needs_attention' === $state
							&& null === ( $row['resolved_at'] ?? null )
							&& null === ( $row['resolved_by'] ?? null ) ) ) {
						$totals[ $state ] = ( $totals[ $state ] ?? 0 ) + 1;
					}
				}

				return array_map(
					// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- compact() consumes both named callback parameters to construct the result row.
					static fn ( string $state, int $total ): object => (object) compact( 'state', 'total' ),
					array_keys( $totals ),
					array_values( $totals )
				);
			}
			if ( str_contains( $query, 'COUNT(*) AS total' ) ) {
				return array( (object) array( 'total' => count( $this->rows ) ) );
			}
			if ( str_contains( $query, 'MAX(latest.id)' )
				&& preg_match( "/latest\\.package_type = '([^']+)' AND latest\\.package_slug = '([^']+)'/", $query, $matches ) === 1 ) {
				$rows = array_values(
					array_filter(
						$this->rows,
						static fn ( array $row ): bool => stripslashes( $matches[1] ) === $row['package_type']
							&& stripslashes( $matches[2] ) === $row['package_slug']
					)
				);
				usort( $rows, static fn ( array $a, array $b ): int => $b['id'] <=> $a['id'] );
				$selected = array();
				if ( isset( $rows[0] ) ) {
					$selected[] = $rows[0];
				}
				foreach ( $rows as $row ) {
					if ( 'succeeded' === $row['state'] && ( ! isset( $selected[0] ) || $selected[0]['id'] !== $row['id'] ) ) {
						$selected[] = $row;
						break;
					}
				}

				return array_map( static fn ( array $row ): object => (object) $row, $selected );
			}
			$rows = $this->rows;
			if ( preg_match( "/correlation_id = '([^']+)'/", $query, $matches ) === 1 ) {
				$rows = array_filter( $rows, static fn ( array $row ): bool => stripslashes( $matches[1] ) === $row['correlation_id'] );
			}
			if ( preg_match( '/\bid = (\d+)/', $query, $matches ) === 1 ) {
				$rows = array_filter( $rows, static fn ( array $row ): bool => (int) $row['id'] === (int) $matches[1] );
			}
			if ( preg_match( '/\bid IN \(([\d, ]+)\)/', $query, $matches ) === 1 ) {
				$ids  = array_map( 'intval', explode( ',', $matches[1] ) );
				$rows = array_filter( $rows, static fn ( array $row ): bool => in_array( (int) $row['id'], $ids, true ) );
			}
			if ( preg_match( "/provider = '([^']+)'/", $query, $matches ) === 1 ) {
				$rows = array_filter( $rows, static fn ( array $row ): bool => stripslashes( $matches[1] ) === $row['provider'] );
			}
			if ( preg_match( "/provider = '([^']+)' AND delivery_id = '([^']+)'/", $query, $matches ) === 1 ) {
				$rows = array_filter(
					$rows,
					static fn ( array $row ): bool => stripslashes( $matches[1] ) === $row['provider']
						&& stripslashes( $matches[2] ) === $row['delivery_id']
				);
			}
			if ( str_contains( $query, "state = 'queued'" ) ) {
				$rows = array_filter( $rows, static fn ( array $row ): bool => 'queued' === $row['state'] );
			}
			if ( str_contains( $query, "state IN ('queued','running')" ) ) {
				$rows = array_filter(
					$rows,
					static fn ( array $row ): bool => in_array( $row['state'], array( 'queued', 'running' ), true )
						|| ( 'needs_attention' === $row['state']
							&& null === ( $row['resolved_at'] ?? null )
							&& null === ( $row['resolved_by'] ?? null ) )
				);
			}
			if ( str_contains( $query, "state IN ('succeeded','failed')" ) ) {
				$rows = array_filter(
					$rows,
					static fn ( array $row ): bool => in_array( $row['state'], array( 'succeeded', 'failed' ), true )
						|| ( 'needs_attention' === $row['state']
							&& null !== ( $row['resolved_at'] ?? null )
							&& null !== ( $row['resolved_by'] ?? null ) )
				);
			}
			if ( str_contains( $query, "source = 'webhook'" ) ) {
				$rows = array_filter( $rows, static fn ( array $row ): bool => 'webhook' === $row['source'] );
			}
			if ( str_contains( $query, "package_type IN ('plugin', 'theme')" ) ) {
				$rows = array_filter( $rows, static fn ( array $row ): bool => in_array( $row['package_type'], array( 'plugin', 'theme' ), true ) );
			}
			if ( preg_match( "/package_type = '([^']+)' AND package_slug = '([^']+)'/", $query, $matches ) === 1 ) {
				$rows = array_filter(
					$rows,
					static fn ( array $row ): bool => stripslashes( $matches[1] ) === $row['package_type']
						&& stripslashes( $matches[2] ) === $row['package_slug']
				);
			}
			if ( preg_match( '/id < (\d+)/', $query, $matches ) === 1 ) {
				$rows = array_filter( $rows, static fn ( array $row ): bool => (int) $row['id'] < (int) $matches[1] );
			}
			if ( str_contains( $query, 'ORDER BY created_at, id' ) ) {
				usort( $rows, static fn ( array $a, array $b ): int => array( $a['created_at'], $a['id'] ) <=> array( $b['created_at'], $b['id'] ) );
			} elseif ( str_contains( $query, 'ORDER BY created_at DESC, id DESC' ) ) {
				usort( $rows, static fn ( array $a, array $b ): int => array( $b['created_at'], $b['id'] ) <=> array( $a['created_at'], $a['id'] ) );
			} elseif ( str_contains( $query, 'ORDER BY id DESC' ) ) {
				usort( $rows, static fn ( array $a, array $b ): int => $b['id'] <=> $a['id'] );
			} elseif ( str_contains( $query, 'ORDER BY id' ) ) {
				usort( $rows, static fn ( array $a, array $b ): int => $a['id'] <=> $b['id'] );
			} elseif ( str_contains( $query, 'ORDER BY package_type, package_slug' ) ) {
				usort( $rows, static fn ( array $a, array $b ): int => array( $a['package_type'], $a['package_slug'] ) <=> array( $b['package_type'], $b['package_slug'] ) );
			}
			if ( preg_match( '/LIMIT (\d+)/', $query, $matches ) === 1 ) {
				$rows = array_slice( array_values( $rows ), 0, (int) $matches[1] );
			}

			return array_map( static fn ( array $row ): object => (object) $row, array_values( $rows ) );
		}
	}
}
