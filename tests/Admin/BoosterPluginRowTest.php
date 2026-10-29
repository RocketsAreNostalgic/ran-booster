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
		require_once dirname( __DIR__ ) . '/Support/BoosterPluginRowGlobalWordPressFunctions.php';
		$GLOBALS['ran_booster_plugin_row_hooks']             = array();
		$GLOBALS['ran_booster_plugin_row_capability_checks'] = array();
		$GLOBALS['ran_booster_plugin_row_capabilities']      = array( 'manage_options' => true );
		$GLOBALS['ran_booster_plugin_row_multisite']         = false;
		$GLOBALS['ran_booster_plugin_row_basename']          = 'ran-booster/ran-booster.php';
		$GLOBALS['ran_booster_plugin_row_basename_inputs']   = array();
		$GLOBALS['ran_booster_plugin_row_admin_url_inputs']  = array();
		$GLOBALS['ran_booster_plugin_row_active']            = array();

		$_GET                      = array();
		$_SERVER['REQUEST_METHOD'] = 'POST';
		unset( $GLOBALS['wp_list_table'] );
	}

	public function test_registers_native_links_against_the_exact_booster_basename_and_preserves_existing_links(): void {
		$booster               = $this->booster();
		$booster->booster_path = '/arbitrary/checkout/';

		$booster->register_plugin_action_links();

		self::assertSame( array( '/arbitrary/checkout/ran-booster.php' ), $GLOBALS['ran_booster_plugin_row_basename_inputs'] );
		self::assertSame( array( 'admin.php?page=ran-booster-transporter#ran-booster-portability-export' ), $GLOBALS['ran_booster_plugin_row_admin_url_inputs'] );
		$action_links = $this->hook( 'plugin_action_links_ran-booster/ran-booster.php' );
		self::assertSame( 1, $action_links['accepted_args'] );
		self::assertSame(
			array(
				'<a href="https://example.test/keep">Keep existing link</a>',
				'<a href="https://example.test/wp-admin/admin.php?page=ran-booster-transporter#ran-booster-portability-export">Export before deleting</a>',
			),
			$action_links['callback']( array( '<a href="https://example.test/keep">Keep existing link</a>' ) )
		);
		self::assertSame( 'after_plugin_row_ran-booster/ran-booster.php', $this->hook( 'after_plugin_row_ran-booster/ran-booster.php' )['hook'] );
	}

	public function test_native_guidance_is_registered_before_database_readiness_and_renders_a_safe_dynamic_row(): void {
		$booster               = $this->booster();
		$booster->booster_path = '/booster/';
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- The isolated row-rendering fixture supplies WordPress's list table.
		$GLOBALS['wp_list_table'] = new class() {
			public function get_column_count(): int {
				return 6;
			}
		};

		$booster->register_plugin_action_links();

		self::assertNotNull( $this->hook_or_null( 'plugin_action_links_ran-booster/ran-booster.php' ) );
		$row = $this->hook( 'after_plugin_row_ran-booster/ran-booster.php' );
		ob_start();
		$row['callback']( 'evil" data-injected="yes' );
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'colspan="6"', $html );
		self::assertStringContainsString( 'data-plugin="evil&quot; data-injected=&quot;yes"', $html );
		self::assertStringNotContainsString( 'data-injected="yes"', $html );
		self::assertStringContainsString( 'Deleting Booster permanently removes its owned local credentials and records.', $html );
	}

	public function test_native_guidance_is_limited_to_single_site_administrators(): void {
		$GLOBALS['ran_booster_plugin_row_capabilities']['manage_options'] = false;
		$this->booster()->register_plugin_action_links();
		self::assertSame( array(), $GLOBALS['ran_booster_plugin_row_hooks'] );
		self::assertSame( array( 'manage_options' ), $GLOBALS['ran_booster_plugin_row_capability_checks'] );

		$GLOBALS['ran_booster_plugin_row_capabilities']['manage_options'] = true;
		$GLOBALS['ran_booster_plugin_row_multisite']                      = true;
		$this->booster()->register_plugin_action_links();
		self::assertSame( array(), $GLOBALS['ran_booster_plugin_row_hooks'] );
	}

	public function test_native_guidance_falls_back_to_four_columns_and_marks_the_active_plugin_row(): void {
		$GLOBALS['ran_booster_plugin_row_active']['ran-booster/ran-booster.php'] = true;
		$booster               = $this->booster();
		$booster->booster_path = '/booster/';
		$booster->register_plugin_action_links();

		$row = $this->hook( 'after_plugin_row_ran-booster/ran-booster.php' );
		ob_start();
		$row['callback']( 'ran-booster/ran-booster.php' );
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'class="plugin-update-tr active"', $html );
		self::assertStringContainsString( 'colspan="4"', $html );
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
