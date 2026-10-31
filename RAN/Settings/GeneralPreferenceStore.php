<?php

declare(strict_types=1);

namespace RAN\Settings;

/** Fixed non-secret site preferences shared by Settings and Core policies. */
final class GeneralPreferenceStore {

	public const OPTION_NAME = 'ran_booster_general_settings';

	private const DEFAULTS = array(
		'hide_github'           => false,
		'self_update_discovery' => true,
		'history_rows'          => 2000,
		'history_days'          => 30,
		'max_archive_bytes'     => 52428800,
	);

	/** @return array{exists:bool,token:string,malformed:bool} */
	public function snapshot(): array {
		return $this->snapshot_for_state( $this->read_option( true ) );
	}

	/** @return array<string,array{value:bool|int,source:'default'|'saved'|'code',locked:bool,error:?string}> */
	public function projection(): array {
		return $this->projection_for_state( $this->read_option() );
	}

	/** @return array{fields:array<string,array{value:bool|int,source:'default'|'saved'|'code',locked:bool,error:?string}>,token:string,malformed:bool} */
	public function view_snapshot(): array {
		$state    = $this->read_option( true );
		$snapshot = $this->snapshot_for_state( $state );

		return array(
			'fields'    => $this->projection_for_state( $state ),
			'token'     => $snapshot['token'],
			'malformed' => $snapshot['malformed'],
		);
	}

	/**
	 * @param array{exists:bool,value:mixed,token:string} $state
	 * @return array<string,array{value:bool|int,source:'default'|'saved'|'code',locked:bool,error:?string}>
	 */
	private function projection_for_state( array $state ): array {
		$projection = $this->project( is_array( $state['value'] ) ? $state['value'] : array() );
		if ( $state['exists'] && ! is_array( $state['value'] ) ) {
			foreach ( $projection as &$field ) {
				$field['error'] = 'invalid_saved';
			}
			unset( $field );
		}
		return $projection;
	}

	/**
	 * @param array{exists:bool,value:mixed,token:string} $state
	 * @return array{exists:bool,token:string,malformed:bool}
	 */
	private function snapshot_for_state( array $state ): array {
		return array(
			'exists'    => $state['exists'],
			'token'     => $state['token'],
			'malformed' => $state['exists'] && ! is_array( $state['value'] ),
		);
	}

	public function history_rows(): ?int {
		return $this->saved_integer( 'history_rows', 200, 100000 );
	}

	public function history_days(): ?int {
		return $this->saved_integer( 'history_days', 1, 365 );
	}

	public function max_archive_bytes(): ?int {
		return $this->saved_integer( 'max_archive_bytes', 1048576, 536870911 );
	}

	public function self_update_discovery( bool $fresh = false ): ?bool {
		$value = $this->saved_value( 'self_update_discovery', $fresh );
		return is_bool( $value ) ? $value : null;
	}

	/**
	 * The management lock wrapper runs before the option lock. Its callback must run
	 * under the existing GitHub package range lock. The eligibility callback is a
	 * read-only current-management decision; false rejects hide=true as stale.
	 * @param array<string,mixed> $submitted
	 * @param callable(callable():string):string $with_management_admission_lock
	 * @param callable():bool $github_hide_eligible
	 * @return 'saved'|'unchanged'|'stale'|'invalid'|'failed'
	 */
	public function save( string $expected_token, array $submitted, callable $with_management_admission_lock, callable $github_hide_eligible ): string {
		global $wpdb;
		if ( ! $this->begin_transaction( $wpdb ) ) {
			return 'failed';
		}
		try {
			return $this->outcome( $with_management_admission_lock( fn (): string => $this->save_locked( $wpdb, $expected_token, $submitted, $github_hide_eligible ) ) );
		} catch ( \Throwable ) {
			return $this->finish( $wpdb, false, 'failed' );
		}
	}

	/**
	 * @param array<string,mixed> $submitted
	 * @param callable():bool $github_hide_eligible
	 * @return 'saved'|'unchanged'|'stale'|'invalid'|'failed'
	 */
	private function save_locked( mixed $wpdb, string $expected_token, array $submitted, callable $github_hide_eligible ): string {
		$state = $this->read_locked_option( $wpdb );
		if ( null === $state || ! hash_equals( $state['token'], $expected_token ) ) {
			return $this->finish( $wpdb, false, 'stale' );
		}
		if ( $state['exists'] && ! is_array( $state['value'] ) ) {
			return $this->finish( $wpdb, false, 'failed' );
		}
		$projection     = $this->project( is_array( $state['value'] ) ? $state['value'] : array() );
		$hide_eligible  = $github_hide_eligible();
		$editable       = array_keys( array_filter( $projection, static fn ( array $field ): bool => ! $field['locked'] ) );
		$hide_submitted = array_key_exists( 'hide_github', $submitted );
		if ( ! $hide_eligible ) {
			$editable = array_values( array_filter( $editable, static fn ( string $key ): bool => 'hide_github' !== $key ) );
			if ( $hide_submitted ) {
				return $this->finish( $wpdb, false, 'stale' );
			}
		}
		$values = $this->parse_submission( $submitted, $editable );
		if ( null === $values ) {
			return $this->finish( $wpdb, false, 'invalid' );
		}
		$replacement = is_array( $state['value'] ) ? $state['value'] : array();
		foreach ( $values as $key => $value ) {
			if ( self::DEFAULTS[ $key ] === $value ) {
				unset( $replacement[ $key ] );
			} else {
				$replacement[ $key ] = $value;
			}
		}
		if ( $replacement === $state['value'] || ( ! $state['exists'] && array() === $replacement ) ) {
			return $this->finish( $wpdb, true, 'unchanged' );
		}
		if ( ! $this->write_locked_option( $wpdb, $state, $replacement ) ) {
			return $this->finish( $wpdb, false, 'failed' );
		}

		return $this->finish( $wpdb, true, 'saved' );
	}

	/**
	 * Called inside the confirmed GitHub-management transaction, before its COMMIT.
	 * It locks an absent option gap too, so a concurrent Save cannot insert hide=true.
	 * The caller must invalidate the WordPress option cache only after its outer
	 * transaction commits.
	 */
	public function clear_confirmed_github_hide_request(): bool {
		global $wpdb;
		$state = $this->read_locked_option( $wpdb );
		if ( null === $state || ! $state['exists'] ) {
			return null !== $state;
		}
		if ( ! is_array( $state['value'] ) ) {
			return false;
		}
		if ( true !== ( $state['value']['hide_github'] ?? null ) ) {
			return true;
		}
		$replacement                = $state['value'];
		$replacement['hide_github'] = false;

		return $this->write_locked_option( $wpdb, $state, $replacement );
	}

	/**
	 * @param array<string,mixed> $submitted
	 * @param list<string> $editable
	 * @return array<string,bool|int>|null
	 */
	public function parse_submission( array $submitted, array $editable ): ?array {
		$submitted = $this->normalize_archive_mib_submission( $submitted, $editable );
		if ( null === $submitted ) {
			return null;
		}
		$keys = array_keys( $submitted );
		sort( $keys );
		$expected = $editable;
		sort( $expected );
		if ( $keys !== $expected ) {
			return null;
		}
		$parsed = array();
		foreach ( $editable as $key ) {
			$value = $submitted[ $key ];
			if ( in_array( $key, array( 'hide_github', 'self_update_discovery' ), true ) ) {
				if ( ! is_string( $value ) || ! in_array( $value, array( '0', '1' ), true ) ) {
					return null;
				}
				$parsed[ $key ] = '1' === $value;
				continue;
			}
			if ( ! is_string( $value ) || 1 !== preg_match( '/\A(?:0|[1-9][0-9]*)\z/D', $value ) ) {
				return null;
			}
			$integer = filter_var( $value, FILTER_VALIDATE_INT );
			if ( false === $integer || ! is_int( $integer ) || ! $this->valid_integer( $key, $integer ) ) {
				return null;
			}
			$parsed[ $key ] = $integer;
		}

		return $parsed;
	}

	/**
	 * The Settings form accepts a decimal MiB value while the durable preference
	 * remains the established integer byte limit.
	 *
	 * @param array<string,mixed> $submitted
	 * @param list<string> $editable
	 * @return array<string,mixed>|null
	 */
	private function normalize_archive_mib_submission( array $submitted, array $editable ): ?array {
		if ( ! in_array( 'max_archive_bytes', $editable, true ) || ! array_key_exists( 'max_archive_mib', $submitted ) ) {
			return $submitted;
		}
		if ( array_key_exists( 'max_archive_bytes', $submitted ) || ! is_string( $submitted['max_archive_mib'] ) ) {
			return null;
		}
		$bytes = $this->mib_to_bytes( $submitted['max_archive_mib'] );
		if ( null === $bytes ) {
			return null;
		}
		unset( $submitted['max_archive_mib'] );
		$submitted['max_archive_bytes'] = (string) $bytes;
		return $submitted;
	}

	private function mib_to_bytes( string $value ): ?int {
		if ( 1 !== preg_match( '/\A(?:0|[1-9][0-9]*)(?:\.[0-9]{1,20})?\z/D', $value ) ) {
			return null;
		}
		$mib = (float) $value;
		if ( ! is_finite( $mib ) || $mib < 1 || $mib > ( 536870911 / 1048576 ) ) {
			return null;
		}
		$bytes = (int) round( $mib * 1048576 );
		return $this->valid_integer( 'max_archive_bytes', $bytes ) ? $bytes : null;
	}

	private function saved_integer( string $key, int $minimum, int $maximum ): ?int {
		$value = $this->saved_value( $key );
		return is_int( $value ) && $value >= $minimum && $value <= $maximum ? $value : null;
	}

	private function saved_value( string $key, bool $fresh = false ): mixed {
		$state = $this->read_option( $fresh );
		return is_array( $state['value'] ) && array_key_exists( $key, $state['value'] ) ? $state['value'][ $key ] : null;
	}

	/**
	 * @param array<string,mixed> $document
	 * @return array<string,array{value:bool|int,source:'default'|'saved'|'code',locked:bool,error:?string}>
	 */
	private function project( array $document ): array {
		return array(
			'hide_github'           => $this->github_hide_projection( $document ),
			'self_update_discovery' => $this->self_update_projection( $document ),
			'history_rows'          => $this->integer_projection( $document, 'history_rows', 'RAN_BOOSTER_HISTORY_ROWS', 200, 100000 ),
			'history_days'          => $this->integer_projection( $document, 'history_days', 'RAN_BOOSTER_HISTORY_DAYS', 1, 365 ),
			'max_archive_bytes'     => $this->integer_projection( $document, 'max_archive_bytes', 'RAN_BOOSTER_MAX_ARCHIVE_BYTES', 1048576, 536870911 ),
		);
	}

	/**
	 * @param array<string,mixed> $document
	 * @return array{value:bool,source:'default'|'saved'|'code',locked:bool,error:?string}
	 */
	private function github_hide_projection( array $document ): array {
		if ( defined( 'RAN_BOOSTER_HIDE_GITHUB_UI' ) ) {
			$value = constant( 'RAN_BOOSTER_HIDE_GITHUB_UI' );
			return array(
				'value'  => is_bool( $value ) ? $value : false,
				'source' => 'code',
				'locked' => true,
				'error'  => is_bool( $value ) ? null : 'invalid_code',
			);
		}

		return $this->boolean_projection( $document, 'hide_github' );
	}

	/**
	 * @param array<string,mixed> $document
	 * @return array{value:bool,source:'default'|'saved',locked:false,error:?string}
	 */
	private function boolean_projection( array $document, string $key ): array {
		if ( is_bool( $document[ $key ] ?? null ) ) {
			return array(
				'value'  => $document[ $key ],
				'source' => 'saved',
				'locked' => false,
				'error'  => null,
			);
		}
		return array(
			'value'  => $this->boolean_default( $key ),
			'source' => 'default',
			'locked' => false,
			'error'  => array_key_exists( $key, $document ) ? 'invalid_saved' : null,
		);
	}

	/**
	 * @param array<string,mixed> $document
	 * @return array{value:bool,source:'default'|'saved'|'code',locked:bool,error:?string}
	 */
	private function self_update_projection( array $document ): array {
		if ( defined( 'RAN_BOOSTER_SELF_UPDATE_MODE' ) ) {
			$value = constant( 'RAN_BOOSTER_SELF_UPDATE_MODE' );
			$mode  = is_string( $value ) ? strtolower( trim( $value ) ) : 'invalid';
			return array(
				'value'  => in_array( $mode, array( 'auto', 'enabled' ), true ),
				'source' => 'code',
				'locked' => true,
				'error'  => in_array( $mode, array( 'auto', 'enabled', 'disabled' ), true ) ? null : 'invalid_code',
			);
		}
		return $this->boolean_projection( $document, 'self_update_discovery' );
	}

	/**
	 * @param array<string,mixed> $document
	 * @return array{value:int,source:'default'|'saved'|'code',locked:bool,error:?string}
	 */
	private function integer_projection( array $document, string $key, string $constant, int $minimum, int $maximum ): array {
		if ( defined( $constant ) ) {
			$value = constant( $constant );
			$valid = is_int( $value ) && $value >= $minimum && $value <= $maximum;
			return array(
				'value'  => $valid ? $value : $this->integer_default( $key ),
				'source' => 'code',
				'locked' => true,
				'error'  => $valid ? null : 'invalid_code',
			);
		}
		$value = $document[ $key ] ?? null;
		if ( is_int( $value ) && $value >= $minimum && $value <= $maximum ) {
			return array(
				'value'  => $value,
				'source' => 'saved',
				'locked' => false,
				'error'  => null,
			);
		}
		return array(
			'value'  => $this->integer_default( $key ),
			'source' => 'default',
			'locked' => false,
			'error'  => array_key_exists( $key, $document ) ? 'invalid_saved' : null,
		);
	}

	private function valid_integer( string $key, int $value ): bool {
		return match ( $key ) {
			'history_rows' => $value >= 200 && $value <= 100000,
			'history_days' => $value >= 1 && $value <= 365,
			'max_archive_bytes' => $value >= 1048576 && $value <= 536870911,
			default => false,
		};
	}

	private function integer_default( string $key ): int {
		return match ( $key ) {
			'history_rows' => 2000,
			'history_days' => 30,
			'max_archive_bytes' => 52428800,
			default => throw new \LogicException( 'Unknown integer preference.' ),
		};
	}

	private function boolean_default( string $key ): bool {
		return match ( $key ) {
			'hide_github' => false,
			'self_update_discovery' => true,
			default => throw new \LogicException( 'Unknown boolean preference.' ),
		};
	}

	/** @return array{exists:bool,value:mixed,token:string} */
	private function read_option( bool $fresh = false ): array {
		if ( $fresh ) {
			global $wpdb;
			$state = $this->read_locked_option( $wpdb, false );
			if ( null === $state ) {
				throw new \RuntimeException( 'General preferences could not be read fresh.' );
			}

			return $state;
		}
		$missing = new \stdClass();
		$value   = $this->has_function( 'get_option' ) ? get_option( self::OPTION_NAME, $missing ) : $missing;
		$exists  = $value !== $missing;
		return array(
			'exists' => $exists,
			'value'  => $exists ? $value : null,
			'token'  => hash( 'sha256', $exists ? $this->serialized( $value ) : "\0missing" ),
		);
	}

	/** @return array{exists:bool,value:mixed,token:string}|null */
	private function read_locked_option( mixed $wpdb, bool $lock = true ): ?array {
		if ( ! is_object( $wpdb ) ) {
			return null;
		}
		$table = $this->option_table( $wpdb );
		if ( null === $table || ! method_exists( $wpdb, 'prepare' ) || ! method_exists( $wpdb, 'get_var' ) ) {
			return null;
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The closed optional lock suffix is selected only from this private boolean.
		$query = $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s' . ( $lock ? ' FOR UPDATE' : '' ), $table, self::OPTION_NAME );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery -- Exact prepared option read locks the caller-owned mutation transaction or bypasses alloptions for a fresh admission read.
		$raw = $wpdb->get_var( $query );
		if ( '' !== trim( (string) ( $wpdb->last_error ?? '' ) ) ) {
			return null;
		}
		if ( null === $raw ) {
			return array(
				'exists' => false,
				'value'  => null,
				'token'  => hash( 'sha256', "\0missing" ),
			);
		}
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- Fallback only when the WordPress option decoder is unavailable.
		$value = $this->has_function( 'maybe_unserialize' ) ? maybe_unserialize( $raw ) : unserialize( $raw );
		return array(
			'exists' => true,
			'value'  => $value,
			'token'  => hash( 'sha256', (string) $raw ),
		);
	}

	/**
	 * @param array{exists:bool,value:mixed,token:string} $expected
	 * @param array<string,mixed> $replacement
	 */
	private function write_locked_option( mixed $wpdb, array $expected, array $replacement ): bool {
		if ( ! is_object( $wpdb ) ) {
			return false;
		}
		$table = $this->option_table( $wpdb );
		if ( null === $table || ! method_exists( $wpdb, 'prepare' ) || ! method_exists( $wpdb, 'query' ) ) {
			return false;
		}
		if ( ! $expected['exists'] ) {
			$query = $wpdb->prepare( "INSERT INTO %i (option_name, option_value, autoload) VALUES (%s, %s, 'off')", $table, self::OPTION_NAME, $this->serialized( $replacement ) );
		} else {
			$query = $wpdb->prepare( 'UPDATE %i SET option_value = %s, autoload = \'off\' WHERE option_name = %s AND BINARY option_value = BINARY %s', $table, $this->serialized( $replacement ), self::OPTION_NAME, $this->serialized( $expected['value'] ) );
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery -- Exact prepared compare-and-set under the held option row lock.
		$result = $wpdb->query( $query );
		return 1 === $result && '' === trim( (string) ( $wpdb->last_error ?? '' ) );
	}

	private function begin_transaction( mixed $wpdb ): bool {
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery -- Closed transaction statements establish the required option-gap serialization boundary.
		return is_object( $wpdb ) && method_exists( $wpdb, 'query' ) && false !== $wpdb->query( 'SET TRANSACTION ISOLATION LEVEL SERIALIZABLE' ) && false !== $wpdb->query( 'START TRANSACTION' );
	}

	/** @return 'saved'|'unchanged'|'stale'|'invalid'|'failed' */
	private function finish( mixed $wpdb, bool $commit, string $result ): string {
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery -- Closed transaction completion selected from a boolean, never request input.
		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'query' ) || false === $wpdb->query( $commit ? 'COMMIT' : 'ROLLBACK' ) ) {
			$result = 'failed';
		}
		$this->clear_cache();
		return $this->outcome( $result );
	}

	/** @return 'saved'|'unchanged'|'stale'|'invalid'|'failed' */
	private function outcome( string $result ): string {
		return match ( $result ) {
			'saved', 'unchanged', 'stale', 'invalid', 'failed' => $result,
			default => 'failed',
		};
	}

	private function option_table( mixed $wpdb ): ?string {
		if ( ! is_object( $wpdb ) ) {
			return null;
		}
		$table = get_object_vars( $wpdb )['options'] ?? null;
		return is_string( $table ) && '' !== $table ? $table : null;
	}

	private function serialized( mixed $value ): string {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Fallback only when the WordPress option encoder is unavailable.
		return $this->has_function( 'maybe_serialize' ) ? maybe_serialize( $value ) : serialize( $value );
	}

	private function has_function( string $name ): bool {
		return function_exists( __NAMESPACE__ . '\\' . $name ) || function_exists( $name );
	}

	private function clear_cache(): void {
		if ( $this->has_function( 'wp_cache_delete' ) ) {
			wp_cache_delete( self::OPTION_NAME, 'options' );
			wp_cache_delete( 'alloptions', 'options' );
			wp_cache_delete( 'notoptions', 'options' );
		}
	}
}
