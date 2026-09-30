<?php

declare(strict_types=1);

namespace RAN\Secrets;

// Base64 is the explicit canonical storage encoding for the binary site key.
// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode, WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode

use RuntimeException;

/**
 * Owns the one database-held key used by the encrypted secrets sidecar.
 *
 * The option value is canonical base64. Callers receive only the decoded
 * 32-byte key and may remove it only through the exact-value cleanup seam.
 */
class SiteKeyStore {

	public const OPTION_NAME = 'ran_booster_secrets_key_v1';
	private const KEY_BYTES  = 32;
	private object $missing_value;

	public function __construct() {
		$this->missing_value = new \stdClass();
	}

	// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public named parameters and promoted properties retain the existing caller contract.
	public function load( bool $repairAutoload = true ): ?string {
		$value = $this->read_stored_value();
		if ( $this->missing_value === $value ) {
			return null;
		}

		$key = $this->decode_stored_key( $value );
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public named parameters and promoted properties retain the existing caller contract.
		$this->verify_non_autoloaded( $repairAutoload );

		return $key;
	}

	/**
	 * @return array{key: string, created: bool}
	 */
	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public and protected methods retain the existing caller and override contracts.
	public function loadOrCreate(): array {
		$existing = $this->load();
		if ( null !== $existing ) {
			return array(
				'key'     => $existing,
				'created' => false,
			);
		}

		$key = $this->generate_key();
		$this->require_raw_key( $key );
		$encoded = base64_encode( $key );
		$created = $this->add_stored_value( $encoded );
		if ( ! $created ) {
			$this->invalidate_option_cache();
		}
		$stored = $this->load();

		if ( null === $stored ) {
			throw new RuntimeException( 'The Booster site key could not be created.' );
		}
		if ( $created && ! hash_equals( $key, $stored ) ) {
			throw new RuntimeException( 'The Booster site key could not be verified.' );
		}

		return array(
			'key'     => $stored,
			'created' => $created,
		);
	}

	/**
	 * Remove only the exact key supplied by a failed first-write operation.
	 */
	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public and protected methods retain the existing caller and override contracts.
	public function deleteExact( #[\SensitiveParameter] string $key ): bool {
		$this->require_raw_key( $key );
		$result = $this->delete_stored_value_exact( base64_encode( $key ) );

		if ( false === $result || $result < 0 || $result > 1 ) {
			throw new RuntimeException( 'The Booster site key could not be removed safely.' );
		}
		if ( 1 !== $result ) {
			return false;
		}

		$this->invalidate_option_cache();

		return true;
	}

	protected function read_stored_value(): mixed {
		if ( ! function_exists( 'get_option' ) ) {
			return $this->missing_value;
		}

		return get_option( self::OPTION_NAME, $this->missing_value );
	}

	protected function missing_stored_value(): object {
		return $this->missing_value;
	}

	protected function add_stored_value( #[\SensitiveParameter] string $encoded ): bool {
		if ( ! function_exists( 'add_option' ) ) {
			return false;
		}

		return add_option( self::OPTION_NAME, $encoded, '', false );
	}

	protected function read_autoload_value(): ?string {
		global $wpdb;

		if ( ! is_object( $wpdb )
			|| ! isset( $wpdb->options )
			|| ! is_string( $wpdb->options )
			|| ! method_exists( $wpdb, 'prepare' )
			|| ! method_exists( $wpdb, 'get_var' )
		) {
			return null;
		}

		$query = $wpdb->prepare(
			'SELECT autoload FROM %i WHERE option_name = %s',
			$wpdb->options,
			self::OPTION_NAME
		);
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery -- WordPress has no public option-autoload metadata reader.
		$value = $wpdb->get_var( $query );

		return is_string( $value ) ? $value : null;
	}

	protected function repair_autoload_value(): void {
		if ( function_exists( 'wp_set_option_autoload_values' ) ) {
			wp_set_option_autoload_values( array( self::OPTION_NAME => false ) );
		}
	}

	protected function delete_stored_value_exact( #[\SensitiveParameter] string $encoded ): int|false {
		global $wpdb;

		if ( ! is_object( $wpdb )
			|| ! isset( $wpdb->options )
			|| ! is_string( $wpdb->options )
			|| ! method_exists( $wpdb, 'prepare' )
			|| ! method_exists( $wpdb, 'query' )
		) {
			return false;
		}

		$query = $wpdb->prepare(
			'DELETE FROM %i WHERE option_name = %s AND option_value = %s',
			$wpdb->options,
			self::OPTION_NAME,
			$encoded
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact-value deletion is required for failed first-write cleanup.
		return $wpdb->query( $query );
	}

	protected function invalidate_option_cache(): void {
		if ( ! function_exists( 'wp_cache_delete' ) ) {
			return;
		}

		wp_cache_delete( self::OPTION_NAME, 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
	}

	protected function generate_key(): string {
		return random_bytes( self::KEY_BYTES );
	}

	private function decode_stored_key( #[\SensitiveParameter] mixed $value ): string {
		if ( ! is_string( $value ) ) {
			throw new RuntimeException( 'The Booster site key is invalid.' );
		}

		$key = base64_decode( $value, true );
		if ( false === $key
			|| self::KEY_BYTES !== strlen( $key )
			|| ! hash_equals( base64_encode( $key ), $value )
		) {
			throw new RuntimeException( 'The Booster site key is invalid.' );
		}

		return $key;
	}

	private function require_raw_key( #[\SensitiveParameter] string $key ): void {
		if ( self::KEY_BYTES !== strlen( $key ) ) {
			throw new RuntimeException( 'The Booster site key is invalid.' );
		}
	}

	private function verify_non_autoloaded( bool $repair ): void {
		$autoload = $this->read_autoload_value();
		if ( null === $autoload ) {
			throw new RuntimeException( 'The Booster site key autoload setting could not be verified.' );
		}
		if ( ! $this->is_autoloaded( $autoload ) ) {
			return;
		}
		if ( ! $repair ) {
			throw new RuntimeException( 'The Booster site key autoload setting is insecure.' );
		}

		$this->repair_autoload_value();
		$autoload = $this->read_autoload_value();
		if ( null === $autoload || $this->is_autoloaded( $autoload ) ) {
			throw new RuntimeException( 'The Booster site key autoload setting could not be secured.' );
		}
	}

	private function is_autoloaded( string $value ): bool {
		$autoloaded = function_exists( 'wp_autoload_values_to_autoload' )
			? wp_autoload_values_to_autoload()
			: array( 'yes', 'on', 'auto-on', 'auto' );

		return in_array( $value, $autoloaded, true );
	}
}
