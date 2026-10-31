<?php

declare(strict_types=1);

namespace RAN\Troubleshooting;

use RuntimeException;
use Throwable;

/**
 * Temporary administrator/site-scoped diagnostic inputs, never generated reports.
 *
 * @phpstan-type DiagnosticRow array{status:string,code:string,message:string,remediation:string}
 * @phpstan-type DiagnosticRun array{completed_at:float,implementation:string,credential_selected:bool,repository_selected:bool,partial:bool,partial_reason:?string,results:list<DiagnosticRow>}
 * @phpstan-type AvailableDiagnosticRun array{provider:string,expires_at:float,completed_at:float,implementation:string,credential_selected:bool,repository_selected:bool,partial:bool,partial_reason:?string,results:list<DiagnosticRow>}
 * @phpstan-type StoredRun array{site:int,user:int,provider:string,nonce:string,run:DiagnosticRun}
 */
class DiagnosticRunStore {

	public const OPTION_NAME      = 'ran_booster_diagnostic_runs';
	private const MAX_BYTES       = 524288;
	private const MAX_ENTRIES     = 64;
	private const MAX_ENTRY_BYTES = 8192;
	private const TTL             = 3600;

	/**
	 * Save only the server-produced sharing projection after an authorized run.
	 *
	 * @param DiagnosticRun $safe_run
	 */
	public function save( string $provider, array $safe_run, ?string $expected_scope = null ): bool {
		try {
			$scope = $this->scope();
			$now   = $this->now();
			if ( null === $scope || ! $this->valid_provider( $provider ) || ! $this->valid_run( $safe_run )
				|| ( null !== $expected_scope && $expected_scope !== $scope['site'] . ':' . $scope['user'] )
				|| $safe_run['completed_at'] > $now || $safe_run['completed_at'] + self::TTL <= $now ) {
				return false;
			}
			$safe_run['completed_at'] = (float) $safe_run['completed_at'];
			$key                      = $scope['site'] . ':' . $scope['user'] . ':' . $provider;
			$entry                    = $scope + array(
				'provider' => $provider,
				'nonce'    => bin2hex( random_bytes( 16 ) ),
				'run'      => $safe_run,
			);
			if ( strlen( $this->encode( $entry ) ) > self::MAX_ENTRY_BYTES ) {
				return false;
			}
			for ( $attempt = 0; $attempt < 5; ++$attempt ) {
				if ( $scope !== $this->scope() ) {
					return false;
				}
				$raw     = $this->read_raw();
				$entries = $this->live_entries( $this->decode( $raw ), $this->now() );
				$current = $entries[ $key ] ?? null;
				if ( null !== $current && ( $current['run']['completed_at'] > $safe_run['completed_at']
					|| ( $current['run']['completed_at'] === $safe_run['completed_at'] && strcmp( $current['nonce'], $entry['nonce'] ) >= 0 ) ) ) {
					return true;
				}
				$entries[ $key ] = $entry;
				if ( count( $entries ) > self::MAX_ENTRIES ) {
					return false;
				}
				$replacement = $this->encode(
					array(
						'schema'  => 1,
						'entries' => $entries,
					)
				);
				if ( strlen( $replacement ) > self::MAX_BYTES ) {
					return false;
				}
				if ( $scope !== $this->scope() || $safe_run['completed_at'] + self::TTL <= $this->now() ) {
					return false;
				}
				if ( $this->compare_and_swap( $raw, $replacement ) ) {
					return true;
				}
			}
		} catch ( Throwable ) {
			return false;
		}
		return false;
	}

	/** Internal server-only initiating scope; never accepted from a request or exported. */
	public function scope_key(): ?string {
		try {
			$scope = $this->scope();
			return null === $scope ? null : $scope['site'] . ':' . $scope['user'];
		} catch ( Throwable ) {
			return null;
		}
	}

	/** @return array{available:bool,runs:list<AvailableDiagnosticRun>} */
	public function available(): array {
		try {
			$scope = $this->scope();
			if ( null === $scope ) {
				return array(
					'available' => false,
					'runs'      => array(),
				);
			}
			$runs = array();
			foreach ( $this->live_entries( $this->decode( $this->read_raw() ), $this->now() ) as $entry ) {
				if ( $entry['site'] === $scope['site'] && $entry['user'] === $scope['user'] ) {
					$runs[] = $entry['run'] + array(
						'provider'   => $entry['provider'],
						'expires_at' => $entry['run']['completed_at'] + self::TTL,
					);
				}
			}
			if ( $scope !== $this->scope() ) {
				return array(
					'available' => false,
					'runs'      => array(),
				);
			}
			return array(
				'available' => true,
				'runs'      => $runs,
			);
		} catch ( Throwable ) {
			return array(
				'available' => false,
				'runs'      => array(),
			);
		}
	}

	/** Bounded expiry housekeeping; no new schedule and no sliding expiry. */
	public function cleanup(): void {
		try {
			for ( $attempt = 0; $attempt < 5; ++$attempt ) {
				$raw     = $this->read_raw();
				$entries = $this->decode( $raw );
				$live    = $this->live_entries( $entries, $this->now() );
				if ( $entries === $live || $this->compare_and_swap(
					$raw,
					$this->encode(
						array(
							'schema'  => 1,
							'entries' => $live,
						)
					)
				) ) {
					return;
				}
			}
		// phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Optional expiry housekeeping must not interrupt existing maintenance.
		} catch ( Throwable ) {
			// Reads enforce expiry even when physical cleanup is unavailable.
		}
	}

	/** Deactivation owns this exact optional diagnostic-input option. */
	public function clear(): void {
		delete_option( self::OPTION_NAME );
	}

	/** @return array{site:int,user:int}|null */
	protected function scope(): ?array {
		if ( ! function_exists( 'current_user_can' ) || ! current_user_can( 'manage_options' )
			|| ! function_exists( 'get_current_user_id' ) || ! function_exists( 'get_current_blog_id' ) ) {
			return null;
		}
		$user = get_current_user_id();
		$site = get_current_blog_id();
		return $user > 0 && $site > 0 ? array(
			'site' => $site,
			'user' => $user,
		) : null;
	}

	protected function now(): float {
		return microtime( true );
	}

	/** Read a capped raw string before JSON decoding; never hydrate arbitrary options. */
	protected function read_raw(): ?string {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Authoritative capped read is required for optimistic concurrency and bounded decoding.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT CASE WHEN OCTET_LENGTH(option_value) <= %d THEN option_value ELSE NULL END AS payload FROM %i WHERE option_name = %s', self::MAX_BYTES, $wpdb->options, self::OPTION_NAME ), ARRAY_A );
		if ( '' !== (string) $wpdb->last_error || ( null !== $row && ! is_string( $row['payload'] ?? null ) ) ) {
			throw new RuntimeException( 'Diagnostic retention is unavailable.' );
		}
		return null === $row ? null : $row['payload'];
	}

	protected function compare_and_swap( ?string $expected, string $replacement ): bool {
		global $wpdb;
		$written = null === $expected
			? $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO %i (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $wpdb->options, self::OPTION_NAME, $replacement ) ) // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic creation of the one nonautoload diagnostic-input option.
			: $wpdb->query( $wpdb->prepare( 'UPDATE %i SET option_value = %s WHERE option_name = %s AND BINARY option_value = BINARY %s', $wpdb->options, $replacement, self::OPTION_NAME, $expected ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact raw-value comparison preserves concurrent completions.
		if ( 1 !== $written || '' !== (string) $wpdb->last_error ) {
			return false;
		}
		wp_cache_delete( self::OPTION_NAME, 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		return true;
	}

	/** @return array<string,StoredRun> */
	private function decode( ?string $raw ): array {
		if ( null === $raw ) {
			return array();
		}
		if ( strlen( $raw ) > self::MAX_BYTES ) {
			throw new RuntimeException( 'Diagnostic retention exceeds its bound.' );
		}
		$document = json_decode( $raw, true, 8, JSON_THROW_ON_ERROR );
		if ( ! is_array( $document ) || array_keys( $document ) !== array( 'schema', 'entries' ) || 1 !== $document['schema']
			|| ! is_array( $document['entries'] ) || count( $document['entries'] ) > self::MAX_ENTRIES ) {
			throw new RuntimeException( 'Diagnostic retention has an invalid schema.' );
		}
		foreach ( $document['entries'] as $key => $entry ) {
			if ( ! is_array( $entry ) || array_keys( $entry ) !== array( 'site', 'user', 'provider', 'nonce', 'run' )
				|| ! is_int( $entry['site'] ) || $entry['site'] < 1 || ! is_int( $entry['user'] ) || $entry['user'] < 1
				|| ! is_string( $entry['provider'] ) || ! $this->valid_provider( $entry['provider'] )
				|| $key !== $entry['site'] . ':' . $entry['user'] . ':' . $entry['provider']
				|| ! is_string( $entry['nonce'] ) || 1 !== preg_match( '/\A[0-9a-f]{32}\z/D', $entry['nonce'] )
				|| ! $this->valid_run( $entry['run'] ) || strlen( $this->encode( $entry ) ) > self::MAX_ENTRY_BYTES ) {
				throw new RuntimeException( 'Diagnostic retention has an invalid entry.' );
			}
		}
		return $document['entries'];
	}

	/** @param array<string,StoredRun> $entries
	 * @return array<string,StoredRun>
	 */
	private function live_entries( array $entries, float $now ): array {
		return array_filter( $entries, static fn ( array $entry ): bool => $entry['run']['completed_at'] <= $now && $entry['run']['completed_at'] + self::TTL > $now );
	}

	private function valid_provider( string $provider ): bool {
		return 1 === preg_match( '/\A[a-z][a-z0-9-]{0,31}\z/D', $provider );
	}

	/** @phpstan-assert-if-true DiagnosticRun $run */
	private function valid_run( mixed $run ): bool {
		if ( ! is_array( $run ) || array_keys( $run ) !== array( 'completed_at', 'implementation', 'credential_selected', 'repository_selected', 'partial', 'partial_reason', 'results' )
			|| ( ! is_float( $run['completed_at'] ) && ! is_int( $run['completed_at'] ) ) || ! is_finite( (float) $run['completed_at'] )
			|| $run['completed_at'] < 0 || $run['completed_at'] > 253402300799
			|| ! is_string( $run['implementation'] ) || 1 !== preg_match( '/\A[0-9a-f]{64}\z/D', $run['implementation'] )
			|| ! is_bool( $run['credential_selected'] ) || ! is_bool( $run['repository_selected'] ) || ! is_bool( $run['partial'] )
			|| ! in_array( $run['partial_reason'], array( null, 'local_incomplete', 'deadline_exhausted', 'remote_calls_exhausted', 'provider_unavailable', 'provider_results_invalid', 'result_limit_exhausted' ), true )
			|| ( null !== $run['partial_reason'] ) !== $run['partial']
			|| ! is_array( $run['results'] ) || ! array_is_list( $run['results'] ) || count( $run['results'] ) > 8 ) {
			return false;
		}
		foreach ( $run['results'] as $row ) {
			if ( ! is_array( $row ) || array_keys( $row ) !== array( 'status', 'code', 'message', 'remediation' )
				|| ! in_array( $row['status'], array( 'pass', 'warning', 'fail', 'not_configured' ), true )
				|| ! is_string( $row['code'] ) || strlen( $row['code'] ) > 96 || 1 !== preg_match( '/\A[a-z][a-z0-9]*(?:[._-][a-z0-9]+)*\z/D', $row['code'] ) ) {
				return false;
			}
			foreach ( array( 'message', 'remediation' ) as $field ) {
				if ( ! is_string( $row[ $field ] ) || '' === trim( $row[ $field ] ) || strlen( $row[ $field ] ) > 512
					|| 1 !== preg_match( '//u', $row[ $field ] ) || 1 === preg_match( '/[\x00-\x1f\x7f]/', $row[ $field ] ) ) {
					return false;
				}
			}
		}
		return true;
	}

	/** @param array<array-key,mixed> $value */
	private function encode( array $value ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Strict JSON failure and preserved fractional completion timestamps are the persistence contract.
		return json_encode( $value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION );
	}
}
