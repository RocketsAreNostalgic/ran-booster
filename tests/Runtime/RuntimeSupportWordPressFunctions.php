<?php

declare(strict_types=1);

namespace RAN\Runtime;

if ( ! function_exists( __NAMESPACE__ . '\\is_multisite' ) ) {
	function is_multisite(): bool {
		return (bool) ( $GLOBALS['ran_booster_package_mutation_guard_multisite'] ?? false );
	}
}
