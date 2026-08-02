<?php

declare(strict_types=1);

namespace RAN\AddOn\ReleaseTracking;

if ( ! function_exists( __NAMESPACE__ . '\\apply_filters' ) ) {
	function apply_filters( string $hook, mixed $value, mixed ...$arguments ): mixed {
		unset( $hook, $arguments );

		return $value;
	}
}

if ( ! function_exists( __NAMESPACE__ . '\\get_option' ) ) {
	function get_option( string $name, mixed $default = false ): mixed {
		return $GLOBALS['ran_booster_prospective_options'][ $name ] ?? $default;
	}
}
