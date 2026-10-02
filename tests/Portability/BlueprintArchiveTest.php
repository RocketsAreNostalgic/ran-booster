<?php

declare(strict_types=1);

namespace Tests\Portability;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RAN\Portability\BlueprintArchive;
use RAN\Portability\BlueprintCredential;
use RAN\Portability\BlueprintPackage;
use RAN\Portability\PackageBlueprint;
use ZipArchive;

#[CoversClass( BlueprintArchive::class )]
final class BlueprintArchiveTest extends TestCase {

	private string $file;

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this exact lifecycle override name.
	protected function setUp(): void {
		$this->file = sys_get_temp_dir() . '/ran-booster-' . bin2hex( random_bytes( 8 ) ) . '.zip';
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this exact lifecycle override name.
	protected function tearDown(): void {
		if ( is_file( $this->file ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test-only temporary archive cleanup.
			unlink( $this->file );
		}
	}

	public function test_package_only_archive_has_one_plain_entry_and_round_trips(): void {
		$blueprint = new PackageBlueprint( array( $this->package() ) );
		$archive   = new BlueprintArchive();

		$archive->write_to( $this->file, $blueprint, null );

		$zip = $this->open();
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- ZipArchive exposes the native numFiles property.
		self::assertSame( 1, $zip->numFiles );
		self::assertSame( BlueprintArchive::ENTRY, $zip->getNameIndex( 0 ) );
		self::assertSame( ZipArchive::EM_NONE, $zip->statIndex( 0 )['encryption_method'] );
		$zip->close();
		self::assertSame( $blueprint->canonical_json(), $archive->read_from( $this->file, '' )->canonical_json() );
	}

	public function test_credential_archive_uses_aes256_and_round_trips(): void {
		$this->require_aes();
		$blueprint = $this->credential_blueprint();
		$password  = 'correct-horse-battery-staple';
		$archive   = new BlueprintArchive();

		$archive->write_to( $this->file, $blueprint, $password );
		$zip = $this->open();
		self::assertSame( ZipArchive::EM_AES_256, $zip->statIndex( 0 )['encryption_method'] );
		$zip->close();
		self::assertSame( $blueprint->canonical_json(), $archive->read_from( $this->file, $password )->canonical_json() );
	}

	public function test_write_rejects_password_and_credential_mismatch(): void {
		$archive = new BlueprintArchive();
		foreach ( array(
			array( new PackageBlueprint( array( $this->package() ) ), 'unneeded-password-value' ),
			array( $this->credential_blueprint(), null ),
			array( $this->credential_blueprint(), 'too-short' ),
			array( $this->credential_blueprint(), "valid-length-password\n" ),
		) as [ $blueprint, $password ] ) {
			try {
				$archive->write_to( $this->file, $blueprint, $password );
				self::fail( 'Expected an invalid archive password choice.' );
			} catch ( InvalidArgumentException $exception ) {
				self::assertSame( 'The portability archive could not be written.', $exception->getMessage() );
			}
		}
	}

	public function test_credential_bearing_blueprint_is_redacted_from_write_trace(): void {
		try {
			( new BlueprintArchive() )->write_to( $this->file, $this->credential_blueprint(), null );
			self::fail( 'Expected archive write failure.' );
		} catch ( InvalidArgumentException $exception ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- Test-only inspection of redacted exception arguments.
			self::assertStringNotContainsString( 'token-canary-value', var_export( $exception->getTrace(), true ) );
		}
	}

	public function test_read_rejects_missing_and_wrong_passwords_without_leaking_details(): void {
		$this->require_aes();
		$archive = new BlueprintArchive();
		$archive->write_to( $this->file, $this->credential_blueprint(), 'correct-horse-battery-staple' );

		foreach ( array( null, 'wrong-password-value-long-enough' ) as $password ) {
			try {
				$archive->read_from( $this->file, $password );
				self::fail( 'Expected archive read failure.' );
			} catch ( InvalidArgumentException $exception ) {
				self::assertSame( 'The portability archive is invalid.', $exception->getMessage() );
				self::assertStringNotContainsString( 'password', strtolower( $exception->getMessage() ) );
			}
		}
	}

	public function test_rejects_plain_credentials_and_encrypted_empty_payload(): void {
		$this->require_aes();
		$archive = new BlueprintArchive();
		$this->write_raw( $this->credential_blueprint()->canonical_json(), ZipArchive::EM_NONE, null );
		$this->assert_invalid( fn() => $archive->read_from( $this->file, null ) );

		$this->write_raw( ( new PackageBlueprint( array( $this->package() ) ) )->canonical_json(), ZipArchive::EM_AES_256, 'correct-horse-battery-staple' );
		$this->assert_invalid( fn() => $archive->read_from( $this->file, 'correct-horse-battery-staple' ) );
	}

	public function test_rejects_unsupported_layouts_encryption_and_content(): void {
		$archive = new BlueprintArchive();
		$this->write_raw( '{}', ZipArchive::EM_NONE, null, 'other.json' );
		$this->assert_invalid( fn() => $archive->read_from( $this->file, null ) );
		$this->write_raw( '{}', ZipArchive::EM_NONE, null );
		$this->assert_invalid( fn() => $archive->read_from( $this->file, null ) );
		$this->write_raw( '{}', ZipArchive::EM_NONE, null, BlueprintArchive::ENTRY . '/' );
		$this->assert_invalid( fn() => $archive->read_from( $this->file, null ) );

		$this->write_raw( ( new PackageBlueprint( array() ) )->canonical_json(), ZipArchive::EM_TRAD_PKWARE, 'correct-horse-battery-staple' );
		$this->assert_invalid( fn() => $archive->read_from( $this->file, 'correct-horse-battery-staple' ) );

		$zip = new ZipArchive();
		self::assertTrue( $zip->open( $this->file, ZipArchive::CREATE | ZipArchive::OVERWRITE ) );
		self::assertTrue( $zip->addFromString( BlueprintArchive::ENTRY, ( new PackageBlueprint( array() ) )->canonical_json() ) );
		self::assertTrue( $zip->addFromString( 'extra.json', '{}' ) );
		self::assertTrue( $zip->close() );
		$this->assert_invalid( fn() => $archive->read_from( $this->file, null ) );
	}

	public function test_unknown_blueprint_version_fails_without_rewriting_the_archive(): void {
		$json = str_replace(
			'"version":1',
			'"version":2',
			( new PackageBlueprint( array( $this->package() ) ) )->canonical_json()
		);
		$this->write_raw( $json, ZipArchive::EM_NONE, null );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test-only immutable archive evidence.
		$before = file_get_contents( $this->file );

		$this->assert_invalid( fn() => ( new BlueprintArchive() )->read_from( $this->file, null ) );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test-only immutable archive evidence.
		self::assertSame( $before, file_get_contents( $this->file ) );
	}

	public function test_rejects_oversized_and_tampered_archives(): void {
		$archive = new BlueprintArchive();
		$this->write_raw( str_repeat( 'x', BlueprintArchive::MAX_BYTES + 1 ), ZipArchive::EM_NONE, null );
		$this->assert_invalid( fn() => $archive->read_from( $this->file, null ) );

		$archive->write_to( $this->file, new PackageBlueprint( array( $this->package() ) ), null );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test-only archive corruption.
		$bytes = file_get_contents( $this->file );
		self::assertIsString( $bytes );
		$bytes[30] = chr( ord( $bytes[30] ) ^ 1 );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test-only archive corruption.
		self::assertNotFalse( file_put_contents( $this->file, $bytes ) );
		$this->assert_invalid( fn() => $archive->read_from( $this->file, null ) );
	}

	public function test_write_normalizes_libzip_warnings(): void {
		$warnings = array();
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Test assertion captures warnings that the codec must contain.
		set_error_handler(
			// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- The PHP error-handler callback receives severity and message; this fixture only records that a warning occurred.
			static function ( int $severity, string $message ) use ( &$warnings ): bool {
				$warnings[] = compact( 'severity', 'message' );
				return true;
			}
		);
		try {
			$this->expectExceptionMessage( 'The portability archive could not be written.' );
			( new BlueprintArchive() )->write_to( sys_get_temp_dir(), new PackageBlueprint( array() ), null );
		} finally {
			restore_error_handler();
			self::assertSame( array(), $warnings );
		}
	}

	private function write_raw( string $json, int $encryption, ?string $password, string $name = BlueprintArchive::ENTRY ): void {
		$zip = new ZipArchive();
		self::assertTrue( $zip->open( $this->file, ZipArchive::CREATE | ZipArchive::OVERWRITE ) );
		self::assertTrue( $zip->addFromString( $name, $json ) );
		if ( ZipArchive::EM_NONE !== $encryption ) {
			self::assertTrue( $zip->setPassword( (string) $password ) );
			self::assertTrue( $zip->setEncryptionName( $name, $encryption ) );
		}
		self::assertTrue( $zip->close() );
	}

	private function open(): ZipArchive {
		$zip = new ZipArchive();
		self::assertTrue( $zip->open( $this->file ) );

		return $zip;
	}

	private function assert_invalid( callable $read ): void {
		try {
			$read();
			self::fail( 'Expected archive rejection.' );
		} catch ( InvalidArgumentException $exception ) {
			self::assertSame( 'The portability archive is invalid.', $exception->getMessage() );
		}
	}

	private function require_aes(): void {
		if ( ! ZipArchive::isEncryptionMethodSupported( ZipArchive::EM_AES_256, true ) || ! ZipArchive::isEncryptionMethodSupported( ZipArchive::EM_AES_256, false ) ) {
			self::markTestSkipped( 'The current PHP/libzip runtime does not support ZIP AES-256.' );
		}
	}

	private function credential_blueprint(): PackageBlueprint {
		return new PackageBlueprint(
			array( $this->package() ),
			array( new BlueprintCredential( 'gh', 'Team token', 'classic', array( 'owner' => '' ), 'token-canary-value', array( $this->identity() ) ) )
		);
	}

	/** @return array{type:string,identifier:string} */
	private function identity(): array {
		return array(
			'type'       => 'plugin',
			'identifier' => 'example/example.php',
		);
	}

	private function package(): BlueprintPackage {
		return new BlueprintPackage( 'plugin', 'example/example.php', 'Example Plugin', 'gh', '123', 'owner/repository', 'main', null );
	}
}
