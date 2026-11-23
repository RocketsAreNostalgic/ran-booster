<?php

declare(strict_types=1);

namespace RAN\Tests\Storage;

require_once __DIR__ . '/StorageTestEnvironment.php';

/** Schema fixture for tests that do not load WordPress's database class. */
class StorageTestWpdb extends \RAN\Storage\SchemaConnection {

	use StorageTestDatabaseBehavior;
}
