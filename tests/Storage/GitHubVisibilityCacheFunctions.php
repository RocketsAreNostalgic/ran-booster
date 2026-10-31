<?php

declare(strict_types=1);

namespace RAN\Storage;

/** Exact namespaced cache shim used only by the storage persistence fixture. */
function wp_cache_delete( string $key, string $group = '' ): bool {
	$GLOBALS['ran_booster_attempt_cache_deletes'][] = array( $key, $group );
	unset( $GLOBALS['ran_booster_storage_test_persistent_cache'][ $group . ':' . $key ] );
	return true;
}
