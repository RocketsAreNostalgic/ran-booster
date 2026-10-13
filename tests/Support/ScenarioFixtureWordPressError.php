<?php

declare(strict_types=1);

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- Preserve the host-owned error identity in an isolated test process.
class WP_Error {
	public function __construct( public string $code, public string $message ) {}
}
