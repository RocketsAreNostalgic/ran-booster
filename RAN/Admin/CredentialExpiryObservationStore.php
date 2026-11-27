<?php

declare(strict_types=1);

namespace RAN\Admin;

use RAN\Logging\BoosterLogger;
use RAN\RepositoryProvider\CredentialExpiryReport;
use RuntimeException;

/**
 * Stores non-secret, provider-scoped credential expiry observations.
 *
 * A provider observation carries the material tag of the credential it was made
 * for, and is returned only while that tag is still the credential's current one.
 */
class CredentialExpiryObservationStore {

	public const OPTION_NAME = 'ran_booster_credential_expiry_observations';

	private const SCHEMA_VERSION = 1;

	/** @var (\Closure(string, string): ?string)|null */
	private ?\Closure $material_tags = null;

	/**
	 * Set the reader of current material tags once; until then provider observations are hidden and not recorded.
	 *
	 * @param \Closure(string, string): ?string $reader
	 */
	public function read_material_tags_with( \Closure $reader ): void {
		$this->material_tags ??= $reader;
	}

	/**
	 * @return array{manual_expires_on?: string, provider_expires_at?: string, provider_checked_at?: string, event_generation?: int}
	 */
	public function get( string $provider, string $profile_id ): array {
		$this->require_provider_id( $provider );
		$this->require_profile_id( $profile_id );

		return $this->trusted( $provider, $profile_id, $this->all( $this->read_option() )[ $provider ][ $profile_id ] ?? array() );
	}

	/**
	 * @return array<string, array<string, array{manual_expires_on?: string, provider_expires_at?: string, provider_checked_at?: string, event_generation?: int}>>
	 */
	public function observations(): array {
		$profiles = $this->all( $this->read_option() );
		foreach ( $profiles as $provider => &$records ) {
			foreach ( $records as $profile_id => &$record ) {
				$record = $this->trusted( $provider, $profile_id, $record );
			}
			unset( $record );
			$records = array_filter( $records );
		}
		unset( $records );

		return array_filter( $profiles );
	}

	public function set_manual_expiry( string $provider, string $profile_id, ?string $expires_on ): void {
		$this->require_provider_id( $provider );
		$this->require_profile_id( $profile_id );
		if ( null !== $expires_on ) {
			$this->require_date( $expires_on );
		}

		$this->mutate( $provider, $profile_id, array( 'manual_expires_on' => $expires_on ) );
	}

	/** @return bool False when the credential no longer carries $material_tag, or its tag cannot be read. */
	public function record_provider_expiry(
		string $provider,
		string $profile_id,
		CredentialExpiryReport $report,
		string $checked_at,
		string $material_tag
	): bool {
		$this->require_provider_id( $provider );
		$this->require_profile_id( $profile_id );
		$this->require_utc_timestamp( $checked_at );

		return $this->mutate(
			$provider,
			$profile_id,
			array(
				'material_tag'        => $material_tag,
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

	/** Clear stale expiry evidence while retaining a non-secret replacement generation. */
	public function reset_for_replacement( string $provider, string $profile_id ): void {
		$this->require_provider_id( $provider );
		$this->require_profile_id( $profile_id );
		$this->mutate( $provider, $profile_id, null, true );
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
	 * @return array<string, array<string, array{manual_expires_on?: string, provider_expires_at?: string, provider_checked_at?: string, event_generation?: int, material_tag?: string}>>
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
	 * @return array{manual_expires_on?: string, provider_expires_at?: string, provider_checked_at?: string, event_generation?: int, material_tag?: string}
	 */
	private function normalise_record( array $candidate ): array {
		$record = array();

		if ( is_string( $candidate['manual_expires_on'] ?? null )
			&& $this->is_date( $candidate['manual_expires_on'] )
		) {
			$record['manual_expires_on'] = $candidate['manual_expires_on'];
		}

		// A provider observation without the tag of the credential it was made for is never trusted.
		if ( is_string( $candidate['provider_checked_at'] ?? null )
			&& $this->is_utc_timestamp( $candidate['provider_checked_at'] )
			&& is_string( $candidate['material_tag'] ?? null )
			&& $this->is_material_tag( $candidate['material_tag'] )
		) {
			$record['material_tag']        = $candidate['material_tag'];
			$record['provider_checked_at'] = $candidate['provider_checked_at'];
			if ( is_string( $candidate['provider_expires_at'] ?? null )
				&& $this->is_utc_timestamp( $candidate['provider_expires_at'] )
			) {
				$record['provider_expires_at'] = $candidate['provider_expires_at'];
			}
		}
		if ( is_int( $candidate['event_generation'] ?? null ) && $candidate['event_generation'] > 0 ) {
			$record['event_generation'] = $candidate['event_generation'];
		}

		return $record;
	}

	/**
	 * Drop the tag, and the provider observation unless its tag is the credential's current one.
	 *
	 * @param array{manual_expires_on?: string, provider_expires_at?: string, provider_checked_at?: string, event_generation?: int, material_tag?: string} $record
	 * @return array{manual_expires_on?: string, provider_expires_at?: string, provider_checked_at?: string, event_generation?: int}
	 */
	private function trusted( string $provider, string $profile_id, array $record ): array {
		$tag = $record['material_tag'] ?? null;
		unset( $record['material_tag'] );
		if ( null !== $tag && ! $this->is_current_tag( $provider, $profile_id, $tag ) ) {
			unset( $record['provider_checked_at'], $record['provider_expires_at'] );
		}

		return $record;
	}

	private function is_current_tag( string $provider, string $profile_id, string $tag ): bool {
		try {
			$current = null === $this->material_tags ? false : ( $this->material_tags )( $provider, $profile_id );
		} catch ( \Throwable ) {
			$current = false;
		}
		if ( false === $current ) {
			BoosterLogger::log(
				'Credential expiry observation unavailable.',
				array(
					'operation'    => 'credential_expiry_observation',
					'outcome_code' => 'material_tag_unavailable',
				)
			);
		}

		return is_string( $current ) && hash_equals( $current, $tag );
	}

	/**
	 * @param array<string, array<string, array<string, string|int>>> $profiles
	 * @param array<string, string|int>                           $record
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

	/**
	 * A patch that carries a material tag is written only while that tag is current, checked again on every attempt.
	 *
	 * @param array<string, string|null>|null $patch Fields to replace, or null to clear the record.
	 */
	private function mutate( string $provider, string $profile_id, ?array $patch, bool $replacement = false ): bool {
		for ( $attempt = 0; $attempt < 5; ++$attempt ) {
			$expected = $this->read_option( true );
			if ( is_string( $patch['material_tag'] ?? null ) && ! $this->is_current_tag( $provider, $profile_id, $patch['material_tag'] ) ) {
				return false;
			}
			$profiles = $this->all( $expected );
			$previous = $profiles[ $provider ][ $profile_id ] ?? array();
			$record   = null === $patch ? array() : $previous;
			if ( $replacement ) {
				$record['event_generation'] = ( $previous['event_generation'] ?? 0 ) + 1;
			}
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
				return true;
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

	private function is_material_tag( string $value ): bool {
		return 1 === preg_match( '/\A[0-9a-f]{32}\z/', $value );
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
