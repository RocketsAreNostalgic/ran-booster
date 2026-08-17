<?php

declare(strict_types=1);

namespace RAN\AddOn\ReleaseTracking;

use InvalidArgumentException;

/**
 * Bounded, secret-free result consumed by Core release controls.
 */
final readonly class ProspectiveReleaseResult {

	/** @param array<string, mixed> $data */
	private function __construct(
		private bool $successful,
		private string $code,
		private array $data = array()
	) {
		if ( 1 !== preg_match( '/\A[a-z][a-z0-9_]{0,63}\z/D', $code ) ) {
			throw new InvalidArgumentException( 'The prospective release result code is invalid.' );
		}
	}

	/** @param array<string, mixed> $data */
	public static function success( string $code, array $data = array() ): self {
		return new self( true, $code, $data );
	}

	/** @param array<string, mixed> $data */
	public static function failure( string $code, array $data = array() ): self {
		return new self( false, $code, $data );
	}

	public function successful(): bool {
		return $this->successful;
	}

	public function code(): string {
		return $this->code;
	}

	/** @return array<string, mixed> */
	public function data(): array {
		return $this->data;
	}
}
