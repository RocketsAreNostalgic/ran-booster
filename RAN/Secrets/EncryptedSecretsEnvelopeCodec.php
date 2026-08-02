<?php

declare(strict_types=1);

namespace RAN\Secrets;

// Native JSON and Sodium calls define the strict encrypted-document format.
// phpcs:disable WordPress.WP.AlternativeFunctions
// Base64 is the binding RFC 4648 binary encoding for envelope fields.
// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode, WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode

use JsonException;
use RuntimeException;
use Throwable;

/**
 * Encodes and authenticates the encrypted secrets envelope.
 *
 * Plaintext schema validation remains the responsibility of SecretsFile.
 */
final class EncryptedSecretsEnvelopeCodec {

	public const FORMAT    = 'ran-booster-encrypted-secrets';
	public const VERSION   = 1;
	public const ALGORITHM = 'xchacha20-poly1305-ietf';
	public const AAD       = 'ran-booster-encrypted-secrets:v1';
	public const MAX_BYTES = 1048576;

	public function encrypt(
		#[\SensitiveParameter] string $plaintext,
		#[\SensitiveParameter] string $key
	): string {
		$this->requireSodium();
		$this->requireKey( $key );
		if ( strlen( $plaintext ) > self::MAX_BYTES ) {
			throw new RuntimeException( 'The Booster secrets document is too large to encrypt.' );
		}

		try {
			$nonce      = random_bytes( SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES );
			$ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
				$plaintext,
				self::AAD,
				$nonce,
				$key
			);
			$envelope   = $this->canonicalEnvelope(
				base64_encode( $nonce ),
				base64_encode( $ciphertext )
			);
		} catch ( Throwable ) {
			throw new RuntimeException( 'The Booster secrets document could not be encrypted.' );
		}

		if ( strlen( $envelope ) > self::MAX_BYTES ) {
			throw new RuntimeException( 'The Booster secrets document is too large to encrypt.' );
		}

		return $envelope;
	}

	public function decrypt(
		#[\SensitiveParameter] string $envelope,
		#[\SensitiveParameter] string $key
	): string {
		$this->requireSodium();
		$this->requireKey( $key );
		if ( '' === $envelope
			|| strlen( $envelope ) > self::MAX_BYTES
			|| 1 !== preg_match( '//u', $envelope )
		) {
			throw new RuntimeException( 'The encrypted Booster secrets document is invalid.' );
		}

		try {
			$decoded = json_decode( $envelope, true, 4, JSON_THROW_ON_ERROR );
		} catch ( JsonException ) {
			throw new RuntimeException( 'The encrypted Booster secrets document is invalid.' );
		}

		if ( ! is_array( $decoded )
			|| array_keys( $decoded ) !== array( 'format', 'version', 'algorithm', 'nonce', 'ciphertext' )
			|| self::FORMAT !== ( $decoded['format'] ?? null )
			|| self::VERSION !== ( $decoded['version'] ?? null )
			|| self::ALGORITHM !== ( $decoded['algorithm'] ?? null )
			|| ! is_string( $decoded['nonce'] ?? null )
			|| ! is_string( $decoded['ciphertext'] ?? null )
		) {
			throw new RuntimeException( 'The encrypted Booster secrets document is invalid.' );
		}

		$canonical = $this->canonicalEnvelope( $decoded['nonce'], $decoded['ciphertext'] );
		if ( ! hash_equals( $canonical, $envelope ) ) {
			throw new RuntimeException( 'The encrypted Booster secrets document is invalid.' );
		}

		$nonce      = $this->decodeBase64( $decoded['nonce'] );
		$ciphertext = $this->decodeBase64( $decoded['ciphertext'] );
		if ( SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES !== strlen( $nonce )
			|| SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_ABYTES > strlen( $ciphertext )
		) {
			throw new RuntimeException( 'The encrypted Booster secrets document is invalid.' );
		}

		try {
			$plaintext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
				$ciphertext,
				self::AAD,
				$nonce,
				$key
			);
		} catch ( Throwable ) {
			$plaintext = false;
		}
		if ( false === $plaintext ) {
			throw new RuntimeException( 'The encrypted Booster secrets document could not be authenticated.' );
		}

		return $plaintext;
	}

	private function canonicalEnvelope( string $nonce, string $ciphertext ): string {
		try {
			$json = json_encode(
				array(
					'format'     => self::FORMAT,
					'version'    => self::VERSION,
					'algorithm'  => self::ALGORITHM,
					'nonce'      => $nonce,
					'ciphertext' => $ciphertext,
				),
				JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES
			);
		} catch ( JsonException ) {
			throw new RuntimeException( 'The encrypted Booster secrets document is invalid.' );
		}

		return $json . "\n";
	}

	private function decodeBase64( string $encoded ): string {
		$decoded = base64_decode( $encoded, true );
		if ( false === $decoded || ! hash_equals( base64_encode( $decoded ), $encoded ) ) {
			throw new RuntimeException( 'The encrypted Booster secrets document is invalid.' );
		}

		return $decoded;
	}

	private function requireSodium(): void {
		if ( ! extension_loaded( 'sodium' )
			|| ! function_exists( 'sodium_crypto_aead_xchacha20poly1305_ietf_encrypt' )
			|| ! function_exists( 'sodium_crypto_aead_xchacha20poly1305_ietf_decrypt' )
			|| ! defined( 'SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES' )
			|| ! defined( 'SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES' )
			|| ! defined( 'SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_ABYTES' )
		) {
			throw new RuntimeException( 'The Sodium extension is required for encrypted Booster secrets.' );
		}
	}

	private function requireKey( #[\SensitiveParameter] string $key ): void {
		if ( SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES !== strlen( $key ) ) {
			throw new RuntimeException( 'The Booster encryption key is invalid.' );
		}
	}
}
