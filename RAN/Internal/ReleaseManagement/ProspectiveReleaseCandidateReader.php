<?php

declare(strict_types=1);

namespace RAN\Internal\ReleaseManagement;

use InvalidArgumentException;
use RAN\AddOn\ReleaseTracking\ProspectiveReleaseResult;
use RAN\Admin\PackageRepositoryRequestResolver;
use RAN\Deployment\DeploymentPolicy;
use RAN\RepositoryProvider\ProviderRegistry;
use RAN\RepositoryProvider\RepositoryReference;
use RAN\RepositoryProvider\RepositoryReleaseAcquirer;
use RAN\RepositoryProvider\RepositoryReleaseCandidateListing;
use RAN\RepositoryProvider\RepositoryReleaseInspector;
use RAN\RepositoryProvider\RepositoryReleaseMetadata;
use RAN\RepositoryProvider\RepositoryReleaseNativeTargets;
use RAN\Runtime\RuntimeSupport;
use RAN\Runtime\UnsupportedRuntimeException;
use Throwable;

/** @internal Stateless Core reader for one prospective candidate request. */
final class ProspectiveReleaseCandidateReader {
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
		$capabilities = $this->releaseCapabilities( $provider );
		if ( null === $capabilities ) {
			return ProspectiveReleaseResult::failure( 'unsupported_provider' );
		}
		$listing = $capabilities['listing'];

		try {
			$repositoryRequest['deployment_policy'] = DeploymentPolicy::MANUAL->value;
			$repositoryRequest['subdirectory']      = '';
			$repository                             = $this->repositories->resolve( $repositoryRequest );
			$reference                              = new RepositoryReference(
				(string) ( $repository['repository'] ?? '' ),
				is_string( $repository['provider_repository_id'] ?? null ) && '' !== $repository['provider_repository_id'] ? $repository['provider_repository_id'] : null,
				'1' === ( $repository['private'] ?? null ),
				is_string( $repository['credential_id'] ?? null ) && '' !== $repository['credential_id'] ? $repository['credential_id'] : null
			);
			$result                                 = $listing->listReleaseCandidates( $type, $reference, $channel );
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
				$candidates[] = array(
					'release_id'           => $releaseId,
					'tag'                  => $candidate->tag,
					'version'              => $candidate->version,
					'prerelease'           => $candidate->prerelease,
					'published_at'         => $candidate->publishedAt,
					'expected_asset_names' => $candidate->expectedAssetNames,
				);
			}

			return ProspectiveReleaseResult::success(
				'release_candidates_available',
				array(
					'candidates' => $candidates,
					'channel'    => $channel,
				)
			);
		} catch ( Throwable ) {
			return ProspectiveReleaseResult::failure( 'unable_to_check' );
		}
	}

	public function supportsProviderCode( string $provider ): bool {
		return null !== $this->releaseCapabilities( $provider );
	}

	/**
	 * Resolve the complete prospective-release provider tuple before accepting
	 * any provider for candidate work.
	 *
	 * @return array{listing: RepositoryReleaseCandidateListing}|null
	 */
	private function releaseCapabilities( string $provider ): ?array {
		try {
			$listing       = $this->providers->requireCapability( $provider, RepositoryReleaseCandidateListing::class );
			$inspector     = $this->providers->requireCapability( $provider, RepositoryReleaseInspector::class );
			$acquirer      = $this->providers->requireCapability( $provider, RepositoryReleaseAcquirer::class );
			$metadata      = $this->providers->requireCapability( $provider, RepositoryReleaseMetadata::class );
			$nativeTargets = $this->providers->requireCapability( $provider, RepositoryReleaseNativeTargets::class );
		} catch ( Throwable ) {
			return null;
		}

		if ( ! $listing instanceof RepositoryReleaseCandidateListing
			|| ! $inspector instanceof RepositoryReleaseInspector
			|| ! $acquirer instanceof RepositoryReleaseAcquirer
			|| ! $metadata instanceof RepositoryReleaseMetadata
			|| ! $nativeTargets instanceof RepositoryReleaseNativeTargets ) {
			return null;
		}

		return array( 'listing' => $listing );
	}
}
