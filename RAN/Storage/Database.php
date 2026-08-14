<?php

declare(strict_types=1);

namespace RAN\Storage;

use RAN\Runtime\RuntimeSupport;

class Database {

	public static string $booster_db_version = '12.0';

	public const VERSION_OPTION = 'ran_booster_db_version';

	private bool $capabilityChecked                          = false;
	private ?DatabaseCompatibilityFailure $capabilityFailure = null;
	private bool $lifecycleChecked                           = false;
	private bool $lifecycleInspected                         = false;
	private ?DatabaseLifecycleFailure $lifecycleFailure      = null;

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
	public function requireSupported(): void {
		if ( ! $this->capabilityChecked ) {
			try {
				$this->inspectCapabilities();
			} catch ( DatabaseCompatibilityFailure $failure ) {
				$this->capabilityFailure = $failure;
			} catch ( \Throwable ) {
				$this->capabilityFailure = new DatabaseCompatibilityFailure( 'capability_probe_failed' );
			}
			$this->capabilityChecked = true;
		}

		if ( null !== $this->capabilityFailure ) {
			throw $this->capabilityFailure;
		}
	}

	public function isSupported(): bool {
		try {
			$this->requireSupported();

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
	public function maybeUpgrade(): void {
		RuntimeSupport::assertManagedOperationsAllowed();

		$this->runLifecycle( false );
	}

	/**
	 * Install or explicitly verify the current schema.
	 *
	 * @throws DatabaseCompatibilityFailure When the server is unsupported.
	 * @throws DatabaseLifecycleFailure When the schema cannot be prepared safely.
	 */
	public function install(): void {
		RuntimeSupport::assertManagedOperationsAllowed();

		$this->runLifecycle( true );
	}

	private function runLifecycle( bool $inspectCurrentSchema ): void {
		if ( $this->lifecycleChecked ) {
			if ( null !== $this->lifecycleFailure ) {
				throw $this->lifecycleFailure;
			}
			if ( ! $inspectCurrentSchema || $this->lifecycleInspected ) {
				return;
			}
		}

		try {
			$this->lifecycleInspected = $this->prepareSchema( $inspectCurrentSchema );
			$this->lifecycleChecked   = true;
		} catch ( DatabaseCompatibilityFailure $failure ) {
			throw $failure;
		} catch ( DatabaseLifecycleFailure $failure ) {
			$this->lifecycleFailure = $failure;
			$this->lifecycleChecked = true;
		} catch ( \Throwable ) {
			$this->lifecycleFailure = new DatabaseLifecycleFailure( 'schema_operation_failed' );
			$this->lifecycleChecked = true;
		}

		if ( null !== $this->lifecycleFailure ) {
			throw $this->lifecycleFailure;
		}
	}

	/**
	 * Require usable, current storage. Storage callers should use this guard.
	 *
	 * @throws DatabaseCompatibilityFailure When the server is unsupported.
	 * @throws DatabaseLifecycleFailure When the schema cannot be prepared safely.
	 */
	public function requireReady(): void {
		$this->maybeUpgrade();
	}

	/**
	 * Read-only readiness status for passive diagnostics and administrator UI.
	 *
	 * This intentionally does not inspect or mutate tables. Normal lifecycle
	 * hooks call maybeUpgrade() before this status is presented.
	 */
	public function isReady(): bool {
		if ( $this->lifecycleChecked ) {
			return null === $this->lifecycleFailure;
		}

		if ( ! $this->isSupported() ) {
			return false;
		}

		try {
			return self::$booster_db_version === $this->installedVersion();
		} catch ( DatabaseLifecycleFailure ) {
			return false;
		}
	}

	private function prepareSchema( bool $inspectCurrentSchema ): bool {
		$this->requireSupported();
		$wpdb = $this->connection();

		$installedVersion = $this->installedVersion();
		if ( ! $inspectCurrentSchema && self::$booster_db_version === $installedVersion ) {
			return false;
		}

		$packageTable   = ran_booster_table_name();
		$attemptTable   = self::attemptTableName();
		$auditTable     = self::rejectedAdmissionAuditTableName();
		$charsetCollate = $wpdb->get_charset_collate();
		$tables         = array(
			$packageTable => array(
				'schema'          => $this->packageSchema( $packageTable, $charsetCollate ),
				'columns'         => $this->packageColumns(),
				'indexes'         => $this->packageIndexes(),
				'additiveColumns' => array(),
				'additiveIndexes' => array(),
			),
			$attemptTable => array(
				'schema'          => $this->attemptSchema( $attemptTable, $charsetCollate ),
				'columns'         => $this->attemptColumns(),
				'indexes'         => $this->attemptIndexes(),
				'additiveColumns' => array( 'resolved_at', 'resolved_by' ),
				'additiveIndexes' => array(),
			),
			$auditTable   => array(
				'schema'          => $this->rejectedAdmissionAuditSchema( $auditTable, $charsetCollate ),
				'columns'         => $this->rejectedAdmissionAuditColumns(),
				'indexes'         => $this->rejectedAdmissionAuditIndexes(),
				'additiveColumns' => array(),
				'additiveIndexes' => array(),
			),
		);

		$needsDelta = array();
		foreach ( $tables as $table => $contract ) {
			$needsDelta[ $table ] = $this->needsAdditiveUpdate(
				$table,
				$contract['columns'],
				$contract['indexes'],
				$contract['additiveColumns'],
				$contract['additiveIndexes']
			);
		}

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		foreach ( $tables as $table => $contract ) {
			if ( $needsDelta[ $table ] ) {
				dbDelta( $contract['schema'] );
			}
		}

		foreach ( $tables as $table => $contract ) {
			$this->verifyTable( $table, $contract['columns'], $contract['indexes'] );
		}

		if ( ! update_option( self::VERSION_OPTION, self::$booster_db_version, false )
			&& self::$booster_db_version !== get_option( self::VERSION_OPTION, false ) ) {
			throw new DatabaseLifecycleFailure( 'version_write_failed' );
		}

		if ( self::$booster_db_version !== get_option( self::VERSION_OPTION, false ) ) {
			throw new DatabaseLifecycleFailure( 'version_verification_failed' );
		}

		return true;
	}

	public static function attemptTableName(): string {
		global $wpdb;

		return $wpdb->prefix . 'ran_booster_deployment_attempts';
	}

	public static function rejectedAdmissionAuditTableName(): string {
		global $wpdb;

		return $wpdb->prefix . 'ran_booster_rejected_admission_audit';
	}

	private function packageSchema( string $tableName, string $charsetCollate ): string {
		return "CREATE TABLE $tableName (
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
        ) ENGINE=InnoDB $charsetCollate;";
	}

	private function attemptSchema( string $tableName, string $charsetCollate ): string {
		return "CREATE TABLE $tableName (
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
        ) ENGINE=InnoDB $charsetCollate;";
	}

	private function rejectedAdmissionAuditSchema( string $tableName, string $charsetCollate ): string {
		return "CREATE TABLE $tableName (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            event varchar(32) NOT NULL,
            attempt_id bigint(20) unsigned NOT NULL,
            correlation_id char(32) NOT NULL,
            package_type varchar(8) NOT NULL,
            package_slug varchar(191) NOT NULL,
            actor_id bigint(20) unsigned NOT NULL,
            operation varchar(32) NOT NULL,
            occurred_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY deduplication (event, attempt_id, actor_id, operation, occurred_at, id),
            KEY activity (occurred_at, id),
            KEY attempt_activity (attempt_id, occurred_at, id)
        ) ENGINE=InnoDB $charsetCollate;";
	}

	/**
	 * @param array<string, array{type: string, nullable: bool, default: ?string, extra: string}> $expectedColumns
	 * @param array<string, array{0: bool, 1: list<string>}> $expectedIndexes
	 * @param list<string> $additiveColumns
	 * @param list<string> $additiveIndexes
	 */
	private function needsAdditiveUpdate(
		string $tableName,
		array $expectedColumns,
		array $expectedIndexes,
		array $additiveColumns,
		array $additiveIndexes
	): bool {
		$wpdb = $this->connection();

		$query            = $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $tableName ) );
		$wpdb->last_error = '';
		// Schema installation must inspect the authoritative database.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$result = $wpdb->get_var( $query );
		if ( '' !== trim( (string) $wpdb->last_error ) ) {
			throw new DatabaseLifecycleFailure( 'schema_read_failed' );
		}
		if ( ! is_string( $result ) ) {
			return true;
		}
		if ( ! hash_equals( $tableName, $result ) ) {
			throw new DatabaseLifecycleFailure( 'schema_identity_failed' );
		}

		$actual = $this->inspectTable( $tableName );
		if ( array_diff_key( $actual['columns'], $expectedColumns )
			|| array_diff_key( $actual['indexes'], $expectedIndexes ) ) {
			throw new DatabaseLifecycleFailure( 'incompatible_schema' );
		}
		foreach ( $actual['columns'] as $name => $type ) {
			if ( $type !== $expectedColumns[ $name ] ) {
				throw new DatabaseLifecycleFailure( 'incompatible_schema' );
			}
		}
		foreach ( $actual['indexes'] as $name => $index ) {
			if ( $index !== $expectedIndexes[ $name ] ) {
				throw new DatabaseLifecycleFailure( 'incompatible_schema' );
			}
		}

		$missingColumns = array_diff_key( $expectedColumns, $actual['columns'] );
		$missingIndexes = array_diff_key( $expectedIndexes, $actual['indexes'] );
		if ( array_diff_key( $missingColumns, array_flip( $additiveColumns ) )
			|| array_diff_key( $missingIndexes, array_flip( $additiveIndexes ) ) ) {
			throw new DatabaseLifecycleFailure( 'incompatible_schema' );
		}

		return array() !== $missingColumns || array() !== $missingIndexes;
	}

	/**
	 * @param array<string, array{type: string, nullable: bool, default: ?string, extra: string}> $expectedColumns
	 * @param array<string, array{0: bool, 1: list<string>}> $expectedIndexes Index name to unique flag and ordered columns.
	 */
	private function verifyTable( string $tableName, array $expectedColumns, array $expectedIndexes ): void {
		$actual = $this->inspectTable( $tableName );
		if ( $actual['columns'] !== $expectedColumns || $actual['indexes'] !== $expectedIndexes ) {
			throw new DatabaseLifecycleFailure( 'schema_verification_failed' );
		}
	}

	/**
	 * @return array{
	 *     columns: array<string, array{type: string, nullable: bool, default: ?string, extra: string}>,
	 *     indexes: array<string, array{0: bool, 1: list<string>}>
	 * }
	 */
	private function inspectTable( string $tableName ): array {
		$wpdb = $this->connection();

		$statusQuery      = $wpdb->prepare( 'SHOW TABLE STATUS WHERE Name = %s', $tableName );
		$columnsQuery     = $wpdb->prepare( 'SHOW COLUMNS FROM %i', $tableName );
		$indexesQuery     = $wpdb->prepare( 'SHOW INDEX FROM %i', $tableName );
		$wpdb->last_error = '';
		// Schema installation must inspect the authoritative database.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$status = $wpdb->get_row( $statusQuery );
		// Schema installation must inspect the authoritative database.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$columnRows = $wpdb->get_results( $columnsQuery );
		// Schema installation must inspect the authoritative database.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$indexRows = $wpdb->get_results( $indexesQuery );
		if ( '' !== trim( (string) $wpdb->last_error )
			|| ! is_array( $columnRows )
			|| ! is_array( $indexRows ) ) {
			throw new DatabaseLifecycleFailure( 'schema_read_failed' );
		}
		if ( ! is_object( $status ) || ! isset( $status->Engine ) || 0 !== strcasecmp( 'InnoDB', (string) $status->Engine ) ) {
			throw new DatabaseLifecycleFailure( 'wrong_storage_engine' );
		}

		$columns = array();
		foreach ( $columnRows as $row ) {
			if ( ! is_object( $row ) || ! isset( $row->Field, $row->Type )
				|| ! isset( $row->Null, $row->Extra )
				|| ! property_exists( $row, 'Default' )
				|| '' === (string) $row->Field
				|| '' === (string) $row->Type ) {
				throw new DatabaseLifecycleFailure( 'schema_read_failed' );
			}
			$name = (string) $row->Field;
			if ( isset( $columns[ $name ] ) ) {
				throw new DatabaseLifecycleFailure( 'schema_read_failed' );
			}
			$nullable = strtoupper( (string) $row->Null );
			if ( ! in_array( $nullable, array( 'YES', 'NO' ), true )
				|| ( null !== $row->Default && ! is_scalar( $row->Default ) ) ) {
				throw new DatabaseLifecycleFailure( 'schema_read_failed' );
			}
			$columns[ $name ] = array(
				'type'     => $this->normalizeColumnType( (string) $row->Type ),
				'nullable' => 'YES' === $nullable,
				'default'  => null === $row->Default ? null : (string) $row->Default,
				'extra'    => strtolower( trim( (string) $row->Extra ) ),
			);
		}

		$indexes = array();
		foreach ( $indexRows as $row ) {
			if ( ! is_object( $row )
				|| ! isset( $row->Key_name, $row->Non_unique, $row->Seq_in_index, $row->Column_name )
				|| ! property_exists( $row, 'Sub_part' )
				|| ! is_numeric( $row->Non_unique )
				|| ! is_numeric( $row->Seq_in_index ) ) {
				throw new DatabaseLifecycleFailure( 'schema_read_failed' );
			}
			if ( null !== $row->Sub_part ) {
				throw new DatabaseLifecycleFailure( 'incompatible_schema' );
			}

			$name     = (string) $row->Key_name;
			$sequence = (int) $row->Seq_in_index;
			$column   = (string) $row->Column_name;
			$unique   = 0 === (int) $row->Non_unique;
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
	private function packageColumns(): array {
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
	private function packageIndexes(): array {
		return array(
			'PRIMARY'           => array( true, array( 'id' ) ),
			'package_type'      => array( true, array( 'type', 'package' ) ),
			'provider_identity' => array( false, array( 'provider', 'provider_repository_id' ) ),
		);
	}

	/**
	 * @return array<string, array{type: string, nullable: bool, default: ?string, extra: string}>
	 */
	private function attemptColumns(): array {
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
	private function attemptIndexes(): array {
		return array(
			'PRIMARY'         => array( true, array( 'id' ) ),
			'correlation_id'  => array( true, array( 'correlation_id' ) ),
			'package_history' => array( false, array( 'package_type', 'package_slug', 'created_at', 'id' ) ),
			'queue'           => array( false, array( 'state', 'created_at', 'id' ) ),
			'webhook_target'  => array( true, array( 'provider', 'delivery_id', 'package_type', 'package_slug' ) ),
		);
	}

	/**
	 * @return array<string, array{type: string, nullable: bool, default: ?string, extra: string}>
	 */
	private function rejectedAdmissionAuditColumns(): array {
		$columns = array(
			'id'             => $this->column( 'bigint(20) unsigned', false, null, 'auto_increment' ),
			'event'          => $this->column( 'varchar(32)' ),
			'attempt_id'     => $this->column( 'bigint(20) unsigned' ),
			'correlation_id' => $this->column( 'char(32)' ),
			'package_type'   => $this->column( 'varchar(8)' ),
			'package_slug'   => $this->column( 'varchar(191)' ),
			'actor_id'       => $this->column( 'bigint(20) unsigned' ),
			'operation'      => $this->column( 'varchar(32)' ),
			'occurred_at'    => $this->column( 'datetime' ),
		);
		ksort( $columns, SORT_STRING );

		return $columns;
	}

	/** @return array<string, array{0: bool, 1: list<string>}> */
	private function rejectedAdmissionAuditIndexes(): array {
		return array(
			'PRIMARY'          => array( true, array( 'id' ) ),
			'activity'         => array( false, array( 'occurred_at', 'id' ) ),
			'attempt_activity' => array( false, array( 'attempt_id', 'occurred_at', 'id' ) ),
			'deduplication'    => array( false, array( 'event', 'attempt_id', 'actor_id', 'operation', 'occurred_at', 'id' ) ),
		);
	}

	/**
	 * @return array{type: string, nullable: bool, default: ?string, extra: string}
	 */
	private function column(
		string $type,
		bool $nullable = false,
		?string $default = null,
		string $extra = ''
	): array {
		return array(
			'type'     => $this->normalizeColumnType( $type ),
			'nullable' => $nullable,
			'default'  => $default,
			'extra'    => $extra,
		);
	}

	private function installedVersion(): ?string {
		$value = get_option( self::VERSION_OPTION, false );
		if ( false === $value ) {
			return null;
		}
		if ( ! is_string( $value ) || 1 !== preg_match( '/^[0-9]+\.[0-9]+$/D', $value ) ) {
			throw new DatabaseLifecycleFailure( 'malformed_schema_version' );
		}
		if ( version_compare( $value, '10.0', '<' ) ) {
			throw new DatabaseLifecycleFailure( 'unsupported_old_schema' );
		}
		if ( version_compare( $value, self::$booster_db_version, '>' ) ) {
			throw new DatabaseLifecycleFailure( 'newer_schema' );
		}
		if ( ! in_array( $value, array( '10.0', '11.0', self::$booster_db_version ), true ) ) {
			throw new DatabaseLifecycleFailure( 'unknown_schema_version' );
		}

		return $value;
	}

	private function normalizeColumnType( string $type ): string {
		$type = strtolower( trim( preg_replace( '/\s+/', ' ', $type ) ?? $type ) );

		return preg_replace( '/^(bigint|tinyint)\([0-9]+\)/', '$1', $type ) ?? $type;
	}

	private function inspectCapabilities(): void {
		$database = $this->connection();
		if ( defined( 'WP_CONTENT_DIR' ) && is_string( WP_CONTENT_DIR ) && is_file( rtrim( WP_CONTENT_DIR, '/\\' ) . '/db.php' ) ) {
			throw new DatabaseCompatibilityFailure( 'database_drop_in' );
		}
		if ( ! method_exists( $database, 'db_server_info' ) ) {
			throw new DatabaseCompatibilityFailure( 'unknown_server' );
		}

		$previousError    = property_exists( $database, 'last_error' ) ? (string) $database->last_error : null;
		$errorsSuppressed = null;
		if ( null !== $previousError ) {
			$database->last_error = '';
		}
		try {
			if ( method_exists( $database, 'suppress_errors' ) ) {
				$errorsSuppressed = (bool) $database->suppress_errors( true );
			}

			$serverInfo = $database->db_server_info();
			if ( ! is_string( $serverInfo ) ) {
				throw new DatabaseCompatibilityFailure( 'unknown_server' );
			}
			$identity = $this->classifyServer( trim( $serverInfo ) );
			if ( null === $identity ) {
				throw new DatabaseCompatibilityFailure( 'unknown_server' );
			}
			$minimum = 'mariadb' === $identity['family'] ? '10.11.0' : '8.0.0';
			if ( version_compare( $identity['version'], $minimum, '<' ) ) {
				throw new DatabaseCompatibilityFailure( 'unsupported_version' );
			}

			// Capability inspection must read the authoritative server engine list.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$engines = $database->get_results( 'SHOW ENGINES' );
			$error   = property_exists( $database, 'last_error' ) ? trim( (string) $database->last_error ) : '';
			if ( ! is_array( $engines ) || '' !== $error ) {
				throw new DatabaseCompatibilityFailure( 'innodb_unavailable' );
			}
			foreach ( $engines as $engine ) {
				if ( is_object( $engine )
					&& isset( $engine->Engine, $engine->Support )
					&& 0 === strcasecmp( 'InnoDB', (string) $engine->Engine )
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
			if ( null !== $previousError ) {
				$database->last_error = $previousError;
			}
			if ( null !== $errorsSuppressed ) {
				try {
					$database->suppress_errors( $errorsSuppressed );
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
	private function classifyServer( string $serverInfo ): ?array {
		if ( '' === $serverInfo ) {
			return null;
		}
		if ( false !== stripos( $serverInfo, 'mariadb' ) ) {
			$prefix = (string) stristr( $serverInfo, 'MariaDB', true );
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
		if ( preg_match( '/^(?<version>[0-9]+\.[0-9]+\.[0-9]+)(?:[- ].*)?$/D', $serverInfo, $matches ) !== 1 ) {
			return null;
		}
		if ( preg_match( '/(?:postgres|sqlite|tidb|percona)/i', $serverInfo ) === 1 ) {
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
