<?php

declare(strict_types=1);

use RAN\Admin\CredentialExpiryObservationStore;
use RAN\Admin\PublicRepositoryLookupProfileStore;

// Called only by the existing disposable installed-archive database proof.
return static function ( wpdb $database ): void {
	if ( ! defined( 'DB_USER' ) || ! defined( 'DB_PASSWORD' ) || ! defined( 'DB_NAME' ) || ! defined( 'DB_HOST' ) ) {
		throw new RuntimeException( 'Option SQL proof requires installed WordPress database configuration.' );
	}
	$other  = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
	$saved  = array();
	$expect = static function ( bool $condition ): void {
		if ( ! $condition ) {
			throw new RuntimeException( 'Concurrent option update lost a distinct entry or failed to refresh the cached snapshot.' );
		}
	};
	try {
		foreach ( array( CredentialExpiryObservationStore::OPTION_NAME, PublicRepositoryLookupProfileStore::OPTION_NAME ) as $option ) {
			$saved[ $option ] = get_option( $option, null );
			foreach ( array( false, true ) as $exists ) {
				delete_option( $option );
				$expiry   = CredentialExpiryObservationStore::OPTION_NAME === $option;
				$original = $expiry ? array(
					'version'  => 1,
					'profiles' => array(),
				) : array();
				if ( $exists ) {
					add_option( $option, $original, '', false );
				}
				// Prime both the existing-value and missing-option caches before interleaving.
				get_option( $option );
				$replacement = $expiry
					? array(
						'version'  => 1,
						'profiles' => array( 'bb' => array( 'other-profile' => array( 'manual_expires_on' => '2028-01-01' ) ) ),
					)
					: array( 'bb' => 'other-profile' );
				$raced       = false;
				$race        = static function ( string $sql ) use ( $other, $database, $option, $exists, $replacement, &$raced, $expect ): string {
					if ( $raced || ! str_contains( $sql, $option ) || ! preg_match( '/^(UPDATE|INSERT)/', $sql ) ) {
						return $sql;
					}
					$raced = true;
					$query = $exists
						? $other->prepare( 'UPDATE %i SET option_value = %s WHERE option_name = %s', $database->options, maybe_serialize( $replacement ), $option )
						: $other->prepare( "INSERT INTO %i (option_name, option_value, autoload) VALUES (%s, %s, 'off')", $database->options, $option, maybe_serialize( $replacement ) );
					// Deliberately leave request A's cached document stale: a failed CAS must refresh it.
					$expect( null !== $query && 1 === $other->query( $query ) );
					return $sql;
				};
				add_filter( 'query', $race );
				try {
					if ( $expiry ) {
						$store = new CredentialExpiryObservationStore();
						$store->set_manual_expiry( 'gh', 'public-profile', '2027-01-01' );
						$expect( array( 'manual_expires_on' => '2028-01-01' ) === $store->get( 'bb', 'other-profile' ) );
						$expect( array( 'manual_expires_on' => '2027-01-01' ) === $store->get( 'gh', 'public-profile' ) );
					} else {
						$store = new PublicRepositoryLookupProfileStore();
						$store->set( 'gh', 'public-profile' );
						$expect( 'other-profile' === $store->get( 'bb' ) && 'public-profile' === $store->get( 'gh' ) );
					}
					$expect( $raced );
				} finally {
					remove_filter( 'query', $race );
				}
			}
			// A matching cached value is not proof that the requested preference is still stored.
			get_option( $option );
			$changed       = $expiry
				? array(
					'version'  => 1,
					'profiles' => array( 'gh' => array( 'public-profile' => array( 'manual_expires_on' => '2029-01-01' ) ) ),
				)
				: array( 'gh' => 'changed-profile' );
			$changed_query = $other->prepare( 'UPDATE %i SET option_value = %s WHERE option_name = %s', $database->options, maybe_serialize( $changed ), $option );
			$expect( null !== $changed_query && 1 === $other->query( $changed_query ) );
			if ( $expiry ) {
				( new CredentialExpiryObservationStore() )->set_manual_expiry( 'gh', 'public-profile', '2027-01-01' );
			} else {
				( new PublicRepositoryLookupProfileStore() )->set( 'gh', 'public-profile' );
			}
			wp_cache_delete( $option, 'options' );
			$stored = get_option( $option );
			$expect( is_array( $stored ) && ( $expiry ? '2027-01-01' === ( $stored['profiles']['gh']['public-profile']['manual_expires_on'] ?? null ) : 'public-profile' === ( $stored['gh'] ?? null ) ) );

		}
	} finally {
		foreach ( $saved as $option => $value ) {
			delete_option( $option );
			if ( null !== $value ) {
				add_option( $option, $value, '', false );
			}
		}
		$other->close();
	}
};
