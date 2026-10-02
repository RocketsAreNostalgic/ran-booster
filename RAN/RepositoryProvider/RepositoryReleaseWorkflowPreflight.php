<?php

declare(strict_types=1);

namespace RAN\RepositoryProvider;

use InvalidArgumentException;

/** Provider-neutral release preflight facts needed by workflow setup and inspection. */
readonly class RepositoryReleaseWorkflowPreflight {
	public const READY                 = 'ready';
	public const RELEASE_UNAVAILABLE   = 'release_unavailable';
	public const PREFLIGHT_UNAVAILABLE = 'preflight_unavailable';

	public function __construct( private string $code, private string $reason_code = '' ) {
		if ( 1 !== preg_match( '/\A[a-z0-9_]{1,55}\z/D', $this->code )
			|| ( '' !== $this->reason_code && 1 !== preg_match( '/\A[a-z0-9_]{1,96}\z/D', $this->reason_code ) ) ) {
			throw new InvalidArgumentException( 'Release workflow preflight is invalid.' );
		}
	}

	public function code(): string {
		return $this->code;
	}

	public function reason_code(): string {
		return $this->reason_code;
	}
}
