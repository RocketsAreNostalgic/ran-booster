<?php

declare(strict_types=1);

// Loaded only inside ManagedReleaseThemeRootTest's isolated processes, where WordPress declares these helpers globally.

if ( ! function_exists( 'get_theme_root' ) ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WordPress function double must retain the host-owned name.
	function get_theme_root( string $stylesheet_or_template = '' ): string {
		return (string) ( $GLOBALS['ran_booster_release_theme_roots'][ $stylesheet_or_template ] ?? $GLOBALS['ran_booster_release_theme_roots'][''] );
	}
}

if ( ! function_exists( 'trailingslashit' ) ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WordPress function double must retain the host-owned name.
	function trailingslashit( string $value ): string {
		return rtrim( $value, '/\\' ) . '/';
	}
}

if ( ! function_exists( 'get_file_data' ) ) {
	/**
	 * Reads the requested headers the way WordPress does for a short file.
	 *
	 * @param array<string, string> $headers
	 * @return array<string, string>
	 */
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WordPress function double must retain the host-owned name.
	function get_file_data( string $file, array $headers ): array {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads a disposable test-owned header file.
		$contents = (string) file_get_contents( $file );
		$data     = array();
		foreach ( $headers as $key => $name ) {
			$data[ $key ] = 1 === preg_match( '/^[ \t\/*#@]*' . preg_quote( $name, '/' ) . ':(.*)$/mi', $contents, $match ) ? trim( $match[1] ) : '';
		}
		return $data;
	}
}
