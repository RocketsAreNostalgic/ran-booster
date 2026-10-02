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

	private readonly ?string $credential_id;
	private readonly ?string $repository;
	private readonly int $remote_call_limit;
	private readonly float $deadline;
	private readonly Closure $clock;
	private int $remote_calls          = 0;
	private ?string $exhaustion_reason = null;

	public function __construct(
		?string $credential_id = null,
		?string $repository = null,
		int $remote_call_limit = self::MAX_REMOTE_CALLS,
		float $seconds = self::MAX_SECONDS,
		?Closure $clock = null
	) {
		if ( $remote_call_limit < 1 || $remote_call_limit > self::MAX_REMOTE_CALLS ) {
			throw new InvalidArgumentException( 'Provider diagnostics allow between one and five remote calls.' );
		}

		if ( $seconds <= 0.0 || $seconds > self::MAX_SECONDS ) {
			throw new InvalidArgumentException( 'Provider diagnostics allow a deadline of up to ten seconds.' );
		}
		$this->credential_id     = $this->optional_credential_id( $credential_id );
		$this->repository        = null === $repository ? null : RepositoryLocator::require_valid( $repository );
		$this->remote_call_limit = $remote_call_limit;
		$this->clock             = $clock ?? static fn(): float => hrtime( true ) / 1_000_000_000;
		$this->deadline          = ( $this->clock )() + $seconds;
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public methods retain the existing connected caller contract.
	public function get_credential_id(): ?string {
		return $this->credential_id;
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public methods retain the existing connected caller contract.
	public function get_repository(): ?string {
		return $this->repository;
	}

	/**
	 * Claim one remote call and return its maximum remaining timeout in seconds.
	 */
	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public methods retain the existing connected caller contract.
	public function claim_remote_call(): float {
		$remaining = $this->remaining_seconds();

		if ( $remaining <= 0.0 ) {
			$this->exhaustion_reason ??= ProviderDiagnosticBudgetExceeded::DEADLINE;
			throw ProviderDiagnosticBudgetExceeded::deadline();
		}

		if ( $this->remote_calls >= $this->remote_call_limit ) {
			$this->exhaustion_reason ??= ProviderDiagnosticBudgetExceeded::REMOTE_CALLS;
			throw ProviderDiagnosticBudgetExceeded::remote_calls();
		}

		++$this->remote_calls;

		return $remaining;
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public methods retain the existing connected caller contract.
	public function get_remote_calls(): int {
		return $this->remote_calls;
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public methods retain the existing connected caller contract.
	public function get_exhaustion_reason(): ?string {
		return $this->exhaustion_reason;
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public methods retain the existing connected caller contract.
	public function remaining_seconds(): float {
		return max( 0.0, $this->deadline - ( $this->clock )() );
	}

	private function optional_credential_id( ?string $value ): ?string {
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
