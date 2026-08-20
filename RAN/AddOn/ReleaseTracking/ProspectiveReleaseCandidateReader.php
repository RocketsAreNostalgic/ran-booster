<?php

declare(strict_types=1);

namespace RAN\AddOn\ReleaseTracking;

use InvalidArgumentException;
use RAN\Admin\PackageRepositoryRequestResolver;
use RAN\RepositoryProvider\ProviderRegistry;
use RAN\RepositoryProvider\RepositoryReleaseCandidateListing;
use RAN\Runtime\RuntimeSupport;
use RAN\Runtime\UnsupportedRuntimeException;
use RAN\Deployment\DeploymentPolicy;
use RAN\RepositoryProvider\RepositoryReference;

/** @internal Shared Core implementation for one prospective candidate read. */
class ProspectiveReleaseCandidateReader {
	public function __construct(
		private readonly PackageRepositoryRequestResolver $repositories,
		private readonly ProviderRegistry $providers
	) {
	}

	/** @param array<string, mixed> $repositoryRequest */
	public function read( string $type, array $repositoryRequest, string $channel ): ProspectiveReleaseResult {
		if ( ! RuntimeSupport::current()->allowsManagedOperations() ) {
			return ProspectiveReleaseResult::failure( UnsupportedRuntimeException::ERROR_CODE );
		}
		if ( ! in_array( $type, array( 'plugin', 'theme' ), true ) || ! in_array( $channel, array( 'stable', 'prerelease' ), true ) ) {
			return ProspectiveReleaseResult::failure( 'forbidden' );
		}
		$provider = $repositoryRequest['provider'] ?? null;
		if ( ! is_string( $provider ) ) {
			return ProspectiveReleaseResult::failure( 'unsupported_provider' );
		}
		$listing = $this->providers->requireCapability( $provider, RepositoryReleaseCandidateListing::class );
		if ( ! $listing instanceof RepositoryReleaseCandidateListing ) {
			return ProspectiveReleaseResult::failure( 'unsupported_provider' );
		}
		$repositoryRequest['deployment_policy'] = DeploymentPolicy::MANUAL->value;
		$repositoryRequest['subdirectory']      = '';
		$repository = $this->repositories->resolve( $repositoryRequest );
		$reference  = new RepositoryReference(
			(string) ( $repository['repository'] ?? '' ),
			is_string( $repository['provider_repository_id'] ?? null ) && '' !== $repository['provider_repository_id'] ? $repository['provider_repository_id'] : null,
			'1' === ( $repository['private'] ?? null ),
			is_string( $repository['credential_id'] ?? null ) && '' !== $repository['credential_id'] ? $repository['credential_id'] : null
		);
			$result = $listing->listReleaseCandidates( $type, $reference, $channel );
			if ( array() === $result->candidates ) {
				return ProspectiveReleaseResult::failure( 'no_releases' );
			}
			$candidates = array();
			foreach ( $result->candidates as $candidate ) {
				if ( 'stable' === $channel && $candidate->prerelease ) {
					throw new InvalidArgumentException( 'The release candidate conflicts with the requested channel.' );
				}
				$releaseId = filter_var( $candidate->providerReleaseId, FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1 ) ) );
				if ( 1 !== preg_match( '/\A[1-9][0-9]*\z/D', $candidate->providerReleaseId ) || false === $releaseId ) {
					throw new InvalidArgumentException( 'The release candidate identity is incompatible.' );
				}
				$candidates[] = array( 'release_id' => $releaseId, 'tag' => $candidate->tag, 'version' => $candidate->version, 'prerelease' => $candidate->prerelease, 'published_at' => $candidate->publishedAt, 'expected_asset_names' => $candidate->expectedAssetNames );
			}
		return ProspectiveReleaseResult::success( 'release_candidates_available', array( 'candidates' => $candidates, 'channel' => $channel ) );
	}
}
