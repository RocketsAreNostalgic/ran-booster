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

	protected function setUp(): void {
		$this->file = sys_get_temp_dir() . '/ran-booster-' . bin2hex( random_bytes( 8 ) ) . '.zip';
	}

	protected function tearDown(): void {
		if ( is_file( $this->file ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test-only temporary archive cleanup.
			unlink( $this->file );
		}
	}

	public function testPackageOnlyArchiveHasOnePlainEntryAndRoundTrips(): void {
		$blueprint = new PackageBlueprint( array( $this->package() ) );
		$archive   = new BlueprintArchive();

		$archive->writeTo( $this->file, $blueprint, null );

		$zip = $this->open();
		self::assertSame( 1, $zip->numFiles );
		self::assertSame( BlueprintArchive::ENTRY, $zip->getNameIndex( 0 ) );
		self::assertSame( ZipArchive::EM_NONE, $zip->statIndex( 0 )['encryption_method'] );
		$zip->close();
		self::assertSame( $blueprint->canonicalJson(), $archive->readFrom( $this->file, '' )->canonicalJson() );
	}

	public function testCredentialArchiveUsesAes256AndRoundTrips(): void {
		$this->requireAes();
		$blueprint = $this->credentialBlueprint();
		$password  = 'correct-horse-battery-staple';
		$archive   = new BlueprintArchive();

		$archive->writeTo( $this->file, $blueprint, $password );
		$zip = $this->open();
		self::assertSame( ZipArchive::EM_AES_256, $zip->statIndex( 0 )['encryption_method'] );
		$zip->close();
		self::assertSame( $blueprint->canonicalJson(), $archive->readFrom( $this->file, $password )->canonicalJson() );
	}

	public function testWriteRejectsPasswordAndCredentialMismatch(): void {
		$archive = new BlueprintArchive();
		foreach ( array(
			array( new PackageBlueprint( array( $this->package() ) ), 'unneeded-password-value' ),
			array( $this->credentialBlueprint(), null ),
			array( $this->credentialBlueprint(), 'too-short' ),
			array( $this->credentialBlueprint(), "valid-length-password\n" ),
		) as [ $blueprint, $password ] ) {
			try {
				$archive->writeTo( $this->file, $blueprint, $password );
				self::fail( 'Expected an invalid archive password choice.' );
			} catch ( InvalidArgumentException $exception ) {
				self::assertSame( 'The portability archive could not be written.', $exception->getMessage() );
			}
		}
	}

	public function testCredentialBearingBlueprintIsRedactedFromWriteTrace(): void {
		try {
			( new BlueprintArchive() )->writeTo( $this->file, $this->credentialBlueprint(), null );
			self::fail( 'Expected archive write failure.' );
		} catch ( InvalidArgumentException $exception ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- Test-only inspection of redacted exception arguments.
			self::assertStringNotContainsString( 'token-canary-value', var_export( $exception->getTrace(), true ) );
		}
	}

	public function testReadRejectsMissingAndWrongPasswordsWithoutLeakingDetails(): void {
		$this->requireAes();
		$archive = new BlueprintArchive();
		$archive->writeTo( $this->file, $this->credentialBlueprint(), 'correct-horse-battery-staple' );

		foreach ( array( null, 'wrong-password-value-long-enough' ) as $password ) {
			try {
				$archive->readFrom( $this->file, $password );
				self::fail( 'Expected archive read failure.' );
			} catch ( InvalidArgumentException $exception ) {
				self::assertSame( 'The portability archive is invalid.', $exception->getMessage() );
				self::assertStringNotContainsString( 'password', strtolower( $exception->getMessage() ) );
			}
		}
	}

	public function testRejectsPlainCredentialsAndEncryptedEmptyPayload(): void {
		$this->requireAes();
		$archive = new BlueprintArchive();
		$this->writeRaw( $this->credentialBlueprint()->canonicalJson(), ZipArchive::EM_NONE, null );
		$this->assertInvalid( fn() => $archive->readFrom( $this->file, null ) );

		$this->writeRaw( ( new PackageBlueprint( array( $this->package() ) ) )->canonicalJson(), ZipArchive::EM_AES_256, 'correct-horse-battery-staple' );
		$this->assertInvalid( fn() => $archive->readFrom( $this->file, 'correct-horse-battery-staple' ) );
	}

	public function testRejectsUnsupportedLayoutsEncryptionAndContent(): void {
		$archive = new BlueprintArchive();
		$this->writeRaw( '{}', ZipArchive::EM_NONE, null, 'other.json' );
		$this->assertInvalid( fn() => $archive->readFrom( $this->file, null ) );
		$this->writeRaw( '{}', ZipArchive::EM_NONE, null );
		$this->assertInvalid( fn() => $archive->readFrom( $this->file, null ) );
		$this->writeRaw( '{}', ZipArchive::EM_NONE, null, BlueprintArchive::ENTRY . '/' );
		$this->assertInvalid( fn() => $archive->readFrom( $this->file, null ) );

		$this->writeRaw( ( new PackageBlueprint( array() ) )->canonicalJson(), ZipArchive::EM_TRAD_PKWARE, 'correct-horse-battery-staple' );
		$this->assertInvalid( fn() => $archive->readFrom( $this->file, 'correct-horse-battery-staple' ) );

		$zip = new ZipArchive();
		self::assertTrue( $zip->open( $this->file, ZipArchive::CREATE | ZipArchive::OVERWRITE ) );
		self::assertTrue( $zip->addFromString( BlueprintArchive::ENTRY, ( new PackageBlueprint( array() ) )->canonicalJson() ) );
		self::assertTrue( $zip->addFromString( 'extra.json', '{}' ) );
		self::assertTrue( $zip->close() );
		$this->assertInvalid( fn() => $archive->readFrom( $this->file, null ) );
	}

	public function testUnknownBlueprintVersionFailsWithoutRewritingTheArchive(): void {
		$json = str_replace(
			'"version":1',
			'"version":2',
			( new PackageBlueprint( array( $this->package() ) ) )->canonicalJson()
		);
		$this->writeRaw( $json, ZipArchive::EM_NONE, null );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test-only immutable archive evidence.
		$before = file_get_contents( $this->file );

		$this->assertInvalid( fn() => ( new BlueprintArchive() )->readFrom( $this->file, null ) );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test-only immutable archive evidence.
		self::assertSame( $before, file_get_contents( $this->file ) );
	}

	public function testRejectsOversizedAndTamperedArchives(): void {
		$archive = new BlueprintArchive();
		$this->writeRaw( str_repeat( 'x', BlueprintArchive::MAX_BYTES + 1 ), ZipArchive::EM_NONE, null );
		$this->assertInvalid( fn() => $archive->readFrom( $this->file, null ) );

		$archive->writeTo( $this->file, new PackageBlueprint( array( $this->package() ) ), null );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test-only archive corruption.
		$bytes = file_get_contents( $this->file );
		self::assertIsString( $bytes );
		$bytes[30] = chr( ord( $bytes[30] ) ^ 1 );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test-only archive corruption.
		self::assertNotFalse( file_put_contents( $this->file, $bytes ) );
		$this->assertInvalid( fn() => $archive->readFrom( $this->file, null ) );
	}

	public function testWriteNormalizesLibzipWarnings(): void {
		$warnings = array();
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Test assertion captures warnings that the codec must contain.
		set_error_handler(
			static function ( int $severity, string $message ) use ( &$warnings ): bool {
				$warnings[] = compact( 'severity', 'message' );
				return true;
			}
		);
		try {
			$this->expectExceptionMessage( 'The portability archive could not be written.' );
			( new BlueprintArchive() )->writeTo( sys_get_temp_dir(), new PackageBlueprint( array() ), null );
		} finally {
			restore_error_handler();
			self::assertSame( array(), $warnings );
		}
	}

	private function writeRaw( string $json, int $encryption, ?string $password, string $name = BlueprintArchive::ENTRY ): void {
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

	private function assertInvalid( callable $read ): void {
		try {
			$read();
			self::fail( 'Expected archive rejection.' );
		} catch ( InvalidArgumentException $exception ) {
			self::assertSame( 'The portability archive is invalid.', $exception->getMessage() );
		}
	}

	private function requireAes(): void {
		if ( ! ZipArchive::isEncryptionMethodSupported( ZipArchive::EM_AES_256, true ) || ! ZipArchive::isEncryptionMethodSupported( ZipArchive::EM_AES_256, false ) ) {
			self::markTestSkipped( 'The current PHP/libzip runtime does not support ZIP AES-256.' );
		}
	}

	private function credentialBlueprint(): PackageBlueprint {
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
