<?php

declare(strict_types=1);

namespace RAN\RepositoryProvider;

use RuntimeException;

/** Bounded provider-neutral rejection of one exact release acquisition. */
final class RepositoryReleaseAcquisitionRejected extends RuntimeException {
	public const INVALID_RELEASE = 'invalid_release';
	public const CLEANUP_FAILED  = 'cleanup_failed';
	public const CHANGED         = 'release_changed';

	public readonly ?ProviderFailure $failure;

	private function __construct( public readonly string $reason, ?ProviderFailure $failure = null ) {
		$this->failure = ProviderFailure::within( $failure, ProviderFailure::RELEASE_REASONS );
		parent::__construct( 'The exact repository release could not be acquired.' );
	}

	public static function invalid_release( ?ProviderFailure $failure = null ): self {
		return new self( self::INVALID_RELEASE, $failure );
	}

	public static function cleanup_failed( ?ProviderFailure $failure = null ): self {
		return new self( self::CLEANUP_FAILED, $failure );
	}

	/** The release differs from the one that was inspected. */
	public static function changed( ?ProviderFailure $failure = null ): self {
		return new self( self::CHANGED, $failure );
	}
}
