<?php

declare(strict_types=1);

namespace Tests\Container;

use RAN\Internal\CoreContainer;
use Tests\RANBoosterTestCase;

final class ContainerTest extends RANBoosterTestCase {

	private CoreContainer $container;

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this exact lifecycle override name.
	protected function setUp(): void {
		$this->container = new CoreContainer();
	}

	public function test_it_can_resolve_a_class_with_no_dependencies(): void {
		$db = $this->container->make( DB::class );

		$this->assertInstanceOf( DB::class, $db );
	}

	public function test_it_can_resolve_a_class_with_nested_dependencies(): void {
		$manager = $this->container->make( UserManager::class );

		$this->assertInstanceOf( UserManager::class, $manager );
	}

	public function test_it_can_bind_an_alias(): void {
		$this->container->bind( UserRepository::class, DBUserRepository::class );

		$repository = $this->container->make( UserRepository::class );

		$this->assertInstanceOf( DBUserRepository::class, $repository );
	}

	public function test_it_can_bind_a_closure(): void {
		// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- The container factory receives CoreContainer; this fixture deliberately constructs an independent DB instance.
		$closure = function ( CoreContainer $container ): DB {
			return new DB();
		};

		$this->container->bind( DB::class, $closure );

		$db = $this->container->make( DB::class );

		$this->assertInstanceOf( DB::class, $db );
	}

	public function test_it_can_bind_an_instance(): void {
		$db_instance = new DB();

		$this->container->bind( DB::class, $db_instance );

		$db = $this->container->make( DB::class );

		$this->assertSame( $db_instance, $db );
		$this->assertSame( $db, $this->container->make( DB::class ) );
	}
}

// Fixtures:
class DB {

	// Class with no dependencies
}

class EntityMapper {

	// Class with no dependencies
}

interface UserRepository {

}

class DBUserRepository {

	public function __construct( DB $db, EntityMapper $em ) {
		// Constructor stuff
	}
}

class UserManager {

	public function __construct( DBUserRepository $users ) {
		// Constructor stuff
	}
}
