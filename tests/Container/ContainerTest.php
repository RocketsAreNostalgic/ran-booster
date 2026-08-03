<?php

declare(strict_types=1);

namespace Tests\Container;

use RAN\Internal\CoreContainer;
use Tests\RANBoosterTestCase;

final class ContainerTest extends RANBoosterTestCase {

	private CoreContainer $container;

	protected function setUp(): void {
		$this->container = new CoreContainer();
	}

	public function testItCanResolveAClassWithNoDependencies(): void {
		$db = $this->container->make( DB::class );

		$this->assertInstanceOf( DB::class, $db );
	}

	public function testItCanResolveAClassWithNestedDependencies(): void {
		$manager = $this->container->make( UserManager::class );

		$this->assertInstanceOf( UserManager::class, $manager );
	}

	public function testItCanBindAnAlias(): void {
		$this->container->bind( UserRepository::class, DBUserRepository::class );

		$repository = $this->container->make( UserRepository::class );

		$this->assertInstanceOf( DBUserRepository::class, $repository );
	}

	public function testItCanBindAClosure(): void {
		$closure = function ( CoreContainer $container ): DB {
			return new DB();
		};

		$this->container->bind( DB::class, $closure );

		$db = $this->container->make( DB::class );

		$this->assertInstanceOf( DB::class, $db );
	}

	public function testItCanBindAnInstance(): void {
		$dbInstance = new DB();

		$this->container->bind( DB::class, $dbInstance );

		$db = $this->container->make( DB::class );

		$this->assertSame( $dbInstance, $db );
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
