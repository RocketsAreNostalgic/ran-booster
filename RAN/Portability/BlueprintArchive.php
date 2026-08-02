<?php

declare(strict_types=1);

namespace RAN\Portability;

use InvalidArgumentException;
use Throwable;
use ZipArchive;

/** The single, non-extracting ZIP transport for portability blueprints. */
final class BlueprintArchive {

	public const FILENAME  = 'ran-booster-blueprint.zip';
	public const ENTRY     = 'blueprint.json';
	public const MAX_BYTES = 1048576;

	public function writeTo( string $path, #[\SensitiveParameter] PackageBlueprint $blueprint, #[\SensitiveParameter] ?string $password ): void {
		$password  = '' === $password ? null : $password;
		$encrypted = array() !== $blueprint->credentials;
		if ( $encrypted !== ( null !== $password ) || ( $encrypted && ! self::validPassword( $password ) ) || ! self::zipAvailable( $encrypted ) ) {
			throw new InvalidArgumentException( 'The portability archive could not be written.' );
		}

		$zip = new ZipArchive();
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Contains libzip warnings inside the normalized public error boundary.
		set_error_handler( static fn(): bool => true );
		try {
			if ( true !== $zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE )
				|| ! $zip->addFromString( self::ENTRY, $blueprint->canonicalJson() )
				|| ( $encrypted && ( ! $zip->setPassword( $password ) || ! $zip->setEncryptionName( self::ENTRY, ZipArchive::EM_AES_256 ) ) )
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

	public function readFrom( string $path, #[\SensitiveParameter] ?string $password ): PackageBlueprint {
		$password = '' === $password ? null : $password;
		if ( ! self::zipAvailable() || ! is_file( $path ) || 0 === filesize( $path ) || filesize( $path ) > self::MAX_BYTES ) {
			throw new InvalidArgumentException( 'The portability archive is invalid.' );
		}

		$zip = new ZipArchive();
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Contains libzip warnings inside the normalized public error boundary.
		set_error_handler( static fn(): bool => true );
		try {
			if ( true !== $zip->open( $path, ZipArchive::RDONLY | ZipArchive::CHECKCONS ) || 1 !== $zip->numFiles ) {
				throw new InvalidArgumentException();
			}
			$stat       = $zip->statIndex( 0 );
			$encryption = is_array( $stat ) ? ( $stat['encryption_method'] ?? ZipArchive::EM_UNKNOWN ) : ZipArchive::EM_UNKNOWN;
			if ( ! is_array( $stat ) || self::ENTRY !== $stat['name'] || str_ends_with( $stat['name'], '/' ) || $stat['size'] > self::MAX_BYTES
				|| ! in_array( $encryption, array( ZipArchive::EM_NONE, ZipArchive::EM_AES_256 ), true )
				|| ( ZipArchive::EM_NONE === $encryption && null !== $password )
				|| ( ZipArchive::EM_AES_256 === $encryption && ( ! self::validPassword( $password ) || ! self::zipAvailable( true ) || ! $zip->setPassword( $password ) ) ) ) {
				throw new InvalidArgumentException();
			}
			$json = $zip->getFromIndex( 0, PackageBlueprint::MAX_BYTES + 1 );
			if ( ! is_string( $json ) || strlen( $json ) > PackageBlueprint::MAX_BYTES || ! $zip->close() ) {
				throw new InvalidArgumentException();
			}
			$blueprint = PackageBlueprint::fromJson( $json );
			if ( ( array() !== $blueprint->credentials ) !== ( ZipArchive::EM_AES_256 === $encryption ) ) {
				throw new InvalidArgumentException();
			}

			return $blueprint;
		} catch ( Throwable ) {
			throw new InvalidArgumentException( 'The portability archive is invalid.' );
		} finally {
			restore_error_handler();
		}
	}

	private static function validPassword( ?string $password ): bool {
		return null !== $password && strlen( $password ) >= 20 && strlen( $password ) <= 256 && 1 === preg_match( '//u', $password ) && ! preg_match( '/[\x00-\x1F\x7F]/', $password );
	}

	private static function zipAvailable( bool $aes = false ): bool {
		return class_exists( ZipArchive::class ) && ( ! $aes || ( defined( ZipArchive::class . '::EM_AES_256' ) && method_exists( ZipArchive::class, 'isEncryptionMethodSupported' ) && ZipArchive::isEncryptionMethodSupported( ZipArchive::EM_AES_256, true ) && ZipArchive::isEncryptionMethodSupported( ZipArchive::EM_AES_256, false ) ) );
	}
}
