<?php

declare(strict_types=1);

namespace RAN\Activity;

use InvalidArgumentException;
use RAN\AdoptionIdentity;
use RAN\RepositoryProvider\ProviderCode;
use RAN\RepositoryProvider\ProviderDiagnosticResult;
use RAN\Storage\DeploymentWriteConnection;

/**
 * Durable reporting; deployment writes join the journal's database transaction.
 *
 * @phpstan-type Capture array{subject_type:string,subject_identity:string,adoption_token:?string,context:string,purpose:?string,provenance:string,actor?:int}
 * @phpstan-type Record array<string, int|string|null>
 */
final class ActivityStore {
	public const VERSION_OPTION = 'ran_booster_activity_schema';
	public const TABLE_SUFFIX   = 'ran_booster_activity';
	private const PURPOSES      = array( 'branch.check', 'release.check', 'workflow.assessment' );
	private string $table;

	public function __construct( private ?object $database = null, ?string $table = null ) {
		if ( null === $this->database ) {
			global $wpdb;
			$this->database = $wpdb;
		}
		$this->table = $table ?? ( $this->database instanceof \wpdb ? $this->database->prefix : '' ) . self::TABLE_SUFFIX;
		if ( 1 !== preg_match( '/\A[A-Za-z0-9_]+\z/', $this->table ) ) {
			throw new InvalidArgumentException( 'Invalid activity table name.' );
		}
	}

	/** Called only by explicit activation/upgrade, never from a history reader. */
	public function install(): bool {
		$version = get_option( self::VERSION_OPTION );
		if ( false !== $version && '1' !== $version ) {
			return false;
		}
		$sql = "CREATE TABLE IF NOT EXISTS {$this->table} (
			id bigint unsigned NOT NULL AUTO_INCREMENT,
			correlation char(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
			sort_at datetime NOT NULL, started_at datetime NOT NULL, completed_at datetime NULL,
			provider varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
			subject_type varchar(16) NOT NULL, subject_identity varbinary(255) NOT NULL,
			adoption_token varbinary(643) NULL, context varbinary(2048) NOT NULL,
			context_key char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
			operation varchar(96) NOT NULL, purpose varchar(32) NULL,
			provenance varchar(16) NOT NULL, actor bigint unsigned NOT NULL,
			status varchar(16) NOT NULL, stage varchar(96) NOT NULL DEFAULT '',
			reason varchar(96) NOT NULL DEFAULT '', message text NOT NULL, remediation text NOT NULL,
			facts text NOT NULL, effect varchar(16) NOT NULL DEFAULT 'none',
			PRIMARY KEY (id), UNIQUE KEY correlation (correlation),
			KEY history (sort_at,id),
			KEY summary (context_key,purpose,id)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
		if ( false === $this->query( $sql ) ) {
			return false;
		}
		$columns = $this->rows( "SELECT id,correlation,sort_at,started_at,completed_at,provider,subject_type,subject_identity,adoption_token,context,context_key,operation,purpose,provenance,actor,status,stage,reason,message,remediation,facts,effect FROM {$this->table} LIMIT 0" );
		if ( null === $columns || ! $this->transactional_storage() ) {
			return false;
		}
		update_option( self::VERSION_OPTION, '1', false );
		return $this->available();
	}

	public function available(): bool {
		return '1' === get_option( self::VERSION_OPTION );
	}

	/** @return array{valid:bool,maximum_rows:int,maximum_days:int,source:'configured'|'default'} */
	public static function retention_policy( mixed $rows = null, mixed $days = null ): array {
		$configured = null !== $rows || null !== $days || defined( 'RAN_BOOSTER_HISTORY_ROWS' ) || defined( 'RAN_BOOSTER_HISTORY_DAYS' );
		$rows     ??= defined( 'RAN_BOOSTER_HISTORY_ROWS' ) ? constant( 'RAN_BOOSTER_HISTORY_ROWS' ) : 2000;
		$days     ??= defined( 'RAN_BOOSTER_HISTORY_DAYS' ) ? constant( 'RAN_BOOSTER_HISTORY_DAYS' ) : 30;
		$valid_rows = is_int( $rows ) && $rows >= 200 && $rows <= 100000;
		$valid_days = is_int( $days ) && $days >= 1 && $days <= 365;
		return array(
			'valid'        => $valid_rows && $valid_days,
			'maximum_rows' => $valid_rows ? $rows : 2000,
			'maximum_days' => $valid_days ? $days : 30,
			'source'       => $configured ? 'configured' : 'default',
		);
	}

	/** @param Capture $capture */
	public function begin( string $provider, ActivityRequest $request, array $capture, ?string $correlation = null ): ?int {
		if ( ! $this->available() ) {
			return null;
		}
		$coupled = null !== $correlation;
		try {
			ProviderCode::parse( $provider );
			$this->validate_capture( $capture );
			$correlation ??= bin2hex( random_bytes( 16 ) );
			if ( 1 !== preg_match( '/\A[a-f0-9]{32}\z/', $correlation ) ) {
				return null;
			}
		} catch ( InvalidArgumentException | \Random\RandomException ) {
			return null;
		}
		if ( $coupled && ! $this->transactional_storage() ) {
			return null;
		}
		$token = $capture['adoption_token'];
		if ( null !== $token && ! AdoptionIdentity::matches( $token, $provider, $request->repository->provider_repository_id ?? '' ) ) {
			return null;
		}
		$data = array_merge(
			$capture,
			array(
				'provider'    => $provider,
				'operation'   => $request->operation,
				'correlation' => $correlation,
				'context_key' => $this->context_key( $capture ),
				'actor'       => $capture['actor'] ?? 0,
				'started_at'  => gmdate( 'Y-m-d H:i:s' ),
				'sort_at'     => current_time( 'mysql' ),
				'status'      => 'incomplete',
				'message'     => '',
				'remediation' => '',
				'facts'       => '{}',
			)
		);
		if ( 1 !== $this->connection()?->insert( $this->table, $data ) ) {
			return null;
		}
		$rows = $this->rows( $this->prepare( "SELECT id FROM {$this->table} WHERE correlation = %s", $correlation ) );
		return isset( $rows[0]['id'] ) ? (int) $rows[0]['id'] : null;
	}

	public function progress( ?int $id, string $code, string $message = '' ): bool {
		try {
			$this->validate_text( $code, $message );
		} catch ( InvalidArgumentException | \ValueError ) {
			return false;
		}
		if ( null === $id ) {
			return false;
		}
		return 1 === $this->query(
			$this->prepare(
				"UPDATE {$this->table} SET stage = %s, message = %s WHERE id = %d AND status = 'incomplete'",
				$code,
				$message,
				$id
			)
		);
	}

	/** @param array<string, scalar|null> $facts */
	public function progress_by_correlation( string $correlation, string $code, string $message = '', array $facts = array() ): bool {
		try {
			$this->validate_text( $code, $message );
			$encoded = $this->encode_facts( $facts );
		} catch ( InvalidArgumentException | \ValueError ) {
			return false;
		}
		if ( 1 !== preg_match( '/\A[a-f0-9]{32}\z/', $correlation ) || ! $this->transactional_storage() ) {
			return false;
		}
		return 1 === $this->query(
			$this->prepare(
				"UPDATE {$this->table} SET stage = %s, message = %s, facts = %s WHERE correlation = %s AND status = 'incomplete'",
				$code,
				$message,
				$encoded,
				$correlation
			)
		);
	}

	/** @param array<string, scalar|null> $facts Core-normalized observation fields, never arbitrary provider payloads. */
	public function complete( ?int $id, string $status, string $reason, string $message = '', string $remediation = '', array $facts = array(), string $effect = 'none' ): bool {
		return $this->complete_record( $id, null, $status, $reason, $message, $remediation, $facts, $effect );
	}

	/** @param array<string, scalar|null> $facts */
	public function complete_by_correlation( string $correlation, string $status, string $reason, string $message = '', string $remediation = '', array $facts = array(), string $effect = 'none' ): bool {
		if ( 1 !== preg_match( '/\A[a-f0-9]{32}\z/', $correlation ) || ! $this->transactional_storage() ) {
			return false;
		}
		return $this->complete_record( null, $correlation, $status, $reason, $message, $remediation, $facts, $effect );
	}

	/** @param array<string, scalar|null> $facts */
	private function complete_record( ?int $id, ?string $correlation, string $status, string $reason, string $message, string $remediation, array $facts, string $effect ): bool {
		try {
			$this->validate_text( $reason, $message, $remediation );
			$encoded = $this->encode_facts( $facts );
		} catch ( InvalidArgumentException | \ValueError ) {
			return false;
		}
		if ( ! in_array( $status, array( 'succeeded', 'failed', 'rejected', 'skipped' ), true )
			|| ! in_array( $effect, array( 'none', 'established', 'invalidated' ), true ) ) {
			return false;
		}
		if ( null === $id && null === $correlation ) {
			return false;
		}
		$where  = null !== $id ? 'id = %d' : 'correlation = %s';
		$value  = $id ?? $correlation;
		$result = $this->query(
			$this->prepare(
				"UPDATE {$this->table} SET status = %s, reason = %s, message = %s, remediation = %s, facts = %s, effect = %s, completed_at = %s WHERE $where AND status = 'incomplete'",
				$status,
				$reason,
				$message,
				$remediation,
				$encoded,
				$effect,
				gmdate( 'Y-m-d H:i:s' ),
				$value
			)
		);
		if ( 1 === $result ) {
			return true;
		}
		if ( 0 !== $result ) {
			return false;
		}
		$rows     = $this->rows( $this->prepare( "SELECT status,reason,message,remediation,facts,effect FROM {$this->table} WHERE $where", $value ) );
		$expected = array(
			'status'      => $status,
			'reason'      => $reason,
			'message'     => $message,
			'remediation' => $remediation,
			'facts'       => $encoded,
			'effect'      => $effect,
		);
		return isset( $rows[0] ) && $expected === $rows[0];
	}

	/**
	 * Bounded history source, including protected unresolved deployment reports.
	 * @param array<string,string> $filters
	 * @param array{time:string,source:string,id:int}|null $before
	 * @return array{available:bool,records:list<Record>}
	 */
	public function activity_history( array $filters = array(), ?array $before = null, int $limit = 26 ): array {
		$unavailable = array(
			'available' => false,
			'records'   => array(),
		);
		if ( ! $this->available() || $limit < 1 || $limit > 101 ) {
			return $unavailable;
		}
		$where = $this->history_scope_where();
		foreach ( $filters as $key => $value ) {
			$outcome_filter = 'outcome' === $key;
			$key            = $outcome_filter ? 'status' : $key;
			if ( ! in_array( $key, array( 'subject_type', 'subject_identity', 'provider', 'operation', 'status', 'adoption_token', 'context', 'purpose' ), true ) || strlen( $value ) > 2048 ) {
				return $unavailable;
			}
			$where[] = $outcome_filter
				? $this->prepare( "(BINARY status = %s OR BINARY JSON_UNQUOTE(JSON_EXTRACT(facts, '$.deployment_state')) = %s)", $value, $value )
				: $this->prepare( "BINARY $key = %s", $value );
		}
		if ( null !== $before ) {
			if ( ! in_array( $before['source'], array( 'activity', 'deployment' ), true ) || $before['id'] < 1
				|| 1 !== preg_match( '/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\z/', $before['time'] ) ) {
				return $unavailable;
			}
			$where[] = 'deployment' === $before['source']
				? $this->prepare( 'sort_at <= %s', $before['time'] )
				: $this->prepare( '(sort_at < %s OR (sort_at = %s AND id < %d))', $before['time'], $before['time'], $before['id'] );
		}
		if ( in_array( null, $where, true ) ) {
			return $unavailable;
		}
		$records = $this->rows( "SELECT * FROM {$this->table} WHERE " . implode( ' AND ', $where ) . ' ORDER BY sort_at DESC,id DESC LIMIT ' . $limit );
		return array(
			'available' => null !== $records,
			'records'   => $records ?? array(),
		);
	}

	/**
	 * Choices remain available for retained historical records, including protected deployments.
	 * @return array{available:bool,providers:list<string>,packages:list<array{provider:string,subject_type:string,subject_identity:string}>}
	 */
	public function activity_filter_choices(): array {
		$unavailable = array(
			'available' => false,
			'providers' => array(),
			'packages'  => array(),
		);
		if ( ! $this->available() ) {
			return $unavailable;
		}
		$where = $this->history_scope_where();
		if ( in_array( null, $where, true ) ) {
			return $unavailable;
		}
		$scope     = implode( ' AND ', $where );
		$providers = $this->rows( "SELECT DISTINCT BINARY provider AS provider FROM {$this->table} WHERE $scope ORDER BY BINARY provider" );
		$packages  = $this->rows( "SELECT DISTINCT BINARY provider AS provider, BINARY subject_type AS subject_type, BINARY subject_identity AS subject_identity FROM {$this->table} WHERE $scope AND BINARY subject_type IN ('plugin','theme') AND BINARY subject_identity <> '' ORDER BY BINARY provider,BINARY subject_type,BINARY subject_identity" );
		if ( null === $providers || null === $packages ) {
			return $unavailable;
		}
		$provider_choices = array();
		foreach ( $providers as $provider ) {
			if ( is_string( $provider['provider'] ?? null ) && '' !== $provider['provider'] ) {
				$provider_choices[] = $provider['provider'];
			}
		}
		$package_choices = array();
		foreach ( $packages as $package ) {
			if ( is_string( $package['provider'] ?? null ) && is_string( $package['subject_type'] ?? null ) && is_string( $package['subject_identity'] ?? null ) && '' !== $package['provider'] && '' !== $package['subject_identity'] ) {
				$package_choices[] = array(
					'provider'         => $package['provider'],
					'subject_type'     => $package['subject_type'],
					'subject_identity' => $package['subject_identity'],
				);
			}
		}
		return array(
			'available' => true,
			'providers' => $provider_choices,
			'packages'  => $package_choices,
		);
	}

	/** @return array{available:bool,latest:?Record,observation:?Record} */
	public function summary( string $subject_type, string $subject_identity, string $adoption_token, string $context, string $purpose ): array {
		$unavailable = array(
			'available'   => false,
			'latest'      => null,
			'observation' => null,
		);
		if ( ! $this->available() || ! in_array( $purpose, self::PURPOSES, true ) ) {
			return $unavailable;
		}
		try {
			AdoptionIdentity::parse( $adoption_token );
		} catch ( InvalidArgumentException ) {
			return $unavailable;
		}
		$where = $this->prepare(
			'context_key = %s AND BINARY subject_type = %s AND subject_identity = %s AND adoption_token = %s AND context = %s AND BINARY purpose = %s',
			hash( 'sha256', $subject_type . "\0" . $subject_identity . "\0" . $adoption_token . "\0" . $context ),
			$subject_type,
			$subject_identity,
			$adoption_token,
			$context,
			$purpose
		);
		if ( null === $where ) {
			return $unavailable;
		}
		$latest   = $this->rows( "SELECT * FROM {$this->table} WHERE $where ORDER BY id DESC LIMIT 1" );
		$observed = $this->rows( "SELECT * FROM {$this->table} WHERE $where AND effect <> 'none' ORDER BY id DESC LIMIT 1" );
		if ( null === $latest || null === $observed ) {
			return $unavailable;
		}
		return array(
			'available'   => true,
			'latest'      => $latest[0] ?? null,
			'observation' => $observed[0] ?? null,
		);
	}

	/**
	 * Existing composition supplies current active contexts; absence is not an empty authoritative inventory.
	 * @param list<Capture> $current_contexts
	 */
	public function prune( array $current_contexts ): int|false {
		if ( ! $this->available() ) {
			return false;
		}
		$active = array();
		foreach ( $current_contexts as $capture ) {
			try {
				$this->validate_capture( $capture );
			} catch ( InvalidArgumentException ) {
				return false;
			}
			if ( null === $capture['adoption_token'] || null === $capture['purpose'] ) {
				continue;
			}
			$active[] = $this->prepare( '(context_key = %s AND BINARY subject_type = %s AND subject_identity = %s AND adoption_token = %s AND context = %s AND BINARY purpose = %s)', $this->context_key( $capture ), $capture['subject_type'], $capture['subject_identity'], $capture['adoption_token'], $capture['context'], $capture['purpose'] );
		}
		if ( in_array( null, $active, true ) ) {
			return false;
		}
		$active_sql = empty( $active ) ? '0 = 1' : implode( ' OR ', $active );
		// UNION materializes the same-table protection query on supported MySQL/MariaDB.
		$protection        = "SELECT retained_id FROM (SELECT MAX(id) retained_id FROM {$this->table} WHERE $active_sql GROUP BY context_key,purpose,subject_type,subject_identity,adoption_token,context UNION SELECT MAX(id) retained_id FROM {$this->table} WHERE ($active_sql) AND effect <> 'none' GROUP BY context_key,purpose,subject_type,subject_identity,adoption_token,context) AS protected_activity";
		$retention         = self::retention_policy();
		$maximum_rows      = $retention['maximum_rows'];
		$deployment_safety = $this->deployment_safety_sql();
		$rows              = $this->rows( $this->prepare( "SELECT id,status FROM {$this->table} WHERE id NOT IN ($protection) AND NOT ($deployment_safety) AND (started_at < %s OR id NOT IN (SELECT id FROM (SELECT id FROM {$this->table} ORDER BY id DESC LIMIT $maximum_rows) AS retained)) ORDER BY id LIMIT 100", gmdate( 'Y-m-d H:i:s', time() - $retention['maximum_days'] * 86400 ) ) );
		if ( null === $rows ) {
			return false;
		}
		if ( array() === $rows ) {
			return 0;
		}
		$candidates = array();
		foreach ( $rows as $row ) {
			$candidates[] = $this->prepare( '(id = %d AND status = %s)', (int) $row['id'], $row['status'] );
		}
		if ( in_array( null, $candidates, true ) ) {
			return false;
		}
		// Recheck each snapshot status and current protection in one bounded deletion.
		$selected = implode( ' OR ', $candidates );
		$result   = $this->query( "DELETE FROM {$this->table} WHERE ($selected) AND id NOT IN ($protection) AND NOT ($deployment_safety)" );
		return is_int( $result ) && $result >= 0 ? $result : false;
	}

	private function deployment_safety_sql(): string {
		$state = "JSON_UNQUOTE(JSON_EXTRACT(facts, '$.deployment_state'))";
		return "(JSON_EXTRACT(facts, '$.deployment_attempt_id') IS NOT NULL AND ($state IN ('queued','running') OR ($state = 'needs_attention' AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(facts, '$.deployment_resolved_at')), 'null') = 'null')))";
	}

	/** @return list<string|null> */
	private function history_scope_where(): array {
		$retention         = self::retention_policy();
		$maximum_rows      = $retention['maximum_rows'];
		$deployment_safety = $this->deployment_safety_sql();
		return array(
			$this->prepare( "(started_at >= %s OR $deployment_safety)", gmdate( 'Y-m-d H:i:s', time() - $retention['maximum_days'] * 86400 ) ),
			"(id IN (SELECT id FROM (SELECT id FROM {$this->table} ORDER BY id DESC LIMIT $maximum_rows) AS recent_activity) OR $deployment_safety)",
		);
	}

	/** Coupled callers hold a transaction: the first read pins engine and indexes until it ends. */
	private function transactional_storage(): bool {
		if ( null === $this->rows( "SELECT id FROM {$this->table} WHERE id = 0" ) ) {
			return false;
		}
		$rows = $this->rows( $this->prepare( 'SELECT ENGINE AS engine FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND BINARY TABLE_NAME = %s', $this->table ) );
		if ( 1 !== count( $rows ?? array() ) || ! is_string( $rows[0]['engine'] ?? null ) || 0 !== strcasecmp( 'InnoDB', $rows[0]['engine'] ) ) {
			return false;
		}
		$indexes  = $this->rows( $this->prepare( 'SELECT INDEX_NAME AS index_name, NON_UNIQUE AS non_unique, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS columns, SUM(SUB_PART IS NOT NULL) AS prefixes FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND BINARY TABLE_NAME = %s GROUP BY INDEX_NAME, NON_UNIQUE', $this->table ) );
		$required = array(
			'PRIMARY'     => array( '0', 'id' ),
			'correlation' => array( '0', 'correlation' ),
			'history'     => array( '1', 'sort_at,id' ),
			'summary'     => array( '1', 'context_key,purpose,id' ),
		);
		foreach ( $indexes ?? array() as $index ) {
			$name = $index['index_name'] ?? '';
			if ( ! isset( $required[ $name ] ) ) {
				continue;
			}
			if ( (string) ( $index['non_unique'] ?? '' ) !== $required[ $name ][0] || ( $index['columns'] ?? null ) !== $required[ $name ][1] || '0' !== (string) ( $index['prefixes'] ?? '' ) ) {
				return false;
			}
			unset( $required[ $name ] );
		}
		return array() === $required;
	}

	/** @param Capture $capture */
	private function validate_capture( array $capture ): void {
		if ( ! in_array( $capture['subject_type'], array( 'plugin', 'theme', 'repository', 'provider' ), true )
			|| '' === $capture['subject_identity'] || strlen( $capture['subject_identity'] ) > 255
			|| preg_match( '/[\x00-\x1F\x7F]/', $capture['subject_identity'] )
			|| strlen( $capture['context'] ) > 2048
			|| ! in_array( $capture['provenance'], array( 'core', 'provider' ), true )
			|| ( null !== $capture['purpose'] && ! in_array( $capture['purpose'], self::PURPOSES, true ) )
			|| ( null !== $capture['purpose'] && null === $capture['adoption_token'] )
			|| ( $capture['actor'] ?? 0 ) < 0 ) {
			throw new InvalidArgumentException( 'Invalid activity context.' );
		}
		if ( null !== $capture['adoption_token'] ) {
			AdoptionIdentity::parse( $capture['adoption_token'] );
		}
	}

	private function validate_text( string $code, string $message, string $remediation = '' ): void {
		// Reuse the established provider text boundary, including its secret/header/path exclusions.
		new ProviderDiagnosticResult( 'pass', $code, '' === $message ? 'Activity recorded.' : $message, '' === $remediation ? 'No action required.' : $remediation );
	}

	/** @param array<string,scalar|null> $facts */
	private function encode_facts( array $facts ): string {
		foreach ( $facts as $key => $value ) {
			$expiry = 'expires_at' === $key && is_string( $value ) ? \DateTimeImmutable::createFromFormat( '!Y-m-d\TH:i:s\Z', $value ) : false;
			$valid  = match ( $key ) {
				'expiry_state' => in_array( $value, array( 'not_reported', 'unknown', 'known' ), true ),
				'expires_at' => null === $value || ( is_string( $value ) && $expiry instanceof \DateTimeImmutable && $expiry->format( 'Y-m-d\TH:i:s\Z' ) === $value ),
				'webhook_state' => in_array( $value, array( 'succeeded', 'partial', 'ambiguous', 'failed' ), true ),
				'webhook_delivery' => in_array( $value, array( 'configured_pending_delivery', 'verified', 'unverified', 'unknown', 'absent' ), true ),
				'webhook_persistence' => in_array( $value, array( 'partial', 'unconfirmed', 'confirmed', 'not_confirmed' ), true ),
				'diagnostic_passed', 'diagnostic_warning', 'diagnostic_failed', 'diagnostic_not_configured' => is_int( $value ) && $value >= 0 && $value <= 8,
				'partial_reason' => in_array( $value, array( null, 'local_incomplete', 'deadline_exhausted', 'remote_calls_exhausted', 'provider_unavailable', 'provider_results_invalid', 'result_limit_exhausted' ), true ),
				'deployment_attempt_id' => is_int( $value ) && $value > 0,
				'deployment_source' => in_array( $value, array( 'manual', 'webhook' ), true ),
				'deployment_package_slug' => is_string( $value ) && '' !== $value && strlen( $value ) <= 191 && 1 === preg_match( '//u', $value ) && ! preg_match( '/[\x00-\x1F\x7F]/', $value ),
				'deployment_state' => in_array( $value, array( 'queued', 'running', 'succeeded', 'failed', 'needs_attention' ), true ),
				'deployment_requested_ref' => null === $value || ( is_string( $value ) && strlen( $value ) <= 255 && 1 === preg_match( '//u', $value ) && ! preg_match( '/[\x00-\x1F\x7F]/', $value ) && ! preg_match( '/(?:https?:\/\/|[A-Za-z][A-Za-z0-9+.-]*:\/\/)[^\s]*@|\b(?:authorization|bearer|token|secret|password|signature)\b\s*[:=]/i', $value ) ),
				'deployment_resolved_ref' => null === $value || ( is_string( $value ) && strlen( $value ) <= 191 && 1 === preg_match( '//u', $value ) && ! preg_match( '/[\x00-\x1F\x7F]/', $value ) && ! preg_match( '/(?:https?:\/\/|[A-Za-z][A-Za-z0-9+.-]*:\/\/)[^\s]*@|\b(?:authorization|bearer|token|secret|password|signature)\b\s*[:=]/i', $value ) ),
				'deployment_mutation_started_at', 'deployment_finished_at' => null === $value || ( is_string( $value ) && 1 === preg_match( '/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\z/', $value ) ),
				'deployment_resolved_at' => null === $value || ( is_string( $value ) && 1 === preg_match( '/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\z/', $value ) ),
				'deployment_resolved_by' => null === $value || ( is_int( $value ) && $value > 0 ),
				'deployment_outcome' => null === $value || ( is_string( $value ) && 1 === preg_match( '/\A[a-z][a-z0-9_]{0,63}\z/', $value ) ),
				default => null,
			};
			if ( null !== $valid ) {
				if ( ! $valid ) {
					throw new InvalidArgumentException( 'Invalid activity facts.' );
				}
				continue;
			}
			if ( ! in_array( $key, array( 'installed_version', 'candidate_version', 'offered_version', 'relationship', 'candidate_validation_code', 'assessment', 'revision', 'observation_kind', 'failure_stage', 'diagnostic_code', 'diagnostic_available', 'correlation_reference', 'webhook_local_code' ), true )
				|| ( null !== $value && ! ( 'diagnostic_available' === $key && is_bool( $value ) ) && ( ! is_string( $value ) || strlen( $value ) > 96 || preg_match( '/[^A-Za-z0-9_.+\/-]/', $value ) ) ) ) {
				throw new InvalidArgumentException( 'Invalid activity facts.' );
			}
		}
		if ( isset( $facts['expiry_state'] ) && ( ! array_key_exists( 'expires_at', $facts ) || ( 'known' === $facts['expiry_state'] ) !== is_string( $facts['expires_at'] ) ) ) {
			throw new InvalidArgumentException( 'Invalid expiry observation.' );
		}
		ksort( $facts );
		$encoded = wp_json_encode( $facts );
		if ( false === $encoded ) {
			throw new InvalidArgumentException( 'Invalid activity facts.' );
		}
		return $encoded;
	}

	/** @param Capture $capture */
	private function context_key( array $capture ): string {
		return hash( 'sha256', $capture['subject_type'] . "\0" . $capture['subject_identity'] . "\0" . ( $capture['adoption_token'] ?? '' ) . "\0" . $capture['context'] );
	}

	private function connection(): \wpdb|DeploymentWriteConnection|null {
		if ( ! $this->database instanceof \wpdb && ! $this->database instanceof DeploymentWriteConnection ) {
			return null;
		}
		return $this->database;
	}

	private function query( ?string $query ): mixed {
		return null === $query ? false : ( $this->connection()?->query( $query ) ?? false );
	}

	private function prepare( string $query, mixed ...$arguments ): ?string {
		$result = $this->connection()?->prepare( $query, ...$arguments );
		if ( ! is_string( $result ) ) {
			return null;
		}
		return $result;
	}

	/** @return list<Record>|null */
	private function rows( ?string $query ): ?array {
		if ( null === $query ) {
			return null;
		}
		if ( $this->database instanceof \wpdb ) {
			$this->database->last_error = '';
		}
		$rows = $this->connection()?->get_results( $query );
		if ( ! is_array( $rows ) || ( $this->database instanceof \wpdb && '' !== $this->database->last_error ) ) {
			return null;
		}
		$result = array();
		foreach ( $rows as $row ) {
			if ( is_object( $row ) ) {
				$row = get_object_vars( $row );
			}
			if ( ! is_array( $row ) ) {
				return null;
			}
			$record = array();
			foreach ( $row as $key => $value ) {
				if ( ! is_string( $key ) || ( ! is_string( $value ) && ! is_int( $value ) && null !== $value ) ) {
					return null;
				}
				$record[ $key ] = $value;
			}
			$result[] = $record;
		}
		return $result;
	}
}
