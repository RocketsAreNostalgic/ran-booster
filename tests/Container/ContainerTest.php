<?php

declare(strict_types=1);

namespace Tests\Container;

use RAN\Booster;
use Tests\RANBoosterTestCase;

final class ContainerTest extends RANBoosterTestCase {

	private Booster $booster;

	protected function setUp(): void {
		$this->booster = new Booster();
	}

	public function testItCanResolveAClassWithNoDependencies(): void {
		$db = $this->booster->make( DB::class );

		$this->assertInstanceOf( DB::class, $db );
	}

	public function testItCanResolveAClassWithNestedDependencies(): void {
		$manager = $this->booster->make( UserManager::class );

		$this->assertInstanceOf( UserManager::class, $manager );
	}

	public function testItCanBindAnAlias(): void {
		$this->booster->bind( UserRepository::class, DBUserRepository::class );

		$repository = $this->booster->make( UserRepository::class );

		$this->assertInstanceOf( DBUserRepository::class, $repository );
	}

	public function testItCanBindAClosure(): void {
		$closure = function ( Booster $booster ): DB {
			return new DB();
		};

		$this->booster->bind( DB::class, $closure );

		$db = $this->booster->make( DB::class );

		$this->assertInstanceOf( DB::class, $db );
	}

	public function testItCanBindAnInstance(): void {
		$dbInstance = new DB();

		$this->booster->bind( DB::class, $dbInstance );

		$db = $this->booster->make( DB::class );

		$this->assertSame( $dbInstance, $db );
		$this->assertSame( $db, $this->booster->make( DB::class ) );
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
