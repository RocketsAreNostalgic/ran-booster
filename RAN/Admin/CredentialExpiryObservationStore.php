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

		return $this->all()[ $provider ][ $profile_id ] ?? array();
	}

	/**
	 * @return array<string, array<string, array{manual_expires_on?: string, provider_expires_at?: string, provider_checked_at?: string}>>
	 */
	public function observations(): array {
		return $this->all();
	}

	public function set_manual_expiry( string $provider, string $profile_id, ?string $expires_on ): void {
		$this->require_provider_id( $provider );
		$this->require_profile_id( $profile_id );
		if ( null !== $expires_on ) {
			$this->require_date( $expires_on );
		}

		$profiles = $this->all();
		$record   = $profiles[ $provider ][ $profile_id ] ?? array();

		if ( null === $expires_on ) {
			unset( $record['manual_expires_on'] );
		} else {
			$record['manual_expires_on'] = $expires_on;
		}

		$this->replace_record( $profiles, $provider, $profile_id, $record );
		$this->persist( $profiles );
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

		$profiles                      = $this->all();
		$record                        = $profiles[ $provider ][ $profile_id ] ?? array();
		$record['provider_checked_at'] = $checked_at;
		if ( $report->is_known() ) {

			$record['provider_expires_at'] = (string) $report->expires_at;
		} else {
			unset( $record['provider_expires_at'] );
		}

		$this->replace_record( $profiles, $provider, $profile_id, $record );
		$this->persist( $profiles );
	}

	public function clear( string $provider, string $profile_id ): void {
		$this->require_provider_id( $provider );
		$this->require_profile_id( $profile_id );

		$profiles = $this->all();
		unset( $profiles[ $provider ][ $profile_id ] );
		if ( array() === ( $profiles[ $provider ] ?? array() ) ) {
			unset( $profiles[ $provider ] );
		}

		$this->persist( $profiles );
	}

	/**
	 * @return array<string, mixed>
	 */
	protected function read_option(): array {
		if ( ! function_exists( 'get_option' ) ) {
			return array();
		}

		$value = get_option( self::OPTION_NAME, array() );

		return is_array( $value ) ? $value : array();
	}

	/**
	 * @param array<string, mixed> $document Canonical option document.
	 */
	protected function write_option( array $document ): bool {
		if ( ! function_exists( 'update_option' ) ) {
			return false;
		}

		return update_option( self::OPTION_NAME, $document, false );
	}

	/**
	 * @return array<string, array<string, array{manual_expires_on?: string, provider_expires_at?: string, provider_checked_at?: string}>>
	 */
	private function all(): array {
		$document = $this->read_option();
		if ( self::SCHEMA_VERSION !== ( $document['version'] ?? null )
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

	/**
	 * @param array<string, array<string, array<string, string>>> $profiles
	 */
	private function persist( array $profiles ): void {
		ksort( $profiles );
		foreach ( $profiles as &$provider_profiles ) {
			ksort( $provider_profiles );
		}
		unset( $provider_profiles );

		$document = array(
			'version'  => self::SCHEMA_VERSION,
			'profiles' => $profiles,
		);
		if ( ! $this->write_option( $document ) && $document !== $this->read_option() ) {
			throw new RuntimeException( 'Booster could not save credential expiry information.' );
		}
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
