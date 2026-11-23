<?php

declare(strict_types=1);

use RAN\Tests\Storage\StorageTestDatabaseBehavior;

require_once __DIR__ . '/StorageTestEnvironment.php';

/** Native database double loaded only in isolated drop-in tests. */
// phpcs:ignore PEAR.NamingConventions.ValidClassName.StartWithCapital, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- The native admission check requires WordPress's exact class identity.
class wpdb {

	use StorageTestDatabaseBehavior {
		query as private storage_query;
	}

	public bool $show_errors = false;
	public int $num_queries  = 0;

	/**
	 * @param string $query
	 * @return int|false
	 */
	public function query( $query ) {
		return $this->storage_query( $query );
	}

	public function db_connect(): bool {
		return true;
	}
}
