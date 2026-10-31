<?php

declare(strict_types=1);

namespace RAN\Troubleshooting;

if ( ! function_exists( __NAMESPACE__ . '\\__' ) ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- The isolated projection fixture retains WordPress's translation boundary.
	function __( string $text, string $domain = 'default' ): string {
		unset( $domain );

		return $text;
	}
}

if ( ! function_exists( __NAMESPACE__ . '\\get_file_data' ) ) {
	/**
	 * @param array<string,string> $headers
	 * @return array<string,string>
	 */
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- The fixture supplies only the bounded installed-version header read.
	function get_file_data( string $file, array $headers ): array {
		unset( $file, $headers );

		return array( 'Version' => '1.0.0-beta.41' );
	}
}
