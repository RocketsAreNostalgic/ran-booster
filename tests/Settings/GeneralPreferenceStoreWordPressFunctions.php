<?php

declare(strict_types=1);

namespace RAN\Settings;

function get_option( string $name, mixed $default = false ): mixed { // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.defaultFound -- Mirrors the WordPress boundary.
	unset( $name );
	if ( array_key_exists( 'ran_booster_general_preference_cached_option', $GLOBALS ) ) {
		return $GLOBALS['ran_booster_general_preference_cached_option'];
	}
	$raw = $GLOBALS['ran_booster_general_preference_raw'] ?? null;
	$GLOBALS['ran_booster_general_preference_reads'] = ( $GLOBALS['ran_booster_general_preference_reads'] ?? 0 ) + 1;
	if ( 1 === $GLOBALS['ran_booster_general_preference_reads'] && isset( $GLOBALS['ran_booster_general_preference_swap_after_first_read'] ) ) {
		$GLOBALS['ran_booster_general_preference_raw'] = $GLOBALS['ran_booster_general_preference_swap_after_first_read'];
	}
	$value = null === $raw ? $default : maybe_unserialize( $raw );
	$GLOBALS['ran_booster_general_preference_cached_option'] = $value;
	return $value;
}

function maybe_serialize( mixed $value ): string {
	return is_array( $value ) || is_object( $value ) ? serialize( $value ) : (string) $value; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Controlled exact option fixture.
}

function maybe_unserialize( mixed $value ): mixed {
	return is_string( $value ) && str_starts_with( $value, 'a:' ) ? unserialize( $value ) : $value; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- Controlled exact option fixture.
}

function wp_cache_delete( string $key, string $group = '' ): bool {
	if ( 'options' === $group && in_array( $key, array( GeneralPreferenceStore::OPTION_NAME, 'alloptions', 'notoptions' ), true ) ) {
		unset( $GLOBALS['ran_booster_general_preference_cached_option'] );
	}
	$GLOBALS['ran_booster_general_preference_cache'][] = array( $key, $group );
	return true;
}
