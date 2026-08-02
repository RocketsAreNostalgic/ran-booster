<?php

declare(strict_types=1);

namespace RAN\WordPress;

function wp_json_encode( mixed $value ): string|false {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- This is the isolated test replacement for WordPress's encoder.
	return json_encode( $value );
}
