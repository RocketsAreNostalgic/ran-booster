<?php

declare(strict_types=1);

namespace RAN\Tests\Support;

// Direct local filesystem operations inspect and remove the isolated capture under test.

use RAN\Logging\BoosterLogger;
use RAN\Logging\TemporaryDebugCapture;

require_once __DIR__ . '/../Logging/LoggingWordPressFunctions.php';

/** Captures the lines Booster would log, so a test can assert what a boundary records. */
trait CapturesBoosterLog {

	private ?string $log_directory = null;

	private function start_log_capture(): void {
		$this->log_directory = sys_get_temp_dir() . '/ran-booster-log-' . bin2hex( random_bytes( 8 ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Native I/O controls the isolated test fixture without requiring WordPress filesystem bootstrap.
		mkdir( $this->log_directory, 0700 );
		$capture = new TemporaryDebugCapture( $this->log_directory . '/secrets.json', static fn(): int => strtotime( '2026-07-23T12:00:00Z' ) );
		$capture->start();
		BoosterLogger::configure_capture( $capture );
	}

	/** @return list<string> */
	private function captured_log_lines(): array {
		$capture = new TemporaryDebugCapture( $this->log_directory . '/secrets.json', static fn(): int => strtotime( '2026-07-23T12:00:00Z' ) );

		return array_values( array_map( static fn ( array $entry ): string => (string) $entry['line'], $capture->snapshot()['entries'] ) );
	}

	private function stop_log_capture(): void {
		BoosterLogger::configure_capture( null );
		if ( null === $this->log_directory ) {
			return;
		}
		foreach ( array( 'ran-booster-debug.php', 'ran-booster-debug.php.lock' ) as $name ) {
			if ( is_file( $this->log_directory . '/' . $name ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Native I/O controls the isolated test fixture without requiring WordPress filesystem bootstrap.
				unlink( $this->log_directory . '/' . $name );
			}
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Native I/O controls the isolated test fixture without requiring WordPress filesystem bootstrap.
		rmdir( $this->log_directory );
		$this->log_directory = null;
	}
}
