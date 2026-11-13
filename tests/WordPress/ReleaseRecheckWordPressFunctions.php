<?php

declare(strict_types=1);

namespace RAN\WordPress;

if ( ! function_exists( __NAMESPACE__ . '\\delete_site_transient' ) ) {
	function delete_site_transient( string $name ): bool {
		if ( true === ( $GLOBALS['ran_booster_recheck_failure'] ?? false ) ) {
			throw new \RuntimeException( 'Transient storage failed.' );
		}
		$GLOBALS['ran_booster_recheck_deleted'][] = $name;

		return true;
	}
}
