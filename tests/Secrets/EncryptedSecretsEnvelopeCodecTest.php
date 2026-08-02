<?php

declare(strict_types=1);

namespace Tests\Secrets;

// Native JSON and base64 calls inspect the exact pure codec wire format.
// phpcs:disable WordPress.WP.AlternativeFunctions
// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode, WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RAN\Secrets\EncryptedSecretsEnvelopeCodec;
use RuntimeException;

#[CoversClass( EncryptedSecretsEnvelopeCodec::class )]
final class EncryptedSecretsEnvelopeCodecTest extends TestCase {

	private const KEY       = '12345678901234567890123456789012';
	private const WRONG_KEY = 'abcdefghijklmnopqrstuvwxyzABCDEF';
	private const PLAINTEXT = '{"schema_version":2,"credentials":{"gh":{"profile":{"secret":"sentinel-plaintext-secret"}}},"webhooks":{}}';

	protected function setUp(): void {
		if ( ! extension_loaded( 'sodium' ) ) {
			self::markTestSkipped( 'The Sodium extension is required for envelope codec tests.' );
		}
	}

	public function testRoundTripUsesCanonicalEnvelopeWithoutPlaintext(): void {
		$codec    = new EncryptedSecretsEnvelopeCodec();
		$envelope = $codec->encrypt( self::PLAINTEXT, self::KEY );
		$decoded  = json_decode( $envelope, true, 4, JSON_THROW_ON_ERROR );

		self::assertSame( self::PLAINTEXT, $codec->decrypt( $envelope, self::KEY ) );
		self::assertStringEndsWith( "\n", $envelope );
		self::assertStringNotContainsString( self::PLAINTEXT, $envelope );
		self::assertStringNotContainsString( 'sentinel-plaintext-secret', $envelope );
		self::assertSame(
			array( 'format', 'version', 'algorithm', 'nonce', 'ciphertext' ),
			array_keys( $decoded )
		);
		self::assertSame( EncryptedSecretsEnvelopeCodec::FORMAT, $decoded['format'] );
		self::assertSame( EncryptedSecretsEnvelopeCodec::VERSION, $decoded['version'] );
		self::assertSame( EncryptedSecretsEnvelopeCodec::ALGORITHM, $decoded['algorithm'] );
		self::assertSame( base64_encode( base64_decode( $decoded['nonce'], true ) ), $decoded['nonce'] );
		self::assertSame( base64_encode( base64_decode( $decoded['ciphertext'], true ) ), $decoded['ciphertext'] );
	}

	public function testEveryWriteUsesAFreshNonce(): void {
		$codec = new EncryptedSecretsEnvelopeCodec();

		$first  = $codec->encrypt( self::PLAINTEXT, self::KEY );
		$second = $codec->encrypt( self::PLAINTEXT, self::KEY );

		self::assertNotSame( $first, $second );
		self::assertSame( self::PLAINTEXT, $codec->decrypt( $first, self::KEY ) );
		self::assertSame( self::PLAINTEXT, $codec->decrypt( $second, self::KEY ) );
	}

	#[DataProvider( 'nonCanonicalEnvelopeProvider' )]
	public function testNonCanonicalEnvelopeFormsAreRejected( callable $mutate ): void {
		$codec     = new EncryptedSecretsEnvelopeCodec();
		$canonical = $codec->encrypt( self::PLAINTEXT, self::KEY );
		$invalid   = $mutate( $canonical );

		$this->expectException( RuntimeException::class );
		$codec->decrypt( $invalid, self::KEY );
	}

	/** @return array<string, array{callable(string): string}> */
	public static function nonCanonicalEnvelopeProvider(): array {
		return array(
			'missing field'           => array(
				static function ( string $json ): string {
					$value = json_decode( $json, true, 4, JSON_THROW_ON_ERROR );
					unset( $value['algorithm'] );

					return json_encode( $value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES ) . "\n";
				},
			),
			'additional field'        => array(
				static function ( string $json ): string {
					$value          = json_decode( $json, true, 4, JSON_THROW_ON_ERROR );
					$value['extra'] = true;

					return json_encode( $value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES ) . "\n";
				},
			),
			'reordered fields'        => array(
				static function ( string $json ): string {
					$value = json_decode( $json, true, 4, JSON_THROW_ON_ERROR );

					return json_encode(
						array(
							'version'    => $value['version'],
							'format'     => $value['format'],
							'algorithm'  => $value['algorithm'],
							'nonce'      => $value['nonce'],
							'ciphertext' => $value['ciphertext'],
						),
						JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES
					) . "\n";
				},
			),
			'leading whitespace'      => array( static fn( string $json ): string => ' ' . $json ),
			'crlf ending'             => array( static fn( string $json ): string => rtrim( $json, "\n" ) . "\r\n" ),
			'missing final line feed' => array( static fn( string $json ): string => rtrim( $json, "\n" ) ),
			'duplicate field'         => array(
				static fn( string $json ): string => preg_replace(
					'/^\\{/',
					'{"format":"ran-booster-encrypted-secrets",',
					$json,
					1
				) ?? '',
			),
			'wrong format'            => array( static fn( string $json ): string => str_replace( EncryptedSecretsEnvelopeCodec::FORMAT, 'wrong-format', $json ) ),
			'wrong version'           => array( static fn( string $json ): string => str_replace( '"version":1', '"version":2', $json ) ),
			'float version'           => array( static fn( string $json ): string => str_replace( '"version":1', '"version":1.0', $json ) ),
			'wrong algorithm'         => array( static fn( string $json ): string => str_replace( EncryptedSecretsEnvelopeCodec::ALGORITHM, 'wrong-algorithm', $json ) ),
			'invalid utf8'            => array( static fn( string $json ): string => substr( $json, 0, -2 ) . "\xFF\n" ),
		);
	}

	#[DataProvider( 'invalidBase64Provider' )]
	public function testNonCanonicalBase64AndNonceLengthsAreRejected( string $field, callable $mutate ): void {
		$codec           = new EncryptedSecretsEnvelopeCodec();
		$value           = json_decode( $codec->encrypt( self::PLAINTEXT, self::KEY ), true, 4, JSON_THROW_ON_ERROR );
		$original        = $value[ $field ];
		$value[ $field ] = $mutate( $original );
		$invalid         = json_encode( $value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES ) . "\n";

		$this->expectException( RuntimeException::class );
		$codec->decrypt( $invalid, self::KEY );
	}

	/** @return array<string, array{string, callable(string): string}> */
	public static function invalidBase64Provider(): array {
		return array(
			'invalid alphabet'  => array( 'nonce', static fn( string $value ): string => '!' . substr( $value, 1 ) ),
			'extra padding'     => array( 'nonce', static fn( string $value ): string => $value . '=' ),
			'whitespace'        => array( 'ciphertext', static fn( string $value ): string => $value . ' ' ),
			'url-safe alphabet' => array( 'ciphertext', static fn( string $value ): string => '-' . substr( $value, 1 ) ),
			'short nonce'       => array( 'nonce', static fn(): string => base64_encode( 'short' ) ),
			'short ciphertext'  => array( 'ciphertext', static fn(): string => base64_encode( 'short' ) ),
		);
	}

	public function testTamperAndWrongKeyFailAuthentication(): void {
		$codec               = new EncryptedSecretsEnvelopeCodec();
		$envelope            = $codec->encrypt( self::PLAINTEXT, self::KEY );
		$value               = json_decode( $envelope, true, 4, JSON_THROW_ON_ERROR );
		$bytes               = base64_decode( $value['ciphertext'], true );
		$bytes[0]            = chr( ord( $bytes[0] ) ^ 1 );
		$value['ciphertext'] = base64_encode( $bytes );
		$tampered            = json_encode( $value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES ) . "\n";

		foreach ( array(
			$tampered => self::KEY,
			$envelope => self::WRONG_KEY,
		) as $document => $key ) {
			try {
				$codec->decrypt( $document, $key );
				self::fail( 'Tamper and wrong-key reads must fail.' );
			} catch ( RuntimeException $exception ) {
				self::assertStringNotContainsString( self::PLAINTEXT, $exception->getMessage() );
				self::assertStringNotContainsString( self::KEY, $exception->getMessage() );
				self::assertStringNotContainsString( self::WRONG_KEY, $exception->getMessage() );
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- The trace is inspected only to prove secret redaction.
				$traceArguments = var_export( $exception->getTrace()[0]['args'] ?? array(), true );
				self::assertStringNotContainsString( self::PLAINTEXT, $traceArguments );
				self::assertStringNotContainsString( self::KEY, $traceArguments );
				self::assertStringNotContainsString( self::WRONG_KEY, $traceArguments );
			}
		}
	}

	#[DataProvider( 'authenticatedByteMutationProvider' )]
	public function testValidLengthNonceCiphertextAndTagMutationsFailAuthentication(
		string $field,
		int $offset
	): void {
		$codec            = new EncryptedSecretsEnvelopeCodec();
		$value            = json_decode( $codec->encrypt( self::PLAINTEXT, self::KEY ), true, 4, JSON_THROW_ON_ERROR );
		$bytes            = base64_decode( $value[ $field ], true );
		$offset           = $offset < 0 ? strlen( $bytes ) + $offset : $offset;
		$bytes[ $offset ] = chr( ord( $bytes[ $offset ] ) ^ 1 );
		$value[ $field ]  = base64_encode( $bytes );
		$mutated          = json_encode( $value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES ) . "\n";

		$this->expectException( RuntimeException::class );
		$codec->decrypt( $mutated, self::KEY );
	}

	/** @return array<string, array{string, int}> */
	public static function authenticatedByteMutationProvider(): array {
		return array(
			'nonce byte'      => array( 'nonce', 0 ),
			'ciphertext byte' => array( 'ciphertext', 0 ),
			'final tag byte'  => array( 'ciphertext', -1 ),
		);
	}

	public function testChangedAdditionalAuthenticatedDataCannotDecryptTheEnvelope(): void {
		$envelope   = ( new EncryptedSecretsEnvelopeCodec() )->encrypt( self::PLAINTEXT, self::KEY );
		$value      = json_decode( $envelope, true, 4, JSON_THROW_ON_ERROR );
		$nonce      = base64_decode( $value['nonce'], true );
		$ciphertext = base64_decode( $value['ciphertext'], true );

		self::assertFalse(
			sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
				$ciphertext,
				EncryptedSecretsEnvelopeCodec::AAD . ':changed',
				$nonce,
				self::KEY
			)
		);
	}

	public function testWrongLengthKeysAreRejected(): void {
		$codec = new EncryptedSecretsEnvelopeCodec();

		foreach ( array( '', 'short', str_repeat( 'x', 33 ) ) as $key ) {
			try {
				$codec->encrypt( self::PLAINTEXT, $key );
				self::fail( 'A wrong-length encryption key must fail.' );
			} catch ( RuntimeException $exception ) {
				if ( '' !== $key ) {
					self::assertStringNotContainsString( $key, $exception->getMessage() );
				}
			}
		}
	}

	public function testEnvelopeAndPlaintextSizeBoundsAreEnforced(): void {
		$codec = new EncryptedSecretsEnvelopeCodec();

		try {
			$codec->encrypt( str_repeat( 'x', EncryptedSecretsEnvelopeCodec::MAX_BYTES + 1 ), self::KEY );
			self::fail( 'An oversized plaintext must fail.' );
		} catch ( RuntimeException ) {
			self::assertTrue( true );
		}
		try {
			$codec->encrypt( str_repeat( 'x', 800000 ), self::KEY );
			self::fail( 'A plaintext whose encoded envelope exceeds the limit must fail.' );
		} catch ( RuntimeException ) {
			self::assertTrue( true );
		}

		$this->expectException( RuntimeException::class );
		$codec->decrypt( str_repeat( 'x', EncryptedSecretsEnvelopeCodec::MAX_BYTES + 1 ), self::KEY );
	}
}
