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
	private object $missingValue;

	public function __construct() {
		$this->missingValue = new \stdClass();
	}

	public function load( bool $repairAutoload = true ): ?string {
		$value = $this->readStoredValue();
		if ( $this->missingValue === $value ) {
			return null;
		}

		$key = $this->decodeStoredKey( $value );
		$this->verifyNonAutoloaded( $repairAutoload );

		return $key;
	}

	/**
	 * @return array{key: string, created: bool}
	 */
	public function loadOrCreate(): array {
		$existing = $this->load();
		if ( null !== $existing ) {
			return array(
				'key'     => $existing,
				'created' => false,
			);
		}

		$key = $this->generateKey();
		$this->requireRawKey( $key );
		$encoded = base64_encode( $key );
		$created = $this->addStoredValue( $encoded );
		if ( ! $created ) {
			$this->invalidateOptionCache();
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
	public function deleteExact( #[\SensitiveParameter] string $key ): bool {
		$this->requireRawKey( $key );
		$result = $this->deleteStoredValueExact( base64_encode( $key ) );

		if ( false === $result || $result < 0 || $result > 1 ) {
			throw new RuntimeException( 'The Booster site key could not be removed safely.' );
		}
		if ( 1 !== $result ) {
			return false;
		}

		$this->invalidateOptionCache();

		return true;
	}

	protected function readStoredValue(): mixed {
		if ( ! function_exists( 'get_option' ) ) {
			return $this->missingValue;
		}

		return get_option( self::OPTION_NAME, $this->missingValue );
	}

	protected function missingStoredValue(): object {
		return $this->missingValue;
	}

	protected function addStoredValue( #[\SensitiveParameter] string $encoded ): bool {
		if ( ! function_exists( 'add_option' ) ) {
			return false;
		}

		return add_option( self::OPTION_NAME, $encoded, '', false );
	}

	protected function readAutoloadValue(): ?string {
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

	protected function repairAutoloadValue(): void {
		if ( function_exists( 'wp_set_option_autoload_values' ) ) {
			wp_set_option_autoload_values( array( self::OPTION_NAME => false ) );
		}
	}

	protected function deleteStoredValueExact( #[\SensitiveParameter] string $encoded ): int|false {
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

	protected function invalidateOptionCache(): void {
		if ( ! function_exists( 'wp_cache_delete' ) ) {
			return;
		}

		wp_cache_delete( self::OPTION_NAME, 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
	}

	protected function generateKey(): string {
		return random_bytes( self::KEY_BYTES );
	}

	private function decodeStoredKey( #[\SensitiveParameter] mixed $value ): string {
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

	private function requireRawKey( #[\SensitiveParameter] string $key ): void {
		if ( self::KEY_BYTES !== strlen( $key ) ) {
			throw new RuntimeException( 'The Booster site key is invalid.' );
		}
	}

	private function verifyNonAutoloaded( bool $repair ): void {
		$autoload = $this->readAutoloadValue();
		if ( null === $autoload ) {
			throw new RuntimeException( 'The Booster site key autoload setting could not be verified.' );
		}
		if ( ! $this->isAutoloaded( $autoload ) ) {
			return;
		}
		if ( ! $repair ) {
			throw new RuntimeException( 'The Booster site key autoload setting is insecure.' );
		}

		$this->repairAutoloadValue();
		$autoload = $this->readAutoloadValue();
		if ( null === $autoload || $this->isAutoloaded( $autoload ) ) {
			throw new RuntimeException( 'The Booster site key autoload setting could not be secured.' );
		}
	}

	private function isAutoloaded( string $value ): bool {
		$autoloaded = function_exists( 'wp_autoload_values_to_autoload' )
			? wp_autoload_values_to_autoload()
			: array( 'yes', 'on', 'auto-on', 'auto' );

		return in_array( $value, $autoloaded, true );
	}
}
