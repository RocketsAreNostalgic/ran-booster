<?php

declare(strict_types=1);

namespace RAN\RepositoryProvider;

use RuntimeException;
use Throwable;

/** Provider-neutral credential, access, rate-limit, or transport read failure. */
final class RepositoryReleaseReadUnavailable extends RuntimeException {

	public readonly ?ProviderFailure $failure;

	/** @param ProviderFailure|null $failure Optional reason; one outside the request group is dropped. */
	public function __construct( string $message = '', int $code = 0, ?Throwable $previous = null, ?ProviderFailure $failure = null ) {
		$this->failure = ProviderFailure::within( $failure, ProviderFailure::REQUEST_REASONS );
		parent::__construct( $message, $code, $previous );
	}
}
