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

	public function set( string $provider, ?string $profile_id ): void {
		$this->require_provider_id( $provider );
		if ( null !== $profile_id ) {
			$this->require_profile_id( $profile_id );
		}

		$profiles = $this->all();
		if ( null === $profile_id ) {
			unset( $profiles[ $provider ] );
		} else {
			$profiles[ $provider ] = $profile_id;
		}
		ksort( $profiles );

		if ( ! $this->write_option( $profiles ) && $profiles !== $this->all() ) {
			throw new RuntimeException( 'Booster could not save the public repository lookup preference.' );
		}
	}

	/**
	 * @return array<string, string>
	 */
	protected function read_option(): array {
		$value = get_option( self::OPTION_NAME, array() );

		return is_array( $value ) ? $value : array();
	}

	/**
	 * @param array<string, string> $profiles Provider-to-profile mapping.
	 */
	protected function write_option( array $profiles ): bool {
		return update_option( self::OPTION_NAME, $profiles, false );
	}

	/**
	 * @return array<string, string>
	 */
	private function all(): array {
		$profiles = array();

		foreach ( $this->read_option() as $provider => $profile_id ) {
			if ( ! is_string( $provider ) || ! is_string( $profile_id ) ) {
				continue;
			}
			if ( 1 !== preg_match( '/^[a-z][a-z0-9-]{0,31}$/D', $provider )
				|| 1 !== preg_match( '/^[A-Za-z0-9_-]{3,64}$/D', $profile_id ) ) {
				continue;
			}

			$profiles[ $provider ] = $profile_id;
		}

		return $profiles;
	}

	private function require_provider_id( string $provider ): void {
		if ( 1 !== preg_match( '/^[a-z][a-z0-9-]{0,31}$/D', $provider ) ) {
			throw new RuntimeException( 'Booster cannot save a public lookup preference for this provider.' );
		}
	}

	private function require_profile_id( string $profile_id ): void {
		if ( 1 !== preg_match( '/^[A-Za-z0-9_-]{3,64}$/D', $profile_id ) ) {
			throw new RuntimeException( 'Booster cannot save this public repository lookup profile.' );
		}
	}
}
