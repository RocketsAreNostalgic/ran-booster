<?php

declare(strict_types=1);

namespace RAN\Activity;

/** Test-local WordPress boundaries avoid altering unrelated test symbol worlds. */
function get_option( string $name ): mixed {
	$failure = $GLOBALS['ran_booster_activity_option_failure'] ?? null;
	if ( $failure instanceof \Throwable ) {
		throw $failure;
	}
	return $GLOBALS['ran_booster_activity_options'][ $name ] ?? false;
}

function update_option( string $name, mixed $value, bool $autoload ): bool {
	if ( $autoload ) {
		throw new \RuntimeException( 'Activity options must not autoload.' );
	}
	$GLOBALS['ran_booster_activity_options'][ $name ] = $value;
	return true;
}

function current_time( string $type ): string {
	return 'mysql' === $type ? '2026-10-12 17:00:00' : '';
}

function wp_json_encode( mixed $value ): string|false {
	return json_encode( $value ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Test spy implements the native WordPress wrapper.
}
