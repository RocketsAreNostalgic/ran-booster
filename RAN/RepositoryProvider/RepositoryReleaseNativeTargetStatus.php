<?php

declare(strict_types=1);

namespace RAN\RepositoryProvider;

use InvalidArgumentException;

final readonly class RepositoryReleaseNativeTargetStatus {
	private const RELATIONSHIPS = array( '', 'newer', 'same', 'older', 'invalid' );

	public function __construct(
		public bool $active,
		public string $offeredVersion = '',
		public string $versionRelationship = '',
		public ?int $lastCheck = null,
		public ?int $nextCheck = null,
		public string $failureCode = '',
		public string $candidateCode = '',
		public string $candidateReleaseTag = '',
		public string $candidateReleaseVersion = '',
		public string $candidatePackageHeaderVersion = ''
	) {
		if ( ! in_array( $versionRelationship, self::RELATIONSHIPS, true )
			|| ! self::validVersion( $offeredVersion )
			|| ! self::validTime( $lastCheck )
			|| ! self::validTime( $nextCheck )
			|| ! self::validCode( $failureCode )
			|| ! self::validCode( $candidateCode )
			|| ! self::validText( $candidateReleaseTag, 100 )
			|| ! self::validVersion( $candidateReleaseVersion )
			|| ! self::validVersion( $candidatePackageHeaderVersion ) ) {
			throw new InvalidArgumentException( 'The repository release native target status is invalid.' );
		}
		$candidateValues = array(
			$candidateCode,
			$candidateReleaseTag,
			$candidateReleaseVersion,
		);
		if ( ( array() !== array_filter( $candidateValues, static fn ( string $value ): bool => '' === $value )
			&& array() !== array_filter( $candidateValues, static fn ( string $value ): bool => '' !== $value ) )
			|| ( '' === $candidateCode && '' !== $candidatePackageHeaderVersion ) ) {
			throw new InvalidArgumentException( 'The repository release native target status is invalid.' );
		}
	}

	private static function validTime( ?int $value ): bool {
		return null === $value || $value > 0;
	}

	private static function validCode( string $value ): bool {
		return '' === $value || 1 === preg_match( '/\A[a-z][a-z0-9_]{0,63}\z/D', $value );
	}

	private static function validVersion( string $value ): bool {
		return '' === $value || 1 === preg_match( '/\A[A-Za-z0-9][A-Za-z0-9._+-]{0,63}\z/D', $value );
	}

	private static function validText( string $value, int $maximumLength ): bool {
		return strlen( $value ) <= $maximumLength && 0 === preg_match( '/[\x00-\x1F\x7F]/', $value );
	}
}
