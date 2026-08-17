<?php

declare(strict_types=1);

namespace RAN\RepositoryProvider;

use RuntimeException;

/** Bounded provider-neutral rejection of one exact release acquisition. */
final class RepositoryReleaseAcquisitionRejected extends RuntimeException {
	public const INVALID_RELEASE = 'invalid_release';
	public const CLEANUP_FAILED  = 'cleanup_failed';

	private function __construct( public readonly string $reason ) {
		parent::__construct( 'The exact repository release could not be acquired.' );
	}

	public static function invalidRelease(): self {
		return new self( self::INVALID_RELEASE );
	}

	public static function cleanupFailed(): self {
		return new self( self::CLEANUP_FAILED );
	}
}
