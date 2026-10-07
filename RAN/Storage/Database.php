<?php

declare(strict_types=1);

namespace RAN\Storage;

use RAN\Runtime\RuntimeSupport;

class Database {

	public static string $booster_db_version = '13.0';

	public const VERSION_OPTION = 'ran_booster_db_version';

	private bool $capability_checked                          = false;
	private ?DatabaseCompatibilityFailure $capability_failure = null;
	private bool $lifecycle_checked                           = false;
	private bool $lifecycle_inspected                         = false;
	private ?DatabaseLifecycleFailure $lifecycle_failure      = null;

	public function __construct( private ?object $database = null ) {
	}

	/**
	 * Prove the request's database can satisfy Booster's MySQL-oriented storage contract.
	 *
	 * The result is cached on the container-shared lifecycle object. Full table
	 * inspection remains an activation, upgrade or explicit diagnostic concern.
	 *
	 * @throws DatabaseCompatibilityFailure When the server is outside the supported envelope.
	 */
	public function require_supported(): void {
		if ( ! $this->capability_checked ) {
			try {
				$this->inspect_capabilities();
			} catch ( DatabaseCompatibilityFailure $failure ) {
				$this->capability_failure = $failure;
			} catch ( \Throwable ) {
				$this->capability_failure = new DatabaseCompatibilityFailure( 'capability_probe_failed' );
			}
			$this->capability_checked = true;
		}

		if ( null !== $this->capability_failure ) {
			throw $this->capability_failure;
		}
	}

	public function is_supported(): bool {
		try {
			$this->require_supported();

			return true;
		} catch ( DatabaseCompatibilityFailure ) {
			return false;
		}
	}

	/**
	 * Prepare the current schema once per request.
	 *
	 * @throws DatabaseCompatibilityFailure When the server is unsupported.
	 * @throws DatabaseLifecycleFailure When the schema cannot be prepared safely.
	 */
	public function maybe_upgrade(): void {
		RuntimeSupport::assert_managed_operations_allowed();

		$this->run_lifecycle( false );
	}

	/**
	 * Install or explicitly verify the current schema.
	 *
	 * @throws DatabaseCompatibilityFailure When the server is unsupported.
	 * @throws DatabaseLifecycleFailure When the schema cannot be prepared safely.
	 */
	public function install(): void {
		RuntimeSupport::assert_managed_operations_allowed();

		$this->run_lifecycle( true );
	}

	private function run_lifecycle( bool $inspect_current_schema ): void {
		if ( $this->lifecycle_checked ) {
			if ( null !== $this->lifecycle_failure ) {
				throw $this->lifecycle_failure;
			}
			if ( ! $inspect_current_schema || $this->lifecycle_inspected ) {
				return;
			}
		}

		try {
			$this->lifecycle_inspected = $this->prepare_schema( $inspect_current_schema );
			$this->lifecycle_checked   = true;
		} catch ( DatabaseCompatibilityFailure $failure ) {
			throw $failure;
		} catch ( DatabaseLifecycleFailure $failure ) {
			$this->lifecycle_failure = $failure;
			$this->lifecycle_checked = true;
		} catch ( \Throwable ) {
			$this->lifecycle_failure = new DatabaseLifecycleFailure( 'schema_operation_failed' );
			$this->lifecycle_checked = true;
		}

		if ( null !== $this->lifecycle_failure ) {
			throw $this->lifecycle_failure;
		}
	}

	/**
	 * Require usable, current storage. Storage callers should use this guard.
	 *
	 * @throws DatabaseCompatibilityFailure When the server is unsupported.
	 * @throws DatabaseLifecycleFailure When the schema cannot be prepared safely.
	 */
	public function require_ready(): void {
		$this->maybe_upgrade();
	}

	/**
	 * Read-only readiness status for passive diagnostics and administrator UI.
	 *
	 * This intentionally does not inspect or mutate tables. Normal lifecycle
	 * hooks call maybeUpgrade() before this status is presented.
	 */
	public function is_ready(): bool {
		if ( $this->lifecycle_checked ) {
			return null === $this->lifecycle_failure;
		}

		if ( ! $this->is_supported() ) {
			return false;
		}

		try {
			return self::$booster_db_version === $this->installed_version();
		} catch ( DatabaseLifecycleFailure ) {
			return false;
		}
	}

	private function prepare_schema( bool $inspect_current_schema ): bool {
		$this->require_supported();
		$wpdb = $this->connection();

		$installed_version = $this->installed_version();
		if ( ! $inspect_current_schema && self::$booster_db_version === $installed_version ) {
			return false;
		}

		$package_table   = ran_booster_table_name();
		$attempt_table   = self::attempt_table_name();
		$charset_collate = $wpdb->get_charset_collate();
		$tables          = array(
			$package_table => array(
				'schema'  => $this->package_schema( $package_table, $charset_collate ),
				'columns' => $this->package_columns(),
				'indexes' => $this->package_indexes(),
			),
			$attempt_table => array(
				'schema'  => $this->attempt_schema( $attempt_table, $charset_collate ),
				'columns' => $this->attempt_columns(),
				'indexes' => $this->attempt_indexes(),
			),
		);

		$missing_tables = array();
		foreach ( $tables as $table => $contract ) {
			$missing_tables[ $table ] = $this->needs_current_table_creation(
				$table,
				$contract['columns'],
				$contract['indexes']
			);
		}

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		foreach ( $tables as $table => $contract ) {
			if ( $missing_tables[ $table ] ) {
				dbDelta( $contract['schema'] );
			}
		}

		foreach ( $tables as $table => $contract ) {
			$this->verify_table( $table, $contract['columns'], $contract['indexes'] );
		}

		if ( ! update_option( self::VERSION_OPTION, self::$booster_db_version, false )
			// phpcs:ignore WordPress.PHP.YodaConditions.NotYoda -- Read the mutable expected version before get_option filters can run.
			&& self::$booster_db_version !== get_option( self::VERSION_OPTION, false ) ) {
			throw new DatabaseLifecycleFailure( 'version_write_failed' );
		}

		// phpcs:ignore WordPress.PHP.YodaConditions.NotYoda -- Read the mutable expected version before get_option filters can run.
		if ( self::$booster_db_version !== get_option( self::VERSION_OPTION, false ) ) {
			throw new DatabaseLifecycleFailure( 'version_verification_failed' );
		}

		return true;
	}

	public static function attempt_table_name(): string {
		global $wpdb;

		return $wpdb->prefix . 'ran_booster_deployment_attempts';
	}

	private function package_schema( string $table_name, string $charset_collate ): string {
		return "CREATE TABLE $table_name (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            package varchar(255) NOT NULL,
            repository varchar(512) NOT NULL,
            branch varchar(255) NOT NULL DEFAULT 'main',
            type tinyint(3) unsigned NOT NULL,
            deployment_policy varchar(10) NOT NULL DEFAULT 'manual',
            source varchar(16) NOT NULL DEFAULT 'branch',
            source_revision bigint(20) unsigned NOT NULL DEFAULT '1',
            source_previous varchar(16) DEFAULT NULL,
            source_changed_at datetime DEFAULT NULL,
            source_changed_by bigint(20) unsigned DEFAULT NULL,
            provider varchar(32) NOT NULL,
            provider_repository_id varchar(191) NOT NULL,
            private tinyint(1) unsigned NOT NULL,
            credential_id varchar(64) DEFAULT NULL,
            subdirectory varchar(255) DEFAULT NULL,
            release_configuration text DEFAULT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY package_type (type, package),
            KEY provider_identity (provider, provider_repository_id)
        ) ENGINE=InnoDB $charset_collate;";
	}

	private function attempt_schema( string $table_name, string $charset_collate ): string {
		return "CREATE TABLE $table_name (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            correlation_id char(32) NOT NULL,
            source varchar(16) NOT NULL,
            operation varchar(16) NOT NULL,
            package_type varchar(8) NOT NULL,
            package_slug varchar(191) NOT NULL,
            package_source varchar(16) NOT NULL DEFAULT 'branch',
            package_source_revision bigint(20) unsigned NOT NULL DEFAULT '0',
            provider varchar(32) NOT NULL,
            provider_repository_id varchar(191) NOT NULL,
            requested_ref varchar(255) NOT NULL,
            resolved_ref varchar(191) DEFAULT NULL,
            delivery_id varchar(191) DEFAULT NULL,
            delivery_digest char(64) DEFAULT NULL,
            state varchar(20) NOT NULL,
            mutation_started_at datetime DEFAULT NULL,
            outcome_code varchar(64) DEFAULT NULL,
            request_json text NOT NULL,
            created_at datetime NOT NULL,
            finished_at datetime DEFAULT NULL,
            resolved_at datetime DEFAULT NULL,
            resolved_by bigint(20) unsigned DEFAULT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY correlation_id (correlation_id),
            UNIQUE KEY webhook_target (provider, delivery_id, package_type, package_slug),
            KEY queue (state, created_at, id),
            KEY package_history (package_type, package_slug, created_at, id)
        ) ENGINE=InnoDB $charset_collate;";
	}

	/**
	 * @param array<string, array{type: string, nullable: bool, default: ?string, extra: string}> $expected_columns
	 * @param array<string, array{0: bool, 1: list<string>}> $expected_indexes
	 */
	private function needs_current_table_creation(
		string $table_name,
		array $expected_columns,
		array $expected_indexes
	): bool {
		$wpdb = $this->connection();

		$query            = $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table_name ) );
		$wpdb->last_error = '';
		// Schema installation must inspect the authoritative database.
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The preceding prepare() binds the escaped LIKE value; authoritative schema installation cannot use a cached existence result.
		$result = $wpdb->get_var( $query );
		if ( '' !== trim( (string) $wpdb->last_error ) ) {
			throw new DatabaseLifecycleFailure( 'schema_read_failed' );
		}
		if ( ! is_string( $result ) ) {
			return true;
		}
		if ( ! hash_equals( $table_name, $result ) ) {
			throw new DatabaseLifecycleFailure( 'schema_identity_failed' );
		}

		$actual = $this->inspect_table( $table_name );
		if ( $actual['columns'] !== $expected_columns || $actual['indexes'] !== $expected_indexes ) {
			throw new DatabaseLifecycleFailure( 'incompatible_schema' );
		}

		return false;
	}

	/**
	 * @param array<string, array{type: string, nullable: bool, default: ?string, extra: string}> $expected_columns
	 * @param array<string, array{0: bool, 1: list<string>}> $expected_indexes Index name to unique flag and ordered columns.
	 */
	private function verify_table( string $table_name, array $expected_columns, array $expected_indexes ): void {
		$actual = $this->inspect_table( $table_name );
		if ( $actual['columns'] !== $expected_columns || $actual['indexes'] !== $expected_indexes ) {
			throw new DatabaseLifecycleFailure( 'schema_verification_failed' );
		}
	}

	/**
	 * @return array{
	 *     columns: array<string, array{type: string, nullable: bool, default: ?string, extra: string}>,
	 *     indexes: array<string, array{0: bool, 1: list<string>}>
	 * }
	 */
	private function inspect_table( string $table_name ): array {
		$wpdb = $this->connection();

		$status_query     = $wpdb->prepare( 'SHOW TABLE STATUS WHERE Name = %s', $table_name );
		$columns_query    = $wpdb->prepare( 'SHOW COLUMNS FROM %i', $table_name );
		$indexes_query    = $wpdb->prepare( 'SHOW INDEX FROM %i', $table_name );
		$wpdb->last_error = '';
		// Schema installation must inspect the authoritative database.
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The preceding prepare() binds the table name as %s; inspect the live engine metadata before accepting the schema.
		$status = $wpdb->get_row( $status_query );
		// Schema installation must inspect the authoritative database.
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The preceding prepare() binds the table identifier as %i; inspect live column definitions before accepting the schema.
		$column_rows = $wpdb->get_results( $columns_query );
		// Schema installation must inspect the authoritative database.
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The preceding prepare() binds the table identifier as %i; inspect live index definitions before accepting the schema.
		$index_rows = $wpdb->get_results( $indexes_query );
		if ( '' !== trim( (string) $wpdb->last_error )
			|| ! is_array( $column_rows )
			|| ! is_array( $index_rows ) ) {
			throw new DatabaseLifecycleFailure( 'schema_read_failed' );
		}
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Database metadata retains its native field names.
		if ( ! is_object( $status ) || ! isset( $status->Engine ) || 0 !== strcasecmp( 'InnoDB', (string) $status->Engine ) ) {
			throw new DatabaseLifecycleFailure( 'wrong_storage_engine' );
		}

		$columns = array();
		foreach ( $column_rows as $row ) {
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Database metadata retains its native field names.
			if ( ! is_object( $row ) || ! isset( $row->Field, $row->Type )
				// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Database metadata retains its native field names.
				|| ! isset( $row->Null, $row->Extra )
				|| ! property_exists( $row, 'Default' )
				// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Database metadata retains its native field names.
				|| '' === (string) $row->Field
				// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Database metadata retains its native field names.
				|| '' === (string) $row->Type ) {
				throw new DatabaseLifecycleFailure( 'schema_read_failed' );
			}
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Database metadata retains its native field names.
			$name = (string) $row->Field;
			if ( isset( $columns[ $name ] ) ) {
				throw new DatabaseLifecycleFailure( 'schema_read_failed' );
			}
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Database metadata retains its native field names.
			$nullable = strtoupper( (string) $row->Null );
			if ( ! in_array( $nullable, array( 'YES', 'NO' ), true )
				// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Database metadata retains its native field names.
				|| ( null !== $row->Default && ! is_scalar( $row->Default ) ) ) {
				throw new DatabaseLifecycleFailure( 'schema_read_failed' );
			}
			$columns[ $name ] = array(
				// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Database metadata retains its native field names.
				'type'     => $this->normalize_column_type( (string) $row->Type ),
				'nullable' => 'YES' === $nullable,
				// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Database metadata retains its native field names.
				'default'  => null === $row->Default ? null : (string) $row->Default,
				// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Database metadata retains its native field names.
				'extra'    => strtolower( trim( (string) $row->Extra ) ),
			);
		}

		$indexes = array();
		foreach ( $index_rows as $row ) {
			if ( ! is_object( $row )
				// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Database metadata retains its native field names.
				|| ! isset( $row->Key_name, $row->Non_unique, $row->Seq_in_index, $row->Column_name )
				|| ! property_exists( $row, 'Sub_part' )
				// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Database metadata retains its native field names.
				|| ! is_numeric( $row->Non_unique )
				// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Database metadata retains its native field names.
				|| ! is_numeric( $row->Seq_in_index ) ) {
				throw new DatabaseLifecycleFailure( 'schema_read_failed' );
			}
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Database metadata retains its native field names.
			if ( null !== $row->Sub_part ) {
				throw new DatabaseLifecycleFailure( 'incompatible_schema' );
			}

			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Database metadata retains its native field names.
			$name = (string) $row->Key_name;
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Database metadata retains its native field names.
			$sequence = (int) $row->Seq_in_index;
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Database metadata retains its native field names.
			$column = (string) $row->Column_name;
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Database metadata retains its native field names.
			$unique = 0 === (int) $row->Non_unique;
			if ( '' === $name || $sequence < 1 || '' === $column
				|| ( isset( $indexes[ $name ][0] ) && $indexes[ $name ][0] !== $unique )
				|| isset( $indexes[ $name ][1][ $sequence ] ) ) {
				throw new DatabaseLifecycleFailure( 'schema_read_failed' );
			}
			$indexes[ $name ][0]              = $unique;
			$indexes[ $name ][1][ $sequence ] = $column;
		}
		foreach ( $indexes as &$index ) {
			ksort( $index[1], SORT_NUMERIC );
			$index[1] = array_values( $index[1] );
		}
		unset( $index );

		ksort( $columns, SORT_STRING );
		ksort( $indexes, SORT_STRING );

		return array(
			'columns' => $columns,
			'indexes' => $indexes,
		);
	}

	/**
	 * @return array<string, array{type: string, nullable: bool, default: ?string, extra: string}>
	 */
	private function package_columns(): array {
		$columns = array(
			'id'                     => $this->column( 'bigint(20) unsigned', false, null, 'auto_increment' ),
			'package'                => $this->column( 'varchar(255)' ),
			'repository'             => $this->column( 'varchar(512)' ),
			'branch'                 => $this->column( 'varchar(255)', false, 'main' ),
			'type'                   => $this->column( 'tinyint(3) unsigned' ),
			'deployment_policy'      => $this->column( 'varchar(10)', false, 'manual' ),
			'source'                 => $this->column( 'varchar(16)', false, 'branch' ),
			'source_revision'        => $this->column( 'bigint(20) unsigned', false, '1' ),
			'source_previous'        => $this->column( 'varchar(16)', true ),
			'source_changed_at'      => $this->column( 'datetime', true ),
			'source_changed_by'      => $this->column( 'bigint(20) unsigned', true ),
			'provider'               => $this->column( 'varchar(32)' ),
			'provider_repository_id' => $this->column( 'varchar(191)' ),
			'private'                => $this->column( 'tinyint(1) unsigned' ),
			'credential_id'          => $this->column( 'varchar(64)', true ),
			'subdirectory'           => $this->column( 'varchar(255)', true ),
			'release_configuration'  => $this->column( 'text', true ),
		);
		ksort( $columns, SORT_STRING );

		return $columns;
	}

	/**
	 * @return array<string, array{0: bool, 1: list<string>}>
	 */
	private function package_indexes(): array {
		return array(
			'PRIMARY'           => array( true, array( 'id' ) ),
			'package_type'      => array( true, array( 'type', 'package' ) ),
			'provider_identity' => array( false, array( 'provider', 'provider_repository_id' ) ),
		);
	}

	/**
	 * @return array<string, array{type: string, nullable: bool, default: ?string, extra: string}>
	 */
	private function attempt_columns(): array {
		$columns = array(
			'id'                      => $this->column( 'bigint(20) unsigned', false, null, 'auto_increment' ),
			'correlation_id'          => $this->column( 'char(32)' ),
			'source'                  => $this->column( 'varchar(16)' ),
			'operation'               => $this->column( 'varchar(16)' ),
			'package_type'            => $this->column( 'varchar(8)' ),
			'package_slug'            => $this->column( 'varchar(191)' ),
			'package_source'          => $this->column( 'varchar(16)', false, 'branch' ),
			'package_source_revision' => $this->column( 'bigint(20) unsigned', false, '0' ),
			'provider'                => $this->column( 'varchar(32)' ),
			'provider_repository_id'  => $this->column( 'varchar(191)' ),
			'requested_ref'           => $this->column( 'varchar(255)' ),
			'resolved_ref'            => $this->column( 'varchar(191)', true ),
			'delivery_id'             => $this->column( 'varchar(191)', true ),
			'delivery_digest'         => $this->column( 'char(64)', true ),
			'state'                   => $this->column( 'varchar(20)' ),
			'mutation_started_at'     => $this->column( 'datetime', true ),
			'outcome_code'            => $this->column( 'varchar(64)', true ),
			'request_json'            => $this->column( 'text' ),
			'created_at'              => $this->column( 'datetime' ),
			'finished_at'             => $this->column( 'datetime', true ),
			'resolved_at'             => $this->column( 'datetime', true ),
			'resolved_by'             => $this->column( 'bigint(20) unsigned', true ),
		);
		ksort( $columns, SORT_STRING );

		return $columns;
	}

	/**
	 * @return array<string, array{0: bool, 1: list<string>}>
	 */
	private function attempt_indexes(): array {
		return array(
			'PRIMARY'         => array( true, array( 'id' ) ),
			'correlation_id'  => array( true, array( 'correlation_id' ) ),
			'package_history' => array( false, array( 'package_type', 'package_slug', 'created_at', 'id' ) ),
			'queue'           => array( false, array( 'state', 'created_at', 'id' ) ),
			'webhook_target'  => array( true, array( 'provider', 'delivery_id', 'package_type', 'package_slug' ) ),
		);
	}

	/**
	 * @return array{type: string, nullable: bool, default: ?string, extra: string}
	 */
	private function column(
		string $type,
		bool $nullable = false,
		?string $default_value = null,
		string $extra = ''
	): array {
		return array(
			'type'     => $this->normalize_column_type( $type ),
			'nullable' => $nullable,
			'default'  => $default_value,
			'extra'    => $extra,
		);
	}

	private function installed_version(): ?string {
		$value = get_option( self::VERSION_OPTION, false );
		if ( false === $value ) {
			return null;
		}
		if ( ! is_string( $value ) || 1 !== preg_match( '/^[0-9]+\.[0-9]+$/D', $value ) ) {
			throw new DatabaseLifecycleFailure( 'malformed_schema_version' );
		}
		if ( version_compare( $value, '10.0', '<' )
			|| in_array( $value, array( '10.0', '11.0', '12.0' ), true )
		) {
			throw new DatabaseLifecycleFailure( 'unsupported_old_schema' );
		}
		if ( version_compare( $value, self::$booster_db_version, '>' ) ) {
			throw new DatabaseLifecycleFailure( 'newer_schema' );
		}
		if ( self::$booster_db_version !== $value ) {
			throw new DatabaseLifecycleFailure( 'unknown_schema_version' );
		}

		return $value;
	}

	private function normalize_column_type( string $type ): string {
		$type = strtolower( trim( preg_replace( '/\s+/', ' ', $type ) ?? $type ) );

		return preg_replace( '/^(bigint|tinyint)\([0-9]+\)/', '$1', $type ) ?? $type;
	}

	private function inspect_capabilities(): void {
		$database = $this->connection();
		if ( defined( 'WP_CONTENT_DIR' ) && is_string( constant( 'WP_CONTENT_DIR' ) ) && is_file( rtrim( WP_CONTENT_DIR, '/\\' ) . '/db.php' ) ) {
			throw new DatabaseCompatibilityFailure( 'database_drop_in' );
		}
		if ( ! method_exists( $database, 'db_server_info' ) ) {
			throw new DatabaseCompatibilityFailure( 'unknown_server' );
		}

		$previous_error    = property_exists( $database, 'last_error' ) ? (string) $database->last_error : null;
		$errors_suppressed = null;
		if ( null !== $previous_error ) {
			$database->last_error = '';
		}
		try {
			if ( method_exists( $database, 'suppress_errors' ) ) {
				$errors_suppressed = (bool) $database->suppress_errors( true );
			}

			$server_info = $database->db_server_info();
			if ( ! is_string( $server_info ) ) {
				throw new DatabaseCompatibilityFailure( 'unknown_server' );
			}
			$identity = $this->classify_server( trim( $server_info ) );
			if ( null === $identity ) {
				throw new DatabaseCompatibilityFailure( 'unknown_server' );
			}
			$minimum = 'mariadb' === $identity['family'] ? '10.11.0' : '8.0.0';
			if ( version_compare( $identity['version'], $minimum, '<' ) ) {
				throw new DatabaseCompatibilityFailure( 'unsupported_version' );
			}

			// Capability inspection must read the authoritative server engine list.
			$engines = $database->get_results( 'SHOW ENGINES' );
			$error   = property_exists( $database, 'last_error' ) ? trim( (string) $database->last_error ) : '';
			if ( ! is_array( $engines ) || '' !== $error ) {
				throw new DatabaseCompatibilityFailure( 'innodb_unavailable' );
			}
			foreach ( $engines as $engine ) {
				if ( is_object( $engine )
					// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Database metadata retains its native field names.
					&& isset( $engine->Engine, $engine->Support )
					// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Database metadata retains its native field names.
					&& 0 === strcasecmp( 'InnoDB', (string) $engine->Engine )
					// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Database metadata retains its native field names.
					&& in_array( strtoupper( (string) $engine->Support ), array( 'YES', 'DEFAULT' ), true ) ) {
					return;
				}
			}

			throw new DatabaseCompatibilityFailure( 'innodb_unavailable' );
		} catch ( DatabaseCompatibilityFailure $failure ) {
			throw $failure;
		} catch ( \Throwable ) {
			throw new DatabaseCompatibilityFailure( 'capability_probe_failed' );
		} finally {
			if ( null !== $previous_error ) {
				$database->last_error = $previous_error;
			}
			if ( null !== $errors_suppressed ) {
				try {
					$database->suppress_errors( $errors_suppressed );
				// phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Restoring optional wpdb error display must not escape the safe-state probe.
				} catch ( \Throwable ) {
					// The compatibility result remains authoritative.
				}
			}
		}
	}

	/**
	 * @return array{family: 'mysql'|'mariadb', version: string}|null
	 */
	private function classify_server( string $server_info ): ?array {
		if ( '' === $server_info ) {
			return null;
		}
		if ( false !== stripos( $server_info, 'mariadb' ) ) {
			$prefix = (string) stristr( $server_info, 'MariaDB', true );
			if ( preg_match_all( '/(?<![0-9])([0-9]+\.[0-9]+\.[0-9]+)(?![0-9])/', $prefix, $matches ) < 1 ) {
				return null;
			}
			$version = end( $matches[1] );

			return is_string( $version )
				? array(
					'family'  => 'mariadb',
					'version' => $version,
				)
				: null;
		}
		if ( preg_match( '/^(?<version>[0-9]+\.[0-9]+\.[0-9]+)(?:[- ].*)?$/D', $server_info, $matches ) !== 1 ) {
			return null;
		}
		if ( preg_match( '/(?:postgres|sqlite|tidb|percona)/i', $server_info ) === 1 ) {
			return null;
		}

		return array(
			'family'  => 'mysql',
			'version' => $matches['version'],
		);
	}

	private function connection(): object {
		if ( null !== $this->database ) {
			return $this->database;
		}

		global $wpdb;
		if ( ! is_object( $wpdb ) ) {
			throw new DatabaseCompatibilityFailure( 'database_unavailable' );
		}

		$this->database = $wpdb;

		return $wpdb;
	}
}
