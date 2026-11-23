<?php

declare(strict_types=1);

use RAN\Tests\Storage\StorageTestDatabaseBehavior;

require_once __DIR__ . '/StorageTestEnvironment.php';

/** Native database double loaded only in isolated drop-in tests. */
// phpcs:ignore PEAR.NamingConventions.ValidClassName.StartWithCapital, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- The native admission check requires WordPress's exact class identity.
class wpdb {

	use StorageTestDatabaseBehavior;

	public function db_connect(): bool {
		return true;
	}
}
