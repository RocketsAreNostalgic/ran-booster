<?php

declare(strict_types=1);

namespace RAN\RepositoryProvider;

use InvalidArgumentException;

final readonly class RepositoryReleaseCandidate {
	private const UTC_PATTERN = '/\A(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})(?:\.\d{1,6})?Z\z/D';

	/** @param list<string> $expected_asset_names */
	public function __construct(
		public string $provider_release_id,
		public string $tag,
		public string $version,
		public bool $prerelease,
		public string $published_at,
		public array $expected_asset_names
	) {
		if ( 1 !== preg_match( '/\A[^\x00-\x1F\x7F]{1,191}\z/D', $provider_release_id )
			|| 1 !== preg_match( '/\A[^\x00-\x1F\x7F]{1,100}\z/D', $tag )
			|| 1 !== preg_match( '/\A[A-Za-z0-9][A-Za-z0-9._+-]{0,63}\z/D', $version )
			|| ! self::valid_utc_timestamp( $published_at )
			|| ! array_is_list( $expected_asset_names )
			|| count( $expected_asset_names ) > 8 ) {
			throw new InvalidArgumentException( 'The repository release candidate is invalid.' );
		}
		foreach ( $expected_asset_names as $asset_name ) {
			if ( ! is_string( $asset_name )
				|| strlen( $asset_name ) > 220
				|| ! str_ends_with( strtolower( $asset_name ), '.zip' )
				|| 1 === preg_match( '/[\x00-\x20\x7f]/', $asset_name ) ) {
				throw new InvalidArgumentException( 'The repository release candidate is invalid.' );
			}
		}
	}

	private static function valid_utc_timestamp( string $value ): bool {
		return 1 === preg_match( self::UTC_PATTERN, $value, $matches )
			&& checkdate( (int) $matches[2], (int) $matches[3], (int) $matches[1] )
			&& (int) $matches[4] <= 23
			&& (int) $matches[5] <= 59
			&& (int) $matches[6] <= 59;
	}
}
