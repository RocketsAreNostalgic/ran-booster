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

	public function read( string $type, array $repository_request, string $channel ): ProspectiveReleaseResult {
		if ( ! RuntimeSupport::current()->allows_managed_operations() ) {
			return ProspectiveReleaseResult::failure( UnsupportedRuntimeException::ERROR_CODE );
		}
		if ( ! in_array( $type, array( 'plugin', 'theme' ), true ) || ! in_array( $channel, array( 'stable', 'prerelease' ), true ) ) {
			return ProspectiveReleaseResult::failure( 'forbidden' );
		}

		$provider = $repository_request['provider'] ?? null;
		if ( ! is_string( $provider ) ) {
			return ProspectiveReleaseResult::failure( 'unsupported_provider' );
		}
		$capabilities = $this->release_capabilities( $provider );
		if ( null === $capabilities ) {
			return ProspectiveReleaseResult::failure( 'unsupported_provider' );
		}
		$listing = $capabilities['listing'];

		try {

			$repository_request['deployment_policy'] = DeploymentPolicy::MANUAL->value;

			$repository_request['subdirectory'] = '';

			$repository = $this->repositories->resolve( $repository_request );
			$reference  = new RepositoryReference(
				(string) ( $repository['repository'] ?? '' ),
				is_string( $repository['provider_repository_id'] ?? null ) && '' !== $repository['provider_repository_id'] ? $repository['provider_repository_id'] : null,
				'1' === ( $repository['private'] ?? null ),
				is_string( $repository['credential_id'] ?? null ) && '' !== $repository['credential_id'] ? $repository['credential_id'] : null
			);
			$result     = $listing->list_release_candidates( $type, $reference, $channel );
			if ( array() === $result->candidates ) {
				return ProspectiveReleaseResult::failure( 'no_releases' );
			}

			$candidates = array();
			foreach ( $result->candidates as $candidate ) {
				if ( 'stable' === $channel && $candidate->prerelease ) {
					throw new InvalidArgumentException( 'The release candidate conflicts with the requested channel.' );
				}

				$release_id = $candidate->provider_release_id;
				if ( 1 !== preg_match( '/\A[^\x00-\x1F\x7F]{1,191}\z/D', $release_id ) ) {
					throw new InvalidArgumentException( 'The release candidate identity is incompatible.' );
				}
				$candidates[] = array(
					'release_id'           => $release_id,
					'tag'                  => $candidate->tag,
					'version'              => $candidate->version,
					'prerelease'           => $candidate->prerelease,

					'published_at'         => $candidate->published_at,

					'expected_asset_names' => $candidate->expected_asset_names,
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


	public function supports_provider_code( string $provider ): bool {
		return null !== $this->release_capabilities( $provider );
	}

	/**
	 * Resolve the complete prospective-release provider tuple before accepting
	 * any provider for candidate work.
	 *
	 * @return array{listing: RepositoryReleaseCandidateListing}|null
	 */
	private function release_capabilities( string $provider ): ?array {
		try {
			$listing        = $this->providers->require_capability( $provider, RepositoryReleaseCandidateListing::class );
			$inspector      = $this->providers->require_capability( $provider, RepositoryReleaseInspector::class );
			$acquirer       = $this->providers->require_capability( $provider, RepositoryReleaseAcquirer::class );
			$metadata       = $this->providers->require_capability( $provider, RepositoryReleaseMetadata::class );
			$native_targets = $this->providers->require_capability( $provider, RepositoryReleaseNativeTargets::class );
		} catch ( Throwable ) {
			return null;
		}

		if ( ! $listing instanceof RepositoryReleaseCandidateListing
			|| ! $inspector instanceof RepositoryReleaseInspector
			|| ! $acquirer instanceof RepositoryReleaseAcquirer
			|| ! $metadata instanceof RepositoryReleaseMetadata
			|| ! $native_targets instanceof RepositoryReleaseNativeTargets ) {
			return null;
		}

		return array( 'listing' => $listing );
	}
}
