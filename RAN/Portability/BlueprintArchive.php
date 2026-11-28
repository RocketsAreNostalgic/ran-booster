<?php

declare(strict_types=1);

namespace RAN\Portability;

use InvalidArgumentException;
use RAN\Logging\BoosterLogger;
use Throwable;
use ZipArchive;

/**
 * The single, non-extracting ZIP transport for portability blueprints.
 *
 * A package-only blueprint is stored as plain JSON. A credential-bearing one is
 * sealed with libsodium: an Argon2id key from the password and XChaCha20-Poly1305,
 * with the header (format, version, key-derivation parameters, salt and nonce)
 * authenticated as associated data.
 */
final class BlueprintArchive {

	public const FILENAME     = 'ran-booster-blueprint.zip';
	public const ENTRY        = 'blueprint.json';
	public const SEALED_ENTRY = 'blueprint.sealed.json';
	public const MAX_BYTES    = 1048576;
	public const SEAL_FORMAT  = 'ran-booster-sealed-blueprint';
	public const SEAL_VERSION = 1;

	/** Argon2id parameter pairs a sealed header may name; any other pair is refused before a key is derived. */
	private const PWHASH_PARAMETERS = array( array( SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE, SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE ) );

	public function write_to( string $path, #[\SensitiveParameter] PackageBlueprint $blueprint, #[\SensitiveParameter] ?string $password ): void {
		$password = '' === $password ? null : $password;
		$sealed   = array() !== $blueprint->credentials;
		$code     = $sealed && ! self::sealing_available() ? 'sealing_unavailable' : 'seal_refused';
		try {
			if ( ( null !== $password ) !== $sealed || ! class_exists( ZipArchive::class ) || 'sealing_unavailable' === $code ) {
				throw new InvalidArgumentException();
			}
			$entry = null === $password ? $blueprint->canonical_json() : self::seal( $blueprint->canonical_json(), $password );
		} catch ( Throwable ) {
			if ( $sealed ) {
				self::log_refusal( $code );
			}
			throw new InvalidArgumentException( 'The portability archive could not be written.' );
		}

		$zip = new ZipArchive();
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Contains libzip warnings inside the normalized public error boundary.
		set_error_handler( static fn(): bool => true );
		try {
			if ( true !== $zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE )
				|| ! $zip->addFromString( $sealed ? self::SEALED_ENTRY : self::ENTRY, $entry )
				|| ! $zip->close()
				|| ! is_file( $path ) || filesize( $path ) > self::MAX_BYTES ) {
				throw new InvalidArgumentException( 'The portability archive could not be written.' );
			}
		} catch ( Throwable ) {
			if ( is_file( $path ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Removes only this failed output artifact.
				unlink( $path );
			}
			throw new InvalidArgumentException( 'The portability archive could not be written.' );
		} finally {
			restore_error_handler();
		}
	}

	public function read_from( string $path, #[\SensitiveParameter] ?string $password ): PackageBlueprint {
		$password = '' === $password ? null : $password;
		if ( ! class_exists( ZipArchive::class ) || ! is_file( $path ) || 0 === filesize( $path ) || filesize( $path ) > self::MAX_BYTES ) {
			throw new InvalidArgumentException( 'The portability archive is invalid.' );
		}

		$zip  = new ZipArchive();
		$code = null;
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Contains libzip warnings inside the normalized public error boundary.
		set_error_handler( static fn(): bool => true );
		try {
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- ZipArchive retains its native public property contract.
			if ( true !== $zip->open( $path, ZipArchive::RDONLY | ZipArchive::CHECKCONS ) || 1 !== $zip->numFiles ) {
				throw new InvalidArgumentException();
			}
			$stat = $zip->statIndex( 0 );
			if ( ! is_array( $stat ) || ! in_array( $stat['name'], array( self::ENTRY, self::SEALED_ENTRY ), true ) || $stat['size'] > self::MAX_BYTES ) {
				throw new InvalidArgumentException();
			}
			$encryption = $stat['encryption_method'] ?? ZipArchive::EM_UNKNOWN;
			if ( ZipArchive::EM_NONE !== $encryption ) {
				// Credential archives were once ZIP-encrypted; they must be exported again, and nothing reads them.
				$code = ZipArchive::EM_AES_256 === $encryption ? 'legacy_format' : null;
				throw new InvalidArgumentException();
			}
			$sealed = self::SEALED_ENTRY === $stat['name'];
			if ( ( null !== $password ) !== $sealed || ( null !== $password && ! self::accepts_password( $password ) ) ) {
				throw new InvalidArgumentException();
			}
			$limit   = $sealed ? self::MAX_BYTES : PackageBlueprint::MAX_BYTES;
			$content = $zip->getFromIndex( 0, $limit + 1 );
			if ( ! is_string( $content ) || strlen( $content ) > $limit || ! $zip->close() ) {
				throw new InvalidArgumentException();
			}
			$json = null === $password ? $content : self::unseal( $content, $password, $code );
			// A credential is accepted only from a sealed entry, and a sealed entry always carries one.
			$code      = $sealed ? 'document_invalid' : null;
			$blueprint = PackageBlueprint::from_json( $json );
			if ( ( array() !== $blueprint->credentials ) !== $sealed ) {
				$code = $sealed ? 'document_invalid' : 'credentials_unsealed';
				throw new InvalidArgumentException();
			}

			return $blueprint;
		} catch ( Throwable ) {
			if ( null !== $code ) {
				self::log_refusal( $code );
			}
			throw new InvalidArgumentException( 'The portability archive is invalid.' );
		} finally {
			restore_error_handler();
		}
	}

	/**
	 * At least 20 and at most 256 characters, with at least 10 distinct ones.
	 * Input over 1,024 bytes is refused before it is split into characters.
	 *
	 * @phpstan-assert-if-true =string $password
	 */
	public static function accepts_password( ?string $password ): bool {
		if ( null === $password || strlen( $password ) > 1024 || 1 === preg_match( '/[\x00-\x1F\x7F]/', $password ) ) {
			return false;
		}
		$characters = preg_split( '//u', $password, -1, PREG_SPLIT_NO_EMPTY );

		return is_array( $characters ) && count( $characters ) >= 20 && count( $characters ) <= 256 && count( array_unique( $characters ) ) >= 10;
	}

	/** WordPress's sodium_compat defines the functions but cannot derive an Argon2id key, so only the extension can seal. */
	public static function sealing_available(): bool {
		return extension_loaded( 'sodium' );
	}

	private static function seal( string $json, #[\SensitiveParameter] string $password ): string {
		if ( ! self::accepts_password( $password ) ) {
			throw new InvalidArgumentException();
		}
		$nonce  = random_bytes( SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES );
		$header = array(
			'format'   => self::SEAL_FORMAT,
			'version'  => self::SEAL_VERSION,
			'kdf'      => 'argon2id13',
			'opslimit' => self::PWHASH_PARAMETERS[0][0],
			'memlimit' => self::PWHASH_PARAMETERS[0][1],
			'salt'     => sodium_bin2base64( random_bytes( SODIUM_CRYPTO_PWHASH_SALTBYTES ), SODIUM_BASE64_VARIANT_ORIGINAL ),
			'nonce'    => sodium_bin2base64( $nonce, SODIUM_BASE64_VARIANT_ORIGINAL ),
		);
		$key    = self::sealing_key( $password, $header );
		try {
			$encrypted = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt( $json, self::encode( $header ), $nonce, $key );
		} finally {
			sodium_memzero( $key );
		}

		return self::encode( $header + array( 'ciphertext' => sodium_bin2base64( $encrypted, SODIUM_BASE64_VARIANT_ORIGINAL ) ) );
	}

	/** @param-out string $code */
	private static function unseal( string $envelope, #[\SensitiveParameter] string $password, ?string &$code ): string {
		if ( ! self::sealing_available() ) {
			$code = 'sealing_unavailable';
			throw new InvalidArgumentException();
		}
		$code   = 'unseal_failed';
		$header = json_decode( $envelope, true, 2, JSON_THROW_ON_ERROR );
		// Only the exact canonical bytes are accepted, so no whitespace, escape or duplicate member variant authenticates as the same header.
		if ( ! is_array( $header ) || array( 'format', 'version', 'kdf', 'opslimit', 'memlimit', 'salt', 'nonce', 'ciphertext' ) !== array_keys( $header )
			|| ! hash_equals( self::encode( $header ), $envelope ) || self::SEAL_FORMAT !== $header['format'] || self::SEAL_VERSION !== $header['version'] || 'argon2id13' !== $header['kdf'] ) {
			throw new InvalidArgumentException();
		}
		// A crafted header must not choose the memory or time spent deriving the key.
		if ( ! in_array( array( $header['opslimit'], $header['memlimit'] ), self::PWHASH_PARAMETERS, true ) ) {
			$code = 'seal_parameters_rejected';
			throw new InvalidArgumentException();
		}
		// Everything that can fail is checked before a key exists; libsodium still refuses a salt of the wrong length.
		$nonce      = self::decoded( $header['nonce'] );
		$ciphertext = self::decoded( $header['ciphertext'] );
		if ( SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES !== strlen( $nonce ) || strlen( $ciphertext ) < SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_ABYTES ) {
			throw new InvalidArgumentException();
		}
		unset( $header['ciphertext'] );
		$key = self::sealing_key( $password, $header );
		try {
			$json = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt( $ciphertext, self::encode( $header ), $nonce, $key );
		} finally {
			sodium_memzero( $key );
		}
		if ( ! is_string( $json ) ) {
			throw new InvalidArgumentException();
		}

		return $json;
	}

	/** @param array<string, mixed> $header */
	private static function sealing_key( #[\SensitiveParameter] string $password, array $header ): string {
		if ( ! is_int( $header['opslimit'] ) || ! is_int( $header['memlimit'] ) ) {
			throw new InvalidArgumentException();
		}

		return sodium_crypto_pwhash( SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES, $password, self::decoded( $header['salt'] ), $header['opslimit'], $header['memlimit'], SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13 );
	}

	private static function decoded( mixed $value ): string {
		if ( ! is_string( $value ) ) {
			throw new InvalidArgumentException();
		}

		return sodium_base642bin( $value, SODIUM_BASE64_VARIANT_ORIGINAL );
	}

	/** @param array<string, mixed> $value */
	private static function encode( array $value ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Exact authenticated bytes; exceptions fail closed.
		return json_encode( $value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES );
	}

	private static function log_refusal( string $code ): void {
		BoosterLogger::log(
			'Blueprint archive refused.',
			array(
				'operation'    => 'portability_archive',
				'outcome_code' => $code,
			)
		);
	}
}
