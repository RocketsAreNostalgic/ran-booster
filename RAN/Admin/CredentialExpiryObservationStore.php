<?php

declare(strict_types=1);

namespace RAN\Admin;

use RAN\Logging\BoosterLogger;
use RAN\RepositoryProvider\CredentialExpiryReport;
use RAN\Secrets\SecretsFile;
use RuntimeException;

/**
 * Stores non-secret, provider-scoped credential expiry observations.
 *
 * Provider observations are keyed by the material tag they were made for, and only the current tag's is returned.
 * A stored credential keeps one; the constant keeps two, so a request holding a rotated value writes beside the new one.
 *
 * @phpstan-type ProviderObservation array{provider_checked_at: string, provider_expires_at?: string}
 * @phpstan-type Observation array{manual_expires_on?: string, provider_expires_at?: string, provider_checked_at?: string, event_generation?: int}
 * @phpstan-type StoredObservation array{manual_expires_on?: string, event_generation?: int, provider_observations?: array<string, ProviderObservation>}
 */
class CredentialExpiryObservationStore {

	public const OPTION_NAME = 'ran_booster_credential_expiry_observations';

	private const SCHEMA_VERSION = 1;

	/** @var (\Closure(string, string): ?string)|null */
	private ?\Closure $material_tags = null;

	/**
	 * Set the reader of current material tags once; until then provider observations are hidden and not recorded.
	 * The store belongs to one SecretsFile, so a later binding is ignored.
	 *
	 * @param \Closure(string, string): ?string $reader
	 */
	public function read_material_tags_with( \Closure $reader ): void {
		$this->material_tags ??= $reader;
	}

	/** @return Observation */
	public function get( string $provider, string $profile_id ): array {
		$this->require_provider_id( $provider );
		$this->require_profile_id( $profile_id );

		return $this->trusted( $provider, $profile_id, $this->all( $this->read_option() )[ $provider ][ $profile_id ] ?? array() );
	}

	/** @return array<string, array<string, Observation>> */
	public function observations(): array {
		return $this->all( $this->read_option(), true );
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
				'material_tag' => $material_tag,
				'observation'  => array( 'provider_checked_at' => $checked_at ) + ( $report->is_known() ? array( 'provider_expires_at' => (string) $report->expires_at ) : array() ),
			)
		);
	}

	public function clear( string $provider, string $profile_id ): void {
		$this->require_provider_id( $provider );
		$this->require_profile_id( $profile_id );

		$this->mutate( $provider, $profile_id, null );
	}

	/** Clear stale expiry evidence, keeping the current material's observation and a non-secret replacement generation. */
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

	/** @return ($trusted is true ? array<string, array<string, Observation>> : array<string, array<string, StoredObservation>>) */
	private function all( mixed $document, bool $trusted = false ): array {
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
				if ( $trusted ) {
					$record = $this->trusted( $provider, $profile_id, $record );
				}
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
	 * @return StoredObservation
	 */
	private function normalise_record( array $candidate ): array {
		$record = array();

		if ( is_string( $candidate['manual_expires_on'] ?? null )
			&& $this->is_date( $candidate['manual_expires_on'] )
		) {
			$record['manual_expires_on'] = $candidate['manual_expires_on'];
		}

		// A provider observation is trusted only under the tag of the credential it was made for.
		$stored = $candidate['provider_observations'] ?? null;
		foreach ( is_array( $stored ) && count( $stored ) <= 2 ? $stored : array() as $tag => $observation ) {
			if ( is_string( $tag ) && 1 === preg_match( '/\A[0-9a-f]{32}\z/', $tag )
				&& is_string( $observation['provider_checked_at'] ?? null )
				&& $this->is_utc_timestamp( $observation['provider_checked_at'] )
			) {
				$record['provider_observations'][ $tag ] = array( 'provider_checked_at' => $observation['provider_checked_at'] );
				if ( is_string( $observation['provider_expires_at'] ?? null )
					&& $this->is_utc_timestamp( $observation['provider_expires_at'] )
				) {
					$record['provider_observations'][ $tag ]['provider_expires_at'] = $observation['provider_expires_at'];
				}
			}
		}
		if ( is_int( $candidate['event_generation'] ?? null ) && $candidate['event_generation'] > 0 ) {
			$record['event_generation'] = $candidate['event_generation'];
		}

		return $record;
	}

	/**
	 * Keep only the provider observation made for the credential's current tag.
	 *
	 * @param StoredObservation $record
	 * @return Observation
	 */
	private function trusted( string $provider, string $profile_id, array $record ): array {
		$observations = $record['provider_observations'] ?? array();
		unset( $record['provider_observations'] );
		$current = array() === $observations ? null : $this->current_tag( $provider, $profile_id );

		return $record + ( array_values( $this->current_observations( $observations, $current ) )[0] ?? array() );
	}

	/**
	 * @param array<string, ProviderObservation> $observations
	 * @return array<string, ProviderObservation>
	 */
	private function current_observations( array $observations, ?string $current ): array {
		return array_filter( $observations, static fn ( string $tag ): bool => null !== $current && hash_equals( $current, $tag ), ARRAY_FILTER_USE_KEY );
	}

	private function current_tag( string $provider, string $profile_id ): ?string {
		try {
			$reader = $this->material_tags ?? throw new RuntimeException( 'No material tag reader is bound.' );

			return $reader( $provider, $profile_id );
		} catch ( \Throwable ) {
			BoosterLogger::log(
				'Credential expiry observation unavailable.',
				array(
					'operation'    => 'credential_expiry_observation',
					'outcome_code' => 'material_tag_unavailable',
				)
			);

			return null;
		}
	}

	/**
	 * @param array<string, array<string, StoredObservation>> $profiles
	 * @param StoredObservation                               $record
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
	 * A provider observation is written only while its tag is current, checked again on every attempt.
	 * Clearing keeps only the current material's provider observation.
	 *
	 * @param array{manual_expires_on: ?string}|array{material_tag: string, observation: ProviderObservation}|null $patch Null clears the record.
	 */
	private function mutate( string $provider, string $profile_id, ?array $patch, bool $replacement = false ): bool {
		$tag = isset( $patch['material_tag'] ) ? $patch['material_tag'] : null;
		for ( $attempt = 0; $attempt < 5; ++$attempt ) {
			$expected     = $this->read_option( true );
			$profiles     = $this->all( $expected );
			$previous     = $profiles[ $provider ][ $profile_id ] ?? array();
			$observations = $previous['provider_observations'] ?? array();
			$current      = null !== $tag || ( null === $patch && array() !== $observations ) ? $this->current_tag( $provider, $profile_id ) : null;
			if ( null !== $tag && ( null === $current || ! hash_equals( $current, $tag ) ) ) {
				return false;
			}
			$record = null === $patch ? array_filter( array( 'provider_observations' => $this->current_observations( $observations, $current ) ) ) : $previous;
			if ( $replacement ) {
				$record['event_generation'] = ( $previous['event_generation'] ?? 0 ) + 1;
			}
			if ( isset( $patch['observation'] ) ) {
				// An existing tag keeps its place; a new one evicts the oldest beyond the bound.
				$observations[ $patch['material_tag'] ] = $patch['observation'];
				$record['provider_observations']        = array_slice( $observations, SecretsFile::CONSTANT_PROFILE === $profile_id ? -2 : -1, null, true );
			} elseif ( null !== $patch && array_key_exists( 'manual_expires_on', $patch ) ) {
				unset( $record['manual_expires_on'] );
				if ( null !== $patch['manual_expires_on'] ) {
					$record['manual_expires_on'] = $patch['manual_expires_on'];
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
