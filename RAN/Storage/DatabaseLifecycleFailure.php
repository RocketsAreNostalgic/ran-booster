<?php

declare(strict_types=1);

namespace RAN\Storage;

use RuntimeException;

/**
 * A display-safe failure to prepare Booster's owned database schema.
 */
final class DatabaseLifecycleFailure extends RuntimeException {

	public const SUMMARY     = 'RAN Booster database storage needs attention.';
	public const REQUIREMENT = self::SUMMARY . ' Review Troubleshooting before trying again.';

	public function __construct( private string $failure_reason ) {
		parent::__construct( self::REQUIREMENT );
	}

	public function reason(): string {
		return $this->failure_reason;
	}
}
