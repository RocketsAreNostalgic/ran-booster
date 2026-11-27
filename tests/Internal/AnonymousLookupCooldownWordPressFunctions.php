<?php

declare(strict_types=1);

namespace RAN\Internal;

// Namespace-local site-transient doubles, so other tests' global doubles never collide. They act only
// while a test has opted in by setting the store; every other test sees no cooldown and records none.
function get_site_transient( string $name ): mixed {
	return is_array( $GLOBALS['ran_booster_cooldown_transients'] ?? null ) ? $GLOBALS['ran_booster_cooldown_transients'][ $name ]['value'] ?? false : false;
}

function set_site_transient( string $name, mixed $value, int $expiration = 0 ): bool {
	if ( ! is_array( $GLOBALS['ran_booster_cooldown_transients'] ?? null ) ) {
		return false;
	}
	$GLOBALS['ran_booster_cooldown_transients'][ $name ] = array(
		'value'      => $value,
		'expiration' => $expiration,
	);

	return true;
}
