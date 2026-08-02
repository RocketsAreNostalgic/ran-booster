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
	private ?string $credentialId;
	private int $startedAt;
	private int $remoteCalls   = 0;
	private int $responseBytes = 0;

	public function __construct(
		RepositoryBrowseMode $mode,
		?string $owner = null,
		?string $credentialId = null
	) {
		$this->rejectEmptyValue( $owner, 'Repository owner' );
		$this->rejectEmptyValue( $credentialId, 'Credential ID' );

		if ( RepositoryBrowseMode::PUBLIC_OWNER === $mode && null === $owner ) {
			throw new InvalidArgumentException( 'Public-owner repository browsing requires an owner.' );
		}

		if ( RepositoryBrowseMode::ACCESSIBLE === $mode && null !== $owner ) {
			throw new InvalidArgumentException( 'Accessible repository browsing does not accept an owner.' );
		}

		if ( RepositoryBrowseMode::ACCESSIBLE === $mode && null === $credentialId ) {
			throw new InvalidArgumentException( 'Accessible repository browsing requires a credential.' );
		}

		$this->mode         = $mode;
		$this->owner        = $owner;
		$this->credentialId = $credentialId;
		$this->startedAt    = hrtime( true );
	}

	public static function publicOwner( string $owner, ?string $credentialId = null ): self {
		return new self( RepositoryBrowseMode::PUBLIC_OWNER, $owner, $credentialId );
	}

	/**
	 * Browse repositories available through one selected access profile.
	 */
	public static function accessible( string $credentialId ): self {
		return new self( RepositoryBrowseMode::ACCESSIBLE, null, $credentialId );
	}

	public function getMode(): RepositoryBrowseMode {
		return $this->mode;
	}

	public function getOwner(): ?string {
		return $this->owner;
	}

	public function getCredentialId(): ?string {
		return $this->credentialId;
	}

	/**
	 * Claim one outbound request and receive its bounded timeout.
	 */
	public function claimRemoteCall(): float {
		$remaining = $this->remainingSeconds();
		if ( self::MAX_REMOTE_CALLS <= $this->remoteCalls || $remaining <= 0.0 ) {
			throw new RuntimeException( 'Repository browsing reached its request limit.', 503 );
		}

		++$this->remoteCalls;

		return min( self::REQUEST_TIMEOUT, $remaining );
	}

	public function getResponseSizeLimit(): int {
		return self::PER_RESPONSE_BYTES + 1;
	}

	public function acceptResponseBody( string $body ): void {
		$bytes = strlen( $body );
		if ( self::PER_RESPONSE_BYTES < $bytes || self::AGGREGATE_BYTES < $this->responseBytes + $bytes ) {
			throw new RuntimeException( 'Repository provider response exceeded the safe size limit.', 413 );
		}

		$this->responseBytes += $bytes;
	}

	public function hasCapacity(): bool {
		return $this->remoteCalls < self::MAX_REMOTE_CALLS && $this->remainingSeconds() > 0.0;
	}

	private function remainingSeconds(): float {
		$elapsed = ( hrtime( true ) - $this->startedAt ) / 1_000_000_000;

		return max( 0.0, self::DEADLINE_SECONDS - $elapsed );
	}

	private function rejectEmptyValue( ?string $value, string $label ): void {
		if ( null !== $value && '' === trim( $value ) ) {
			throw new InvalidArgumentException( 'Repository browse values cannot be empty.' );
		}
	}
}
