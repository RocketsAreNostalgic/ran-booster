<?php

declare(strict_types=1);

namespace RAN\Deployment;

// Loaded only inside AdmittedBranchPathsTest's isolated processes.
if ( ! function_exists( __NAMESPACE__ . '\\get_theme_root' ) ) {
	function get_theme_root( string $stylesheet_or_template = '' ): string {
		return (string) ( $GLOBALS['ran_booster_paths_theme_roots'][ $stylesheet_or_template ] ?? $GLOBALS['ran_booster_paths_theme_roots'][''] );
	}
}

if ( ! function_exists( __NAMESPACE__ . '\\get_option' ) ) {
	// phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.defaultFound -- Bounded WordPress double with the WordPress parameter signature; no package is active.
	function get_option( string $option, mixed $default = false ): mixed {
		unset( $option );
		return $default;
	}
}

if ( ! function_exists( __NAMESPACE__ . '\\trailingslashit' ) ) {
	function trailingslashit( string $value ): string {
		return rtrim( $value, '/\\' ) . '/';
	}
}
