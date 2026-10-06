<?php

declare(strict_types=1);

namespace RAN\Tests\Secrets;

// phpcs:disable WordPress.WP.AlternativeFunctions -- Native temporary file proves existence before the injected stat failure.

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use RAN\Secrets\SecretsFile;
use RAN\Secrets\SecretsStorageUnavailable;
use ReflectionClass;

final class SecretsFileStatFailureTest extends TestCase {
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_failed_stat_rejects_existing_file_without_array_offset_warning(): void {
		require __DIR__ . '/FailedStatFunctions.php';

		$path = tempnam( sys_get_temp_dir(), 'ran-booster-stat-' );
		self::assertIsString( $path );
		self::assertFileExists( $path );

		$reflection = new ReflectionClass( SecretsFile::class );
		$secrets    = $reflection->newInstanceWithoutConstructor();
		$reflection->getProperty( 'path' )->setValue( $secrets, $path );
		$warnings = array();
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Regression captures native warnings to ensure failed stat is handled cleanly.
		set_error_handler(
			static function ( int $severity, string $message ) use ( &$warnings ): bool {
				$warnings[] = array( $severity, $message );
				return true;
			}
		);
		try {
			try {
				$reflection->getMethod( 'has_file' )->invoke( $secrets );
				self::fail( 'An unverified secrets file must be rejected.' );
			} catch ( SecretsStorageUnavailable $failure ) {
				self::assertSame( 'Refusing to use an invalid encrypted Booster secrets file.', $failure->getMessage() );
			}
		} finally {
			restore_error_handler();
			unlink( $path );
		}

		self::assertSame( array(), $warnings, 'A failed stat must be handled before reading its array offsets.' );
	}
}
