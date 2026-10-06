<?php

declare(strict_types=1);

namespace RAN\Tests\WordPress;

use PHPUnit\Framework\TestCase;

final class Phase44ArchiveExtractionTest extends TestCase {
	public function test_native_archive_entry_validation_precedes_extraction(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read the actual opt-in harness without launching its WordPress/MySQL process.
		$source = file_get_contents( dirname( __DIR__ ) . '/Integration/phase-4.4-core-disposable-harness.php' );
		self::assertIsString( $source );
		$start = strpos( $source, 'function ran_booster_phase44_extract_core(' );
		$end   = strpos( $source, 'function ran_booster_phase44_config(' );
		self::assertIsInt( $start );
		self::assertIsInt( $end );
		// phpcs:ignore Squiz.PHP.Eval.Discouraged -- Evaluate only the bounded extraction function from this repository's own harness, avoiding its destructive launcher.
		eval( substr( $source, $start, $end - $start ) );
		$root = sys_get_temp_dir() . '/ran-phase44-archive-' . bin2hex( random_bytes( 8 ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Native ZIP proof owns this unique private temporary directory.
		self::assertTrue( mkdir( $root, 0700 ) );
		try {
			foreach ( array( null, 'other.php', 'ran-booster/../escape.php', 'ran-booster/back\\slash.php' ) as $invalid ) {
				$zip = new \ZipArchive();
				self::assertTrue( $zip->open( $root . '/input.zip', \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) );
				self::assertTrue( $zip->addFromString( 'ran-booster/ran-booster.php', '<?php' ) );
				if ( null !== $invalid ) {
					self::assertTrue( $zip->addFromString( $invalid, '<?php' ) );
				}
				self::assertTrue( $zip->close() );
				try {
					\ran_booster_phase44_extract_core( $root . '/input.zip', $root . '/output' ); // @phpstan-ignore function.notFound (This exact function was evaluated from the harness above without executing its destructive launcher.)
					self::assertNull( $invalid, 'Unsafe native ZIP entry reached extraction.' );
					self::assertFileExists( $root . '/output/ran-booster/ran-booster.php' );
					// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Remove the exact owned fixture extracted by this proof.
					unlink( $root . '/output/ran-booster/ran-booster.php' );
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Remove the exact owned fixture directory.
					rmdir( $root . '/output/ran-booster' );
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Remove the exact owned extraction directory.
					rmdir( $root . '/output' );
				} catch ( \RuntimeException $error ) {
					self::assertNotNull( $invalid );
					self::assertSame( 'Core ZIP path is unsafe.', $error->getMessage() );
					self::assertDirectoryDoesNotExist( $root . '/output' );
				}
			}
		} finally {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Remove the exact native ZIP owned by this test.
			unlink( $root . '/input.zip' );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Remove the unique private temporary directory owned by this test.
			rmdir( $root );
		}
	}
}
