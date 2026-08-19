<?php

declare(strict_types=1);

namespace Tests\PackageRemoval;

require_once __DIR__ . '/WordPressPackageRemovalGatewayWordPressFunctions.php';

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use RAN\PackageRemoval\WordPressPackageRemovalGateway;
use RuntimeException;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class WordPressPackageRemovalGatewayTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['ran_booster_package_removal_gateway_events'] = array();
	}

	protected function tearDown(): void {
		unset(
			$GLOBALS['ran_booster_package_removal_gateway_events'],
			$GLOBALS['ran_booster_package_removal_gateway_result']
		);
	}

	#[DataProvider( 'deleteResults' )]
	public function testPluginInventoryIsRefreshedAfterEveryCompletedDeleteAttempt( mixed $result, bool $deleted ): void {
		$GLOBALS['ran_booster_package_removal_gateway_result'] = $result;

		self::assertSame(
			$deleted,
			( new WordPressPackageRemovalGateway() )->deletePlugin( 'example/example.php' )
		);
		self::assertSame(
			array(
				array( 'delete', array( 'example/example.php' ) ),
				array( 'clean', false ),
			),
			$GLOBALS['ran_booster_package_removal_gateway_events']
		);
	}

	/** @return list<array{mixed, bool}> */
	public static function deleteResults(): array {
		return array(
			array( true, true ),
			array( false, false ),
		);
	}

	public function testPluginInventoryIsRefreshedWhenWordPressDeletionThrows(): void {
		$GLOBALS['ran_booster_package_removal_gateway_result'] = new RuntimeException( 'Fixture deletion failed.' );

		try {
			( new WordPressPackageRemovalGateway() )->deletePlugin( 'example/example.php' );
			self::fail( 'Expected the WordPress deletion failure.' );
		} catch ( RuntimeException $failure ) {
			self::assertSame( 'Fixture deletion failed.', $failure->getMessage() );
		}
		self::assertSame(
			array(
				array( 'delete', array( 'example/example.php' ) ),
				array( 'clean', false ),
			),
			$GLOBALS['ran_booster_package_removal_gateway_events']
		);
	}
}
