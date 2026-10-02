<?php

declare(strict_types=1);

namespace Tests\Admin;

require_once dirname( __DIR__ ) . '/Support/RepositoryAdminWordPressFunctions.php';

use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RAN\Admin\AdminAddOnContext;
use RAN\Admin\AdminAddOnRegistry;
use RAN\Admin\AdminAddOnTab;

final class AdminAddOnRegistryTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['ran_booster_repository_admin_allowed'] = true;
	}

	protected function tearDown(): void {
		unset( $GLOBALS['ran_booster_repository_admin_allowed'] );
	}

	public function test_add_on_api_seven_construction_boundaries_do_not_expose_generic_logging(): void {
		$registry_parameters = array_map(
			static fn ( \ReflectionParameter $parameter ): string => $parameter->getName(),
			( new ReflectionMethod( AdminAddOnRegistry::class, '__construct' ) )->getParameters()
		);
		$context_parameters  = array_map(
			static fn ( \ReflectionParameter $parameter ): string => $parameter->getName(),
			( new ReflectionMethod( AdminAddOnContext::class, 'for_current_administrator' ) )->getParameters()
		);

		self::assertNotContains( 'logging', $registry_parameters );
		self::assertNotContains( 'logger', $context_parameters );
	}

	public function test_sealed_registry_provides_one_trusted_callable_tab(): void {
		$facade   = new \stdClass();
		$registry = new AdminAddOnRegistry( array( 'example_service' => $facade ), 7, 7 );
		$rendered = array();
		$tab      = new AdminAddOnTab(
			'ran-booster-example',
			'example',
			'Example',
			static function ( AdminAddOnContext $context ) use ( &$rendered ): void {
				$rendered[] = $context->tab_key();
			},
			7,
			7,
			7,
			7,
			'example_service'
		);

		$registry->register( $tab );
		$registry->seal();
		$context = $registry->context_for(
			$tab,
			'https://example.test/wp-admin/admin.php?page=ran-booster&tab=example',
			'site'
		);
		$tab->render( $context );

		self::assertSame( array( $tab ), $registry->all() );
		self::assertSame( $tab, $registry->get( 'example' ) );
		self::assertSame( array( 'example' ), $rendered );
		self::assertSame( 'site', $context->scope() );
		self::assertSame( $facade, $context->facade( 'example_service' ) );
		self::assertNull( $context->facade( 'not_available' ) );
	}

	/** @return list<array{string, string}> */
	public static function invalid_tab_definitions(): array {
		return array(
			array( 'Uppercase', 'Valid label' ),
			array( '../path', 'Valid label' ),
			array( 'valid', '' ),
			array( 'valid', "Unsafe\nlabel" ),
			array( 'valid', str_repeat( 'x', 65 ) ),
		);
	}

	#[DataProvider( 'invalid_tab_definitions' )]
	public function test_tab_rejects_unsafe_keys_and_labels( string $key, string $label ): void {
		$this->expectException( InvalidArgumentException::class );

		new AdminAddOnTab( 'ran-booster-test', $key, $label, static function (): void {} );
	}

	public function test_registry_rejects_duplicate_and_late_registration(): void {
		$registry = new AdminAddOnRegistry();
		$registry->register( $this->tab( 'first' ) );

		try {
			$registry->register( $this->tab( 'first' ) );
			self::fail( 'Duplicate tabs must be rejected.' );
		} catch ( LogicException ) {
			self::addToAssertionCount( 1 );
		}

		$registry->seal();
		$this->expectException( LogicException::class );
		$registry->register( $this->tab( 'late' ) );
	}

	public function test_registry_allows_only_one_tab_per_add_on(): void {
		$registry = new AdminAddOnRegistry();
		$registry->register( $this->tab( 'first' ) );

		$this->expectException( LogicException::class );
		$registry->register( new AdminAddOnTab( 'ran-booster-test', 'second', 'Second', static function (): void {} ) );
	}

	public function test_registry_rejects_undeclared_facade_and_incompatible_generation(): void {
		$registry = new AdminAddOnRegistry( array(), 7, 7 );

		try {
			$registry->register(
				new AdminAddOnTab(
					'ran-booster-test',
					'needs-facade',
					'Needs facade',
					static function (): void {},
					7,
					7,
					7,
					7,
					'unknown_service'
				)
			);
			self::fail( 'Undeclared facades must be rejected.' );
		} catch ( LogicException ) {
			self::addToAssertionCount( 1 );
		}

		$this->expectException( LogicException::class );
		$registry->register( new AdminAddOnTab( 'ran-booster-future', 'future', 'Future', static function (): void {}, 8 ) );
	}

	public function test_context_cannot_be_created_for_an_unauthorized_user(): void {
		$GLOBALS['ran_booster_repository_admin_allowed'] = false;

		$this->expectException( LogicException::class );
		$this->context( 'example' );
	}

	public function test_renderer_rejects_another_tabs_context(): void {
		$this->expectException( LogicException::class );
		$this->tab( 'first' )->render( $this->context( 'other' ) );
	}

	public function test_tab_renders_only_inside_its_declared_api_bounds(): void {
		$tab = new AdminAddOnTab( 'ran-booster-test', 'compatible', 'Compatible', static function (): void {}, 7, 7, 7, 7 );

		self::assertFalse( $tab->supports( $this->context( 'compatible', 6, 7 ) ) );
		self::assertFalse( $tab->supports( $this->context( 'compatible', 7, 6 ) ) );
		self::assertTrue( $tab->supports( $this->context( 'compatible', 7, 7 ) ) );
	}

	private function tab( string $key ): AdminAddOnTab {
		return new AdminAddOnTab( 'ran-booster-test', $key, 'Label', static function (): void {} );
	}

	private function context( string $tab_key, int $core_api_version = 7, int $add_on_api_version = 7 ): AdminAddOnContext {
		return AdminAddOnContext::for_current_administrator(
			$tab_key,
			'https://example.test/wp-admin/admin.php?page=ran-booster',
			'site',
			$core_api_version,
			$add_on_api_version
		);
	}
}
