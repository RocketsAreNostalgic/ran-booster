<?php

declare(strict_types=1);

namespace RAN\Tests\Secrets;

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
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Remove only disposable native filesystem entries owned by this fixture.
			unlink( $path );
		}

		self::assertSame( array(), $warnings, 'A failed stat must be handled before reading its array offsets.' );
	}

	public function test_replace_ciphertext_never_falls_back_to_system_temp_directory(): void {
		$before = glob( sys_get_temp_dir() . '/.ran-booster-*' );
		$before = is_array( $before ) ? $before : array();

		$reflection = new ReflectionClass( SecretsFile::class );
		$secrets    = $reflection->newInstanceWithoutConstructor();
		$missing    = sys_get_temp_dir() . '/ran-booster-missing-' . bin2hex( random_bytes( 8 ) ) . '/secrets.json';

		try {
			$reflection->getMethod( 'replace_ciphertext' )->invoke( $secrets, $missing, 'ciphertext' );
			self::fail( 'replace_ciphertext() must fail when the private directory does not exist.' );
		} catch ( SecretsStorageUnavailable $failure ) {
			self::assertSame( 'Could not create a temporary encrypted Booster secrets file.', $failure->getMessage() );
		}

		$after = glob( sys_get_temp_dir() . '/.ran-booster-*' );
		$after = is_array( $after ) ? $after : array();
		self::assertSame( $before, $after );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_zero_inode_is_rejected_in_handle_and_delete_identity_checks(): void {
		require __DIR__ . '/ZeroInodeStatFunctions.php';

		$path = tempnam( sys_get_temp_dir(), 'ran-booster-zero-ino-' );
		self::assertIsString( $path );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Set private mode required by SecretsFile identity checks.
		self::assertTrue( chmod( $path, 0600 ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Acquire native handle to test assert_handle_matches_path against zero-inode stats.
		$handle = fopen( $path, 'r+b' );
		self::assertIsResource( $handle );

		$reflection = new ReflectionClass( SecretsFile::class );
		$secrets    = $reflection->newInstanceWithoutConstructor();
		$stat       = \lstat( $path );
		self::assertIsArray( $stat );

		try {
			try {
				$reflection->getMethod( 'assert_handle_matches_path' )->invoke( $secrets, $handle, $path, 'secrets lock' );
				self::fail( 'assert_handle_matches_path() must reject zero-inode file stats.' );
			} catch ( SecretsStorageUnavailable $failure ) {
				self::assertSame( 'Refusing to use an invalid encrypted Booster secrets lock.', $failure->getMessage() );
			}

			try {
				$expected = array(
					'dev'   => (int) $stat['dev'],
					'ino'   => 0,
					'mode'  => (int) $stat['mode'],
					'uid'   => (int) $stat['uid'],
					'nlink' => (int) $stat['nlink'],
				);
				$reflection->getMethod( 'delete_exact_file' )->invoke( $secrets, $path, $expected, 'Could not remove secrets file.' );
				self::fail( 'delete_exact_file() must reject zero-inode file stats.' );
			} catch ( SecretsStorageUnavailable $failure ) {
				self::assertSame( 'Could not remove secrets file.', $failure->getMessage() );
			}
			self::assertFileExists( $path );
		} finally {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close disposable handle opened by test.
			fclose( $handle );
			if ( is_file( $path ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Remove disposable file owned by this test.
				unlink( $path );
			}
		}
	}
}
