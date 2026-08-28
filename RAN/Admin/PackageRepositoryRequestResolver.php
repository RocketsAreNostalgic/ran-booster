<?php

declare(strict_types=1);

namespace RAN\Admin;

use InvalidArgumentException;
use RAN\Deployment\DeploymentPolicy;
use RAN\PackageSubdirectory;
use RAN\RepositoryProvider\CredentialedPublicRepositoryBrowser;
use RAN\RepositoryProvider\ProviderCode;
use RAN\RepositoryProvider\ProviderRegistry;
use RAN\RepositoryProvider\RepositoryLookupRequest;
use RAN\RepositoryProvider\WebhookNormalizer;
use RuntimeException;

/**
 * Replaces browser-supplied repository metadata with provider-verified values.
 */
final readonly class PackageRepositoryRequestResolver {

	public function __construct( private ProviderRegistry $providers ) {
	}

	/**
	 * @param array<string, mixed> $request Package form request.
	 * @return array<string, mixed>
	 */
	public function resolve( array $request ): array {
		return $this->resolveRequest( $request );
	}

	/**
	 * Resolve one controller-authorized public lookup without persisting it as package access.
	 *
	 * @param array<string, mixed> $request Package form request.
	 * @return array<string, mixed>
	 */
	public function resolveWithTrustedPublicLookupProfile( array $request, ?string $profileId ): array {
		if ( null !== $profileId && 1 !== preg_match( '/^[A-Za-z0-9_-]{3,64}$/D', $profileId ) ) {
			throw new InvalidArgumentException( 'Choose a valid public repository lookup profile.' );
		}

		return $this->resolveRequest( $request, $profileId, true );
	}

	/**
	 * @param array<string, mixed> $request Package form request.
	 * @return array<string, mixed>
	 */
	private function resolveRequest( array $request, ?string $trustedPublicLookupId = null, bool $trustedPublicLookup = false ): array {
		$providerInput = $request['provider'] ?? null;
		if ( ! is_string( $providerInput ) ) {
			throw new InvalidArgumentException( 'Choose a repository provider.' );
		}

		$provider  = ProviderCode::parse( wp_unslash( $providerInput ) );
		$aggregate = $this->providers->get( $provider );

		$deploymentPolicyInput = $request['deployment_policy'] ?? DeploymentPolicy::MANUAL->value;
		if ( ! is_string( $deploymentPolicyInput ) ) {
			throw new InvalidArgumentException( 'Choose a valid deployment policy.' );
		}
		try {
			$deploymentPolicy = DeploymentPolicy::fromDatabase( $deploymentPolicyInput );
		} catch ( InvalidArgumentException ) {
			throw new InvalidArgumentException( 'Choose a valid deployment policy.' );
		}

		if ( DeploymentPolicy::AUTOMATIC === $deploymentPolicy ) {
			$this->providers->requireCapability( $provider, WebhookNormalizer::class );
		}

		$repositoryInput = $request['repository'] ?? null;
		if ( ! is_string( $repositoryInput ) ) {
			throw new InvalidArgumentException( 'Enter a repository account and name.' );
		}

		$credentialInput = $request['credential_id'] ?? null;
		$credentialId    = is_string( $credentialInput ) ? trim( wp_unslash( $credentialInput ) ) : '';
		if ( '' !== $credentialId && 1 !== preg_match( '/^[A-Za-z0-9_-]{3,64}$/', $credentialId ) ) {
			throw new InvalidArgumentException( 'Choose a valid repository credential.' );
		}

		$publicLookupInput = $request['public_lookup_profile_id'] ?? null;
		if ( array_key_exists( 'public_lookup_profile_id', $request ) && ! is_string( $publicLookupInput ) ) {
			throw new InvalidArgumentException( 'Choose a valid public repository lookup profile.' );
		}
		$publicLookupId = is_string( $publicLookupInput ) ? trim( wp_unslash( $publicLookupInput ) ) : '';
		if ( '' !== $publicLookupId && 1 !== preg_match( '/^[A-Za-z0-9_-]{3,64}$/D', $publicLookupId ) ) {
			throw new InvalidArgumentException( 'Choose a valid public repository lookup profile.' );
		}

		$identitySourceInput = $request['provider_repository_identity_source'] ?? '';
		$identitySource      = is_string( $identitySourceInput ) ? sanitize_key( wp_unslash( $identitySourceInput ) ) : '';
		$publicPicker        = 'picker' === $identitySource && '' === $credentialId;

		if ( '' !== $publicLookupId ) {
			if ( ! $publicPicker ) {
				throw new InvalidArgumentException( 'Public repository lookup identity conflicts with package access.' );
			}
			$this->providers->requireCapability( $provider, CredentialedPublicRepositoryBrowser::class );
		}
		if ( $trustedPublicLookup ) {
			$browser = $this->providers->requireCapability( $provider, CredentialedPublicRepositoryBrowser::class );
			if ( ! $browser->getPublicRepositoryBrowseMetadata()->supportsProviderDefaultProfile ) {
				throw new InvalidArgumentException( 'A default public repository lookup profile is unavailable for this provider.' );
			}
			$publicPicker = true;
		}

		$verificationCredentialId = $trustedPublicLookup
			? $trustedPublicLookupId
			: ( '' !== $publicLookupId ? $publicLookupId : $credentialId );
		$repository               = $aggregate->resolveRepository(
			new RepositoryLookupRequest(
				wp_unslash( $repositoryInput ),
				'' === $verificationCredentialId ? null : $verificationCredentialId,
				$publicPicker
			)
		);

		if ( ! $repository->provider->equals( $provider )
			|| $repository->credentialId !== ( '' === $verificationCredentialId ? null : $verificationCredentialId )
			|| ( $publicPicker && $repository->private ) ) {
			throw new RuntimeException( 'Repository provider returned mismatched repository identity.' );
		}

		$branchInput = $request['branch'] ?? '';
		$branch      = is_string( $branchInput ) ? trim( sanitize_text_field( wp_unslash( $branchInput ) ) ) : '';

		$subdirectoryInput = $request['subdirectory'] ?? null;
		$subdirectory      = PackageSubdirectory::normalize( is_string( $subdirectoryInput ) ? wp_unslash( $subdirectoryInput ) : $subdirectoryInput );

		$request['provider']                            = $provider->value;
		$request['repository']                          = $repository->locator;
		$request['package_slug']                        = PackageSubdirectory::installationSlug( $repository->packageSlug, $subdirectory );
		$request['subdirectory']                        = $subdirectory ?? '';
		$request['provider_repository_id']              = $repository->providerRepositoryId;
		$request['provider_repository_identity_source'] = 'resolved';
		$request['repository_default_branch']           = $repository->defaultBranch;
		$request['private']                             = $repository->private ? '1' : '0';
		$request['credential_id']                       = null !== $trustedPublicLookupId
			? $credentialId
			: ( $publicPicker ? '' : $repository->credentialId ?? '' );
		$request['branch']                              = '' === $branch ? $repository->defaultBranch : $branch;
		$request['deployment_policy']                   = $deploymentPolicy->value;
		unset( $request['public_lookup_profile_id'] );

		return $request;
	}
}
