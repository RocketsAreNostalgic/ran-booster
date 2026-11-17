<?php

declare(strict_types=1);

namespace RAN\Tests\Logging;

use PHPUnit\Framework\TestCase;
use RAN\Logging\BoosterLogger;
use RAN\Logging\TemporaryDebugCapture;
use RAN\RepositoryProvider\ProviderFailure;
use RAN\Troubleshooting\SupportReport;

require_once __DIR__ . '/LoggingWordPressFunctions.php';

final class FailureSinkPrivacyTest extends TestCase {
	public function test_real_capture_and_support_projection_keep_their_distinct_boundaries(): void {
		$directory = sys_get_temp_dir() . '/ran-failure-privacy-' . bin2hex( random_bytes( 8 ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Isolated capture fixture.
		self::assertTrue( mkdir( $directory, 0700 ) );
		try {
			$capture = new TemporaryDebugCapture( $directory . '/secrets.json' );
			$capture->start();
			BoosterLogger::configure_capture( $capture );
			$unsafe = new class() {
				public function __toString(): string {
					throw new \RuntimeException( 'Must not convert a diagnostic.' );
				}
			};
			foreach ( array( 'secret_orchid', "api.error\n", 'api%2eerror', str_repeat( 'a', 64 ) . '_secret', 123, $unsafe ) as $value ) {
				BoosterLogger::log_failure(
					'attempt finished',
					new ProviderFailure( 'http_4xx_other', source_code: $value ),
					array(
						'attempt_id' => 12,
						'token'      => 'private-sentinel',
					)
				);
			}
			BoosterLogger::log_failure( 'attempt finished', new ProviderFailure( 'http_4xx_other', source_code: 'api.abuse_detected' ), array( 'attempt_id' => 12 ) );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read this test's capture bytes.
			$bytes = file_get_contents( $directory . '/ran-booster-debug.php' );
			self::assertIsString( $bytes );
			self::assertStringContainsString( 'api.abuse_detected', $bytes );
			self::assertSame( 1, substr_count( $bytes, 'source_code' ) );
			foreach ( array( 'private-sentinel', 'secret_orchid', 'api%2eerror', str_repeat( 'a', 64 ) . '_secret' ) as $sentinel ) {
				self::assertStringNotContainsString( $sentinel, $bytes );
			}
			$entries = $capture->snapshot()['entries'];
			$line    = end( $entries )['line'];
			$export  = SupportReport::safe_log( $line );
			self::assertNotNull( $export );
			self::assertStringContainsString( 'attempt=', $export );
			self::assertStringNotContainsString( 'api.abuse_detected', $export );
		} finally {
			BoosterLogger::configure_capture( null );
			$files = glob( $directory . '/*' );
			foreach ( false === $files ? array() : $files as $file ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Remove this test's capture files.
				unlink( $file );
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Remove this test's private directory.
			rmdir( $directory );
		}
	}
}
