<?php

declare(strict_types=1);

namespace RAN\Storage;

use RAN\Runtime\RuntimeSupport;
use RAN\RepositoryProvider\GitHubReplacementDeclaration;

class Database {

	public static string $booster_db_version = '15.0';

	public const VERSION_OPTION = 'ran_booster_db_version';

	private bool $capability_checked                          = false;
	private ?DatabaseCompatibilityFailure $capability_failure = null;
	private bool $lifecycle_checked                           = false;
	private bool $lifecycle_inspected                         = false;
	private ?DatabaseLifecycleFailure $lifecycle_failure      = null;
	private ?int $lower_case_table_names                      = null;

	public function __construct( private ?object $database = null, private ?GitHubReplacementDeclaration $bundled_declaration = null ) {
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

	/** Recovery requires authoritative healthy tables and must never install or repair them. */
	public function require_recovery_ready(): void {
		$this->require_supported();
		if ( self::$booster_db_version !== $this->installed_version() ) {
			throw new DatabaseLifecycleFailure( 'incompatible_schema' );
		}
		$this->verify_table( ran_booster_table_name(), $this->package_columns(), $this->package_indexes() );
		$this->verify_table( self::attempt_table_name(), $this->attempt_columns(), $this->attempt_indexes() );
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

		$installed_version = $this->installed_version();
		if ( ! $inspect_current_schema && self::$booster_db_version === $installed_version ) {
			return false;
		}

		if ( null === $installed_version && ( ! $inspect_current_schema || null === $this->bundled_declaration ) ) {
			throw new DatabaseLifecycleFailure( 'selection_install_required' );
		}

		$wpdb            = $this->schema_connection();
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
		$fresh = null === $installed_version && ! in_array( false, $missing_tables, true )
			&& isset( $wpdb->options ) && is_string( $wpdb->options ) && '' !== $wpdb->options
			&& ! defined( 'RAN_BOOSTER_ENCRYPTED_SECRETS_DIR' ) && ! defined( 'RAN_BOOSTER_ENCRYPTED_SECRETS_FILE' )
			&& ! defined( 'RAN_BOOSTER_GITHUB_TOKEN' );
		if ( $fresh ) {
			$wpdb->last_error = '';
			$query            = $wpdb->prepare( 'SELECT option_name FROM %i WHERE option_name LIKE %s LIMIT 1', $wpdb->options, $wpdb->esc_like( 'ran_booster_' ) . '%' );
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Identifiers and the LIKE pattern are prepared above.
			$existing_option = $wpdb->get_var( $query );
			$fresh           = ! $this->identity_read_failed() && null === $existing_option;
		}
		if ( $fresh ) {
			$wpdb->last_error = '';
			$activity_name    = substr( $attempt_table, 0, -strlen( 'ran_booster_deployment_attempts' ) ) . \RAN\Activity\ActivityStore::TABLE_SUFFIX;
			$query            = $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $activity_name ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The table pattern is prepared above.
			$activity_table = $wpdb->get_var( $query );
			if ( $this->identity_read_failed() ) {
				$fresh = false;
			} elseif ( is_string( $activity_table ) ) {
				try {
					$fresh = ! $this->table_name_matches( $activity_name, $activity_table );
				} catch ( DatabaseLifecycleFailure ) {
					$fresh = false;
				}
			}
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

		if ( ! isset( $wpdb->options ) || ! is_string( $wpdb->options ) || '' === $wpdb->options ) {
			throw new DatabaseLifecycleFailure( 'selection_storage_unsupported' );
		}
		$selection = new GitHubSelectionStore( $wpdb, $wpdb->options );
		if ( null === $installed_version && null !== $this->bundled_declaration ) {
			$selection->initialize_bundled( $this->bundled_declaration );
		}
		// Explicit current-version verification never repairs missing or corrupt authority.
		$selection->read( true );

		if ( ! update_option( self::VERSION_OPTION, self::$booster_db_version, false )
			// phpcs:ignore WordPress.PHP.YodaConditions.NotYoda -- Read the mutable expected version before get_option filters can run.
			&& self::$booster_db_version !== get_option( self::VERSION_OPTION, false ) ) {
			throw new DatabaseLifecycleFailure( 'version_write_failed' );
		}

		// phpcs:ignore WordPress.PHP.YodaConditions.NotYoda -- Read the mutable expected version before get_option filters can run.
		if ( self::$booster_db_version !== get_option( self::VERSION_OPTION, false ) ) {
			throw new DatabaseLifecycleFailure( 'version_verification_failed' );
		}
		if ( $fresh ) {
			try {
				add_option( \RAN\Admin\OnboardingPresenter::PRESENTATION_OPTION, 'automatic', '', false );
			} catch ( \Throwable ) {
				// Presentation is optional and cannot prevent activation.
				return true;
			}
		}

		return true;
	}

	/** @phpstan-impure */
	private function identity_read_failed(): bool {
		return '' !== $this->schema_connection()->last_error;
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
            package_type tinyint(3) unsigned NOT NULL,
            deployment_policy varchar(10) NOT NULL DEFAULT 'manual',
            source varchar(16) NOT NULL DEFAULT 'branch',
            source_revision bigint(20) unsigned NOT NULL DEFAULT '1',
            source_previous varchar(16) DEFAULT NULL,
            source_changed_at datetime DEFAULT NULL,
            source_changed_by bigint(20) unsigned DEFAULT NULL,
            provider varchar(32) NOT NULL,
            provider_repository_id varchar(191) NOT NULL,
            adoption_token varchar(643) DEFAULT NULL,
            private tinyint(1) unsigned NOT NULL,
            credential_id varchar(64) DEFAULT NULL,
            subdirectory varchar(255) DEFAULT NULL,
            release_configuration text DEFAULT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY package_type (package_type, package),
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
		$wpdb = $this->schema_connection();

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
		if ( ! $this->table_name_matches( $table_name, $result ) ) {
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
	private function inspect_table( string $table_name, bool $ignore_prefix_indexes = false ): array {
		$wpdb = $this->schema_connection();

		$status_query     = $wpdb->prepare( 'SHOW TABLE STATUS WHERE Name = %s', $table_name );
		$columns_query    = $wpdb->prepare( 'SHOW COLUMNS FROM %i', $table_name );
		$indexes_query    = $wpdb->prepare( 'SHOW INDEX FROM %i', $table_name );
		$wpdb->last_error = '';
		// Schema installation must inspect the authoritative database.
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The preceding prepare() binds the table name as %s; inspect the live engine metadata before accepting the schema.
		$status = $wpdb->get_row( $status_query );
		if ( $this->identity_read_failed() ) {
			throw new DatabaseLifecycleFailure( 'schema_read_failed' );
		}
		if ( null === $status ) {
			$mode           = $this->lower_case_table_names();
			$fallback_query = null;
			if ( 1 === $mode && strtolower( $table_name ) !== $table_name ) {
				$fallback_query = $wpdb->prepare( 'SHOW TABLE STATUS WHERE Name = %s', strtolower( $table_name ) );
			} elseif ( 2 === $mode ) {
				$fallback_query = $wpdb->prepare( 'SHOW TABLE STATUS WHERE LOWER(Name) = %s', strtolower( $table_name ) );
			}
			if ( null !== $fallback_query ) {
				$wpdb->last_error = '';
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared case-folding fallback when lower_case_table_names folds or preserves table names.
				$status = $wpdb->get_row( $fallback_query );
				if ( $this->identity_read_failed() ) {
					throw new DatabaseLifecycleFailure( 'schema_read_failed' );
				}
			}
		}
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Database metadata retains its native field names.
		if ( ! is_object( $status ) || ! isset( $status->Name ) || ! is_string( $status->Name ) || ! $this->table_name_matches( $table_name, $status->Name ) ) {
			$status = null;
		}
		// Schema installation must inspect the authoritative database.
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The preceding prepare() binds the table identifier as %i; inspect live column definitions before accepting the schema.
		$column_rows = $wpdb->get_results( $columns_query );
		if ( $this->identity_read_failed() ) {
			throw new DatabaseLifecycleFailure( 'schema_read_failed' );
		}
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

		$indexes        = array();
		$prefix_indexes = array();
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
				if ( ! $ignore_prefix_indexes ) {
					throw new DatabaseLifecycleFailure( 'incompatible_schema' );
				}
				// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Native database metadata.
				$prefix_indexes[ (string) $row->Key_name ] = true;
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
		$indexes = array_diff_key( $indexes, $prefix_indexes );
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

	/** Verify only the options invariants needed by the selection row, without altering WordPress tables. */
	public function require_selection_storage( string $table ): void {
		$shape = $this->inspect_table( $table, true );
		foreach ( array( 'option_name', 'option_value', 'autoload' ) as $column ) {
			if ( ! isset( $shape['columns'][ $column ] ) ) {
				throw new DatabaseLifecycleFailure( 'selection_storage_unsupported' );
			}
		}
		foreach ( $shape['indexes'] as $index ) {
			if ( array( true, array( 'option_name' ) ) === $index ) {
				return;
			}
		}
		throw new DatabaseLifecycleFailure( 'selection_storage_unsupported' );
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
			'package_type'           => $this->column( 'tinyint(3) unsigned' ),
			'deployment_policy'      => $this->column( 'varchar(10)', false, 'manual' ),
			'source'                 => $this->column( 'varchar(16)', false, 'branch' ),
			'source_revision'        => $this->column( 'bigint(20) unsigned', false, '1' ),
			'source_previous'        => $this->column( 'varchar(16)', true ),
			'source_changed_at'      => $this->column( 'datetime', true ),
			'source_changed_by'      => $this->column( 'bigint(20) unsigned', true ),
			'provider'               => $this->column( 'varchar(32)' ),
			'provider_repository_id' => $this->column( 'varchar(191)' ),
			'adoption_token'         => $this->column( 'varchar(643)', true ),
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
			'package_type'      => array( true, array( 'package_type', 'package' ) ),
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
			|| in_array( $value, array( '10.0', '11.0', '12.0', '13.0', '14.0' ), true )
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
		$database    = $this->connection();
		$has_drop_in = defined( 'WP_CONTENT_DIR' )
			&& is_string( constant( 'WP_CONTENT_DIR' ) )
			&& is_file( rtrim( WP_CONTENT_DIR, '/\\' ) . '/db.php' );
		if ( ! method_exists( $database, 'db_server_info' ) ) {
			throw new DatabaseCompatibilityFailure( $has_drop_in ? 'database_drop_in' : 'unknown_server' );
		}

		$previous_error    = property_exists( $database, 'last_error' ) ? (string) $database->last_error : null;
		$errors_suppressed = null;
		$suppress_errors   = null;
		if ( null !== $previous_error ) {
			$database->last_error = '';
		}
		try {
			if ( method_exists( $database, 'suppress_errors' ) ) {
				$suppress_errors   = $database->suppress_errors( ... );
				$errors_suppressed = (bool) $suppress_errors( true );
			}

			$query_results = array( $database, 'get_results' );
			$live          = null;
			if ( $has_drop_in ) {
				if ( ! is_callable( $query_results ) ) {
					throw new DatabaseCompatibilityFailure( 'database_drop_in' );
				}
				$live = $this->verify_drop_in_server( $database, $query_results );
			}
			$server_info = $database->db_server_info();
			$identity    = is_string( $server_info ) ? $this->classify_server( trim( $server_info ) ) : null;
			if ( null === $identity ) {
				throw new DatabaseCompatibilityFailure( 'unknown_server' );
			}
			if ( null !== $live && $live['family'] !== $identity['family'] ) {
				throw new DatabaseCompatibilityFailure( 'database_drop_in' );
			}
			$minimum = 'mariadb' === $identity['family'] ? '10.11.0' : '8.0.0';
			if ( version_compare( $identity['version'], $minimum, '<' ) || ( null !== $live && version_compare( $live['version'], $minimum, '<' ) ) ) {
				throw new DatabaseCompatibilityFailure( 'unsupported_version' );
			}

			// Capability inspection must read the authoritative server engine list.
			if ( ! is_callable( $query_results ) ) {
				throw new DatabaseCompatibilityFailure( 'capability_probe_failed' );
			}
			if ( null !== $previous_error ) {
				$database->last_error = '';
			}
			$engines      = $query_results( 'SHOW ENGINES' );
			$engine_error = trim( $this->capability_read_error( $database ) );
			if ( ! is_array( $engines ) || '' !== $engine_error ) {
				throw new DatabaseCompatibilityFailure( $has_drop_in ? 'database_drop_in' : 'innodb_unavailable' );
			}
			$innodb_supported = false;
			foreach ( $engines as $engine ) {
				if ( is_object( $engine )
					// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Database metadata retains its native field names.
					&& isset( $engine->Engine, $engine->Support )
					// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Database metadata retains its native field names.
					&& 0 === strcasecmp( 'InnoDB', (string) $engine->Engine )
					// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Database metadata retains its native field names.
					&& in_array( strtoupper( (string) $engine->Support ), array( 'YES', 'DEFAULT' ), true ) ) {
					// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Database metadata retains its native field names.
					$transactions = $engine->Transactions ?? null;
					if ( null === $transactions ) {
						if ( $has_drop_in ) {
							throw new DatabaseCompatibilityFailure( 'database_drop_in' );
						}
					} elseif ( 0 !== strcasecmp( 'YES', (string) $transactions ) ) {
						throw new DatabaseCompatibilityFailure( 'innodb_unavailable' );
					}
					$innodb_supported = true;
					break;
				}
			}

			if ( ! $innodb_supported ) {
				throw new DatabaseCompatibilityFailure( 'innodb_unavailable' );
			}
		} catch ( DatabaseCompatibilityFailure $failure ) {
			throw $failure;
		} catch ( \Throwable ) {
			throw new DatabaseCompatibilityFailure( 'capability_probe_failed' );
		} finally {
			if ( null !== $previous_error ) {
				$database->last_error = $previous_error;
			}
			if ( null !== $errors_suppressed && null !== $suppress_errors ) {
				try {
					$suppress_errors( $errors_suppressed );
				// phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Restoring optional wpdb error display must not escape the safe-state probe.
				} catch ( \Throwable ) {
					// The compatibility result remains authoritative.
				}
			}
		}
	}

	/**
	 * Prove that the drop-in keeps a session variable and its readback on one MySQL connection.
	 *
	 * @param callable(string): mixed $query_results
	 * @return array{family: 'mysql'|'mariadb', version: string}
	 */
	private function verify_drop_in_server( object $database, callable $query_results ): array {
		if ( ! method_exists( $database, 'query' ) ) {
			throw new DatabaseCompatibilityFailure( 'database_drop_in' );
		}
		$property   = $this->single_drop_in_handle( $database );
		$dbh_before = $property->isInitialized( $database ) ? $property->getValue( $database ) : null;
		if ( ! $dbh_before instanceof \mysqli ) {
			throw new DatabaseCompatibilityFailure( 'database_drop_in' );
		}
		try {
			if ( false === $database->query( 'SET @ran_booster_probe := CONNECTION_ID()' ) || '' !== $this->capability_read_error( $database ) ) {
				throw new DatabaseCompatibilityFailure( 'database_drop_in' );
			}
			$rows = $query_results( 'SELECT @@version AS version, @@version_comment AS comment, @ran_booster_probe AS probe_connection_id, CONNECTION_ID() AS read_connection_id' );
			if ( '' !== $this->capability_read_error( $database ) || ! is_array( $rows ) || 1 !== count( $rows ) || ! is_object( $rows[0] ?? null )
				|| ! $property->isInitialized( $database ) || $dbh_before !== $property->getValue( $database ) ) {
				throw new DatabaseCompatibilityFailure( 'database_drop_in' );
			}
		} catch ( \Throwable ) {
			throw new DatabaseCompatibilityFailure( 'database_drop_in' );
		} finally {
			try {
				$database->query( 'SET @ran_booster_probe := NULL' );
			// phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Clearing a disposable session variable is best effort.
			} catch ( \Throwable ) {
				// The compatibility result remains authoritative.
			}
		}
		$row     = $rows[0];
		$probe   = $row->probe_connection_id ?? null;
		$read    = $row->read_connection_id ?? null;
		$version = $row->version ?? null;
		$comment = $row->comment ?? null;
		$live    = is_string( $version ) ? $this->classify_server( trim( $version ) ) : null;
		if ( ( ! is_int( $probe ) && ! is_string( $probe ) ) || ( ! is_int( $read ) && ! is_string( $read ) )
			|| 1 !== preg_match( '/\A[1-9][0-9]*\z/', (string) $probe ) || (string) $probe !== (string) $read
			|| null === $live || ! is_string( $comment ) || '' === trim( $comment )
			|| 1 === preg_match( '/(?:postgres|sqlite|tidb|percona)/i', $comment )
			|| ( 'mysql' === $live['family'] && false !== stripos( $comment, 'mariadb' ) )
			|| ( 'mariadb' === $live['family'] && false !== stripos( $comment, 'mysql' ) ) ) {
			throw new DatabaseCompatibilityFailure( 'database_drop_in' );
		}

		return $live;
	}

	/** @phpstan-impure The database API mutates last_error while executing queries. */
	private function capability_read_error( object $database ): string {
		return (string) ( $database->last_error ?? '' );
	}

	private function single_drop_in_handle( object $database ): \ReflectionProperty {
		// Routed drop-ins cannot guarantee that options and Booster tables share a transaction.
		$properties = array( 'dbhs', 'hyper_servers', 'hyper_tables', 'ludicrous_servers', 'ludicrous_tables', 'srtm', 'callbacks', 'table_datasets', 'read_dbh', 'write_dbh' );
		$methods    = array( 'add_database', 'add_table', 'add_callback', 'get_table_from_query', 'is_write_query', 'send_reads_to_masters', 'determine_dataset' );
		$handle     = null;
		for ( $reflection = new \ReflectionObject( $database ); false !== $reflection; $reflection = $reflection->getParentClass() ) {
			if ( $reflection->hasMethod( 'db_connect' ) ) {
				$connect = $reflection->getMethod( 'db_connect' );
				if ( ( $database instanceof \wpdb && \wpdb::class !== $connect->getDeclaringClass()->getName() )
					|| 'query' === ( $connect->getParameters()[0] ?? null )?->getName() ) {
					throw new DatabaseCompatibilityFailure( 'database_drop_in' );
				}
			}
			foreach ( $methods as $method ) {
				// Core wpdb uses its table parser for charset checks. A drop-in override can route SQL.
				if ( $reflection->hasMethod( $method )
					&& ( 'get_table_from_query' !== $method || \wpdb::class !== $reflection->getMethod( $method )->getDeclaringClass()->getName() ) ) {
					throw new DatabaseCompatibilityFailure( 'database_drop_in' );
				}
			}
			foreach ( $reflection->getProperties() as $property ) {
				$name = $property->getName();
				if ( in_array( $name, $properties, true ) ) {
					throw new DatabaseCompatibilityFailure( 'database_drop_in' );
				}
				if ( 'dbh' === $name ) {
					$handle ??= $property;
				}
				$value = $property->isInitialized( $database ) ? $property->getValue( $database ) : null;
				if ( is_array( $value ) && array_filter( $value, static fn ( mixed $entry ): bool => $entry instanceof \mysqli ) ) {
					throw new DatabaseCompatibilityFailure( 'database_drop_in' );
				}
			}
		}

		return $handle ?? throw new DatabaseCompatibilityFailure( 'database_drop_in' );
	}

	private function table_name_matches( string $expected, string $actual ): bool {
		$mode = $this->lower_case_table_names();

		return match ( $mode ) {
			0 => hash_equals( $expected, $actual ),
			1 => hash_equals( strtolower( $expected ), $actual ),
			2 => 0 === strcasecmp( $expected, $actual ),
			default => false,
		};
	}

	private function lower_case_table_names(): int {
		if ( null !== $this->lower_case_table_names ) {
			return $this->lower_case_table_names;
		}

		$wpdb             = $this->schema_connection();
		$wpdb->last_error = '';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read the server's live table-name casing mode once per request.
		$value = $wpdb->get_var( 'SELECT @@lower_case_table_names' );
		if ( $this->identity_read_failed() ) {
			throw new DatabaseLifecycleFailure( 'schema_read_failed' );
		}
		$mode = is_int( $value )
			? $value
			: ( is_string( $value ) && 1 === preg_match( '/\A[012]\z/', $value ) ? (int) $value : null );
		if ( null === $mode || ! in_array( $mode, array( 0, 1, 2 ), true ) ) {
			throw new DatabaseLifecycleFailure( 'schema_read_failed' );
		}

		$this->lower_case_table_names = $mode;

		return $mode;
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

	private function schema_connection(): \wpdb|SchemaConnection {
		$database = $this->connection();
		if ( ! $database instanceof \wpdb && ! $database instanceof SchemaConnection ) {
			throw new DatabaseLifecycleFailure( 'schema_operation_failed' );
		}

		return $database;
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
