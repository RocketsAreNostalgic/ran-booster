<?php

declare(strict_types=1);

namespace Tests\Portability;

require_once __DIR__ . '/WpPusherCoexistenceWordPressFunctions.php';

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RAN\Portability\WpPusherCoexistencePolicy;
use RuntimeException;

final class WpPusherCoexistencePolicyTest extends TestCase {

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this exact lifecycle override name.
	protected function setUp(): void {
		$GLOBALS['ran_booster_wp_pusher_active_plugins']  = array();
		$GLOBALS['ran_booster_wp_pusher_network_plugins'] = array();
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this exact lifecycle override name.
	protected function tearDown(): void {
		unset(
			$GLOBALS['ran_booster_wp_pusher_active_plugins'],
			$GLOBALS['ran_booster_wp_pusher_network_plugins']
		);
	}

	/** @return iterable<string, array{array<mixed>, array<mixed>, bool}> */
	public static function state_provider(): iterable {
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
	 * @param array<mixed> $site_active
	 * @param array<mixed> $network_active
	 */
	#[DataProvider( 'state_provider' )]
	public function test_reports_only_exact_active_word_press_inventory_state(
		array $site_active,
		array $network_active,
		bool $expected_conflict
	): void {
		$GLOBALS['ran_booster_wp_pusher_active_plugins']  = $site_active;
		$GLOBALS['ran_booster_wp_pusher_network_plugins'] = $network_active;

		self::assertSame( $expected_conflict, WpPusherCoexistencePolicy::conflict_active() );
	}

	public function test_malformed_active_inventory_fails_closed(): void {
		$GLOBALS['ran_booster_wp_pusher_active_plugins'] = 'malformed';

		self::assertTrue( WpPusherCoexistencePolicy::conflict_active() );
		$this->expectException( RuntimeException::class );
		WpPusherCoexistencePolicy::assert_package_mutation_allowed();
	}

	public function test_inactive_wp_pusher_allows_package_mutation(): void {
		WpPusherCoexistencePolicy::assert_package_mutation_allowed();
		$this->addToAssertionCount( 1 );
	}

	public function test_blocks_only_exact_wp_pusher_activation_while_core_is_active(): void {
		WpPusherCoexistencePolicy::block_wp_pusher_activation( 'wppusher-copy/wppusher.php' );
		$this->addToAssertionCount( 1 );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'cannot be activated while RAN Booster is active' );
		WpPusherCoexistencePolicy::block_wp_pusher_activation( WpPusherCoexistencePolicy::WP_PUSHER_PLUGIN );
	}
}
