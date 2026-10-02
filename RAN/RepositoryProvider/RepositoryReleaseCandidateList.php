<?php

declare(strict_types=1);

namespace RAN\RepositoryProvider;

use InvalidArgumentException;

final readonly class RepositoryReleaseCandidateList {

	/** @param list<RepositoryReleaseCandidate> $candidates */
	public function __construct( public array $candidates ) {
		if ( ! array_is_list( $candidates ) || count( $candidates ) > 8 ) {
			throw new InvalidArgumentException( 'Repository release candidates must be a bounded list.' );
		}
		$release_ids = array();
		foreach ( $candidates as $candidate ) {
			if ( ! $candidate instanceof RepositoryReleaseCandidate ) {
				throw new InvalidArgumentException( 'Repository release candidates must be typed values.' );
			}
			if ( isset( $release_ids[ $candidate->provider_release_id ] ) ) {
				throw new InvalidArgumentException( 'Repository release candidate identities must be unique.' );
			}
			$release_ids[ $candidate->provider_release_id ] = true;
		}
	}
}
