<?php

declare(strict_types=1);

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( mixed $value, int $flags = 0, int $depth = 512 ): string|false {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Test shim implements WordPress JSON behavior.
		return json_encode( $value, $flags, $depth );
	}
}
