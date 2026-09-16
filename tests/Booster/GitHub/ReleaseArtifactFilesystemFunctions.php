<?php

declare(strict_types=1);

namespace RAN\Deployment;

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


/** @param resource $stream */
function fclose( $stream ): bool {
	$falseResults = (int) ( $GLOBALS['ran_booster_custody_fclose_false_results'] ?? 0 );
	if ( $falseResults > 0 ) {
		$GLOBALS['ran_booster_custody_fclose_false_results'] = $falseResults - 1;
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Test-only seam must close the real stream while reporting a false result.
		\fclose( $stream );

		return false;
	}

	$remaining = (int) ( $GLOBALS['ran_booster_custody_fclose_failures'] ?? 0 );
	if ( $remaining > 0 ) {
		$GLOBALS['ran_booster_custody_fclose_failures'] = $remaining - 1;
		throw new \RuntimeException( 'Test-only stream close failure.' );
	}

	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Test-only deterministic filesystem seam.
	return \fclose( $stream );
}


function unlink( string $filename ): bool {
	if ( ! empty( $GLOBALS['ran_booster_custody_unlink_throw'] ) ) {
		throw new \RuntimeException( 'Test-only Core-copy removal failure.' );
	}

	// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test-only deterministic filesystem seam.
	return \unlink( $filename );
}
