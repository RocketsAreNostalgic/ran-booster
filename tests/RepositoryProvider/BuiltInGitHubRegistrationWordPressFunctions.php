<?php

declare(strict_types=1);

namespace RAN;

if ( ! function_exists( __NAMESPACE__ . '\\add_action' ) ) {
	function add_action( string $hook, callable $callback, int $priority = 10, int $acceptedArgs = 1 ): bool {
		unset( $hook, $callback, $priority, $acceptedArgs );

		return true;
	}
}
