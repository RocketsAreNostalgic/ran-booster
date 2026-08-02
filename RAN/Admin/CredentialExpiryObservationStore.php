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
	public function get( string $provider, string $profileId ): array {
		$this->requireProviderId( $provider );
		$this->requireProfileId( $profileId );

		return $this->all()[ $provider ][ $profileId ] ?? array();
	}

	/**
	 * @return array<string, array<string, array{manual_expires_on?: string, provider_expires_at?: string, provider_checked_at?: string}>>
	 */
	public function observations(): array {
		return $this->all();
	}

	public function setManualExpiry( string $provider, string $profileId, ?string $expiresOn ): void {
		$this->requireProviderId( $provider );
		$this->requireProfileId( $profileId );
		if ( null !== $expiresOn ) {
			$this->requireDate( $expiresOn );
		}

		$profiles = $this->all();
		$record   = $profiles[ $provider ][ $profileId ] ?? array();

		if ( null === $expiresOn ) {
			unset( $record['manual_expires_on'] );
		} else {
			$record['manual_expires_on'] = $expiresOn;
		}

		$this->replaceRecord( $profiles, $provider, $profileId, $record );
		$this->persist( $profiles );
	}

	public function recordProviderExpiry(
		string $provider,
		string $profileId,
		CredentialExpiryReport $report,
		string $checkedAt
	): void {
		$this->requireProviderId( $provider );
		$this->requireProfileId( $profileId );
		$this->requireUtcTimestamp( $checkedAt );

		$profiles                      = $this->all();
		$record                        = $profiles[ $provider ][ $profileId ] ?? array();
		$record['provider_checked_at'] = $checkedAt;
		if ( $report->isKnown() ) {
			$record['provider_expires_at'] = (string) $report->expiresAt;
		} else {
			unset( $record['provider_expires_at'] );
		}

		$this->replaceRecord( $profiles, $provider, $profileId, $record );
		$this->persist( $profiles );
	}

	public function clear( string $provider, string $profileId ): void {
		$this->requireProviderId( $provider );
		$this->requireProfileId( $profileId );

		$profiles = $this->all();
		unset( $profiles[ $provider ][ $profileId ] );
		if ( array() === ( $profiles[ $provider ] ?? array() ) ) {
			unset( $profiles[ $provider ] );
		}

		$this->persist( $profiles );
	}

	/**
	 * @return array<string, mixed>
	 */
	protected function readOption(): array {
		if ( ! function_exists( 'get_option' ) ) {
			return array();
		}

		$value = get_option( self::OPTION_NAME, array() );

		return is_array( $value ) ? $value : array();
	}

	/**
	 * @param array<string, mixed> $document Canonical option document.
	 */
	protected function writeOption( array $document ): bool {
		if ( ! function_exists( 'update_option' ) ) {
			return false;
		}

		return update_option( self::OPTION_NAME, $document, false );
	}

	/**
	 * @return array<string, array<string, array{manual_expires_on?: string, provider_expires_at?: string, provider_checked_at?: string}>>
	 */
	private function all(): array {
		$document = $this->readOption();
		if ( self::SCHEMA_VERSION !== ( $document['version'] ?? null )
			|| ! is_array( $document['profiles'] ?? null )
		) {
			return array();
		}

		$profiles = array();
		foreach ( $document['profiles'] as $provider => $providerProfiles ) {
			if ( ! is_string( $provider )
				|| 1 !== preg_match( '/^[a-z][a-z0-9-]{0,31}$/D', $provider )
				|| ! is_array( $providerProfiles )
			) {
				continue;
			}

			foreach ( $providerProfiles as $profileId => $candidate ) {
				if ( ! is_string( $profileId )
					|| 1 !== preg_match( '/^[A-Za-z0-9_-]{3,64}$/D', $profileId )
					|| ! is_array( $candidate )
				) {
					continue;
				}

				$record = $this->normaliseRecord( $candidate );
				if ( array() !== $record ) {
					$profiles[ $provider ][ $profileId ] = $record;
				}
			}
		}

		ksort( $profiles );
		foreach ( $profiles as &$providerProfiles ) {
			ksort( $providerProfiles );
		}
		unset( $providerProfiles );

		return $profiles;
	}

	/**
	 * @param array<string, mixed> $candidate Stored observation candidate.
	 * @return array{manual_expires_on?: string, provider_expires_at?: string, provider_checked_at?: string}
	 */
	private function normaliseRecord( array $candidate ): array {
		$record = array();

		if ( is_string( $candidate['manual_expires_on'] ?? null )
			&& $this->isDate( $candidate['manual_expires_on'] )
		) {
			$record['manual_expires_on'] = $candidate['manual_expires_on'];
		}

		if ( is_string( $candidate['provider_checked_at'] ?? null )
			&& $this->isUtcTimestamp( $candidate['provider_checked_at'] )
		) {
			$record['provider_checked_at'] = $candidate['provider_checked_at'];
			if ( is_string( $candidate['provider_expires_at'] ?? null )
				&& $this->isUtcTimestamp( $candidate['provider_expires_at'] )
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
	private function replaceRecord( array &$profiles, string $provider, string $profileId, array $record ): void {
		if ( array() === $record ) {
			unset( $profiles[ $provider ][ $profileId ] );
			if ( array() === ( $profiles[ $provider ] ?? array() ) ) {
				unset( $profiles[ $provider ] );
			}

			return;
		}

		ksort( $record );
		$profiles[ $provider ][ $profileId ] = $record;
	}

	/**
	 * @param array<string, array<string, array<string, string>>> $profiles
	 */
	private function persist( array $profiles ): void {
		ksort( $profiles );
		foreach ( $profiles as &$providerProfiles ) {
			ksort( $providerProfiles );
		}
		unset( $providerProfiles );

		$document = array(
			'version'  => self::SCHEMA_VERSION,
			'profiles' => $profiles,
		);
		if ( ! $this->writeOption( $document ) && $document !== $this->readOption() ) {
			throw new RuntimeException( 'Booster could not save credential expiry information.' );
		}
	}

	private function requireProviderId( string $provider ): void {
		if ( 1 !== preg_match( '/^[a-z][a-z0-9-]{0,31}$/D', $provider ) ) {
			throw new RuntimeException( 'Credential expiry provider is invalid.' );
		}
	}

	private function requireProfileId( string $profileId ): void {
		if ( 1 !== preg_match( '/^[A-Za-z0-9_-]{3,64}$/D', $profileId ) ) {
			throw new RuntimeException( 'Credential expiry profile is invalid.' );
		}
	}

	private function requireDate( string $value ): void {
		if ( ! $this->isDate( $value ) ) {
			throw new RuntimeException( 'Credential expiry date is invalid.' );
		}
	}

	private function requireUtcTimestamp( string $value ): void {
		if ( ! $this->isUtcTimestamp( $value ) ) {
			throw new RuntimeException( 'Credential expiry timestamp is invalid.' );
		}
	}

	private function isDate( string $value ): bool {
		return 1 === preg_match( '/\A(\d{4})-(\d{2})-(\d{2})\z/D', $value, $matches )
			&& checkdate( (int) $matches[2], (int) $matches[3], (int) $matches[1] );
	}

	private function isUtcTimestamp( string $value ): bool {
		return 1 === preg_match( '/\A(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})Z\z/D', $value, $matches )
			&& checkdate( (int) $matches[2], (int) $matches[3], (int) $matches[1] )
			&& (int) $matches[4] <= 23
			&& (int) $matches[5] <= 59
			&& (int) $matches[6] <= 59;
	}
}
