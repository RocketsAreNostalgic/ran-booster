<?php

declare(strict_types=1);

namespace RAN\RepositoryProvider;

if ( ! function_exists( __NAMESPACE__ . '\\__' ) ) {
	function __( string $text, string $domain = 'default' ): string {
		unset( $domain );

		return $text;
	}
}

if ( ! function_exists( __NAMESPACE__ . '\\_n' ) ) {
	function _n( string $single, string $plural, int $number, string $domain = 'default' ): string {
		unset( $domain );

		return 1 === $number ? $single : $plural;
	}
}
