<?php

declare(strict_types=1);

namespace RAN\RepositoryProvider;

use InvalidArgumentException;

final readonly class RepositoryReleaseCandidate {

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
			|| 1 !== preg_match( '/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9:.+-]{5,30}Z?\z/D', $publishedAt )
			|| ! array_is_list( $expectedAssetNames )
			|| count( $expectedAssetNames ) > 8 ) {
			throw new InvalidArgumentException( 'The repository release candidate is invalid.' );
		}
		foreach ( $expectedAssetNames as $assetName ) {
			if ( ! is_string( $assetName )
				|| 1 !== preg_match( '/\A[A-Za-z0-9][A-Za-z0-9._-]{0,190}\z/D', $assetName ) ) {
				throw new InvalidArgumentException( 'The repository release candidate is invalid.' );
			}
		}
	}
}
