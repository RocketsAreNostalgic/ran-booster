<?php

declare(strict_types=1);

/** Test-owned signed-in user without a stored notice acknowledgement. */
function get_current_user_id(): int { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WordPress fixture signature.
	return 1;
}

function get_user_meta( int $user_id, string $key, bool $single ): mixed { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WordPress fixture signature.
	unset( $user_id, $key, $single );

	return '';
}
