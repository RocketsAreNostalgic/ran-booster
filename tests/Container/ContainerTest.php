<?php

declare(strict_types=1);

namespace RAN\Tests\Container;

use RAN\Internal\CoreContainer;
use RAN\Tests\RANBoosterTestCase;

final class ContainerTest extends RANBoosterTestCase {

	private CoreContainer $container;

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this exact lifecycle override name.
	protected function setUp(): void {
		$this->container = new CoreContainer();
	}

	public function test_untyped_parameter_still_receives_null(): void {
		$result = $this->container->make( UntypedDependency::class );

		self::assertNull( $result->dependency );
	}

	public function test_union_dependency_is_rejected_without_selecting_an_alternative(): void {
		$this->expectException( \Error::class );
		$this->expectExceptionMessage( 'Core container cannot resolve union or intersection constructor parameter types.' );
		$this->container->make( UnionDependency::class );
	}

	public function test_intersection_dependency_is_rejected_without_selecting_a_component(): void {
		$this->expectException( \Error::class );
		$this->expectExceptionMessage( 'Core container cannot resolve union or intersection constructor parameter types.' );
		$this->container->make( IntersectionDependency::class );
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
// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- This concrete dependency fixture stays beside the container reflection assertions.
class DB {

	// Class with no dependencies
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- This concrete dependency fixture stays beside the container reflection assertions.
class EntityMapper {

	// Class with no dependencies
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- This concrete dependency fixture stays beside the container reflection assertions.
interface UserRepository {

}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- This concrete dependency fixture stays beside the container reflection assertions.
class DBUserRepository {

	public function __construct( public DB $db, public EntityMapper $em ) {
		// Constructor stuff
	}
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- This concrete dependency fixture stays beside the container reflection assertions.
class UserManager {

	public function __construct( public DBUserRepository $users ) {
		// Constructor stuff
	}
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- This concrete dependency fixture stays beside the container reflection assertions.
class UntypedDependency {
	/** @param mixed $dependency Deliberately lacks a native type for the reflection failure test. */
	public function __construct( public $dependency ) {
	}
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- This concrete dependency fixture stays beside the container reflection assertions.
class UnionDependency {
	public function __construct( public DB|EntityMapper $dependency ) {
	}
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- This concrete dependency fixture stays beside the container reflection assertions.
class IntersectionDependency {
	public function __construct( public DB&UserRepository $dependency ) {
	}
}
