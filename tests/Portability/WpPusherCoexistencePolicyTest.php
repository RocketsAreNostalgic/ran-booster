<?php

declare(strict_types=1);

namespace Tests\Portability;

require_once __DIR__ . '/WpPusherCoexistenceWordPressFunctions.php';

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RAN\Portability\WpPusherCoexistencePolicy;
use RuntimeException;

final class WpPusherCoexistencePolicyTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['ran_booster_wp_pusher_active_plugins']  = array();
		$GLOBALS['ran_booster_wp_pusher_network_plugins'] = array();
	}

	protected function tearDown(): void {
		unset(
			$GLOBALS['ran_booster_wp_pusher_active_plugins'],
			$GLOBALS['ran_booster_wp_pusher_network_plugins']
		);
	}

	/** @return iterable<string, array{array<mixed>, array<mixed>, bool}> */
	public static function stateProvider(): iterable {
		yield 'inactive' => array( array(), array(), false );
		yield 'site-active WP Pusher' => array(
			array( WpPusherCoexistencePolicy::WP_PUSHER_PLUGIN ),
			array(),
			true,
		);
		yield 'network-active WP Pusher' => array(
			array(),
			array( WpPusherCoexistencePolicy::WP_PUSHER_PLUGIN => time() ),
			true,
		);
		yield 'similar basename ignored' => array(
			array( 'wppusher-copy/wppusher.php' ),
			array(),
			false,
		);
	}

	/**
	 * @param array<mixed> $siteActive
	 * @param array<mixed> $networkActive
	 */
	#[DataProvider( 'stateProvider' )]
	public function testReportsOnlyExactActiveWordPressInventoryState(
		array $siteActive,
		array $networkActive,
		bool $expectedConflict
	): void {
		$GLOBALS['ran_booster_wp_pusher_active_plugins']  = $siteActive;
		$GLOBALS['ran_booster_wp_pusher_network_plugins'] = $networkActive;

		self::assertSame( $expectedConflict, WpPusherCoexistencePolicy::conflictActive() );
	}

	public function testMalformedActiveInventoryFailsClosed(): void {
		$GLOBALS['ran_booster_wp_pusher_active_plugins'] = 'malformed';

		self::assertTrue( WpPusherCoexistencePolicy::conflictActive() );
		$this->expectException( RuntimeException::class );
		WpPusherCoexistencePolicy::assertPackageMutationAllowed();
	}

	public function testInactiveWpPusherAllowsPackageMutation(): void {
		WpPusherCoexistencePolicy::assertPackageMutationAllowed();
		$this->addToAssertionCount( 1 );
	}

	public function testBlocksOnlyExactWpPusherActivationWhileCoreIsActive(): void {
		WpPusherCoexistencePolicy::blockWpPusherActivation( 'wppusher-copy/wppusher.php' );
		$this->addToAssertionCount( 1 );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'cannot be activated while RAN Booster is active' );
		WpPusherCoexistencePolicy::blockWpPusherActivation( WpPusherCoexistencePolicy::WP_PUSHER_PLUGIN );
	}
}
