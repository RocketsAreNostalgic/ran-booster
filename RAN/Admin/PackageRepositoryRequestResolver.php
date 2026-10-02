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
		return $this->resolve_request( $request );
	}

	/**
	 * Resolve one controller-authorized public lookup without persisting it as package access.
	 *
	 * @param array<string, mixed> $request Package form request.
	 * @return array<string, mixed>
	 */
	public function resolve_with_trusted_public_lookup_profile( array $request, ?string $profile_id ): array {
		if ( null !== $profile_id && 1 !== preg_match( '/^[A-Za-z0-9_-]{3,64}$/D', $profile_id ) ) {
			throw new InvalidArgumentException( 'Choose a valid public repository lookup profile.' );
		}

		return $this->resolve_request( $request, $profile_id, true );
	}

	/**
	 * @param array<string, mixed> $request Package form request.
	 * @return array<string, mixed>
	 */
	private function resolve_request( array $request, ?string $trusted_public_lookup_id = null, bool $trusted_public_lookup = false ): array {
		$provider_input = $request['provider'] ?? null;
		if ( ! is_string( $provider_input ) ) {
			throw new InvalidArgumentException( 'Choose a repository provider.' );
		}

		$provider  = ProviderCode::parse( wp_unslash( $provider_input ) );
		$aggregate = $this->providers->get( $provider );

		$deployment_policy_input = $request['deployment_policy'] ?? DeploymentPolicy::MANUAL->value;
		if ( ! is_string( $deployment_policy_input ) ) {
			throw new InvalidArgumentException( 'Choose a valid deployment policy.' );
		}
		try {
			$deployment_policy = DeploymentPolicy::from_database( $deployment_policy_input );
		} catch ( InvalidArgumentException ) {
			throw new InvalidArgumentException( 'Choose a valid deployment policy.' );
		}

		if ( DeploymentPolicy::AUTOMATIC === $deployment_policy ) {
			$this->providers->require_capability( $provider, WebhookNormalizer::class );
		}

		$repository_input = $request['repository'] ?? null;
		if ( ! is_string( $repository_input ) ) {
			throw new InvalidArgumentException( 'Enter a repository account and name.' );
		}
		$subdirectory_input = $request['subdirectory'] ?? null;
		$subdirectory       = PackageSubdirectory::normalize( is_string( $subdirectory_input ) ? wp_unslash( $subdirectory_input ) : $subdirectory_input );

		$credential_input = $request['credential_id'] ?? null;
		$credential_id    = is_string( $credential_input ) ? trim( wp_unslash( $credential_input ) ) : '';
		if ( '' !== $credential_id && 1 !== preg_match( '/^[A-Za-z0-9_-]{3,64}$/', $credential_id ) ) {
			throw new InvalidArgumentException( 'Choose a valid repository credential.' );
		}

		$public_lookup_input = $request['public_lookup_profile_id'] ?? null;
		if ( array_key_exists( 'public_lookup_profile_id', $request ) && ! is_string( $public_lookup_input ) ) {
			throw new InvalidArgumentException( 'Choose a valid public repository lookup profile.' );
		}
		$public_lookup_id = is_string( $public_lookup_input ) ? trim( wp_unslash( $public_lookup_input ) ) : '';
		if ( '' !== $public_lookup_id && 1 !== preg_match( '/^[A-Za-z0-9_-]{3,64}$/D', $public_lookup_id ) ) {
			throw new InvalidArgumentException( 'Choose a valid public repository lookup profile.' );
		}

		$identity_source_input = $request['provider_repository_identity_source'] ?? '';
		$identity_source       = is_string( $identity_source_input ) ? sanitize_key( wp_unslash( $identity_source_input ) ) : '';
		$public_picker         = 'picker' === $identity_source && '' === $credential_id;

		if ( '' !== $public_lookup_id ) {
			if ( ! $public_picker ) {
				throw new InvalidArgumentException( 'Public repository lookup identity conflicts with package access.' );
			}
			$this->providers->require_capability( $provider, CredentialedPublicRepositoryBrowser::class );
		}
		if ( $trusted_public_lookup ) {
			$browser = $this->providers->require_capability( $provider, CredentialedPublicRepositoryBrowser::class );
			if ( ! $browser->get_public_repository_browse_metadata()->supports_provider_default_profile ) {
				throw new InvalidArgumentException( 'A default public repository lookup profile is unavailable for this provider.' );
			}
			$public_picker = true;
		}

		$verification_credential_id = $trusted_public_lookup
			? $trusted_public_lookup_id
			: ( '' !== $public_lookup_id ? $public_lookup_id : $credential_id );
		$repository                 = $aggregate->resolve_repository(
			new RepositoryLookupRequest(
				wp_unslash( $repository_input ),
				'' === $verification_credential_id ? null : $verification_credential_id,
				$public_picker
			)
		);

		if ( ! $repository->provider->equals( $provider )

			|| ( '' === $verification_credential_id ? null : $verification_credential_id ) !== $repository->credential_id
			|| ( $public_picker && $repository->private ) ) {
			throw new RuntimeException( 'Repository provider returned mismatched repository identity.' );
		}

		$branch_input = $request['branch'] ?? '';
		$branch       = is_string( $branch_input ) ? trim( sanitize_text_field( wp_unslash( $branch_input ) ) ) : '';

		$request['provider']   = $provider->value;
		$request['repository'] = $repository->locator;

		$request['package_slug'] = PackageSubdirectory::installation_slug( $repository->package_slug, $subdirectory );
		$request['subdirectory'] = $subdirectory ?? '';

		$request['provider_repository_id']              = $repository->provider_repository_id;
		$request['provider_repository_identity_source'] = 'resolved';

		$request['repository_default_branch'] = $repository->default_branch;
		$request['private']                   = $repository->private ? '1' : '0';
		$request['credential_id']             = $trusted_public_lookup
			? $credential_id

			: ( $public_picker ? '' : $repository->credential_id ?? '' );

		$request['branch']            = '' === $branch ? $repository->default_branch : $branch;
		$request['deployment_policy'] = $deployment_policy->value;
		unset( $request['public_lookup_profile_id'] );

		return $request;
	}
}
