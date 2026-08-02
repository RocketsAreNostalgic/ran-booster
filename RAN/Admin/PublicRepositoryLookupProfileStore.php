<?php

declare(strict_types=1);

namespace RAN\Admin;

use RuntimeException;

/**
 * Stores the non-secret default profile identity for public repository lookup.
 *
 * Credential material remains in the provider secrets sidecar. This option
 * contains only exact provider and profile IDs and is deliberately not
 * autoloaded.
 */
class PublicRepositoryLookupProfileStore {

	public const OPTION_NAME = 'ran_booster_public_repository_lookup_profiles';

	public function get( string $provider ): ?string {
		$profiles = $this->all();

		return $profiles[ $provider ] ?? null;
	}

	public function set( string $provider, ?string $profileId ): void {
		$this->requireProviderId( $provider );
		if ( null !== $profileId ) {
			$this->requireProfileId( $profileId );
		}

		$profiles = $this->all();
		if ( null === $profileId ) {
			unset( $profiles[ $provider ] );
		} else {
			$profiles[ $provider ] = $profileId;
		}
		ksort( $profiles );

		if ( ! $this->writeOption( $profiles ) && $profiles !== $this->all() ) {
			throw new RuntimeException( 'Booster could not save the public repository lookup preference.' );
		}
	}

	/**
	 * @return array<string, string>
	 */
	protected function readOption(): array {
		if ( ! function_exists( 'get_option' ) ) {
			return array();
		}

		$value = get_option( self::OPTION_NAME, array() );

		return is_array( $value ) ? $value : array();
	}

	/**
	 * @param array<string, string> $profiles Provider-to-profile mapping.
	 */
	protected function writeOption( array $profiles ): bool {
		if ( ! function_exists( 'update_option' ) ) {
			return false;
		}

		return update_option( self::OPTION_NAME, $profiles, false );
	}

	/**
	 * @return array<string, string>
	 */
	private function all(): array {
		$profiles = array();

		foreach ( $this->readOption() as $provider => $profileId ) {
			if ( ! is_string( $provider ) || ! is_string( $profileId ) ) {
				continue;
			}
			if ( 1 !== preg_match( '/^[a-z][a-z0-9-]{0,31}$/D', $provider )
				|| 1 !== preg_match( '/^[A-Za-z0-9_-]{3,64}$/D', $profileId ) ) {
				continue;
			}

			$profiles[ $provider ] = $profileId;
		}

		return $profiles;
	}

	private function requireProviderId( string $provider ): void {
		if ( 1 !== preg_match( '/^[a-z][a-z0-9-]{0,31}$/D', $provider ) ) {
			throw new RuntimeException( 'Booster cannot save a public lookup preference for this provider.' );
		}
	}

	private function requireProfileId( string $profileId ): void {
		if ( 1 !== preg_match( '/^[A-Za-z0-9_-]{3,64}$/D', $profileId ) ) {
			throw new RuntimeException( 'Booster cannot save this public repository lookup profile.' );
		}
	}
}
