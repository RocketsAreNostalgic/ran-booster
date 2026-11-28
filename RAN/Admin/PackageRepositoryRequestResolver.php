<?php

declare(strict_types=1);

namespace RAN\Admin;

use InvalidArgumentException;
use RAN\Deployment\DeploymentPolicy;
use RAN\Package;
use RAN\InvalidPackageSubdirectory;
use RAN\RepositoryProvider\ArchiveRequest;
use RAN\RepositoryProvider\RepositoryReference;
use RAN\UpdaterSupport\V1\PackageDirectory;
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
	 * Whether resolve() would look the repository up with no credential or lookup profile, so the
	 * provider sees an anonymous request.
	 *
	 * @param array<string, mixed> $request Package form request.
	 */
	public static function anonymous_lookup( array $request ): bool {
		return '' === self::request_id( $request['credential_id'] ?? null ) && '' === self::request_id( $request['public_lookup_profile_id'] ?? null );
	}

	/**
	 * Whether the request chooses a saved credential the package does not already use: a saved lookup
	 * profile, or any credential other than its own for its own repository. Its own counts only with
	 * the provider, repository and expected credential it holds, so the locked save still finds that
	 * credential and no other repository is looked up with it. Going anonymous chooses none.
	 *
	 * @param array<string, mixed> $request Package form request.
	 */
	public static function chooses_credential( array $request, ?Package $own ): bool {
		$credential_id = self::request_id( $request['credential_id'] ?? null );
		if ( '' !== self::request_id( $request['public_lookup_profile_id'] ?? null ) ) {
			return true;
		}

		return '' !== $credential_id && ( ! $own instanceof Package
			|| trim( $own->get_credential_id() ) !== $credential_id
			|| ! is_string( $request['provider'] ?? null ) || wp_unslash( $request['provider'] ) !== $own->get_provider_code()
			|| ! is_string( $request['repository'] ?? null ) || wp_unslash( $request['repository'] ) !== (string) $own->get_repository()
			|| ! is_scalar( $request['expected_credential_id'] ?? null ) || trim( (string) $request['expected_credential_id'] ) !== $credential_id );
	}

	/** A credential or lookup-profile ID from a request, normalized as resolution reads it. */
	private static function request_id( mixed $value ): string {
		return is_string( $value ) ? trim( wp_unslash( $value ) ) : '';
	}

	/**
	 * @param array<string, mixed> $request Package form request.
	 * @return array<string, mixed>
	 */
	public function resolve( array $request ): array {
		return $this->resolve_request( $request );
	}

	/** @param array<string,mixed> $request
	 * @param array{profile_id:?string}|null $trusted_public_lookup
	 * @return array<string,mixed> */
	public function resolve_branch( array $request, ?array $trusted_public_lookup = null ): array {
		return $this->resolve_request( $request, $trusted_public_lookup['profile_id'] ?? null, null !== $trusted_public_lookup, true );
	}

	/**
	 * Complete an edit request from the stored package when no provider-dependent field changed.
	 *
	 * Used only as a fallback when the provider lookup is rate-limited or unavailable: a save
	 * that keeps the same provider, repository, credential, subdirectory and branch can then
	 * proceed on the stored identity. Returns null when any of those fields differs, the branch field
	 * is empty or the stored identity is incomplete, so a changed repository is never accepted unverified.
	 *
	 * @param array<string, mixed> $request Package form request.
	 * @return array<string, mixed>|null
	 */
	public function resolve_unchanged( array $request, Package $stored ): ?array {
		$provider_input   = $request['provider'] ?? null;
		$repository_input = $request['repository'] ?? null;
		$stored_id        = $stored->get_provider_repository_id();
		if ( ! is_string( $provider_input ) || ! is_string( $repository_input ) || null === $stored_id || '' === $stored_id ) {
			return null;
		}

		$provider = ProviderCode::parse( wp_unslash( $provider_input ) );
		if ( $stored->get_provider_code() !== $provider->value || (string) $stored->get_repository() !== wp_unslash( $repository_input ) ) {
			return null;
		}

		$subdirectory_input = $request['subdirectory'] ?? null;
		try {
			$subdirectory = PackageDirectory::normalize( is_string( $subdirectory_input ) ? wp_unslash( $subdirectory_input ) : $subdirectory_input );
		} catch ( InvalidArgumentException ) {
			throw new InvalidPackageSubdirectory( 'The package subdirectory must be a normalized relative path.' );
		}
		$credential_input = $request['credential_id'] ?? null;
		$credential_id    = self::request_id( $credential_input );
		$public_lookup    = $request['public_lookup_profile_id'] ?? null;
		$identity_source  = is_string( $request['provider_repository_identity_source'] ?? null ) ? sanitize_key( wp_unslash( $request['provider_repository_identity_source'] ) ) : '';
		$expected_id      = $request['provider_repository_id'] ?? null;
		if ( ( $subdirectory ?? '' ) !== (string) $stored->get_subdirectory()
			|| $credential_id !== $stored->get_credential_id()
			|| 'picker' === $identity_source
			|| ( is_string( $public_lookup ) && '' !== trim( $public_lookup ) )
			|| ( is_string( $expected_id ) && '' !== $expected_id && ! hash_equals( $stored_id, $expected_id ) ) ) {
			return null;
		}

		$this->providers->get( $provider );
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

		$branch_input = $request['branch'] ?? '';
		$branch       = is_string( $branch_input ) ? trim( sanitize_text_field( wp_unslash( $branch_input ) ) ) : '';
		// An empty field asks for the repository's default branch, which only the provider knows; a different branch needs it too.
		if ( '' === $branch || $branch !== (string) $stored->get_branch() ) {
			return null;
		}

		$request['provider']               = $provider->value;
		$request['repository']             = (string) $stored->get_repository();
		$request['subdirectory']           = $subdirectory ?? '';
		$request['provider_repository_id'] = $stored_id;
		// The values come from the stored, previously verified package, never from the browser, so
		// they take the 'resolved' source. Any other source would make a credentialed public
		// repository private (see PackageOperation::from_input), which a fresh lookup would not.
		$request['provider_repository_identity_source'] = 'resolved';
		$request['private']                             = $stored->get_private() ? '1' : '0';
		$request['credential_id']                       = $stored->get_credential_id();
		$request['branch']                              = (string) $stored->get_branch();
		$request['deployment_policy']                   = $deployment_policy->value;
		unset( $request['public_lookup_profile_id'] );

		return $request;
	}

	/**
	 * @param array<string, mixed> $request Package form request.
	 * @return array<string, mixed>
	 */
	private function resolve_request( array $request, ?string $trusted_public_lookup_id = null, bool $trusted_public_lookup = false, bool $verify_branch = false ): array {
		if ( $trusted_public_lookup && null !== $trusted_public_lookup_id && 1 !== preg_match( '/^[A-Za-z0-9_-]{3,64}$/D', $trusted_public_lookup_id ) ) {
			throw new InvalidArgumentException( 'Choose a valid public repository lookup profile.' );
		}

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
		try {
			$subdirectory = PackageDirectory::normalize( is_string( $subdirectory_input ) ? wp_unslash( $subdirectory_input ) : $subdirectory_input );
		} catch ( InvalidArgumentException ) {
			throw new InvalidPackageSubdirectory( 'The package subdirectory must be a normalized relative path.' );
		}

		$credential_input = $request['credential_id'] ?? null;
		$credential_id    = self::request_id( $credential_input );
		if ( '' !== $credential_id && 1 !== preg_match( '/^[A-Za-z0-9_-]{3,64}$/D', $credential_id ) ) {
			throw new InvalidArgumentException( 'Choose a valid repository credential.' );
		}

		$public_lookup_input = $request['public_lookup_profile_id'] ?? null;
		if ( array_key_exists( 'public_lookup_profile_id', $request ) && ! is_string( $public_lookup_input ) ) {
			throw new InvalidArgumentException( 'Choose a valid public repository lookup profile.' );
		}
		$public_lookup_id = self::request_id( $public_lookup_input );
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

		// A supplied ID is an expectation; the fresh provider descriptor stays authoritative.
		$expected_repository_id = $request['provider_repository_id'] ?? null;
		if ( array_key_exists( 'provider_repository_id', $request ) && ! is_string( $expected_repository_id ) ) {
			throw new InvalidArgumentException( 'Choose a valid repository identity expectation.' );
		}
		if ( is_string( $expected_repository_id ) && '' !== $expected_repository_id
			&& ! hash_equals( $expected_repository_id, $repository->provider_repository_id ) ) {
			throw new RuntimeException( 'Repository identity no longer matches the provider resolution.' );
		}

		$branch_input = $request['branch'] ?? '';
		$branch       = is_string( $branch_input ) ? trim( sanitize_text_field( wp_unslash( $branch_input ) ) ) : '';

		$request['provider']   = $provider->value;
		$request['repository'] = $repository->locator;

		$request['package_slug'] = PackageDirectory::installation_slug( $repository->package_slug, $subdirectory );
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

		if ( $verify_branch ) {
			$archive = $aggregate->prepare_archive( new ArchiveRequest( RepositoryReference::from_descriptor( $repository ), $request['branch'] ) );
			try {
				$revision = $archive->get_resolved_ref();
				if ( '' === trim( $revision ) || strlen( $revision ) > 191 || preg_match( '/[\x00-\x1F\x7F]/', $revision ) ) {
					throw new RuntimeException( 'The repository branch could not be verified.' );
				}
			} finally {
				$archive->cleanup();
			}
		}

		return $request;
	}
}
