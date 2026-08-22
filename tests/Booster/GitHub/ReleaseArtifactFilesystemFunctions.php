<?php

declare(strict_types=1);

namespace RAN\Booster\GitHub;

function random_bytes( int $length ): string {
	$value = $GLOBALS['ran_booster_custody_random_bytes'] ?? null;

	return is_string( $value ) ? $value : \random_bytes( $length );
}

function mkdir( string $directory, int $permissions = 0777, bool $recursive = false, mixed $context = null ): bool {
	if ( ! empty( $GLOBALS['ran_booster_custody_mkdir_failure'] ) ) {
		return false;
	}

	// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Test-only deterministic filesystem seam.
	return null === $context ? @\mkdir( $directory, $permissions, $recursive ) : @\mkdir( $directory, $permissions, $recursive, $context );
}

/** @return resource|false */
function fopen( string $filename, string $mode, bool $useIncludePath = false, mixed $context = null ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Test-only deterministic filesystem seam.
	$stream = null === $context ? \fopen( $filename, $mode, $useIncludePath ) : \fopen( $filename, $mode, $useIncludePath, $context );
	$hook   = 'rb' === $mode
		? $GLOBALS['ran_booster_custody_after_source_open'] ?? null
		: $GLOBALS['ran_booster_custody_after_destination_open'] ?? null;
	if ( is_callable( $hook ) ) {
		$hook( $filename, $stream );
	}

	return $stream;
}
