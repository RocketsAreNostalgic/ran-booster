<?php

declare(strict_types=1);

namespace RAN\RepositoryProvider;

use InvalidArgumentException;
use RuntimeException;

final class RepositoryBrowseRequest {

	public const MAX_REMOTE_CALLS   = 5;
	public const DEADLINE_SECONDS   = 8.0;
	public const PER_RESPONSE_BYTES = 262144;
	public const AGGREGATE_BYTES    = 1048576;
	public const MAX_RESULTS        = 200;
	private const REQUEST_TIMEOUT   = 3.0;

	private RepositoryBrowseMode $mode;
	private ?string $owner;
	private ?string $credential_id;
	private int $started_at;
	private int $remote_calls   = 0;
	private int $response_bytes = 0;

	public function __construct(
		RepositoryBrowseMode $mode,
		?string $owner = null,
		?string $credential_id = null
	) {
		$this->reject_empty_value( $owner );
		$this->reject_empty_value( $credential_id );

		if ( RepositoryBrowseMode::PUBLIC_OWNER === $mode && null === $owner ) {
			throw new InvalidArgumentException( 'Public-owner repository browsing requires an owner.' );
		}

		if ( RepositoryBrowseMode::ACCESSIBLE === $mode && null !== $owner ) {
			throw new InvalidArgumentException( 'Accessible repository browsing does not accept an owner.' );
		}
		if ( RepositoryBrowseMode::ACCESSIBLE === $mode && null === $credential_id ) {
			throw new InvalidArgumentException( 'Accessible repository browsing requires a credential.' );
		}

		$this->mode          = $mode;
		$this->owner         = $owner;
		$this->credential_id = $credential_id;
		$this->started_at    = hrtime( true );
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase,WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public methods and named parameters retain the existing caller contract.
	public static function public_owner( string $owner, ?string $credential_id = null ): self {
		return new self( RepositoryBrowseMode::PUBLIC_OWNER, $owner, $credential_id );
	}

	/**
	 * Browse repositories available through one selected access profile.
	 */
	public static function accessible( string $credential_id ): self {
		return new self( RepositoryBrowseMode::ACCESSIBLE, null, $credential_id );
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public methods retain the existing connected caller contract.
	public function get_mode(): RepositoryBrowseMode {
		return $this->mode;
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public methods retain the existing connected caller contract.
	public function get_owner(): ?string {
		return $this->owner;
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public methods retain the existing connected caller contract.
	public function get_credential_id(): ?string {
		return $this->credential_id;
	}

	/**
	 * Claim one outbound request and receive its bounded timeout.
	 */
	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public methods retain the existing connected caller contract.
	public function claim_remote_call(): float {
		$remaining = $this->remaining_seconds();
		if ( self::MAX_REMOTE_CALLS <= $this->remote_calls || $remaining <= 0.0 ) {
			throw new RuntimeException( 'Repository browsing reached its request limit.', 503 );
		}

		++$this->remote_calls;

		return min( self::REQUEST_TIMEOUT, $remaining );
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public methods retain the existing connected caller contract.
	public function get_response_size_limit(): int {
		return self::PER_RESPONSE_BYTES + 1;
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public methods retain the existing connected caller contract.
	public function accept_response_body( string $body ): void {
		$bytes = strlen( $body );
		if ( self::PER_RESPONSE_BYTES < $bytes || self::AGGREGATE_BYTES < $this->response_bytes + $bytes ) {
			throw new RuntimeException( 'Repository provider response exceeded the safe size limit.', 413 );
		}

		$this->response_bytes += $bytes;
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public methods retain the existing connected caller contract.
	public function has_capacity(): bool {
		return $this->remote_calls < self::MAX_REMOTE_CALLS && $this->remaining_seconds() > 0.0;
	}

	private function remaining_seconds(): float {
		$elapsed = ( hrtime( true ) - $this->started_at ) / 1_000_000_000;

		return max( 0.0, self::DEADLINE_SECONDS - $elapsed );
	}

	private function reject_empty_value( ?string $value ): void {
		if ( null !== $value && '' === trim( $value ) ) {
			throw new InvalidArgumentException( 'Repository browse values cannot be empty.' );
		}
	}
}
