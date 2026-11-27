<?php

declare(strict_types=1);

namespace RAN\Tests\Portability;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RAN\Portability\BlueprintArchive;
use RAN\Portability\BlueprintCredential;
use RAN\Portability\BlueprintPackage;
use RAN\Portability\PackageBlueprint;
use RAN\Tests\Support\CapturesBoosterLog;
use ZipArchive;

require_once dirname( __DIR__ ) . '/Support/CapturesBoosterLog.php';

#[CoversClass( BlueprintArchive::class )]
final class BlueprintArchiveTest extends TestCase {
	use CapturesBoosterLog;

	private const PASSWORD = 'correct-horse-battery-staple';

	private string $file;

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this exact lifecycle override name.
	protected function setUp(): void {
		$this->file = sys_get_temp_dir() . '/ran-booster-' . bin2hex( random_bytes( 8 ) ) . '.zip';
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this exact lifecycle override name.
	protected function tearDown(): void {
		$this->stop_log_capture();
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
		$entry = $zip->statIndex( 0 );
		self::assertIsArray( $entry );
		self::assertSame( ZipArchive::EM_NONE, $entry['encryption_method'] );
		$zip->close();
		self::assertSame( $blueprint->canonical_json(), $archive->read_from( $this->file, '' )->canonical_json() );
	}

	public function test_credential_archive_is_sealed_with_authenticated_interactive_parameters_and_round_trips(): void {
		$blueprint = $this->credential_blueprint();
		$archive   = new BlueprintArchive();

		$archive->write_to( $this->file, $blueprint, self::PASSWORD );
		$zip   = $this->open();
		$entry = $zip->statIndex( 0 );
		self::assertIsArray( $entry );
		self::assertSame( array( BlueprintArchive::SEALED_ENTRY, ZipArchive::EM_NONE ), array( $entry['name'], $entry['encryption_method'] ) );
		$envelope = $zip->getFromIndex( 0 );
		$zip->close();
		self::assertIsString( $envelope );
		// Neither the secret, the package nor the ID salt is readable without the password.
		foreach ( array( 'token-canary-value', 'example/example.php', 'credential_id_salt' ) as $hidden ) {
			self::assertStringNotContainsString( $hidden, $envelope );
		}
		$header = json_decode( $envelope, true );
		self::assertIsArray( $header );
		self::assertSame( array( 'format', 'version', 'kdf', 'opslimit', 'memlimit', 'salt', 'nonce', 'ciphertext' ), array_keys( $header ) );
		self::assertSame(
			array( BlueprintArchive::SEAL_FORMAT, BlueprintArchive::SEAL_VERSION, 'argon2id13', SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE, SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE ),
			array( $header['format'], $header['version'], $header['kdf'], $header['opslimit'], $header['memlimit'] )
		);
		self::assertSame( $blueprint->canonical_json(), $archive->read_from( $this->file, self::PASSWORD )->canonical_json() );
	}

	public function test_password_floor_counts_characters_and_variety_and_accepts_the_generator_output(): void {
		self::assertTrue( BlueprintArchive::accepts_password( self::PASSWORD ) );
		self::assertTrue( BlueprintArchive::accepts_password( str_repeat( 'é', 10 ) . 'abcdefghij' ) );
		foreach ( array( null, str_repeat( '😀', 5 ), str_repeat( 'a', 25 ), 'abababababababababababab', 'abcdefgh' . str_repeat( 'z', 20 ), str_repeat( 'abcdefghij', 25 ) . 'abcdefg', "valid-length-password\n", "valid-length-pass\x7fword" ) as $password ) {
			self::assertFalse( BlueprintArchive::accepts_password( $password ), (string) $password );
		}
		// The Portability screen generates base64url text from 24 random bytes; the floor must never refuse it.
		for ( $sample = 0; $sample < 100000; ++$sample ) {
			$generated = rtrim( strtr( base64_encode( random_bytes( 24 ) ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Reproduces the browser generator's encoding.
			if ( ! BlueprintArchive::accepts_password( $generated ) ) {
				self::fail( 'Generated password refused: ' . $generated );
			}
		}
	}

	public function test_write_rejects_password_and_credential_mismatch(): void {
		$archive = new BlueprintArchive();
		foreach ( array(
			array( new PackageBlueprint( array( $this->package() ) ), 'unneeded-password-value' ),
			array( $this->credential_blueprint(), null ),
			array( $this->credential_blueprint(), '' ),
			array( $this->credential_blueprint(), 'too-short' ),
			array( $this->credential_blueprint(), "valid-length-password\n" ),
			array( $this->credential_blueprint(), str_repeat( 'ab', 15 ) ),
		) as [ $blueprint, $password ] ) {
			try {
				$archive->write_to( $this->file, $blueprint, $password );
				self::fail( 'Expected an invalid archive password choice.' );
			} catch ( InvalidArgumentException $exception ) {
				self::assertSame( 'The portability archive could not be written.', $exception->getMessage() );
			}
		}
		self::assertFalse( is_file( $this->file ) );
	}

	public function test_seal_refusals_are_logged_by_code_only(): void {
		$this->start_log_capture();
		foreach ( array( null, str_repeat( 'ab', 15 ) ) as $password ) {
			try {
				( new BlueprintArchive() )->write_to( $this->file, $this->credential_blueprint(), $password );
				self::fail( 'Expected a seal refusal.' );
			} catch ( InvalidArgumentException ) {
				self::assertFalse( is_file( $this->file ) );
			}
		}
		self::assertSame( array_fill( 0, 2, $this->refusal( 'seal_refused' ) ), $this->captured_log_lines() );
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
		$archive = new BlueprintArchive();
		$archive->write_to( $this->file, $this->credential_blueprint(), self::PASSWORD );
		$this->start_log_capture();

		foreach ( array( null, '', 'wrong-password-value-long-enough' ) as $password ) {
			try {
				$archive->read_from( $this->file, $password );
				self::fail( 'Expected archive read failure.' );
			} catch ( InvalidArgumentException $exception ) {
				self::assertSame( 'The portability archive is invalid.', $exception->getMessage() );
				self::assertStringNotContainsString( 'password', strtolower( $exception->getMessage() ) );
			}
		}
		// Only the wrong password reaches the cipher; a missing one is refused before.
		self::assertSame( array( $this->refusal( 'unseal_failed' ) ), $this->captured_log_lines() );
	}

	public function test_sealed_header_and_ciphertext_are_authenticated(): void {
		$archive = new BlueprintArchive();
		$json    = $this->credential_blueprint()->canonical_json();
		$this->start_log_capture();
		foreach ( array( 'salt', 'nonce', 'ciphertext', 'short salt', 'short nonce' ) as $field ) {
			$this->write_sealed( $json, tamper: $field );
			$this->assert_invalid( fn() => $archive->read_from( $this->file, self::PASSWORD ) );
		}
		$this->write_sealed( $json );
		self::assertCount( 1, $archive->read_from( $this->file, self::PASSWORD )->credentials );
		self::assertSame( array_fill( 0, 5, $this->refusal( 'unseal_failed' ) ), $this->captured_log_lines() );
	}

	public function test_header_parameters_outside_the_allowed_set_are_refused_before_key_derivation(): void {
		$archive = new BlueprintArchive();
		$json    = $this->credential_blueprint()->canonical_json();
		$this->start_log_capture();
		// A genuinely sealed archive with stronger parameters would open if the header chose them, so this proves the guard.
		$this->write_sealed( $json, SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE + 1 );
		$this->assert_invalid( fn() => $archive->read_from( $this->file, self::PASSWORD ) );
		// A crafted header asking for a terabyte of memory is refused with the same code instead of attempting it.
		$this->write_sealed( $json, memlimit: 1 << 40, derive: false );
		$this->assert_invalid( fn() => $archive->read_from( $this->file, self::PASSWORD ) );
		$this->write_sealed( $json, opslimit: '2' );
		$this->assert_invalid( fn() => $archive->read_from( $this->file, self::PASSWORD ) );

		self::assertSame( array_fill( 0, 3, $this->refusal( 'seal_parameters_rejected' ) ), $this->captured_log_lines() );
	}

	public function test_credentials_need_a_sealed_entry_and_a_sealed_entry_needs_credentials(): void {
		$archive = new BlueprintArchive();
		$this->start_log_capture();
		$this->write_raw( $this->credential_blueprint()->canonical_json(), ZipArchive::EM_NONE, null );
		$this->assert_invalid( fn() => $archive->read_from( $this->file, null ) );
		$this->assert_invalid( fn() => $archive->read_from( $this->file, self::PASSWORD ) );

		$this->write_sealed( ( new PackageBlueprint( array( $this->package() ) ) )->canonical_json() );
		$this->assert_invalid( fn() => $archive->read_from( $this->file, self::PASSWORD ) );
		$this->assert_invalid( fn() => $archive->read_from( $this->file, null ) );

		self::assertSame( array( $this->refusal( 'credentials_unsealed' ), $this->refusal( 'document_invalid' ) ), $this->captured_log_lines() );
	}

	public function test_a_sealed_credential_document_without_a_valid_id_salt_is_refused(): void {
		$archive = new BlueprintArchive();
		$json    = $this->credential_blueprint()->canonical_json();
		$this->start_log_capture();
		foreach ( array(
			'missing'   => (string) preg_replace( '/"credential_id_salt":"[0-9a-f]{64}",/', '', $json ),
			'malformed' => (string) preg_replace( '/"credential_id_salt":"[0-9a-f]{64}"/', '"credential_id_salt":"' . str_repeat( 'g', 64 ) . '"', $json ),
		) as $case => $document ) {
			self::assertNotSame( $json, $document, $case );
			$this->write_sealed( $document );
			$this->assert_invalid( fn() => $archive->read_from( $this->file, self::PASSWORD ) );
		}
		self::assertSame( array_fill( 0, 2, $this->refusal( 'document_invalid' ) ), $this->captured_log_lines() );
	}

	public function test_zip_encrypted_credential_archives_need_a_new_export(): void {
		$this->require_aes();
		$this->write_raw( $this->credential_blueprint()->canonical_json(), ZipArchive::EM_AES_256, self::PASSWORD );
		$this->start_log_capture();
		$this->assert_invalid( fn() => ( new BlueprintArchive() )->read_from( $this->file, self::PASSWORD ) );
		$this->assert_invalid( fn() => ( new BlueprintArchive() )->read_from( $this->file, null ) );
		self::assertSame( array_fill( 0, 2, $this->refusal( 'legacy_format' ) ), $this->captured_log_lines() );
	}

	public function test_rejects_unsupported_layouts_encryption_and_content(): void {
		$archive = new BlueprintArchive();
		$this->write_raw( '{}', ZipArchive::EM_NONE, null, 'other.json' );
		$this->assert_invalid( fn() => $archive->read_from( $this->file, null ) );
		$this->write_raw( '{}', ZipArchive::EM_NONE, null );
		$this->assert_invalid( fn() => $archive->read_from( $this->file, null ) );
		$this->write_raw( '{}', ZipArchive::EM_NONE, null, BlueprintArchive::ENTRY . '/' );
		$this->assert_invalid( fn() => $archive->read_from( $this->file, null ) );

		$this->write_raw( ( new PackageBlueprint( array() ) )->canonical_json(), ZipArchive::EM_TRAD_PKWARE, self::PASSWORD );
		$this->assert_invalid( fn() => $archive->read_from( $this->file, self::PASSWORD ) );

		$zip = new ZipArchive();
		self::assertTrue( $zip->open( $this->file, ZipArchive::CREATE | ZipArchive::OVERWRITE ) );
		self::assertTrue( $zip->addFromString( BlueprintArchive::ENTRY, ( new PackageBlueprint( array() ) )->canonical_json() ) );
		self::assertTrue( $zip->addFromString( 'extra.json', '{}' ) );
		self::assertTrue( $zip->close() );
		$this->assert_invalid( fn() => $archive->read_from( $this->file, null ) );
	}

	public function test_unknown_blueprint_version_fails_without_rewriting_the_archive(): void {
		$json = str_replace(
			'"version":' . PackageBlueprint::VERSION,
			'"version":' . ( PackageBlueprint::VERSION + 1 ),
			( new PackageBlueprint( array( $this->package() ) ) )->canonical_json()
		);
		$this->write_raw( $json, ZipArchive::EM_NONE, null );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test-only immutable archive evidence.
		$before = file_get_contents( $this->file );

		$this->assert_invalid( fn() => ( new BlueprintArchive() )->read_from( $this->file, null ) );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test-only immutable archive evidence.
		self::assertSame( $before, file_get_contents( $this->file ) );
	}

	public function test_original_v1_archives_normalize_only_without_credentials(): void {
		$legacy  = '{"format":"ran-booster-package-blueprint","version":1,"packages":[{"type":"plugin","identifier":"example/example.php","display_name":"Example","provider":"gh","provider_repository_id":"123","repository":"owner/repository","branch":"main","subdirectory":null}],"credentials":[]}';
		$archive = new BlueprintArchive();
		$this->write_raw( $legacy, ZipArchive::EM_NONE, null );
		self::assertSame( 'main', $archive->read_from( $this->file, null )->packages[0]->branch );
		// A V1 credential has no ID salt, so it is refused even when sealed rather than imported with an unsalted ID.
		$credential  = '{"provider":"gh","label":"Token","kind":"classic","configuration":{},"secret":"legacy-canary","packages":[{"type":"plugin","identifier":"example/example.php"}]}';
		$with_secret = str_replace( '"credentials":[]', '"credentials":[' . $credential . ']', $legacy );
		$this->write_sealed( $with_secret );
		$this->assert_invalid( fn() => $archive->read_from( $this->file, self::PASSWORD ) );
		$this->write_raw( $with_secret, ZipArchive::EM_NONE, null );
		$this->assert_invalid( fn() => $archive->read_from( $this->file, null ) );
		$this->write_raw( str_replace( '"branch":"main"', '"branch":null', $legacy ), ZipArchive::EM_NONE, null );
		$this->assert_invalid( fn() => $archive->read_from( $this->file, null ) );
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

	/** Seals $json the way the archive does, optionally with other parameters or one tampered field. */
	private function write_sealed( string $json, int|string $opslimit = SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE, int $memlimit = SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE, ?string $tamper = null, bool $derive = true ): void {
		$salt       = random_bytes( SODIUM_CRYPTO_PWHASH_SALTBYTES );
		$nonce      = random_bytes( SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES );
		$header     = array(
			'format'   => BlueprintArchive::SEAL_FORMAT,
			'version'  => BlueprintArchive::SEAL_VERSION,
			'kdf'      => 'argon2id13',
			'opslimit' => $opslimit,
			'memlimit' => $memlimit,
			'salt'     => sodium_bin2base64( $salt, SODIUM_BASE64_VARIANT_ORIGINAL ),
			'nonce'    => sodium_bin2base64( $nonce, SODIUM_BASE64_VARIANT_ORIGINAL ),
		);
		$key        = $derive ? sodium_crypto_pwhash( SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES, self::PASSWORD, $salt, (int) $opslimit, $memlimit, SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13 ) : random_bytes( SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES );
		$ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt( $json, (string) json_encode( $header, JSON_UNESCAPED_SLASHES ), $nonce, $key ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Reproduces the exact authenticated header bytes.
		$header    += array( 'ciphertext' => sodium_bin2base64( $ciphertext, SODIUM_BASE64_VARIANT_ORIGINAL ) );
		if ( null !== $tamper ) {
			$field   = str_replace( 'short ', '', $tamper );
			$encoded = $header[ $field ];
			self::assertIsString( $encoded );
			$bytes            = sodium_base642bin( $encoded, SODIUM_BASE64_VARIANT_ORIGINAL );
			$bytes            = $field === $tamper ? chr( ord( $bytes[0] ) ^ 1 ) . substr( $bytes, 1 ) : substr( $bytes, 1 );
			$header[ $field ] = sodium_bin2base64( $bytes, SODIUM_BASE64_VARIANT_ORIGINAL );
		}
		$this->write_raw( (string) json_encode( $header, JSON_UNESCAPED_SLASHES ), ZipArchive::EM_NONE, null, BlueprintArchive::SEALED_ENTRY ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Test-only sealed fixture.
	}

	private function refusal( string $code ): string {
		return '[ran-booster] Blueprint archive refused. {"operation":"portability_archive","outcome_code":"' . $code . '"}';
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
