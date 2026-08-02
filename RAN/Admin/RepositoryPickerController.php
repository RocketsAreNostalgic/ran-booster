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
		private PublicRepositoryLookupProfileStore $publicLookupProfiles
	) {
	}

	public function handle(): mixed {
		if ( ! current_user_can( 'manage_options' ) ) {
			return wp_send_json_error(
				array(
					'message' => 'You are not allowed to browse repository providers.',
				),
				403
			);
		}

		if ( ! check_ajax_referer( self::NONCE_ACTION, 'nonce', false ) ) {
			return wp_send_json_error(
				array(
					'message' => 'The repository picker session expired. Reload this page and try again.',
				),
				403
			);
		}

		$publicLookupRequested = false;

		try {
			$providerInput = isset( $_POST['provider'] ) ? wp_unslash( $_POST['provider'] ) : '';
			$providerCode  = ProviderCode::parse( is_string( $providerInput ) ? $providerInput : '' );
			$modeInput     = isset( $_POST['mode'] ) ? wp_unslash( $_POST['mode'] ) : 'authenticated';
			$mode          = is_string( $modeInput ) ? sanitize_key( $modeInput ) : '';

			if ( $mode === 'public' ) {
				$ownerInput            = isset( $_POST['owner'] ) ? wp_unslash( $_POST['owner'] ) : '';
				$owner                 = is_string( $ownerInput ) ? $ownerInput : '';
				$identityInput         = isset( $_POST['public_lookup_identity'] ) ? wp_unslash( $_POST['public_lookup_identity'] ) : 'anonymous';
				$publicLookupRequested = is_string( $identityInput ) && 'anonymous' !== sanitize_key( $identityInput );
				$credentialId          = $this->publicLookupProfileId( $providerCode );
				$browser               = $this->providers->requireCapability(
					$providerCode,
					null === $credentialId ? RepositoryBrowser::class : CredentialedPublicRepositoryBrowser::class
				);
				$result                = $browser->browseRepositories(
					RepositoryBrowseRequest::publicOwner(
						$owner,
						$credentialId
					)
				);
			} elseif ( $mode === 'accessible' ) {
				$browser         = $this->providers->requireCapability( $providerCode, RepositoryBrowser::class );
				$credentialInput = isset( $_POST['credential_id'] ) ? wp_unslash( $_POST['credential_id'] ) : '';
				$credentialId    = $this->credentialId( $credentialInput, false );
				$this->secrets->credentialProfiles( $providerCode );
				$result = $browser->browseRepositories(
					RepositoryBrowseRequest::accessible(
						$credentialId
					)
				);
			} else {
				throw new RuntimeException( 'Unknown repository picker mode.', 400 );
			}

			$repositories = $result->repositories;
			foreach ( $repositories as $repository ) {
				if ( ! $repository->provider->equals( $providerCode ) ) {
					throw new RuntimeException( 'Repository provider returned mismatched repository identity.', 502 );
				}
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
					'message'                  => $this->partialMessage( $result ),
					'public_lookup_profile_id' => 'public' === $mode ? $credentialId ?? '' : '',
				)
			);
		} catch ( SecretsStorageUnavailable ) {
			return wp_send_json_error(
				array(
					'message' => 'Encrypted credential storage is unavailable. Restore the matching sidecar and site key, then try again.',
				),
				409
			);
		} catch ( InvalidProviderCode | UnknownProvider ) {
			return wp_send_json_error(
				array(
					'message' => 'The selected repository provider is not available.',
				),
				400
			);
		} catch ( UnsupportedProviderCapability ) {
			$message = isset( $mode ) && 'public' === $mode && $publicLookupRequested
				? 'The selected repository provider does not support authenticated public repository browsing.'
				: 'The selected repository provider does not support repository browsing.';

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
					'message' => $this->errorMessageForStatus( $status ),
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
					'message' => $this->errorMessageForStatus( $status ),
				),
				$status
			);
		} catch ( Throwable ) {
			return wp_send_json_error(
				array(
					'message' => 'Repository browsing failed. Please try again.',
				),
				500
			);
		}
	}

	private function errorMessageForStatus( int $status ): string {
		return match ( $status ) {
			400 => 'The repository request is invalid. Check the selected provider and repository details.',
			401 => 'The repository provider rejected the saved credentials.',
			403 => 'The repository provider denied this request. Check repository access and credential permissions.',
			404 => 'The requested repository owner could not be found.',
			413 => 'The repository provider returned too much data. Enter the repository manually or narrow the account.',
			422 => 'The repository provider returned an invalid response. Try again or enter the repository manually.',
			429 => 'The repository provider rate limit has been reached. Try again later.',
			503, 504 => 'Repository browsing took too long. Try again or enter the repository manually.',
			default => 'Repository browsing failed. Please try again.',
		};
	}

	private function credentialId( mixed $value, bool $allowAnonymous ): string {
		if ( $allowAnonymous && '' === $value ) {
			return '';
		}

		if ( ! is_string( $value ) || 1 !== preg_match( '/\A[A-Za-z0-9_-]{3,64}\z/', $value ) ) {
			throw new InvalidArgumentException( 'Credential ID is invalid.' );
		}

		return $value;
	}

	private function publicLookupProfileId( ProviderCode $provider ): ?string {
		// The AJAX nonce is verified before this helper is called.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$identityInput = isset( $_POST['public_lookup_identity'] ) ? wp_unslash( $_POST['public_lookup_identity'] ) : 'anonymous';
		$identity      = is_string( $identityInput ) ? sanitize_key( $identityInput ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$profileInput = isset( $_POST['public_lookup_profile_id'] ) ? wp_unslash( $_POST['public_lookup_profile_id'] ) : '';
		$profileId    = $this->credentialId( $profileInput, true );

		if ( 'anonymous' === $identity ) {
			if ( '' !== $profileId ) {
				throw new InvalidArgumentException( 'Anonymous public lookup cannot include a profile.' );
			}

			return null;
		}

		$browser  = $this->providers->requireCapability( $provider, CredentialedPublicRepositoryBrowser::class );
		$metadata = $browser->getPublicRepositoryBrowseMetadata();

		if ( 'default' === $identity ) {
			if ( ! $metadata->supportsProviderDefaultProfile || '' !== $profileId ) {
				throw new InvalidArgumentException( 'The public lookup default request is invalid.' );
			}

			$profileId = $this->publicLookupProfiles->get( $provider->value ) ?? '';
			if ( '' === $profileId ) {
				throw new InvalidArgumentException( 'No default public lookup profile is configured.' );
			}
		} elseif ( 'profile' === $identity ) {
			if ( '' === $profileId ) {
				throw new InvalidArgumentException( 'The public lookup profile request is invalid.' );
			}
		} else {
			throw new InvalidArgumentException( 'The public lookup identity is invalid.' );
		}

		foreach ( $this->secrets->credentialProfiles( $provider ) as $profile ) {
			if ( $profileId === ( $profile['id'] ?? null ) && ! empty( $profile['configured'] ) ) {
				return $profileId;
			}
		}

		throw new InvalidArgumentException( 'The public lookup profile is unavailable.' );
	}

	private function partialMessage( RepositoryBrowseResult $result ): ?string {
		return match ( $result->partialReason ) {
			RepositoryBrowseResult::AUTHORIZATION => 'Some repositories are shown, but the selected credential stopped authorizing the request.',
			RepositoryBrowseResult::RATE_LIMIT => 'Some repositories are shown. The provider rate limit was reached; try again later for a complete list.',
			RepositoryBrowseResult::LIMIT => 'The first available repositories are shown. Enter a repository manually if it is not listed.',
			RepositoryBrowseResult::PROVIDER => 'Some repositories are shown, but the provider could not complete the list.',
			default => null,
		};
	}
}
