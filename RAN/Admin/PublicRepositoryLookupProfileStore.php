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
		$profiles = $this->all( $this->read_option() );

		return $profiles[ $provider ] ?? null;
	}

	public function set( string $provider, ?string $profile_id ): void {
		$this->require_provider_id( $provider );
		if ( null !== $profile_id ) {
			$this->require_profile_id( $profile_id );
		}

		for ( $attempt = 0; $attempt < 5; ++$attempt ) {
			$expected = $this->read_option( true );
			$profiles = $this->all( $expected );
			if ( null === $profile_id ) {
				unset( $profiles[ $provider ] );
			} else {
				$profiles[ $provider ] = $profile_id;
			}
			ksort( $profiles );
			if ( $expected === $profiles || $this->write_option( $expected, $profiles ) ) {
				return;
			}
		}
		throw new RuntimeException( 'Booster could not save the public repository lookup preference.' );
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
	 * @return array<string, string>
	 */
	private function all( mixed $document ): array {
		$profiles = array();

		foreach ( is_array( $document ) ? $document : array() as $provider => $profile_id ) {
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
