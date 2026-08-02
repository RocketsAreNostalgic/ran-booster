<?php

declare(strict_types=1);

namespace RAN\Storage;

use RuntimeException;

/**
 * A display-safe failure to prepare Booster's owned database schema.
 */
final class DatabaseLifecycleFailure extends RuntimeException {

	public const REQUIREMENT = 'RAN Booster database storage needs attention. Review Troubleshooting before trying again.';

	public function __construct( private string $failureReason ) {
		parent::__construct( self::REQUIREMENT );
	}

	public function reason(): string {
		return $this->failureReason;
	}
}
