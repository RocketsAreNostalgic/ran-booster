<?php

declare(strict_types=1);

namespace Tests\Admin;

require_once dirname( __DIR__ ) . '/Support/RepositoryAdminWordPressFunctions.php';

use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RAN\AddOn\Logging\LoggingFacade;
use Tests\Support\NullLoggingFacade;
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

	public function testAddOnApiSevenRequiresLoggingAtBothConstructionBoundaries(): void {
		$registryLogging = ( new ReflectionMethod( AdminAddOnRegistry::class, '__construct' ) )->getParameters()[0];
		$contextLogging  = ( new ReflectionMethod( AdminAddOnContext::class, 'forCurrentAdministrator' ) )->getParameters()[5];

		self::assertSame( LoggingFacade::class, (string) $registryLogging->getType() );
		self::assertFalse( $registryLogging->isOptional() );
		self::assertSame( LoggingFacade::class, (string) $contextLogging->getType() );
		self::assertFalse( $contextLogging->isOptional() );
	}

	public function testSealedRegistryProvidesOneTrustedCallableTab(): void {
		$facade   = new \stdClass();
		$logging  = new NullLoggingFacade();
		$registry = new AdminAddOnRegistry( $logging, array( 'example_service' => $facade ), 7, 7 );
		$rendered = array();
		$tab      = new AdminAddOnTab(
			'ran-booster-example',
			'example',
			'Example',
			static function ( AdminAddOnContext $context ) use ( &$rendered ): void {
				$rendered[] = $context->tabKey();
			},
			7,
			7,
			7,
			7,
			'example_service'
		);

		$registry->register( $tab );
		$registry->seal();
		$context = $registry->contextFor(
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
		self::assertInstanceOf( LoggingFacade::class, $context->logger() );
		self::assertSame( $logging, $context->logger() );
		self::assertNull( $context->facade( 'not_available' ) );
	}

	/** @return list<array{string, string}> */
	public static function invalidTabDefinitions(): array {
		return array(
			array( 'Uppercase', 'Valid label' ),
			array( '../path', 'Valid label' ),
			array( 'valid', '' ),
			array( 'valid', "Unsafe\nlabel" ),
			array( 'valid', str_repeat( 'x', 65 ) ),
		);
	}

	#[DataProvider( 'invalidTabDefinitions' )]
	public function testTabRejectsUnsafeKeysAndLabels( string $key, string $label ): void {
		$this->expectException( InvalidArgumentException::class );

		new AdminAddOnTab( 'ran-booster-test', $key, $label, static function (): void {} );
	}

	public function testRegistryRejectsDuplicateAndLateRegistration(): void {
		$registry = new AdminAddOnRegistry( new NullLoggingFacade() );
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

	public function testRegistryAllowsOnlyOneTabPerAddOn(): void {
		$registry = new AdminAddOnRegistry( new NullLoggingFacade() );
		$registry->register( $this->tab( 'first' ) );

		$this->expectException( LogicException::class );
		$registry->register( new AdminAddOnTab( 'ran-booster-test', 'second', 'Second', static function (): void {} ) );
	}

	public function testRegistryRejectsUndeclaredFacadeAndIncompatibleGeneration(): void {
		$registry = new AdminAddOnRegistry( new NullLoggingFacade(), array(), 7, 7 );

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

	public function testContextCannotBeCreatedForAnUnauthorizedUser(): void {
		$GLOBALS['ran_booster_repository_admin_allowed'] = false;

		$this->expectException( LogicException::class );
		$this->context( 'example' );
	}

	public function testRendererRejectsAnotherTabsContext(): void {
		$this->expectException( LogicException::class );
		$this->tab( 'first' )->render( $this->context( 'other' ) );
	}

	public function testTabRendersOnlyInsideItsDeclaredApiBounds(): void {
		$tab = new AdminAddOnTab( 'ran-booster-test', 'compatible', 'Compatible', static function (): void {}, 7, 7, 7, 7 );

		self::assertFalse( $tab->supports( $this->context( 'compatible', 6, 7 ) ) );
		self::assertFalse( $tab->supports( $this->context( 'compatible', 7, 6 ) ) );
		self::assertTrue( $tab->supports( $this->context( 'compatible', 7, 7 ) ) );
	}

	private function tab( string $key ): AdminAddOnTab {
		return new AdminAddOnTab( 'ran-booster-test', $key, 'Label', static function (): void {} );
	}

	private function context( string $tabKey, int $coreApiVersion = 7, int $addOnApiVersion = 7 ): AdminAddOnContext {
		return AdminAddOnContext::forCurrentAdministrator(
			$tabKey,
			'https://example.test/wp-admin/admin.php?page=ran-booster',
			'site',
			$coreApiVersion,
			$addOnApiVersion,
			new NullLoggingFacade()
		);
	}
}
