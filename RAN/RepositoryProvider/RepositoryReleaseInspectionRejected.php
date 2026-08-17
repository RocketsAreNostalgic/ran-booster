<?php

declare(strict_types=1);

namespace RAN\RepositoryProvider;

use RuntimeException;

/** Bounded provider-neutral rejection of one exact release inspection. */
final class RepositoryReleaseInspectionRejected extends RuntimeException {
	public const NO_RELEASES     = 'no_releases';
	public const INVALID_RELEASE = 'invalid_release';
	public const INCOMPATIBLE    = 'incompatible';

	private function __construct( public readonly string $reason ) {
		parent::__construct( 'The exact repository release could not be inspected.' );
	}

	public static function noReleases(): self {
		return new self( self::NO_RELEASES );
	}

	public static function invalidRelease(): self {
		return new self( self::INVALID_RELEASE );
	}

	public static function incompatible(): self {
		return new self( self::INCOMPATIBLE );
	}
}
