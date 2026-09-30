<?php

declare(strict_types=1);

namespace RAN\Admin;

use InvalidArgumentException;
use RAN\RepositoryProvider\CredentialedPublicRepositoryBrowser;
use RAN\RepositoryProvider\InvalidProviderCode;
use RAN\RepositoryProvider\ProviderCode;
use RAN\RepositoryProvider\ProviderRegistry;
use RAN\RepositoryProvider\RepositoryBrowseRequest;
use RAN\RepositoryProvider\RepositoryBrowser;
use RAN\RepositoryProvider\RepositoryBrowseResult;
use RAN\RepositoryProvider\RepositoryDescriptor;
use RAN\RepositoryProvider\UnknownProvider;
use RAN\RepositoryProvider\UnsupportedProviderCapability;
use RAN\Secrets\SecretsFile;
use RAN\Secrets\SecretsStorageUnavailable;
use RuntimeException;
use Throwable;

final class RepositoryPickerController {

	const AJAX_ACTION  = 'ran_booster_list_repositories';
	const NONCE_ACTION = 'ran-booster-repository-picker';

	public function __construct(
		private ProviderRegistry $providers,
		private SecretsFile $secrets,
		private PublicRepositoryLookupProfileStore $public_lookup_profiles
	) {
	}

	public function handle(): mixed {
		if ( ! current_user_can( 'manage_options' ) ) {
			return wp_send_json_error(
				array(
					'message' => __( 'You are not allowed to browse repository providers.', 'ran-booster' ),
				),
				403
			);
		}

		if ( ! check_ajax_referer( self::NONCE_ACTION, 'nonce', false ) ) {
			return wp_send_json_error(
				array(
					'message' => __( 'The repository picker session expired. Reload this page and try again.', 'ran-booster' ),
				),
				403
			);
		}

		$public_lookup_requested = false;

		try {
			$provider_input = isset( $_POST['provider'] ) ? wp_unslash( $_POST['provider'] ) : '';
			$provider_code  = ProviderCode::parse( is_string( $provider_input ) ? $provider_input : '' );
			$mode_input     = isset( $_POST['mode'] ) ? wp_unslash( $_POST['mode'] ) : 'authenticated';
			$mode           = is_string( $mode_input ) ? sanitize_key( $mode_input ) : '';

			if ( 'public' === $mode ) {
				$owner_input             = isset( $_POST['owner'] ) ? wp_unslash( $_POST['owner'] ) : '';
				$owner                   = is_string( $owner_input ) ? $owner_input : '';
				$identity_input          = isset( $_POST['public_lookup_identity'] ) ? wp_unslash( $_POST['public_lookup_identity'] ) : 'anonymous';
				$public_lookup_requested = is_string( $identity_input ) && 'anonymous' !== sanitize_key( $identity_input );
				$credential_id           = $this->public_lookup_profile_id( $provider_code );
				$browser                 = $this->providers->requireCapability(
					$provider_code,
					null === $credential_id ? RepositoryBrowser::class : CredentialedPublicRepositoryBrowser::class
				);
				$result                  = $browser->browseRepositories(
					RepositoryBrowseRequest::publicOwner(
						$owner,
						$credential_id
					)
				);
			} elseif ( 'accessible' === $mode ) {
				$browser          = $this->providers->requireCapability( $provider_code, RepositoryBrowser::class );
				$credential_input = isset( $_POST['credential_id'] ) ? wp_unslash( $_POST['credential_id'] ) : '';
				$credential_id    = $this->credential_id( $credential_input, false );
				$this->secrets->credentialProfiles( $provider_code );
				$result = $browser->browseRepositories(
					RepositoryBrowseRequest::accessible(
						$credential_id
					)
				);
			} else {
				throw new RuntimeException( 'Unknown repository picker mode.', 400 );
			}

			$repositories = $result->repositories;
			foreach ( $repositories as $repository ) {
				if ( ! $repository->provider->equals( $provider_code ) ) {
					throw new RuntimeException( 'Repository provider returned mismatched repository identity.', 502 );
				}
				// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- RepositoryDescriptor property is a connected provider contract.
				if ( 'public' === $mode && ( $repository->private || null !== $repository->credentialId ) ) {
					throw new RuntimeException( 'Repository provider returned a non-public repository.', 502 );
				}
			}

			return wp_send_json_success(
				array(
					'repositories'             => array_map(
						static fn ( RepositoryDescriptor $repository ): array => $repository->toArray(),
						$repositories
					),
					'partial'                  => $result->isPartial(),
					'message'                  => $this->partial_message( $result ),
					'public_lookup_profile_id' => 'public' === $mode ? $credential_id ?? '' : '',
				)
			);
		} catch ( SecretsStorageUnavailable ) {
			return wp_send_json_error(
				array(
					'message' => __( 'Encrypted credential storage is unavailable. Restore the matching sidecar and site key, then try again.', 'ran-booster' ),
				),
				409
			);
		} catch ( InvalidProviderCode | UnknownProvider ) {
			return wp_send_json_error(
				array(
					'message' => __( 'The selected repository provider is not available.', 'ran-booster' ),
				),
				400
			);
		} catch ( UnsupportedProviderCapability ) {
			$message = 'public' === $mode && $public_lookup_requested
				? __( 'The selected repository provider does not support authenticated public repository browsing.', 'ran-booster' )
				: __( 'The selected repository provider does not support repository browsing.', 'ran-booster' );

			return wp_send_json_error(
				array(
					'message' => $message,
				),
				501
			);
		} catch ( InvalidArgumentException $exception ) {
			$status = (int) $exception->getCode();
			if ( $status < 400 || $status > 599 ) {
				$status = 400;
			}

			return wp_send_json_error(
				array(
					'message' => $this->error_message_for_status( $status ),
				),
				$status
			);
		} catch ( RuntimeException $exception ) {
			$status = (int) $exception->getCode();
			if ( $status < 400 || $status > 599 ) {
				$status = 500;
			}

			return wp_send_json_error(
				array(
					'message' => $this->error_message_for_status( $status ),
				),
				$status
			);
		} catch ( Throwable ) {
			return wp_send_json_error(
				array(
					'message' => __( 'Repository browsing failed. Please try again.', 'ran-booster' ),
				),
				500
			);
		}
	}

	private function error_message_for_status( int $status ): string {
		return match ( $status ) {
			400 => __( 'The repository request is invalid. Check the selected provider and repository details.', 'ran-booster' ),
			401 => __( 'The repository provider rejected the saved credentials.', 'ran-booster' ),
			403 => __( 'The repository provider denied this request. Check repository access and credential permissions.', 'ran-booster' ),
			404 => __( 'The requested repository owner could not be found.', 'ran-booster' ),
			413 => __( 'The repository provider returned too much data. Enter the repository manually or narrow the account.', 'ran-booster' ),
			422 => __( 'The repository provider returned an invalid response. Try again or enter the repository manually.', 'ran-booster' ),
			429 => __( 'The repository provider rate limit has been reached. Try again later.', 'ran-booster' ),
			503, 504 => __( 'Repository browsing took too long. Try again or enter the repository manually.', 'ran-booster' ),
			default => __( 'Repository browsing failed. Please try again.', 'ran-booster' ),
		};
	}

	private function credential_id( mixed $value, bool $allow_anonymous ): string {
		if ( $allow_anonymous && '' === $value ) {
			return '';
		}

		if ( ! is_string( $value ) || 1 !== preg_match( '/\A[A-Za-z0-9_-]{3,64}\z/', $value ) ) {
			throw new InvalidArgumentException( 'Credential ID is invalid.' );
		}

		return $value;
	}

	private function public_lookup_profile_id( ProviderCode $provider ): ?string {
		// The AJAX nonce is verified before this helper is called.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$identity_input = isset( $_POST['public_lookup_identity'] ) ? wp_unslash( $_POST['public_lookup_identity'] ) : 'anonymous';
		$identity       = is_string( $identity_input ) ? sanitize_key( $identity_input ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$profile_input = isset( $_POST['public_lookup_profile_id'] ) ? wp_unslash( $_POST['public_lookup_profile_id'] ) : '';
		$profile_id    = $this->credential_id( $profile_input, true );

		if ( 'anonymous' === $identity ) {
			if ( '' !== $profile_id ) {
				throw new InvalidArgumentException( 'Anonymous public lookup cannot include a profile.' );
			}

			return null;
		}

		$browser  = $this->providers->requireCapability( $provider, CredentialedPublicRepositoryBrowser::class );
		$metadata = $browser->getPublicRepositoryBrowseMetadata();

		if ( 'default' === $identity ) {
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Repository provider metadata property is a connected public contract.
			if ( ! $metadata->supportsProviderDefaultProfile || '' !== $profile_id ) {
				throw new InvalidArgumentException( 'The public lookup default request is invalid.' );
			}

			$profile_id = $this->public_lookup_profiles->get( $provider->value ) ?? '';
			if ( '' === $profile_id ) {
				throw new InvalidArgumentException( 'No default public lookup profile is configured.' );
			}
		} elseif ( 'profile' === $identity ) {
			if ( '' === $profile_id ) {
				throw new InvalidArgumentException( 'The public lookup profile request is invalid.' );
			}
		} else {
			throw new InvalidArgumentException( 'The public lookup identity is invalid.' );
		}

		foreach ( $this->secrets->credentialProfiles( $provider ) as $profile ) {
			if ( ( $profile['id'] ?? null ) === $profile_id && ! empty( $profile['configured'] ) ) {
				return $profile_id;
			}
		}

		throw new InvalidArgumentException( 'The public lookup profile is unavailable.' );
	}

	private function partial_message( RepositoryBrowseResult $result ): ?string {
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- RepositoryBrowseResult property is a connected public contract.
		return match ( $result->partialReason ) {
			RepositoryBrowseResult::AUTHORIZATION => __( 'Some repositories are shown, but the selected credential stopped authorizing the request.', 'ran-booster' ),
			RepositoryBrowseResult::RATE_LIMIT => __( 'Some repositories are shown. The provider rate limit was reached; try again later for a complete list.', 'ran-booster' ),
			RepositoryBrowseResult::LIMIT => __( 'The first available repositories are shown. Enter a repository manually if it is not listed.', 'ran-booster' ),
			RepositoryBrowseResult::PROVIDER => __( 'Some repositories are shown, but the provider could not complete the list.', 'ran-booster' ),
			default => null,
		};
	}
}
