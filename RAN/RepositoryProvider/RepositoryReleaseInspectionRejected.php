<?php

declare(strict_types=1);

namespace RAN\RepositoryProvider;

use RuntimeException;

/** Bounded provider-neutral rejection of one exact release inspection. */
final class RepositoryReleaseInspectionRejected extends RuntimeException {
	public const NO_RELEASES     = 'no_releases';
	public const INVALID_RELEASE = 'invalid_release';
	public const INCOMPATIBLE    = 'incompatible';

	/** An optional lower-case reason name for an incompatible release; anything else is dropped. */
	public readonly ?string $detail;

	public readonly ?ProviderFailure $failure;

	private function __construct( public readonly string $reason, ?string $detail = null, ?ProviderFailure $failure = null ) {
		$this->failure = ProviderFailure::within( $failure, ProviderFailure::RELEASE_REASONS );
		$this->detail  = null !== $detail && 1 === preg_match( '/\A[a-z][a-z0-9_]{0,63}\z/D', $detail ) ? $detail : null;
		parent::__construct( 'The exact repository release could not be inspected.' );
	}

	public static function no_releases( ?ProviderFailure $failure = null ): self {
		return new self( self::NO_RELEASES, null, $failure );
	}

	public static function invalid_release( ?ProviderFailure $failure = null ): self {
		return new self( self::INVALID_RELEASE, null, $failure );
	}

	public static function incompatible( ?string $detail = null, ?ProviderFailure $failure = null ): self {
		return new self( self::INCOMPATIBLE, $detail, $failure );
	}
}
