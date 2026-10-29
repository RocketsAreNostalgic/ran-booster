<?php

declare(strict_types=1);

namespace RAN\Tests\Admin;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use RAN\Booster;
use RAN\Internal\CoreContainer;
use RAN\Storage\Database;
use RAN\Storage\DatabaseCompatibilityFailure;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class BoosterPluginRowTest extends TestCase {

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this exact lifecycle override name.
	protected function setUp(): void {
		require_once __DIR__ . '/BoosterPluginRowWordPressFunctions.php';
		$GLOBALS['ran_booster_plugin_row_hooks']             = array();
		$GLOBALS['ran_booster_plugin_row_capability_checks'] = array();
		$GLOBALS['ran_booster_plugin_row_capabilities']      = array( 'manage_options' => true );
		$GLOBALS['ran_booster_plugin_row_multisite']         = false;
		$GLOBALS['ran_booster_plugin_row_basename']          = 'ran-booster/ran-booster.php';
		$GLOBALS['ran_booster_plugin_row_basename_inputs']   = array();
		$GLOBALS['ran_booster_plugin_row_admin_url_inputs']  = array();

		$_GET                      = array();
		$_SERVER['REQUEST_METHOD'] = 'POST';
	}

	public function test_registers_the_exact_native_export_target_before_database_readiness_and_preserves_keyed_links(): void {
		$booster               = $this->booster();
		$booster->booster_path = '/arbitrary/checkout/';

		$booster->register_plugin_action_links();

		self::assertSame( array( '/arbitrary/checkout/ran-booster.php' ), $GLOBALS['ran_booster_plugin_row_basename_inputs'] );
		self::assertSame( array( 'admin.php?page=ran-booster-transporter#ran-booster-portability-export' ), $GLOBALS['ran_booster_plugin_row_admin_url_inputs'] );
		$action_links = $this->hook( 'plugin_action_links_ran-booster/ran-booster.php' );
		self::assertSame( 1, $action_links['accepted_args'] );
		self::assertSame(
			array(
				'deactivate' => '<a href="https://example.test/keep">Keep existing link</a>',
				'<a href="https://example.test/wp-admin/admin.php?page=ran-booster-transporter#ran-booster-portability-export">Export settings before deleting</a>',
			),
			$action_links['callback']( array( 'deactivate' => '<a href="https://example.test/keep">Keep existing link</a>' ) )
		);
		self::assertNull( $this->hook_or_null( 'after_plugin_row_ran-booster/ran-booster.php' ) );
	}

	public function test_native_export_link_is_limited_to_single_site_administrators(): void {
		$GLOBALS['ran_booster_plugin_row_capabilities']['manage_options'] = false;
		$this->booster()->register_plugin_action_links();
		self::assertSame( array(), $GLOBALS['ran_booster_plugin_row_hooks'] );
		self::assertSame( array( 'manage_options' ), $GLOBALS['ran_booster_plugin_row_capability_checks'] );

		$GLOBALS['ran_booster_plugin_row_capabilities']['manage_options'] = true;
		$GLOBALS['ran_booster_plugin_row_multisite']                      = true;
		$this->booster()->register_plugin_action_links();
		self::assertSame( array(), $GLOBALS['ran_booster_plugin_row_hooks'] );
	}

	private function booster(): Booster {
		$container = new CoreContainer();
		$container->bind(
			'RAN\\Storage\\Database',
			new class() extends Database {
				public function __construct() {
				}

				public function require_ready(): void {
					throw new DatabaseCompatibilityFailure( 'test_failure' );
				}
			}
		);

		return new Booster( $container );
	}

	/** @return array{hook: string, callback: callable, priority: int, accepted_args: int} */
	private function hook( string $name ): array {
		$hook = $this->hook_or_null( $name );
		self::assertNotNull( $hook );

		return $hook;
	}

	/** @return array{hook: string, callback: callable, priority: int, accepted_args: int}|null */
	private function hook_or_null( string $name ): ?array {
		foreach ( $GLOBALS['ran_booster_plugin_row_hooks'] as $hook ) {
			if ( $name === $hook['hook'] ) {
				return $hook;
			}
		}

		return null;
	}
}
