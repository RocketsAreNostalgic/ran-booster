<?php

declare(strict_types=1);

namespace RAN\Tests\Quality;

use PHPUnit\Framework\TestCase;

/**
 * Concurrent checkouts only stay apart if PHP itself, not just child processes, uses the bootstrap's directory.
 */
final class TestTemporaryDirectoryIsolationTest extends TestCase {

	public function test_this_process_uses_its_own_temporary_directory(): void {
		$directory = sys_get_temp_dir();

		self::assertMatchesRegularExpression( '~/ran-booster-tests-' . getmypid() . '-[0-9a-f]{8}\z~', $directory );
		self::assertDirectoryExists( $directory );
		self::assertSame( $directory, getenv( 'TMPDIR' ) );
	}
}
