<?php

declare(strict_types=1);

namespace RAN\Tests\WordPress;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use ZipArchive;

final class ReleaseSwitchProofAttestationTest extends TestCase {

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_archive_bytes_match_requires_non_false_string_reads(): void {
		define( 'RAN_BOOSTER_RELEASE_SWITCH_PROOF_LIBRARY_ONLY', true );
		require_once __DIR__ . '/native-release-install-proof/ReleaseSwitchProof.php';

		$directory = sys_get_temp_dir() . '/ran-release-attest-' . bin2hex( random_bytes( 8 ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Isolated temporary directory owned by this test.
		self::assertTrue( mkdir( $directory, 0700 ) );
		$zip_path  = $directory . '/core.zip';
		$file_path = $directory . '/present.php';

		try {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write fixture bytes owned by this test.
			self::assertNotFalse( file_put_contents( $file_path, "<?php\nreturn 1;\n" ) );
			$zip = new ZipArchive();
			self::assertTrue( $zip->open( $zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) );
			self::assertTrue( $zip->addFromString( 'ran-booster/present.php', "<?php\nreturn 1;\n" ) );
			self::assertTrue( $zip->addFromString( 'ran-booster/other.php', "<?php\nreturn 2;\n" ) );
			self::assertTrue( $zip->close() );

			$reader = new ZipArchive();
			self::assertTrue( $reader->open( $zip_path, ZipArchive::RDONLY ) );
			try {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read local fixture file bytes for attestation comparison.
				$present_bytes = file_get_contents( $file_path );
				$entry_bytes   = $reader->getFromName( 'ran-booster/present.php' );
				$other_bytes   = $reader->getFromName( 'ran-booster/other.php' );
				$missing_entry = $reader->getFromName( 'ran-booster/missing.php' );

				self::assertTrue( \RAN_Booster_ReleaseSwitchProof::archive_bytes_match( $entry_bytes, $present_bytes ) );
				self::assertFalse( \RAN_Booster_ReleaseSwitchProof::archive_bytes_match( $other_bytes, $present_bytes ) );
				self::assertFalse( \RAN_Booster_ReleaseSwitchProof::archive_bytes_match( $missing_entry, $present_bytes ) );
				self::assertFalse( \RAN_Booster_ReleaseSwitchProof::archive_bytes_match( $entry_bytes, false ) );
				self::assertFalse( \RAN_Booster_ReleaseSwitchProof::archive_bytes_match( $missing_entry, false ) );
			} finally {
				$reader->close();
			}
		} finally {
			foreach ( array( $file_path, $zip_path ) as $path ) {
				if ( is_file( $path ) ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Remove fixture files owned by this test.
					unlink( $path );
				}
			}
			if ( is_dir( $directory ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Remove temporary directory owned by this test.
				rmdir( $directory );
			}
		}
	}
}
