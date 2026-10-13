<?php

declare(strict_types=1);

namespace RAN\Admin;

use RAN\RepositoryProvider\CredentialExpiryReport;
use RuntimeException;

/**
 * Stores non-secret, provider-scoped credential expiry observations.
 */
class CredentialExpiryObservationStore {

	public const OPTION_NAME = 'ran_booster_credential_expiry_observations';

	private const SCHEMA_VERSION = 1;

	/**
	 * @return array{manual_expires_on?: string, provider_expires_at?: string, provider_checked_at?: string}
	 */
	public function get( string $provider, string $profile_id ): array {
		$this->require_provider_id( $provider );
		$this->require_profile_id( $profile_id );

		return $this->all( $this->read_option() )[ $provider ][ $profile_id ] ?? array();
	}

	/**
	 * @return array<string, array<string, array{manual_expires_on?: string, provider_expires_at?: string, provider_checked_at?: string}>>
	 */
	public function observations(): array {
		return $this->all( $this->read_option() );
	}

	public function set_manual_expiry( string $provider, string $profile_id, ?string $expires_on ): void {
		$this->require_provider_id( $provider );
		$this->require_profile_id( $profile_id );
		if ( null !== $expires_on ) {
			$this->require_date( $expires_on );
		}

		$this->mutate( $provider, $profile_id, array( 'manual_expires_on' => $expires_on ) );
	}

	public function record_provider_expiry(
		string $provider,
		string $profile_id,
		CredentialExpiryReport $report,
		string $checked_at
	): void {
		$this->require_provider_id( $provider );
		$this->require_profile_id( $profile_id );
		$this->require_utc_timestamp( $checked_at );

		$this->mutate(
			$provider,
			$profile_id,
			array(
				'provider_checked_at' => $checked_at,
				'provider_expires_at' => $report->is_known() ? (string) $report->expires_at : null,
			)
		);
	}

	public function clear( string $provider, string $profile_id ): void {
		$this->require_provider_id( $provider );
		$this->require_profile_id( $profile_id );

		$this->mutate( $provider, $profile_id, null );
	}

	protected function read_option( bool $fresh = false ): mixed {
		if ( $fresh ) {
			wp_cache_delete( self::OPTION_NAME, 'options' );
			wp_cache_delete( 'alloptions', 'options' );
			wp_cache_delete( 'notoptions', 'options' );
		}
		return get_option( self::OPTION_NAME, null );
	}

	/** @param array<string, mixed> $replacement Canonical option document. */
	protected function write_option( mixed $expected, array $replacement ): bool {
		global $wpdb;
		try {
			if ( null === $expected ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- INSERT IGNORE admits only the first writer; never replace a concurrently created option.
				$inserted = $wpdb->query(
					$wpdb->prepare(
						"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off')",
						self::OPTION_NAME,
						maybe_serialize( $replacement )
					)
				);
				return 1 === $inserted && '' === trim( (string) $wpdb->last_error );
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Compare the exact option snapshot so concurrent entry updates cannot overwrite one another.
			$updated = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->options} SET option_value = %s, autoload = 'off' WHERE option_name = %s AND BINARY option_value = BINARY %s",
					maybe_serialize( $replacement ),
					self::OPTION_NAME,
					maybe_serialize( $expected )
				)
			);
			return 1 === $updated && '' === trim( (string) $wpdb->last_error );
		} finally {
			wp_cache_delete( self::OPTION_NAME, 'options' );
			wp_cache_delete( 'alloptions', 'options' );
			wp_cache_delete( 'notoptions', 'options' );
		}
	}

	/**
	 * @return array<string, array<string, array{manual_expires_on?: string, provider_expires_at?: string, provider_checked_at?: string}>>
	 */
	private function all( mixed $document ): array {
		if ( ! is_array( $document ) || self::SCHEMA_VERSION !== ( $document['version'] ?? null )
			|| ! is_array( $document['profiles'] ?? null )
		) {
			return array();
		}

		$profiles = array();
		foreach ( $document['profiles'] as $provider => $provider_profiles ) {
			if ( ! is_string( $provider )
				|| 1 !== preg_match( '/^[a-z][a-z0-9-]{0,31}$/D', $provider )
				|| ! is_array( $provider_profiles )
			) {
				continue;
			}

			foreach ( $provider_profiles as $profile_id => $candidate ) {
				if ( ! is_string( $profile_id )
					|| 1 !== preg_match( '/^[A-Za-z0-9_-]{3,64}$/D', $profile_id )
					|| ! is_array( $candidate )
				) {
					continue;
				}

				$record = $this->normalise_record( $candidate );
				if ( array() !== $record ) {
					$profiles[ $provider ][ $profile_id ] = $record;
				}
			}
		}

		ksort( $profiles );
		foreach ( $profiles as &$provider_profiles ) {
			ksort( $provider_profiles );
		}
		unset( $provider_profiles );

		return $profiles;
	}

	/**
	 * @param array<string, mixed> $candidate Stored observation candidate.
	 * @return array{manual_expires_on?: string, provider_expires_at?: string, provider_checked_at?: string}
	 */
	private function normalise_record( array $candidate ): array {
		$record = array();

		if ( is_string( $candidate['manual_expires_on'] ?? null )
			&& $this->is_date( $candidate['manual_expires_on'] )
		) {
			$record['manual_expires_on'] = $candidate['manual_expires_on'];
		}

		if ( is_string( $candidate['provider_checked_at'] ?? null )
			&& $this->is_utc_timestamp( $candidate['provider_checked_at'] )
		) {
			$record['provider_checked_at'] = $candidate['provider_checked_at'];
			if ( is_string( $candidate['provider_expires_at'] ?? null )
				&& $this->is_utc_timestamp( $candidate['provider_expires_at'] )
			) {
				$record['provider_expires_at'] = $candidate['provider_expires_at'];
			}
		}

		return $record;
	}

	/**
	 * @param array<string, array<string, array<string, string>>> $profiles
	 * @param array<string, string>                               $record
	 */
	private function replace_record( array &$profiles, string $provider, string $profile_id, array $record ): void {
		if ( array() === $record ) {
			unset( $profiles[ $provider ][ $profile_id ] );
			if ( array() === ( $profiles[ $provider ] ?? array() ) ) {
				unset( $profiles[ $provider ] );
			}

			return;
		}

		ksort( $record );
		$profiles[ $provider ][ $profile_id ] = $record;
	}

	/** @param array<string, string|null>|null $patch Fields to replace, or null to clear the record. */
	private function mutate( string $provider, string $profile_id, ?array $patch ): void {
		for ( $attempt = 0; $attempt < 5; ++$attempt ) {
			$expected = $this->read_option( true );
			$profiles = $this->all( $expected );
			$record   = null === $patch ? array() : ( $profiles[ $provider ][ $profile_id ] ?? array() );
			foreach ( $patch ?? array() as $field => $value ) {
				if ( null === $value ) {
					unset( $record[ $field ] );
				} else {
					$record[ $field ] = $value;
				}
			}
			$this->replace_record( $profiles, $provider, $profile_id, $record );
			ksort( $profiles );
			foreach ( $profiles as &$provider_profiles ) {
				ksort( $provider_profiles );
			}
			unset( $provider_profiles );
			$document = array(
				'version'  => self::SCHEMA_VERSION,
				'profiles' => $profiles,
			);
			if ( $expected === $document || $this->write_option( $expected, $document ) ) {
				return;
			}
		}
		throw new RuntimeException( 'Booster could not save credential expiry information.' );
	}

	private function require_provider_id( string $provider ): void {
		if ( 1 !== preg_match( '/^[a-z][a-z0-9-]{0,31}$/D', $provider ) ) {
			throw new RuntimeException( 'Credential expiry provider is invalid.' );
		}
	}

	private function require_profile_id( string $profile_id ): void {
		if ( 1 !== preg_match( '/^[A-Za-z0-9_-]{3,64}$/D', $profile_id ) ) {
			throw new RuntimeException( 'Credential expiry profile is invalid.' );
		}
	}

	private function require_date( string $value ): void {
		if ( ! $this->is_date( $value ) ) {
			throw new RuntimeException( 'Credential expiry date is invalid.' );
		}
	}

	private function require_utc_timestamp( string $value ): void {
		if ( ! $this->is_utc_timestamp( $value ) ) {
			throw new RuntimeException( 'Credential expiry timestamp is invalid.' );
		}
	}

	private function is_date( string $value ): bool {
		return 1 === preg_match( '/\A(\d{4})-(\d{2})-(\d{2})\z/D', $value, $matches )
			&& checkdate( (int) $matches[2], (int) $matches[3], (int) $matches[1] );
	}

	private function is_utc_timestamp( string $value ): bool {
		return 1 === preg_match( '/\A(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})Z\z/D', $value, $matches )
			&& checkdate( (int) $matches[2], (int) $matches[3], (int) $matches[1] )
			&& (int) $matches[4] <= 23
			&& (int) $matches[5] <= 59
			&& (int) $matches[6] <= 59;
	}
}
