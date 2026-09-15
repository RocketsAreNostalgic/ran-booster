<?php

declare(strict_types=1);

namespace RAN\RepositoryProvider;

use InvalidArgumentException;

/** Provider-neutral release preflight facts needed by workflow setup and inspection. */
readonly class RepositoryReleaseWorkflowPreflight {
	public const READY                 = 'ready';
	public const RELEASE_UNAVAILABLE   = 'release_unavailable';
	public const PREFLIGHT_UNAVAILABLE = 'preflight_unavailable';

	public function __construct( private string $code, private string $reasonCode = '' ) {
		if ( 1 !== preg_match( '/\A[a-z0-9_]{1,55}\z/D', $this->code )
			|| ( '' !== $this->reasonCode && 1 !== preg_match( '/\A[a-z0-9_]{1,96}\z/D', $this->reasonCode ) ) ) {
			throw new InvalidArgumentException( 'Release workflow preflight is invalid.' );
		}
	}

	public function code(): string {
		return $this->code;
	}

	public function reasonCode(): string {
		return $this->reasonCode;
	}
}
