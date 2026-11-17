<?php

declare(strict_types=1);

namespace RAN\Admin\Interaction;

/** Test-only database double; the WordPress/MySQL proof covers the actual SQL. */
final class InteractionMetaDatabaseDouble {
	public string $usermeta = 'test_usermeta';
	/** @param mixed ...$args */
	public function prepare( string $query, mixed ...$args ): string {
		unset( $query );
		return (string) wp_json_encode( $args );
	}
	public function query( string $query ): int|false {
		if ( true === ( $GLOBALS['ran_booster_interaction_test_delete_fails'] ?? false ) ) {
			return false;
		}
		$args = json_decode( $query, true );
		if ( ! is_array( $args ) || ! is_int( $args[1] ?? null ) || ! is_string( $args[2] ?? null ) ) {
			return false;
		}
		$user_id = $args[1];
		$key     = $args[2];
		$held    = $GLOBALS['ran_booster_interaction_test_meta'][ $user_id ][ $key ] ?? null;
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- The fixture compares WordPress-formatted metadata; no input is deserialized.
		if ( null === $held || ( is_array( $held ) ? serialize( $held ) : $held ) !== ( $args[3] ?? null ) ) {
			return 0;
		}
		unset( $GLOBALS['ran_booster_interaction_test_meta'][ $user_id ][ $key ] );
		return 1;
	}
}
