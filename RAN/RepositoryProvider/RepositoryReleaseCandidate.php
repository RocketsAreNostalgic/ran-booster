<?php

declare(strict_types=1);

namespace RAN\RepositoryProvider;

use InvalidArgumentException;

final readonly class RepositoryReleaseCandidate {
	private const UTC_PATTERN = '/\A(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})(?:\.\d{1,6})?Z\z/D';

	/** @param list<string> $expectedAssetNames */
	public function __construct(
		public string $providerReleaseId,
		public string $tag,
		public string $version,
		public bool $prerelease,
		public string $publishedAt,
		public array $expectedAssetNames
	) {
		if ( 1 !== preg_match( '/\A[^\x00-\x1F\x7F]{1,191}\z/D', $providerReleaseId )
			|| 1 !== preg_match( '/\A[^\x00-\x1F\x7F]{1,100}\z/D', $tag )
			|| 1 !== preg_match( '/\A[A-Za-z0-9][A-Za-z0-9._+-]{0,63}\z/D', $version )
			|| ! self::validUtcTimestamp( $publishedAt )
			|| ! array_is_list( $expectedAssetNames )
			|| count( $expectedAssetNames ) > 8 ) {
			throw new InvalidArgumentException( 'The repository release candidate is invalid.' );
		}
		foreach ( $expectedAssetNames as $assetName ) {
			if ( ! is_string( $assetName )
				|| strlen( $assetName ) > 220
				|| ! str_ends_with( strtolower( $assetName ), '.zip' )
				|| 1 === preg_match( '/[\x00-\x20\x7f]/', $assetName ) ) {
				throw new InvalidArgumentException( 'The repository release candidate is invalid.' );
			}
		}
	}

	private static function validUtcTimestamp( string $value ): bool {
		return 1 === preg_match( self::UTC_PATTERN, $value, $matches )
			&& checkdate( (int) $matches[2], (int) $matches[3], (int) $matches[1] )
			&& (int) $matches[4] <= 23
			&& (int) $matches[5] <= 59
			&& (int) $matches[6] <= 59;
	}
}
