<?php

declare(strict_types=1);

namespace RAN\Activity;

use InvalidArgumentException;
use RAN\AdoptionIdentity;
use RAN\RepositoryProvider\ProviderCode;
use RAN\RepositoryProvider\ProviderDiagnosticResult;
use RAN\Storage\DeploymentWriteConnection;

/**
 * Optional history, deliberately outside package and deployment readiness.
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
		if ( null === $columns ) {
			return false;
		}
		update_option( self::VERSION_OPTION, '1', false );
		return $this->available();
	}

	public function available(): bool {
		return '1' === get_option( self::VERSION_OPTION );
	}

	/** @param Capture $capture */
	public function begin( string $provider, ActivityRequest $request, array $capture ): ?int {
		if ( ! $this->available() ) {
			return null;
		}
		try {
			ProviderCode::parse( $provider );
			$this->validate_capture( $capture );
			$correlation = bin2hex( random_bytes( 16 ) );
		} catch ( InvalidArgumentException | \Random\RandomException ) {
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
		} catch ( InvalidArgumentException ) {
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

	/** @param array<string, scalar|null> $facts Core-normalized observation fields, never arbitrary provider payloads. */
	public function complete( ?int $id, string $status, string $reason, string $message = '', string $remediation = '', array $facts = array(), string $effect = 'none' ): bool {
		try {
			$this->validate_text( $reason, $message, $remediation );
			$encoded = $this->encode_facts( $facts );
		} catch ( InvalidArgumentException ) {
			return false;
		}
		if ( ! in_array( $status, array( 'succeeded', 'failed', 'rejected', 'skipped' ), true )
			|| ! in_array( $effect, array( 'none', 'established', 'invalidated' ), true ) ) {
			return false;
		}
		if ( null === $id ) {
			return false;
		}
		$result = $this->query(
			$this->prepare(
				"UPDATE {$this->table} SET status = %s, reason = %s, message = %s, remediation = %s, facts = %s, effect = %s, completed_at = %s WHERE id = %d AND status = 'incomplete'",
				$status,
				$reason,
				$message,
				$remediation,
				$encoded,
				$effect,
				gmdate( 'Y-m-d H:i:s' ),
				$id
			)
		);
		if ( 1 === $result ) {
			return true;
		}
		if ( 0 !== $result ) {
			return false;
		}
		$rows     = $this->rows( $this->prepare( "SELECT status,reason,message,remediation,facts,effect FROM {$this->table} WHERE id = %d", $id ) );
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
	 * Bounded contributor to the existing presenter's merged deployment/activity history.
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
		$where = array( $this->prepare( 'started_at >= %s', gmdate( 'Y-m-d H:i:s', time() - 30 * 86400 ) ) );
		foreach ( $filters as $key => $value ) {
			$key = 'outcome' === $key ? 'status' : $key;
			if ( ! in_array( $key, array( 'subject_type', 'subject_identity', 'provider', 'operation', 'status', 'adoption_token', 'context', 'purpose' ), true ) || strlen( $value ) > 2048 ) {
				return $unavailable;
			}
			$where[] = $this->prepare( "BINARY $key = %s", $value );
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
		$where[] = "id IN (SELECT id FROM (SELECT id FROM {$this->table} ORDER BY id DESC LIMIT 2000) AS recent_activity)";
		$records = $this->rows( "SELECT * FROM {$this->table} WHERE " . implode( ' AND ', $where ) . ' ORDER BY sort_at DESC,id DESC LIMIT ' . $limit );
		return array(
			'available' => null !== $records,
			'records'   => $records ?? array(),
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
		$protection = "SELECT retained_id FROM (SELECT MAX(id) retained_id FROM {$this->table} WHERE $active_sql GROUP BY context_key,purpose,subject_type,subject_identity,adoption_token,context UNION SELECT MAX(id) retained_id FROM {$this->table} WHERE ($active_sql) AND effect <> 'none' GROUP BY context_key,purpose,subject_type,subject_identity,adoption_token,context) AS protected_activity";
		$rows       = $this->rows( $this->prepare( "SELECT id,status FROM {$this->table} WHERE id NOT IN ($protection) AND (started_at < %s OR id NOT IN (SELECT id FROM (SELECT id FROM {$this->table} ORDER BY id DESC LIMIT 2000) AS retained)) ORDER BY id LIMIT 100", gmdate( 'Y-m-d H:i:s', time() - 30 * 86400 ) ) );
		if ( null === $rows ) {
			return false;
		}
		$deleted = 0;
		foreach ( $rows as $row ) {
			// Do not delete an incomplete row that acquired an observation after the snapshot.
			$result = $this->query( $this->prepare( "DELETE FROM {$this->table} WHERE id = %d AND status = %s AND id NOT IN ($protection)", (int) $row['id'], $row['status'] ) );
			if ( ! is_int( $result ) || $result < 0 ) {
				return false;
			}
			$deleted += $result;
		}
		return $deleted;
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
			if ( ! in_array( $key, array( 'installed_version', 'candidate_version', 'offered_version', 'relationship', 'candidate_validation_code', 'assessment', 'revision', 'observation_kind', 'failure_stage', 'diagnostic_code', 'diagnostic_available', 'correlation_reference' ), true )
				|| ( null !== $value && ! ( 'diagnostic_available' === $key && is_bool( $value ) ) && ( ! is_string( $value ) || strlen( $value ) > 96 || preg_match( '/[^A-Za-z0-9_.+\/-]/', $value ) ) ) ) {
				throw new InvalidArgumentException( 'Invalid activity facts.' );
			}
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
