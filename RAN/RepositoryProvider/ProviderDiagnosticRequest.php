<?php

declare(strict_types=1);

namespace RAN\RepositoryProvider;

use Closure;
use InvalidArgumentException;

/**
 * One selected provider's bounded diagnostic request.
 */
final class ProviderDiagnosticRequest {

	public const MAX_REMOTE_CALLS        = 5;
	public const MAX_SECONDS             = 10.0;
	public const MAX_CREDENTIAL_ID_BYTES = 128;

	private readonly ?string $credentialId;
	private readonly ?string $repository;
	private readonly int $remoteCallLimit;
	private readonly float $deadline;
	private readonly Closure $clock;
	private int $remoteCalls          = 0;
	private ?string $exhaustionReason = null;

	public function __construct(
		?string $credentialId = null,
		?string $repository = null,
		int $remoteCallLimit = self::MAX_REMOTE_CALLS,
		float $seconds = self::MAX_SECONDS,
		?Closure $clock = null
	) {
		if ( $remoteCallLimit < 1 || $remoteCallLimit > self::MAX_REMOTE_CALLS ) {
			throw new InvalidArgumentException( 'Provider diagnostics allow between one and five remote calls.' );
		}

		if ( $seconds <= 0.0 || $seconds > self::MAX_SECONDS ) {
			throw new InvalidArgumentException( 'Provider diagnostics allow a deadline of up to ten seconds.' );
		}

		$this->credentialId    = $this->optionalCredentialId( $credentialId );
		$this->repository      = null === $repository ? null : RepositoryLocator::requireValid( $repository );
		$this->remoteCallLimit = $remoteCallLimit;
		$this->clock           = $clock ?? static fn(): float => hrtime( true ) / 1_000_000_000;
		$this->deadline        = ( $this->clock )() + $seconds;
	}

	public function getCredentialId(): ?string {
		return $this->credentialId;
	}

	public function getRepository(): ?string {
		return $this->repository;
	}

	/**
	 * Claim one remote call and return its maximum remaining timeout in seconds.
	 */
	public function claimRemoteCall(): float {
		$remaining = $this->remainingSeconds();

		if ( $remaining <= 0.0 ) {
			$this->exhaustionReason ??= ProviderDiagnosticBudgetExceeded::DEADLINE;
			throw ProviderDiagnosticBudgetExceeded::deadline();
		}

		if ( $this->remoteCalls >= $this->remoteCallLimit ) {
			$this->exhaustionReason ??= ProviderDiagnosticBudgetExceeded::REMOTE_CALLS;
			throw ProviderDiagnosticBudgetExceeded::remoteCalls();
		}

		++$this->remoteCalls;

		return $remaining;
	}

	public function getRemoteCalls(): int {
		return $this->remoteCalls;
	}

	public function getExhaustionReason(): ?string {
		return $this->exhaustionReason;
	}

	public function remainingSeconds(): float {
		return max( 0.0, $this->deadline - ( $this->clock )() );
	}

	private function optionalCredentialId( ?string $value ): ?string {
		if ( null === $value ) {
			return null;
		}

		$value = trim( $value );
		if ( '' === $value
			|| strlen( $value ) > self::MAX_CREDENTIAL_ID_BYTES
			|| 1 === preg_match( '/[\x00-\x1F\x7F]/', $value )
		) {
			throw new InvalidArgumentException( 'The provider diagnostic credential ID is invalid.' );
		}

		return $value;
	}
}
