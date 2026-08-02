<?php

declare(strict_types=1);

namespace RAN\Storage;

use RuntimeException;

/**
 * A display-safe failure for the supported database envelope.
 */
final class DatabaseCompatibilityFailure extends RuntimeException {

	public const REQUIREMENT = 'RAN Booster requires MySQL 8.0 or newer or MariaDB 10.11 or newer with InnoDB. SQLite, PostgreSQL, and unverified database translation drop-ins are not supported.';

	public function __construct( private readonly string $reason ) {
		parent::__construct( self::REQUIREMENT );
	}

	public function reason(): string {
		return $this->reason;
	}
}
