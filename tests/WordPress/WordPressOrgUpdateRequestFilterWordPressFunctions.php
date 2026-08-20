<?php

declare(strict_types=1);

namespace RAN\WordPress;

function wp_json_encode( mixed $value ): string|false {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- This is the isolated test replacement for WordPress's encoder.
	return json_encode( $value );
}

function wp_parse_url( string $url ): array|false {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- This is the isolated test replacement for WordPress's parser.
	return parse_url( $url );
}
