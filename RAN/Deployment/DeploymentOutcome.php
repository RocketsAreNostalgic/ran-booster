<?php

declare(strict_types=1);

namespace RAN\Deployment;

use InvalidArgumentException;
use Throwable;

/** A closed terminal result; no provider text or arbitrary evidence is stored. */
final readonly class DeploymentOutcome {

	public const CODE_DEPLOYED                       = 'deployed';
	public const CODE_NO_CHANGE                      = 'no_change';
	public const CODE_PROVIDER_FAILED                = 'provider_failed';
	public const CODE_PROVIDER_REQUEST_INVALID       = 'provider_request_invalid';
	public const CODE_PROVIDER_CREDENTIAL_REJECTED   = 'provider_credential_rejected';
	public const CODE_PROVIDER_ACCESS_DENIED         = 'provider_access_denied';
	public const CODE_PROVIDER_REPOSITORY_MISSING    = 'provider_repository_missing';
	public const CODE_PROVIDER_REFERENCE_UNAVAILABLE = 'provider_reference_unavailable';
	public const CODE_PROVIDER_RATE_LIMITED          = 'provider_rate_limited';
	public const CODE_PROVIDER_UNAVAILABLE           = 'provider_unavailable';
	public const CODE_ARCHIVE_COMPRESSED_TOO_LARGE   = 'archive_compressed_too_large';
	public const CODE_ARCHIVE_EXPANDED_TOO_LARGE     = 'archive_expanded_too_large';
	public const CODE_ARCHIVE_LIMIT_INVALID          = 'archive_limit_invalid';
	public const CODE_PREFLIGHT_FAILED               = 'preflight_failed';
	public const CODE_DOWNGRADE_BLOCKED              = 'downgrade_blocked';
	public const CODE_LOCK_UNAVAILABLE               = 'lock_unavailable';
	public const CODE_POLICY_BLOCKED                 = 'policy_blocked';
	public const CODE_STALE_EVENT                    = 'stale_event';
	public const CODE_UPGRADER_FAILED                = 'upgrader_failed';
	public const CODE_ACTIVATION_FAILED              = 'activation_failed';
	public const CODE_WORKER_STOPPED                 = 'worker_stopped';
	public const CODE_INTERRUPTED                    = 'interrupted';
	public const CODE_RESTORATION_UNCERTAIN          = 'restoration_uncertain';
	public const CODE_MAINTENANCE_REMAINING          = 'maintenance_remaining';
	public const CODE_INSTALLED_VERSION_MISMATCH     = 'installed_version_mismatch';
	public const CODE_ACTIVATION_STATE_CHANGED       = 'activation_state_changed';
	public const CODE_PERSISTENCE_UNCERTAIN          = 'persistence_uncertain';

	/** @var array<string, DeploymentState> */
	private const STATES = array(
		self::CODE_DEPLOYED                       => DeploymentState::SUCCEEDED,
		self::CODE_NO_CHANGE                      => DeploymentState::SUCCEEDED,
		self::CODE_PROVIDER_FAILED                => DeploymentState::FAILED,
		self::CODE_PROVIDER_REQUEST_INVALID       => DeploymentState::FAILED,
		self::CODE_PROVIDER_CREDENTIAL_REJECTED   => DeploymentState::FAILED,
		self::CODE_PROVIDER_ACCESS_DENIED         => DeploymentState::FAILED,
		self::CODE_PROVIDER_REPOSITORY_MISSING    => DeploymentState::FAILED,
		self::CODE_PROVIDER_REFERENCE_UNAVAILABLE => DeploymentState::FAILED,
		self::CODE_PROVIDER_RATE_LIMITED          => DeploymentState::FAILED,
		self::CODE_PROVIDER_UNAVAILABLE           => DeploymentState::FAILED,
		self::CODE_ARCHIVE_COMPRESSED_TOO_LARGE   => DeploymentState::FAILED,
		self::CODE_ARCHIVE_EXPANDED_TOO_LARGE     => DeploymentState::FAILED,
		self::CODE_ARCHIVE_LIMIT_INVALID          => DeploymentState::FAILED,
		self::CODE_PREFLIGHT_FAILED               => DeploymentState::FAILED,
		self::CODE_DOWNGRADE_BLOCKED              => DeploymentState::FAILED,
		self::CODE_LOCK_UNAVAILABLE               => DeploymentState::FAILED,
		self::CODE_POLICY_BLOCKED                 => DeploymentState::FAILED,
		self::CODE_STALE_EVENT                    => DeploymentState::FAILED,
		self::CODE_UPGRADER_FAILED                => DeploymentState::FAILED,
		self::CODE_ACTIVATION_FAILED              => DeploymentState::FAILED,
		self::CODE_WORKER_STOPPED                 => DeploymentState::FAILED,
		self::CODE_INTERRUPTED                    => DeploymentState::NEEDS_ATTENTION,
		self::CODE_RESTORATION_UNCERTAIN          => DeploymentState::NEEDS_ATTENTION,
		self::CODE_MAINTENANCE_REMAINING          => DeploymentState::NEEDS_ATTENTION,
		self::CODE_INSTALLED_VERSION_MISMATCH     => DeploymentState::NEEDS_ATTENTION,
		self::CODE_ACTIVATION_STATE_CHANGED       => DeploymentState::NEEDS_ATTENTION,
		self::CODE_PERSISTENCE_UNCERTAIN          => DeploymentState::NEEDS_ATTENTION,
	);

	private function __construct( private string $code, private DeploymentState $state ) {
	}

	public static function fromCode( string $code ): self {
		$state = self::STATES[ $code ] ?? null;
		if ( null === $state ) {
			throw new InvalidArgumentException( 'The deployment outcome code is not recognised.' );
		}

		return new self( $code, $state );
	}

	/**
	 * Convert a provider's normalized status into a safe durable outcome.
	 *
	 * Provider messages and response bodies remain outside the attempt record.
	 */
	public static function fromProviderFailure( Throwable $failure ): self {
		$code = match ( (int) $failure->getCode() ) {
			400, 422      => self::CODE_PROVIDER_REQUEST_INVALID,
			401           => self::CODE_PROVIDER_CREDENTIAL_REJECTED,
			403           => self::CODE_PROVIDER_ACCESS_DENIED,
			404           => self::CODE_PROVIDER_REPOSITORY_MISSING,
			410           => self::CODE_PROVIDER_REFERENCE_UNAVAILABLE,
			429           => self::CODE_PROVIDER_RATE_LIMITED,
			502, 503, 504 => self::CODE_PROVIDER_UNAVAILABLE,
			default       => self::CODE_PROVIDER_FAILED,
		};

		return self::fromCode( $code );
	}

	public function getCode(): string {
		return $this->code;
	}

	public function getState(): DeploymentState {
		return $this->state;
	}
}
