<?php

declare(strict_types=1);

namespace RAN\RepositoryProvider;

use InvalidArgumentException;
use Throwable;

/**
 * Why a provider operation failed: one closed reason and typed facts, never text.
 *
 * Providers have no logger and never write to the administrator. Core chooses the copy and does the logging.
 * The reason is validated against a closed vocabulary, so a bad value is a programming error in the provider.
 */
final readonly class ProviderFailure {

	private const CREDENTIAL = array( 'credential_missing', 'credential_store_unavailable', 'credential_format_invalid', 'credential_rejected', 'credential_expired', 'permission_denied' );
	private const RATE       = array( 'rate_limited', 'rate_limited_secondary' );
	private const LOCATION   = array( 'repository_not_found_or_no_access', 'repository_not_found', 'ref_not_found', 'owner_not_found', 'repository_identity_changed', 'repository_no_default_branch' );
	private const TRANSPORT  = array( 'http_timeout', 'dns_failed', 'tls_failed', 'blocked_by_site', 'redirect_blocked', 'transport_other' );
	private const HTTP       = array( 'http_4xx_other', 'http_5xx', 'response_invalid', 'response_too_large' );
	private const SITE       = array( 'site_filesystem_unsupported', 'site_zip_unavailable', 'operation_superseded' );

	/** The release group: the four ids plus the two `package_*` causes whose copy Core already owns. */
	public const RELEASE_REASONS = array( 'no_releases', 'release_invalid', 'release_changed', 'release_incompatible', 'package_asset_missing', 'package_asset_ambiguous' );

	/** Every reason a request, release listing or inspection read may report. */
	public const REQUEST_REASONS = array(
		...self::CREDENTIAL,
		...self::RATE,
		...self::LOCATION,
		...self::TRANSPORT,
		...self::HTTP,
		...self::SITE,
	);

	/** The whole closed vocabulary. */
	public const REASONS = array( ...self::REQUEST_REASONS, ...self::RELEASE_REASONS );

	/** The reasons a saved-credential check may report. */
	public const CREDENTIAL_CHECK_REASONS = array( ...self::CREDENTIAL, ...self::RATE, ...self::TRANSPORT, ...self::HTTP );

	/** The legacy HTTP-like code of a request failure and the reason Core derives from it. */
	private const LEGACY_REASONS = array(
		400 => 'http_4xx_other',
		401 => 'credential_rejected',
		403 => 'permission_denied',
		404 => 'repository_not_found_or_no_access',
		410 => 'ref_not_found',
		413 => 'response_too_large',
		422 => 'http_4xx_other',
		429 => 'rate_limited',
		502 => 'http_5xx',
		503 => 'http_5xx',
		504 => 'transport_other',
	);

	public ?int $http_status;
	public ?int $retry_after;
	public ?int $reset_at;
	public ?string $credential_expires_at;

	/**
	 * @param string      $reason                Required closed reason id.
	 * @param int|null    $http_status           The host's status, 100 to 599.
	 * @param int|null    $retry_after           Whole seconds, 1 to 86400.
	 * @param int|null    $reset_at              Unix timestamp the limit resets.
	 * @param string|null $credential_expires_at UTC `Y-m-d\TH:i:s\Z`.
	 * @throws InvalidArgumentException For an unknown reason or a value out of range.
	 */
	public function __construct(
		public string $reason,
		?int $http_status = null,
		?int $retry_after = null,
		?int $reset_at = null,
		?string $credential_expires_at = null
	) {
		if ( ! in_array( $reason, self::REASONS, true )
			|| ( null !== $http_status && ( $http_status < 100 || $http_status > 599 ) )
			|| ( null !== $retry_after && ( $retry_after < 1 || $retry_after > 86400 ) )
			|| ( null !== $reset_at && $reset_at < 1 )
			|| ( null !== $credential_expires_at && 1 !== preg_match( '/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/D', $credential_expires_at ) )
		) {
			throw new InvalidArgumentException( 'The provider failure is not valid.' );
		}

		$this->http_status           = $http_status;
		$this->retry_after           = $retry_after;
		$this->reset_at              = $reset_at;
		$this->credential_expires_at = $credential_expires_at;
	}

	/**
	 * The failure when its reason belongs to a group the receiving carrier accepts, otherwise null.
	 *
	 * @param list<string> $accepted
	 */
	public static function within( ?self $failure, array $accepted ): ?self {
		return null !== $failure && in_array( $failure->reason, $accepted, true ) ? $failure : null;
	}

	/** The failure a provider attached to a carrier exception, or null. */
	public static function carried( Throwable $failure ): ?self {
		return match ( true ) {
			$failure instanceof ProviderRequestFailed,
			$failure instanceof RepositoryReleaseReadUnavailable,
			$failure instanceof RepositoryReleaseInspectionRejected,
			$failure instanceof RepositoryReleaseAcquisitionRejected => $failure->failure,
			default => null,
		};
	}

	/** The carried failure, else the reason Core derives from a request exception's legacy code, else null. */
	public static function from_throwable( Throwable $failure ): ?self {
		$carried = self::carried( $failure );
		if ( null !== $carried ) {
			return $carried;
		}

		$code = $failure->getCode();

		return is_int( $code ) && isset( self::LEGACY_REASONS[ $code ] ) ? new self( self::LEGACY_REASONS[ $code ], $code ) : null;
	}

	/** The integer code a host that predates the carrier reads: the status, else the reason's default. */
	public function legacy_code(): int {
		return $this->http_status ?? match ( true ) {
			in_array( $this->reason, array( 'credential_missing', 'credential_format_invalid', 'credential_rejected', 'credential_expired' ), true ) => 401,
			'permission_denied' === $this->reason => 403,
			in_array( $this->reason, array( 'repository_not_found_or_no_access', 'repository_not_found', 'ref_not_found', 'owner_not_found' ), true ) => 404,
			in_array( $this->reason, array( 'repository_identity_changed', 'operation_superseded' ), true ) => 409,
			'response_too_large' === $this->reason => 413,
			'repository_no_default_branch' === $this->reason => 422,
			in_array( $this->reason, self::RATE, true ) => 429,
			in_array( $this->reason, array( 'http_5xx', 'response_invalid' ), true ) => 502,
			in_array( $this->reason, self::TRANSPORT, true ) => 504,
			default => 503,
		};
	}

	/** The deployment attempt outcome code for this reason. */
	public function deployment_outcome_code(): string {
		return match ( true ) {
			in_array( $this->reason, self::CREDENTIAL, true ) && 'permission_denied' !== $this->reason => 'provider_credential_rejected',
			'permission_denied' === $this->reason => 'provider_access_denied',
			in_array( $this->reason, array( 'repository_not_found_or_no_access', 'repository_not_found', 'owner_not_found' ), true ) => 'provider_repository_missing',
			in_array( $this->reason, array( 'ref_not_found', 'repository_no_default_branch' ), true ) => 'provider_reference_unavailable',
			in_array( $this->reason, self::RATE, true ) => 'provider_rate_limited',
			in_array( $this->reason, self::TRANSPORT, true ), in_array( $this->reason, array( 'http_5xx', 'response_invalid', 'response_too_large' ), true ) => 'provider_unavailable',
			'http_4xx_other' === $this->reason => 'provider_request_invalid',
			default => 'provider_failed',
		};
	}

	/** The one Core sentence for this failure. Typed facts are rendered as text; provider text is never part of it. */
	public function message(): string {
		$message = match ( $this->reason ) {
			'credential_missing' => __( 'No credential is saved for this request.', 'ran-booster' ),
			'credential_store_unavailable' => __( 'Booster could not read the saved credential.', 'ran-booster' ),
			'credential_format_invalid' => __( 'The saved credential is not in a format the provider accepts.', 'ran-booster' ),
			'credential_rejected' => __( 'The Git provider rejected the credential.', 'ran-booster' ),
			'credential_expired' => $this->expired_message(),
			'permission_denied' => __( 'The credential lacks permission for this request.', 'ran-booster' ),
			'rate_limited' => __( 'The Git provider rate limit has been reached.', 'ran-booster' ),
			'rate_limited_secondary' => __( 'The Git provider is throttling requests.', 'ran-booster' ),
			'repository_not_found_or_no_access' => __( 'The repository was not found, or the credential cannot access it.', 'ran-booster' ),
			'repository_not_found' => __( 'The repository does not exist.', 'ran-booster' ),
			'ref_not_found' => __( 'The requested revision is not available.', 'ran-booster' ),
			'owner_not_found' => __( 'The repository owner could not be found.', 'ran-booster' ),
			'repository_identity_changed' => __( 'The repository changed identity. Review the package source.', 'ran-booster' ),
			'repository_no_default_branch' => __( 'The repository has no default branch.', 'ran-booster' ),
			'http_timeout' => __( 'The Git provider took too long to respond.', 'ran-booster' ),
			'dns_failed' => __( 'This site could not look up the Git provider host.', 'ran-booster' ),
			'tls_failed' => __( 'This site could not make a secure connection to the Git provider.', 'ran-booster' ),
			'blocked_by_site' => __( 'This site blocks outbound requests to the Git provider.', 'ran-booster' ),
			'redirect_blocked' => __( 'The Git provider redirected the request somewhere that is not allowed.', 'ran-booster' ),
			'transport_other' => __( 'The Git provider could not be reached.', 'ran-booster' ),
			'http_4xx_other' => __( 'The Git provider refused the request.', 'ran-booster' ),
			'http_5xx' => __( 'The Git provider reported an error.', 'ran-booster' ),
			'response_invalid' => __( 'The Git provider returned an invalid response.', 'ran-booster' ),
			'response_too_large' => __( 'The Git provider returned too much data.', 'ran-booster' ),
			'site_filesystem_unsupported' => __( 'This site’s filesystem method does not support the operation.', 'ran-booster' ),
			'site_zip_unavailable' => __( 'This server cannot read ZIP packages.', 'ran-booster' ),
			'operation_superseded' => __( 'A newer operation replaced this one.', 'ran-booster' ),
			'no_releases' => __( 'No published release was found for this track.', 'ran-booster' ),
			'release_invalid' => __( 'The release could not be validated.', 'ran-booster' ),
			'release_changed' => __( 'The release changed after it was inspected.', 'ran-booster' ),
			default => __( 'The release is not a compatible package.', 'ran-booster' ),
		};

		$retry = in_array( $this->reason, self::RATE, true ) ? $this->retry_message() : '';

		return '' === $retry ? $message : $message . ' ' . $retry;
	}

	private function expired_message(): string {
		if ( null === $this->credential_expires_at ) {
			return __( 'The credential has expired.', 'ran-booster' );
		}

		/* translators: %s: date the credential expired, for example 2026-01-31. */
		return sprintf( __( 'The credential expired on %s.', 'ran-booster' ), substr( $this->credential_expires_at, 0, 10 ) );
	}

	private function retry_message(): string {
		$seconds = $this->retry_after ?? ( null === $this->reset_at ? null : $this->reset_at - time() );
		if ( null === $seconds || $seconds < 1 || $seconds > 86400 ) {
			return '';
		}
		if ( $seconds < 60 ) {
			return __( 'Try again in less than a minute.', 'ran-booster' );
		}
		$minutes = (int) ceil( $seconds / 60 );

		/* translators: %d: whole minutes until the request may be retried. */
		return sprintf( _n( 'Try again in about %d minute.', 'Try again in about %d minutes.', $minutes, 'ran-booster' ), $minutes );
	}
}
